<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\MemberIdentityResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the session keeps from a verified claim set, whichever protocol produced it.
 *
 * Only the subject, the display name and the locale are kept, under their configured names. Everything else
 * an identity provider sends, an email address that is not the subject, group memberships, directory
 * attributes and the token's own housekeeping claims, is dropped before the session is written. These tests
 * fail if the whole claim set is stored again.
 */
final class SessionClaimsTest extends TestCase
{
    #[Test]
    public function only_the_subject_the_name_and_the_locale_are_kept(): void
    {
        $kept = MemberIdentityResolver::retain([
            'iss' => 'https://idp.example',
            'aud' => 'ata',
            'sub' => 'member-subject',
            'name' => 'Alice Anderson',
            'locale' => 'fr-CA',
            'email' => 'alice@example.org',
            'groups' => ['staff'],
            'nonce' => 'n',
        ], 'sub', 'name');

        self::assertSame(['sub' => 'member-subject', 'name' => 'Alice Anderson', 'locale' => 'fr-CA'], $kept);
    }

    #[Test]
    public function the_configured_claim_names_decide_what_counts_as_subject_and_name(): void
    {
        // An organisation that grants to email addresses keeps the email as the subject, and the opaque
        // sub is then one more claim it does not need.
        $kept = MemberIdentityResolver::retain([
            'sub' => 'opaque',
            'email' => 'alice@example.org',
            'given_name' => 'Alice',
        ], 'email', 'given_name');

        self::assertSame(['email' => 'alice@example.org', 'given_name' => 'Alice'], $kept);
    }

    #[Test]
    public function a_missing_or_non_scalar_claim_is_left_out(): void
    {
        $kept = MemberIdentityResolver::retain([
            'sub' => 'member-subject',
            'name' => ['Alice', 'Anderson'],
        ], 'sub', 'name');

        self::assertSame(['sub' => 'member-subject'], $kept);
    }
}
