<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contract\RelationshipTypeCatalog;
use App\Http\Controllers\ApiController;
use App\Models\MemberRelationship;
use App\Services\AuditLog;
use App\Services\MemberIdentityResolver;
use App\Services\RelationshipService;
use App\Services\RelationshipStore;
use App\Services\TransactionRunner;
use App\Support\PublicKeys;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * How each of the four operations maps a service result to an HTTP answer, and how the request body is
 * read -- both isolated from the database.
 *
 * The conformance suite drives these end to end; this pins the controller's own decisions: which result
 * becomes 401 / 400 / 404 / 409 / 200 / 204, and the lenient body reading that lets a public_key which is
 * a number, an object or an array reach the handler as "no key" rather than a framework 400, a case
 * each framework's default binding reads differently. It mirrors the Java sibling ApiControllerTest one-for-one.
 *
 * A Feature test rather than a Unit one, and for the same reason MemberIdentityResolverTest is: the
 * controller leans on Laravel's response()/config() helpers and the resolver reads configuration, so it
 * needs the application container -- but it touches no database and no HTTP. The RelationshipService is
 * real, driven from below by a hand-written store fake (no database, no mocking framework); the resolver
 * is the real one, driven by a real session, exactly as the two collaborators behave in production.
 */
final class ApiControllerTest extends TestCase
{
    private ProgrammableStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new ProgrammableStore;
    }

    private function controller(): ApiController
    {
        $service = new RelationshipService(
            $this->store,
            new ImmediateRunner,
            new SilentAuditLog,
            $this->app->make(RelationshipTypeCatalog::class),
        );

        return new ApiController($service, $this->app->make(MemberIdentityResolver::class));
    }

    // 32 non-zero bytes: PublicKeys::parse refuses the all-zero key, and base64 of this round-trips
    // to the single canonical encoding parse() accepts.
    private static function rawKey(): string
    {
        return str_repeat('k', PublicKeys::LENGTH);
    }

    /** A request carrying $claims in the session (null for an unauthenticated caller) and $body as JSON. */
    private function request(?array $claims, ?array $body = null): Request
    {
        return $this->build($claims, $body === null ? null : (string) json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** As above but with the raw request content verbatim -- for bodies that are not well-formed JSON. */
    private function requestWithRawBody(string $content): Request
    {
        return $this->build(null, $content);
    }

    private function build(?array $claims, ?string $content): Request
    {
        $session = new Store('ata_session', new ArraySessionHandler(60));
        if ($claims !== null) {
            $session->put(MemberIdentityResolver::SESSION_KEY, $claims);
        }

        $server = $content === null ? [] : ['CONTENT_TYPE' => 'application/json'];
        $request = Request::create('/api/v1/x', 'POST', [], [], [], $server, $content);
        $request->setLaravelSession($session);

        return $request;
    }

    private function relationship(
        string $type,
        ?string $subtype = null,
        bool $keyed = false,
        ?string $expiresAt = null,
        ?string $revokedAt = null,
    ): MemberRelationship {
        $row = new MemberRelationship;
        $row->id = 1;
        $row->iam_subject_id = 'alice';
        $row->relationship_type = $type;
        $row->relationship_subtype = $subtype;
        if ($keyed) {
            // The accessor reads public_key back from the hex column the queries actually select.
            $row->setAttribute('public_key_hex', bin2hex(self::rawKey()));
        }
        // Raw, as a row hydrated from the database arrives: assigning a date through setAttribute formats
        // it to whole seconds, and the fraction of a second is part of what the expiry tests pin.
        $row->setRawAttributes(['expires_at' => $expiresAt, 'revoked_at' => $revokedAt] + $row->getAttributes());

        return $row;
    }

    // -- GET /me -------------------------------------------------------------------------------------

    #[Test]
    public function me_without_a_session_is_unauthorized(): void
    {
        self::assertSame(401, $this->controller()->me($this->request(null))->getStatusCode());
    }

    #[Test]
    public function me_returns_the_members_name_and_the_relationships_they_hold(): void
    {
        // Two rows so both arms of the public_key / expires_at mapping are exercised at once: a keyed,
        // expiring relationship and an unkeyed, non-expiring one.
        $this->store->all = [
            $this->relationship('employee', keyed: true, expiresAt: '2999-01-01T00:00:00Z'),
            $this->relationship('client'),
        ];

        $response = $this->controller()->me($this->request(['sub' => 'alice', 'name' => 'Alice Anderson']));
        $body = $response->getData(true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Alice Anderson', $body['name']);
        self::assertCount(2, $body['relationships']);
        self::assertSame(base64_encode(self::rawKey()), $body['relationships'][0]['public_key']);
        self::assertSame('2999-01-01T00:00:00Z', $body['relationships'][0]['expires_at']);
        self::assertNull($body['relationships'][1]['public_key']);
        self::assertNull($body['relationships'][1]['expires_at']);
    }

    #[Test]
    public function an_expiry_with_a_fraction_of_a_second_is_written_as_the_other_implementations_write_it(): void
    {
        // ISO 8601 UTC with a trailing Z, the fraction trimmed of trailing zeros and omitted when zero.
        // Carbon's own Zulu string dropped the fraction, so the same row read differently across stacks.
        $this->store->all = [$this->relationship('employee', expiresAt: '2030-06-01T12:00:00.250000Z')];

        $body = $this->controller()->me($this->request(['sub' => 'alice']))->getData(true);

        self::assertSame('2030-06-01T12:00:00.25Z', $body['relationships'][0]['expires_at']);
    }

    #[Test]
    public function me_for_a_member_holding_nothing_is_an_ok_empty_list(): void
    {
        $this->store->all = [];

        $response = $this->controller()->me($this->request(['sub' => 'dave']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $response->getData(true)['relationships']);
    }

    // -- PUT .../key ---------------------------------------------------------------------------------

    #[Test]
    public function set_key_without_a_session_is_unauthorized(): void
    {
        $response = $this->controller()->setKey($this->request(null, ['public_key' => base64_encode(self::rawKey())]), 'employee');

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function set_key_with_a_malformed_key_is_bad_request(): void
    {
        $response = $this->controller()->setKey($this->request(['sub' => 'alice'], ['public_key' => 'too-short']), 'employee');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function set_key_with_a_non_string_public_key_is_bad_request(): void
    {
        // A number where a string was expected reaches the handler as "no key", answered 400 here rather
        // than by the JSON layer -- the lenient-parsing contract shared with attest.
        $response = $this->controller()->setKey($this->request(['sub' => 'alice'], ['public_key' => 1234]), 'employee');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function set_key_on_a_relationship_not_granted_is_not_found(): void
    {
        // The service finds no such row, so the write path never creates one: a PUT is not a self-grant.
        $this->store->finds = [null];

        $response = $this->controller()->setKey($this->request(['sub' => 'alice'], ['public_key' => base64_encode(self::rawKey())]), 'employee');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function set_key_with_a_conflicting_key_is_conflict(): void
    {
        $this->store->finds = [$this->relationship('employee')];
        $this->store->keyHeldElsewhere = true;

        $response = $this->controller()->setKey($this->request(['sub' => 'alice'], ['public_key' => base64_encode(self::rawKey())]), 'employee');

        self::assertSame(409, $response->getStatusCode());
    }

    #[Test]
    public function set_key_saved_rereads_the_row_and_returns_its_status(): void
    {
        // The service's own find and the controller's re-read both go through the store; the second read
        // is what the status is answered from -- here a keyed, live row, so it reports active.
        $saved = $this->relationship('employee', subtype: 'Contractor', keyed: true);
        $this->store->finds = [$saved, $saved];

        $response = $this->controller()->setKey($this->request(['sub' => 'alice'], ['public_key' => base64_encode(self::rawKey())]), 'employee');
        $body = $response->getData(true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(base64_encode(self::rawKey()), $body['public_key']);
        self::assertSame('employee', $body['relationship_type']);
        self::assertSame('Contractor', $body['relationship_subtype']);
        self::assertSame('active', $body['status']);
    }

    #[Test]
    public function set_key_saved_but_gone_on_reread_is_not_found(): void
    {
        // Defensive: a row that vanished between the write and the re-read is a 404, not a 500 -- the
        // status has to come from a row, and there is none.
        $this->store->finds = [$this->relationship('employee'), null];

        $response = $this->controller()->setKey($this->request(['sub' => 'alice'], ['public_key' => base64_encode(self::rawKey())]), 'employee');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function set_key_with_an_empty_string_clears_the_key_and_reports_unkeyed(): void
    {
        // A present but empty public_key is the member clearing their key. The service's find sees a keyed
        // row (so there is something to clear); the controller's re-read sees the now-unkeyed row, which is
        // what the response reports -- a null key and a "waiting for your key" standing.
        $this->store->finds = [
            $this->relationship('employee', keyed: true),
            $this->relationship('employee'),
        ];

        $response = $this->controller()->setKey($this->request(['sub' => 'alice'], ['public_key' => '']), 'employee');
        $body = $response->getData(true);

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($body['public_key']);
        self::assertSame('unkeyed', $body['status']);
    }

    #[Test]
    public function set_key_with_a_blank_string_is_treated_as_a_clear_not_a_bad_request(): void
    {
        $this->store->finds = [
            $this->relationship('employee', keyed: true),
            $this->relationship('employee'),
        ];

        $response = $this->controller()->setKey($this->request(['sub' => 'alice'], ['public_key' => " \t\r\n "]), 'employee');

        self::assertSame(200, $response->getStatusCode());
        self::assertNull($response->getData(true)['public_key']);
    }

    /** @return iterable<string, array{string}> */
    public static function whitespaceThatIsNotBlank(): iterable
    {
        yield 'non-breaking space' => ["\u{00A0}"];
        yield 'form feed' => ["\f"];
        yield 'NUL' => ["\0"];
        yield 'vertical tab' => ["\v"];
    }

    #[Test]
    #[DataProvider('whitespaceThatIsNotBlank')]
    public function set_key_with_whitespace_other_than_space_tab_and_line_breaks_is_bad_request(string $value): void
    {
        // Only space, tab, carriage return and line feed make a blank key. PHP's own trim also strips NUL
        // and vertical tab, which the other two implementations treat as a malformed key.
        $this->store->finds = [$this->relationship('employee', keyed: true)];

        $response = $this->controller()->setKey($this->request(['sub' => 'alice'], ['public_key' => " {$value} "]), 'employee');

        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function set_key_with_no_public_key_field_is_bad_request_not_a_silent_clear(): void
    {
        // An absent public_key comes back null from the lenient reader, indistinguishable from a wrong type:
        // a malformed request, answered 400, never a clear. Only an explicit empty string clears.
        $response = $this->controller()->setKey($this->request(['sub' => 'alice'], ['something_else' => 'x']), 'employee');

        self::assertSame(400, $response->getStatusCode());
    }

    // -- DELETE .../key ------------------------------------------------------------------------------

    #[Test]
    public function delete_without_a_session_is_unauthorized(): void
    {
        self::assertSame(401, $this->controller()->delete($this->request(null), 'employee')->getStatusCode());
    }

    #[Test]
    public function deleting_a_held_relationship_is_no_content(): void
    {
        $this->store->finds = [$this->relationship('employee')];
        $this->store->deleteCount = 1;

        self::assertSame(204, $this->controller()->delete($this->request(['sub' => 'alice']), 'employee')->getStatusCode());
    }

    #[Test]
    public function deleting_a_relationship_not_held_is_not_found(): void
    {
        $this->store->finds = [null];

        self::assertSame(404, $this->controller()->delete($this->request(['sub' => 'alice']), 'employee')->getStatusCode());
    }

    #[Test]
    public function deleting_a_relationship_that_vanished_between_lookup_and_delete_is_not_found(): void
    {
        // The lookup found it and the delete touched nothing: not found, and no audit entry claiming a
        // removal that did not happen (RelationshipServiceTest pins the entry).
        $this->store->finds = [$this->relationship('employee')];
        $this->store->deleteCount = 0;

        self::assertSame(404, $this->controller()->delete($this->request(['sub' => 'alice']), 'employee')->getStatusCode());
    }

    #[Test]
    public function a_relationship_type_differing_from_the_rows_only_in_case_is_not_found(): void
    {
        // The engine's collation may match EMPLOYEE to an employee row. The path has to equal the row's
        // type exactly, on every engine, so the answer is the same 404 a type nobody granted gets.
        $this->store->finds = [$this->relationship('employee')];
        $this->store->deleteCount = 1;

        self::assertSame(404, $this->controller()->setKey($this->request(['sub' => 'alice'], ['public_key' => base64_encode(self::rawKey())]), 'EMPLOYEE')->getStatusCode());
        self::assertSame(404, $this->controller()->delete($this->request(['sub' => 'alice']), 'EMPLOYEE')->getStatusCode());
        self::assertSame(404, $this->controller()->selfRevoke($this->request(['sub' => 'alice']), 'EMPLOYEE')->getStatusCode());
    }

    #[Test]
    public function a_relationship_type_outside_the_vocabulary_is_not_found(): void
    {
        $this->store->finds = [$this->relationship('astronaut')];
        $this->store->deleteCount = 1;

        self::assertSame(404, $this->controller()->setKey($this->request(['sub' => 'alice'], ['public_key' => base64_encode(self::rawKey())]), 'astronaut')->getStatusCode());
        self::assertSame(404, $this->controller()->delete($this->request(['sub' => 'alice']), 'astronaut')->getStatusCode());
        self::assertSame(404, $this->controller()->selfRevoke($this->request(['sub' => 'alice']), 'astronaut')->getStatusCode());
    }

    // -- POST .../revoke -----------------------------------------------------------------------------

    #[Test]
    public function self_revoke_without_a_session_is_unauthorized(): void
    {
        self::assertSame(401, $this->controller()->selfRevoke($this->request(null), 'employee')->getStatusCode());
    }

    #[Test]
    public function self_revoking_a_held_relationship_is_no_content(): void
    {
        $this->store->finds = [$this->relationship('employee')];

        self::assertSame(204, $this->controller()->selfRevoke($this->request(['sub' => 'alice']), 'employee')->getStatusCode());
    }

    #[Test]
    public function self_revoking_a_relationship_not_held_is_not_found(): void
    {
        $this->store->finds = [null];

        self::assertSame(404, $this->controller()->selfRevoke($this->request(['sub' => 'alice']), 'employee')->getStatusCode());
    }

    // -- POST /attest --------------------------------------------------------------------------------

    #[Test]
    public function attest_with_a_malformed_key_is_ok_and_invalid(): void
    {
        $response = $this->controller()->attest($this->request(null, ['public_key' => 'not-a-key']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['valid' => false], $response->getData(true));
    }

    #[Test]
    public function attest_with_a_valid_key_not_on_record_is_ok_and_invalid(): void
    {
        $this->store->byPublicKey = null;

        $response = $this->controller()->attest($this->request(null, ['public_key' => base64_encode(self::rawKey())]));

        self::assertSame(['valid' => false], $response->getData(true));
    }

    #[Test]
    public function attest_with_a_key_on_record_returns_its_standing(): void
    {
        $this->store->byPublicKey = $this->relationship('employee', keyed: true);

        $body = $this->controller()->attest($this->request(null, ['public_key' => base64_encode(self::rawKey())]))->getData(true);

        self::assertTrue($body['valid']);
        self::assertSame('employee', $body['relationship_type']);
        self::assertSame('active', $body['status']);
        // A null subtype is omitted entirely rather than serialised as null.
        self::assertArrayNotHasKey('relationship_subtype', $body);
    }

    #[Test]
    public function attest_with_a_key_on_record_includes_a_present_subtype(): void
    {
        $this->store->byPublicKey = $this->relationship('employee', subtype: 'Contractor', keyed: true);

        $body = $this->controller()->attest($this->request(null, ['public_key' => base64_encode(self::rawKey())]))->getData(true);

        self::assertSame('Contractor', $body['relationship_subtype']);
    }

    #[Test]
    public function attest_with_a_public_key_that_is_an_array_is_ok_and_invalid(): void
    {
        // A shape each framework binds differently by default: an array (or object) public_key is read
        // as no key, a flat invalid rather than a 400, so it cannot be told apart from an unknown key.
        $response = $this->controller()->attest($this->request(null, ['public_key' => ['a', 'b']]));

        self::assertSame(['valid' => false], $response->getData(true));
    }

    #[Test]
    public function attest_with_a_body_that_is_not_json_is_ok_and_invalid(): void
    {
        self::assertSame(['valid' => false], $this->controller()->attest($this->requestWithRawBody('this is not json'))->getData(true));
    }

    #[Test]
    public function attest_with_an_empty_body_is_ok_and_invalid(): void
    {
        self::assertSame(['valid' => false], $this->controller()->attest($this->requestWithRawBody(''))->getData(true));
    }
}

/** Runs the operation inline -- the transaction boundary is not what these tests are about. */
final class ImmediateRunner implements TransactionRunner
{
    public function run(Closure $operation): mixed
    {
        return $operation();
    }
}

/** The controller never inspects the audit log; a silent sink keeps the service happy. */
final class SilentAuditLog implements AuditLog
{
    public function keyRegistered(string $iamSubjectId, string $relationshipType): void {}

    public function keyCleared(string $iamSubjectId, string $relationshipType): void {}

    public function keyRemoved(string $iamSubjectId, string $relationshipType): void {}
}

/**
 * A store whose every answer the test sets directly, so the controller's mapping is exercised without a
 * database. find() consumes $finds in order -- the service reads once, the controller's re-read a second
 * time -- and repeats the last entry once the list is down to one.
 */
final class ProgrammableStore implements RelationshipStore
{
    /** @var list<MemberRelationship|null> */
    public array $finds = [null];

    public bool $keyHeldElsewhere = false;

    public int $deleteCount = 0;

    /** @var list<MemberRelationship> */
    public array $all = [];

    public ?MemberRelationship $byPublicKey = null;

    public function findAllHeldBy(string $iamSubjectId): Collection
    {
        return new Collection($this->all);
    }

    public function find(string $iamSubjectId, string $relationshipType): ?MemberRelationship
    {
        return count($this->finds) > 1 ? array_shift($this->finds) : ($this->finds[0] ?? null);
    }

    public function findByPublicKey(string $rawPublicKey): ?MemberRelationship
    {
        return $this->byPublicKey;
    }

    public function isKeyHeldElsewhere(string $rawPublicKey, int $exceptId): bool
    {
        return $this->keyHeldElsewhere;
    }

    public function updateKey(int $id, string $rawPublicKey): int
    {
        return 1;
    }

    public function clearKey(int $id): int
    {
        return 1;
    }

    public function deleteOwn(string $iamSubjectId, string $relationshipType): int
    {
        return $this->deleteCount;
    }

    public function invokeSelfRevoke(string $iamSubjectId, string $relationshipType): void {}
}
