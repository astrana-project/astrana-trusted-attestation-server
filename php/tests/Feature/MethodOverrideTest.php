<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MemberRelationship;
use App\Services\AuditLog;
use App\Services\MemberIdentityResolver;
use App\Services\RelationshipStore;
use App\Services\TransactionRunner;
use App\Support\PublicKeys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ImmediateTransactionRunner;
use Tests\Support\ProgrammableRelationshipStore;
use Tests\Support\RecordingAuditLog;
use Tests\TestCase;

/**
 * A POST is handled as a POST, whatever it says about another method.
 *
 * Laravel would otherwise read a _method field from the query string or a form body, or an
 * X-HTTP-Method-Override header, and route the POST as the method it names. A plain form on another site,
 * a sibling subdomain included, could then register an attacker's key against a signed-in member's
 * relationship, or remove it, because a form can send a POST without the preflight a real PUT or DELETE
 * needs. The other two implementations have no method override, so a POST to the key's address answers
 * HTTP 405 - Method Not Allowed there, and here too.
 *
 * A Feature test because it drives the router. The store is a programmable fake, so no database is touched.
 */
final class MethodOverrideTest extends TestCase
{
    private const KEY_PATH = '/api/v1/me/relationships/employee/key';

    private ProgrammableRelationshipStore $store;

    private RecordingAuditLog $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $row = new MemberRelationship;
        $row->id = 1;
        $row->iam_subject_id = 'alice';
        $row->relationship_type = 'employee';
        $row->relationship_subtype = null;
        $row->setAttribute('public_key_hex', bin2hex(str_repeat('o', PublicKeys::LENGTH)));
        $row->expires_at = null;
        $row->revoked_at = null;

        $this->store = new ProgrammableRelationshipStore;
        $this->store->own = $row;
        $this->store->deleteCount = 1;
        $this->audit = new RecordingAuditLog;
        $this->app->instance(RelationshipStore::class, $this->store);
        $this->app->instance(AuditLog::class, $this->audit);
        $this->app->instance(TransactionRunner::class, new ImmediateTransactionRunner);
    }

    /** @return iterable<string, array{string, array<string, string>, array<string, string>, string}> */
    public static function overriddenPosts(): iterable
    {
        $key = json_encode(['public_key' => base64_encode(str_repeat('k', PublicKeys::LENGTH))], JSON_THROW_ON_ERROR);

        yield 'PUT in the query string, the key as text' => [self::KEY_PATH.'?_method=PUT', [], ['CONTENT_TYPE' => 'text/plain'], $key];
        yield 'DELETE in the query string' => [self::KEY_PATH.'?_method=DELETE', [], [], ''];
        yield 'DELETE in a form field' => [self::KEY_PATH, ['_method' => 'DELETE'], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], ''];
        yield 'DELETE in the override header' => [self::KEY_PATH, [], ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'DELETE'], ''];
    }

    /**
     * @param  array<string, string>  $form
     * @param  array<string, string>  $server
     */
    #[Test]
    #[DataProvider('overriddenPosts')]
    public function a_post_naming_another_method_is_answered_as_a_post_and_changes_nothing(
        string $uri,
        array $form,
        array $server,
        string $content,
    ): void {
        $response = $this->withSession([MemberIdentityResolver::SESSION_KEY => ['sub' => 'alice']])
            ->call('POST', $uri, $form, [], [], $server, $content);

        $response->assertStatus(405);
        self::assertSame([], $this->audit->events);
    }
}
