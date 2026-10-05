<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\MemberIdentityResolver;
use App\Support\MemberIdentity;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the session is allowed to decide.
 *
 * One question only: who is this? A relationship is deliberately never read from a claim. It exists only
 * because the organisation created it with grant_member_relationship, and anything read from a token
 * could be handed to a member by whoever controls the identity provider's claim mapping. Keeping grant out
 * of the API would count for little if a claim could still conjure one.
 *
 * A Feature test rather than a Unit one because the resolver reads configuration, so it needs the
 * application container -- but it touches no database and no HTTP.
 */
final class MemberIdentityResolverTest extends TestCase
{
    /** @param array<string, mixed>|null $claims */
    private function requestWithClaims(?array $claims): Request
    {
        $session = new Store('ata_session', new ArraySessionHandler(60));

        if ($claims !== null) {
            $session->put(MemberIdentityResolver::SESSION_KEY, $claims);
        }

        $request = Request::create('/api/v1/me');
        $request->setLaravelSession($session);

        return $request;
    }

    private function resolver(): MemberIdentityResolver
    {
        return $this->app->make(MemberIdentityResolver::class);
    }

    #[Test]
    public function it_reads_the_subject_and_name_from_the_session(): void
    {
        $member = $this->resolver()->resolve($this->requestWithClaims([
            'sub' => 'abc-123',
            'name' => 'Alice Anderson',
        ]));

        self::assertInstanceOf(MemberIdentity::class, $member);
        self::assertSame('abc-123', $member->iamSubjectId);
        self::assertSame('Alice Anderson', $member->name);
    }

    #[Test]
    public function no_subject_is_treated_as_unauthenticated(): void
    {
        self::assertNull($this->resolver()->resolve($this->requestWithClaims(['name' => 'Nobody'])));
    }

    #[Test]
    public function no_session_at_all_is_treated_as_unauthenticated(): void
    {
        self::assertNull($this->resolver()->resolve($this->requestWithClaims(null)));
    }

    #[Test]
    public function a_blank_subject_does_not_count_as_one(): void
    {
        self::assertNull($this->resolver()->resolve($this->requestWithClaims(['sub' => '   '])));
    }

    #[Test]
    public function a_member_holding_nothing_still_resolves(): void
    {
        // Authenticated but granted nothing is an ordinary member with an empty list, not a 403, because
        // that is what everyone looks like before their first grant. Refusing them would mean a member
        // could not even see the page that tells them there is nothing yet.
        $member = $this->resolver()->resolve($this->requestWithClaims(['sub' => 'dave']));

        self::assertInstanceOf(MemberIdentity::class, $member);
        self::assertSame('dave', $member->iamSubjectId);
    }

    #[Test]
    public function a_relationship_claim_on_the_session_is_ignored_entirely(): void
    {
        // Whoever controls the identity provider's claim mapping must not be able to hand a member a
        // relationship the organisation never granted, and the only way to be sure of that is for the
        // resolver to have nowhere to put one.
        $member = $this->resolver()->resolve($this->requestWithClaims([
            'sub' => 'abc',
            'relationship_type' => 'director',
            'relationship_subtype' => 'Fellow',
        ]));

        self::assertInstanceOf(MemberIdentity::class, $member);
        self::assertSame('abc', $member->iamSubjectId);
        self::assertSame(['iamSubjectId', 'name'], array_keys(get_object_vars($member)));
    }

    #[Test]
    public function it_falls_back_to_the_subject_when_the_idp_sends_no_name(): void
    {
        $member = $this->resolver()->resolve($this->requestWithClaims(['sub' => 'abc-123']));

        self::assertInstanceOf(MemberIdentity::class, $member);
        self::assertSame('abc-123', $member->name);
    }

    #[Test]
    public function the_subject_is_readable_on_its_own(): void
    {
        // What DELETE and self-revoke rely on: neither needs to show the member anything, so neither
        // should fail because the IdP sent no name.
        self::assertSame('abc-123', $this->resolver()->subject($this->requestWithClaims(['sub' => 'abc-123'])));
    }

    #[Test]
    public function no_subject_means_no_subject_for_the_lighter_path_too(): void
    {
        self::assertNull($this->resolver()->subject($this->requestWithClaims(['name' => 'Nobody'])));
        self::assertNull($this->resolver()->subject($this->requestWithClaims(null)));
    }

    #[Test]
    public function a_non_scalar_claim_is_not_coerced_into_a_subject(): void
    {
        // A provider that sends sub as an array would otherwise stringify to something meaningless, and
        // the member would be keyed by it. Absent is the right reading.
        self::assertNull($this->resolver()->subject($this->requestWithClaims(['sub' => ['a', 'b']])));
    }

    #[Test]
    public function the_subject_of_a_claim_set_is_readable_before_a_session_exists(): void
    {
        // What the sign-in callbacks ask before they establish a session: the same rule the request-time
        // reading applies, so a claim set that would resolve to nobody is refused at the door.
        self::assertSame('abc-123', MemberIdentityResolver::subjectOf(['sub' => 'abc-123']));
        self::assertNull(MemberIdentityResolver::subjectOf([]));
        self::assertNull(MemberIdentityResolver::subjectOf(['sub' => '']));
        self::assertNull(MemberIdentityResolver::subjectOf(['sub' => " \t"]));
        self::assertNull(MemberIdentityResolver::subjectOf(['sub' => ['a']]));

        config(['trusted_attestation.iam.subject_claim' => 'email']);
        self::assertNull(MemberIdentityResolver::subjectOf(['sub' => 'opaque']));
        self::assertSame('alice@example.org', MemberIdentityResolver::subjectOf(['email' => 'alice@example.org']));
    }

    #[Test]
    public function a_non_scalar_name_falls_back_to_the_subject(): void
    {
        // Same reasoning as the subject, one field over: an array name has no meaningful string form, so
        // the page shows the subject rather than a stringified list.
        $member = $this->resolver()->resolve($this->requestWithClaims(['sub' => 'abc', 'name' => ['Ann', 'Bob']]));

        self::assertInstanceOf(MemberIdentity::class, $member);
        self::assertSame('abc', $member->name);
    }

    #[Test]
    public function a_blank_name_falls_back_to_the_subject(): void
    {
        // A present-but-empty name claim is not a name.
        $member = $this->resolver()->resolve($this->requestWithClaims(['sub' => 'abc', 'name' => '   ']));

        self::assertInstanceOf(MemberIdentity::class, $member);
        self::assertSame('abc', $member->name);
    }
}
