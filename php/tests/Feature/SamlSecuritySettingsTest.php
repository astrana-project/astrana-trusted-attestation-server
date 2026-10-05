<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Saml\SamlClient;
use OneLogin\Saml2\Constants;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The SP's security posture -- the settings that decide whether a SAML assertion is trusted.
 *
 * The cryptographic validation itself is the SAML library's, exercised exhaustively by its own
 * maintainers, and the conformance suite drives the assertion consumer with malformed input from
 * outside. What belongs to this implementation is the configuration that tells the library to require a signed assertion in the
 * first place, and to refuse a response it never asked for. A regression there would weaken every SAML
 * login while breaking none of them, so it is pinned here.
 */
final class SamlSecuritySettingsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function security(bool $hasSigningKey = true): array
    {
        return (new SamlClient)->securitySettings($hasSigningKey);
    }

    #[Test]
    public function it_requires_the_idp_to_sign_its_assertions(): void
    {
        // The core of it: an unsigned assertion is just a POST body anyone could have written, and
        // refusing it is the whole job of a service provider.
        self::assertTrue($this->security()['wantAssertionsSigned']);
    }

    #[Test]
    public function it_rejects_a_response_carrying_an_in_response_to_it_did_not_request(): void
    {
        // What this flag actually does: reject a response that carries an InResponseTo which matches no
        // request this SP made. It does NOT cover a response with no InResponseTo at all -- OneLogin only
        // consults the flag when an InResponseTo is present -- so a truly unsolicited (IdP-initiated)
        // assertion is not refused by this setting. That case is closed in SamlController::acs, which
        // rejects any response arriving with no stored request id (see SamlControllerTest); this asserts
        // only the narrower guard the library gives us.
        self::assertTrue($this->security()['rejectUnsolicitedResponsesWithInResponseTo']);
    }

    #[Test]
    public function it_requires_a_name_id(): void
    {
        self::assertTrue($this->security()['wantNameId']);
    }

    #[Test]
    public function it_signs_its_own_requests_when_it_holds_a_key(): void
    {
        self::assertTrue($this->security(true)['authnRequestsSigned']);
        self::assertFalse($this->security(false)['authnRequestsSigned']);
    }

    #[Test]
    public function the_assertion_clock_skew_tolerance_is_180_seconds(): void
    {
        // Not one of this SP's settings but part of its posture, and shared with the other two
        // implementations (which are explicitly set to 180s): how far an assertion's timestamps may be out
        // before it is refused. OneLogin fixes it at 180s via a library constant, so this pins that value
        // -- a dependency update that moved it would change the tolerance silently and diverge from the
        // .NET and Java stacks, and this catches it.
        self::assertSame(180, Constants::ALLOWED_CLOCK_DRIFT);
    }
}
