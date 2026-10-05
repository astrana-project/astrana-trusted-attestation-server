<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\LimitRequestBody;
use App\Models\MemberRelationship;
use App\Services\AuditLog;
use App\Services\MemberIdentityResolver;
use App\Services\RelationshipStore;
use App\Services\TransactionRunner;
use App\Support\PublicKeys;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\ImmediateTransactionRunner;
use Tests\Support\ProgrammableRelationshipStore;
use Tests\Support\RecordingAuditLog;
use Tests\TestCase;

/**
 * How the API reads its request body, through the router and the middleware in front of the controller.
 *
 * Three things the three implementations have to agree on: the body is read as JSON whatever its
 * Content-Type says, a body that is not exactly one JSON value (a byte order mark, trailing tokens,
 * invalid UTF-8) is a malformed body, and a body over 64 kilobytes is HTTP 413 with no body. The cap sits
 * in a middleware, so a test that calls the controller directly never passes through it.
 *
 * A Feature test because it drives the router. The store is a programmable fake bound into the container,
 * so no database is touched.
 */
final class ApiRequestBodyTest extends TestCase
{
    private const KEY_PATH = '/api/v1/me/relationships/employee/key';

    private ProgrammableRelationshipStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = new ProgrammableRelationshipStore;
        $this->app->instance(RelationshipStore::class, $this->store);
        $this->app->instance(AuditLog::class, new RecordingAuditLog);
        $this->app->instance(TransactionRunner::class, new ImmediateTransactionRunner);
    }

    private static function validKeyJson(): string
    {
        return json_encode(['public_key' => base64_encode(str_repeat('k', PublicKeys::LENGTH))], JSON_THROW_ON_ERROR);
    }

    private function grantedRelationship(): MemberRelationship
    {
        $row = new MemberRelationship;
        $row->id = 1;
        $row->iam_subject_id = 'alice';
        $row->relationship_type = 'employee';
        $row->relationship_subtype = null;
        $row->expires_at = null;
        $row->revoked_at = null;

        return $row;
    }

    private function putKey(string $content, string $contentType = 'application/json'): TestResponse
    {
        return $this->withSession([MemberIdentityResolver::SESSION_KEY => ['sub' => 'alice']])
            ->call('PUT', self::KEY_PATH, [], [], [], ['CONTENT_TYPE' => $contentType], $content);
    }

    private function attest(string $content, string $contentType = 'application/json'): TestResponse
    {
        return $this->call('POST', '/api/v1/attest', [], [], [], ['CONTENT_TYPE' => $contentType], $content);
    }

    // -- the body is read whatever the Content-Type says ----------------------------------------------

    /** @return iterable<string, array{string}> */
    public static function otherContentTypes(): iterable
    {
        yield 'text/plain' => ['text/plain'];
        yield 'a form' => ['application/x-www-form-urlencoded'];
        yield 'octet-stream' => ['application/octet-stream'];
    }

    #[Test]
    #[DataProvider('otherContentTypes')]
    public function a_json_body_is_read_whatever_content_type_it_is_sent_under(string $contentType): void
    {
        // The key reaches the service and is saved: a 200 with the row's state, not a 400 for a body the
        // framework declined to parse.
        $this->store->own = $this->grantedRelationship();

        $response = $this->putKey(self::validKeyJson(), $contentType);

        $response->assertOk();
        self::assertSame('employee', $response->json('relationship_type'));
    }

    // -- a body that is not exactly one JSON value is malformed -----------------------------------------

    /** @return iterable<string, array{string}> */
    public static function malformedBodies(): iterable
    {
        yield 'a byte order mark' => ["\u{FEFF}".self::validKeyJson()];
        yield 'trailing tokens' => [self::validKeyJson().' x'];
        yield 'a second JSON value' => [self::validKeyJson().'{}'];
        yield 'invalid UTF-8 in the key' => ['{"public_key":"'."\xC3\x28".'"}'];
        yield 'not JSON' => ['this is not json'];
    }

    #[Test]
    #[DataProvider('malformedBodies')]
    public function a_malformed_body_on_the_key_endpoint_is_bad_request_with_no_body(string $content): void
    {
        $this->store->own = $this->grantedRelationship();

        $response = $this->putKey($content);

        $response->assertStatus(400);
        self::assertSame('', $response->getContent());
        self::assertNull($response->headers->get('Content-Type'));
    }

    #[Test]
    #[DataProvider('malformedBodies')]
    public function a_malformed_body_on_attestation_is_a_plain_invalid(string $content): void
    {
        $response = $this->attest($content);

        $response->assertOk();
        self::assertSame(['valid' => false], $response->json());
    }

    // -- the cap --------------------------------------------------------------------------------------

    #[Test]
    public function a_body_over_64_kilobytes_is_content_too_large_with_no_body_on_the_key_endpoint(): void
    {
        $this->store->own = $this->grantedRelationship();

        $response = $this->putKey('{"public_key":"'.str_repeat('a', LimitRequestBody::MAX_BYTES).'"}');

        $response->assertStatus(413);
        self::assertSame('', $response->getContent());
        self::assertNull($response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    #[Test]
    public function a_body_over_64_kilobytes_is_content_too_large_on_attestation_too(): void
    {
        $response = $this->attest('{"public_key":"'.str_repeat('a', LimitRequestBody::MAX_BYTES).'"}');

        $response->assertStatus(413);
        self::assertSame('', $response->getContent());
    }

    #[Test]
    public function a_body_of_exactly_64_kilobytes_is_read(): void
    {
        // The boundary belongs inside: exactly the cap is read and judged on its content, which here is
        // not a key, so the answer is a plain invalid rather than a refusal of the body.
        $padding = LimitRequestBody::MAX_BYTES - strlen('{"public_key":""}');

        $response = $this->attest('{"public_key":"'.str_repeat('a', $padding).'"}');

        $response->assertOk();
        self::assertSame(['valid' => false], $response->json());
    }

    #[Test]
    public function a_declared_length_over_the_cap_is_refused_before_the_body_is_read(): void
    {
        $response = $this->call('POST', '/api/v1/attest', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_CONTENT_LENGTH' => (string) (LimitRequestBody::MAX_BYTES + 1),
        ], '{}');

        $response->assertStatus(413);
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function operationsThatReadNoBody(): iterable
    {
        yield 'listing the relationships' => ['GET', '/api/v1/me', 200];
        yield 'removing a key' => ['DELETE', self::KEY_PATH, 204];
        yield 'revoking' => ['POST', '/api/v1/me/relationships/employee/revoke', 204];
    }

    #[Test]
    #[DataProvider('operationsThatReadNoBody')]
    public function an_operation_that_reads_no_body_is_not_capped(string $method, string $uri, int $status): void
    {
        // The other two implementations never read a body on these, so a large one changes nothing.
        $this->store->own = $this->grantedRelationship();
        $this->store->deleteCount = 1;

        $response = $this->withSession([MemberIdentityResolver::SESSION_KEY => ['sub' => 'alice']])
            ->call($method, $uri, [], [], [], ['CONTENT_TYPE' => 'application/json'], str_repeat('a', LimitRequestBody::MAX_BYTES + 1));

        $response->assertStatus($status);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function oversizeKeyRequests(): iterable
    {
        yield 'an oversize body' => [['CONTENT_TYPE' => 'application/json'], str_repeat('a', LimitRequestBody::MAX_BYTES + 1)];
        yield 'an oversize declared length' => [['CONTENT_TYPE' => 'application/json', 'HTTP_CONTENT_LENGTH' => (string) (LimitRequestBody::MAX_BYTES + 1)], '{}'];
    }

    /** @param array<string, string> $server */
    #[Test]
    #[DataProvider('oversizeKeyRequests')]
    public function an_oversize_key_request_with_no_member_signed_in_is_401_not_413(array $server, string $content): void
    {
        // No session is answered first, as on the other two implementations, which check the caller before
        // they read the body.
        $response = $this->call('PUT', self::KEY_PATH, [], [], [], $server, $content);

        $response->assertUnauthorized();
        self::assertSame('', $response->getContent());
    }

    #[Test]
    public function the_pages_are_not_capped(): void
    {
        // The SAML consumer receives assertions larger than this, and the pages post forms, so the cap is
        // the API's alone: a large form to the language switcher is still the ordinary redirect.
        $response = $this->call('POST', '/set-language', ['locale' => 'fr', 'next' => '/', 'padding' => str_repeat('a', LimitRequestBody::MAX_BYTES)]);

        $response->assertRedirect('/');
    }
}
