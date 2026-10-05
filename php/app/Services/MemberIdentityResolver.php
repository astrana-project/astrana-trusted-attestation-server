<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\MemberIdentity;
use Illuminate\Http\Request;

/**
 * Resolves the authenticated session into a member, and nothing more than that.
 *
 * It deliberately does not resolve a relationship type from a claim. A relationship exists only because
 * the organisation created it with grant_member_relationship, and the database is the only place that
 * records one. Reading a relationship from a token would let whoever controls the identity system's claim
 * mapping hand a member a relationship the organisation never granted. That is the hole that keeping
 * grant out of the API closes, reopened one layer down, where it would be much harder to notice.
 *
 * So the session answers exactly one question here: who is this? What they hold is a database lookup.
 *
 * The claims were put in the session by the OIDC callback, after the ID token's signature, issuer,
 * audience, expiry and nonce were all verified, or by the SAML assertion consumer after the assertion was
 * validated. Both store only what sessionClaims() keeps: the subject, the display name and the locale.
 */
final class MemberIdentityResolver
{
    public const SESSION_KEY = 'trusted_attestation.claims';

    /** The claim the page's language resolution reads, after the switcher's own choice. */
    public const LOCALE_CLAIM = 'locale';

    /**
     * The part of a verified claim set the session keeps, under the configured claim names.
     *
     * Only the subject, the display name and the locale are kept, so no email address or other directory
     * attribute the identity provider sends outlives the request that carried it, unless it is the
     * configured subject or name. Non-scalar values are dropped, since nothing reads them.
     *
     * @param  array<string, mixed>  $claims
     * @return array<string, mixed>
     */
    public static function sessionClaims(array $claims): array
    {
        return self::retain(
            $claims,
            (string) config('trusted_attestation.iam.subject_claim'),
            (string) config('trusted_attestation.iam.name_claim'),
        );
    }

    /**
     * The framework-free rule behind sessionClaims(), so it can be pinned without booting Laravel.
     *
     * @param  array<string, mixed>  $claims
     * @return array<string, mixed>
     */
    public static function retain(array $claims, string $subjectClaim, string $nameClaim): array
    {
        $kept = [];

        foreach ([$subjectClaim, $nameClaim, self::LOCALE_CLAIM] as $name) {
            if (array_key_exists($name, $claims) && is_scalar($claims[$name])) {
                $kept[$name] = $claims[$name];
            }
        }

        return $kept;
    }

    /**
     * The authenticated member, or null when the session carries no usable subject.
     *
     * There is no second failure mode. "Authenticated but holding nothing" is not a failure at
     * all: it is an ordinary member before their first grant, and they get a 200 with an empty list.
     */
    public function resolve(Request $request): ?MemberIdentity
    {
        $subject = $this->subject($request);
        if ($subject === null) {
            return null;
        }

        $claims = $request->session()->get(self::SESSION_KEY);
        $name = is_array($claims) ? self::claim($claims, (string) config('trusted_attestation.iam.name_claim')) : null;

        return new MemberIdentity($subject, $name !== null && trim($name) !== '' ? $name : $subject);
    }

    /**
     * The member's subject identifier.
     *
     * This is the value the organisation granted against, so getting it wrong does not produce an error
     * -- it produces a member who signs in successfully and appears to hold nothing at all.
     */
    public function subject(Request $request): ?string
    {
        $claims = $request->session()->get(self::SESSION_KEY);

        return is_array($claims) ? self::subjectOf($claims) : null;
    }

    /**
     * The subject a claim set names under the configured claim, or null when it names none a session
     * could be keyed by: the claim is missing, empty, whitespace or not a scalar. The sign-in callbacks
     * ask this before a session is established, so a token or assertion with no usable subject is refused
     * there rather than producing a session that is signed in as nobody.
     *
     * @param  array<string, mixed>  $claims
     */
    public static function subjectOf(array $claims): ?string
    {
        $subject = self::claim($claims, (string) config('trusted_attestation.iam.subject_claim'));

        return $subject !== null && trim($subject) !== '' ? $subject : null;
    }

    private static function claim(array $claims, string $name): ?string
    {
        $value = $claims[$name] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }
}
