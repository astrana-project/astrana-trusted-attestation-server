<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\MemberIdentityResolver;
use App\Services\Saml\SamlClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\LogoutRequest;
use OneLogin\Saml2\LogoutResponse;

/**
 * SAML 2.0 login, for orgs whose IAM speaks SAML rather than OIDC.
 *
 * The two protocols meet here and nowhere else: both put the same small array of claims in the session
 * (subject, display name and locale), and everything downstream reads them by configured name without
 * knowing which protocol produced them.
 */
final class SamlController extends Controller
{
    /** Where a failed login lands: the page, with enough for it to say so. */
    private const LOGIN_FAILED = '/me?error=login';

    /**
     * The AuthnRequest id is correlated through its own cookie rather than the session, because the
     * assertion comes back over the HTTP-POST binding as a cross-site POST from the IdP, and the session
     * cookie (SameSite=Lax, as the contract requires) is not sent on that request -- so the session, and
     * with it the stored request id, would be absent at the consumer and every real login rejected. This
     * cookie is SameSite=None so it survives the cross-site POST; the session cookie is left untouched.
     * Laravel encrypts it, so its value is integrity-protected. The .NET implementation (Sustainsys) keeps
     * its correlation state in its own SameSite=None cookie for the same reason.
     */
    private const REQUEST_ID_COOKIE = 'ata_saml_authn';

    private const REQUEST_ID_COOKIE_MINUTES = 5;

    public const NAME_ID = 'trusted_attestation.saml.name_id';

    private const SESSION_INDEX = 'trusted_attestation.saml.session_index';

    public const LOGOUT_REQUEST_ID = 'trusted_attestation.saml.logout_request_id';

    public function __construct(private readonly SamlClient $saml) {}

    /**
     * Starts the flow by sending the member to the IdP with an AuthnRequest, signed when this service provider
     * holds a key. When the IdP wants signed requests and there is no key, SamlClient::signInAuth() refuses and
     * the member gets HTTP 500 - Internal Server Error, as on the other two implementations.
     */
    public function login(Request $request): RedirectResponse
    {
        $auth = $this->saml->signInAuth();

        $returnTo = url(self::safeLocalPath($request->query('next')));

        // The last argument keeps the URL rather than redirecting and exiting, so this stays an ordinary
        // Laravel response instead of the library writing headers itself.
        $url = $auth->login($returnTo, [], false, false, true);

        // Remembered so the assertion can be tied back to the request this browser actually made.
        // Without it an assertion captured elsewhere could be replayed into this session. Held in a
        // SameSite=None cookie (see REQUEST_ID_COOKIE) rather than the session, so it survives the IdP's
        // cross-site POST back to the consumer.
        $cookie = cookie(
            self::REQUEST_ID_COOKIE, (string) $auth->getLastRequestID(), self::REQUEST_ID_COOKIE_MINUTES,
            '/', null, true, true, false, 'none',
        );

        return redirect()->away($url)->withCookie($cookie);
    }

    /**
     * The assertion consumer service: where the IdP posts the signed assertion.
     *
     * Nothing reaches the session until the library has checked the signature, the conditions, the
     * audience, the timestamps, and that the assertion answers the request this browser made.
     */
    public function acs(Request $request): RedirectResponse
    {
        $auth = $this->saml->auth();
        $requestId = $request->cookie(self::REQUEST_ID_COOKIE);
        // One-shot: clear it whichever way this request ends, so an id cannot be reused.
        Cookie::queue(Cookie::forget(self::REQUEST_ID_COOKIE, '/'));

        // An assertion nobody asked for is not a login. A response that answers no authentication request
        // this browser started -- no stored request id -- is either IdP-initiated SSO (which this service
        // does not do; login always begins at /auth/saml/login) or a forged/replayed one aimed straight
        // at the consumer. OneLogin's rejectUnsolicitedResponsesWithInResponseTo does not cover it: that
        // flag only fires when the response carries an InResponseTo, so a response with none slips past it
        // with the request id passed as null and the correlation check skipped. Reject it here so this
        // implementation refuses an unsolicited assertion exactly as the .NET one does.
        if (! is_string($requestId) || $requestId === '') {
            Log::warning('A SAML assertion arrived that answers no authentication request this service made.');

            return redirect(self::LOGIN_FAILED);
        }

        try {
            $auth->processResponse($requestId);
        } catch (\Throwable $exception) {
            Log::warning('A SAML assertion could not be processed.', ['reason' => $exception->getMessage()]);

            return redirect(self::LOGIN_FAILED);
        }

        $errors = $auth->getErrors();
        if ($errors !== [] || ! $auth->isAuthenticated()) {
            Log::warning('A SAML assertion failed validation.', [
                'errors' => $errors,
                'reason' => $auth->getLastErrorReason(),
            ]);

            return redirect(self::LOGIN_FAILED);
        }

        // The signature verified, but a signature made with a broken algorithm is no assurance at all.
        // Refuse anything signed or digested with less than SHA-256, so this implementation rejects the same
        // assertion the .NET one does (Sustainsys refuses a sub-SHA-256 signature by default) rather than
        // silently trusting it.
        $weakAlgorithms = $this->saml->weakSignatureAlgorithms($auth->getLastResponseXML());
        if ($weakAlgorithms !== []) {
            Log::warning('A SAML assertion was signed with an algorithm weaker than SHA-256.', [
                'algorithms' => $weakAlgorithms,
            ]);

            return redirect(self::LOGIN_FAILED);
        }

        $claims = self::sessionClaims($auth->getAttributes(), (string) $auth->getNameId());

        // A valid assertion that names no usable subject (no attribute of the configured name and an
        // empty NameID) is not a sign-in. Refused before anything reaches the session, as the OIDC
        // callback refuses a token with none, so no session is ever signed in as nobody.
        if (MemberIdentityResolver::subjectOf($claims) === null) {
            Log::warning('A SAML assertion carried no usable subject.', [
                'claim' => config('trusted_attestation.iam.subject_claim'),
            ]);

            return redirect(self::LOGIN_FAILED);
        }

        // A new session id at the moment privileges change, so a session fixed before login cannot be
        // used after it.
        $request->session()->regenerate();

        $request->session()->put(MemberIdentityResolver::SESSION_KEY, $claims);

        // Kept only so logout can tell the IdP which session is ending. Nothing else reads them.
        $request->session()->put(self::NAME_ID, $auth->getNameId());
        $request->session()->put(self::SESSION_INDEX, $auth->getSessionIndex());

        // Only back to this exact origin. The trailing slash is what makes the check exact: without it,
        // the origin prefix "https://host" also matches "https://host.evil.example/", so a forged
        // assertion carrying that RelayState would redirect off-site after login.
        $relayState = $request->input('RelayState');
        $origin = rtrim(url('/'), '/').'/';
        if (is_string($relayState) && str_starts_with($relayState, $origin)) {
            return redirect($relayState);
        }

        return redirect('/me');
    }

    /**
     * What the session keeps from a validated assertion, in the same shape the OIDC path produces: the
     * subject, the display name and the locale, keyed by their configured names, and no other attribute.
     *
     * The assertion is first flattened into single-valued claims. The subject falls back to the NameID
     * when no attribute of the configured name is present, which is the usual case -- NameID is SAML's
     * equivalent of "sub". The fallback is confined to the subject: applying it to claims generally would
     * mean a member with no display name attribute silently acquiring their NameID as one.
     *
     * Public and static so the rule can be pinned without a signed assertion.
     *
     * @param  array<mixed>  $attributes  the assertion's attributes, as OneLogin returns them
     * @return array<string, mixed>
     */
    public static function sessionClaims(array $attributes, string $nameId): array
    {
        $claims = [];

        foreach ($attributes as $name => $values) {
            // Only a single-valued attribute becomes a claim, with one exception below. A subject or a
            // display name the identity provider sent with more than one value is ambiguous, with no basis
            // for picking one, so it is treated as absent rather than silently taking the first, which for
            // the subject means falling through to the NameID below. The other two implementations do the
            // same, so the same assertion keys the member by the same identity everywhere.
            //
            // The exception is the locale. A multivalued locale attribute is read as its first value in all
            // three implementations, because it only chooses a language, and the first value is the one the
            // identity provider lists first.
            if (! is_array($values) || $values === []) {
                continue;
            }

            $first = $values[0] ?? null;
            $usable = count($values) === 1 || (string) $name === MemberIdentityResolver::LOCALE_CLAIM;
            if ($usable && is_scalar($first)) {
                $claims[(string) $name] = (string) $first;
            }
        }

        $subjectClaim = (string) config('trusted_attestation.iam.subject_claim');
        if (! isset($claims[$subjectClaim]) || trim($claims[$subjectClaim]) === '') {
            $claims[$subjectClaim] = $nameId;
        }

        return MemberIdentityResolver::sessionClaims($claims);
    }

    /**
     * This SP's own metadata. Public: an IdP administrator has to be able to read it in order to register
     * this service provider at all, which by definition happens before anyone can log in.
     */
    public function metadata(): Response
    {
        return response($this->saml->metadata(), 200, ['Content-Type' => 'text/xml']);
    }

    /**
     * The member's own sign-out: ends the session here, then sends a signed LogoutRequest to the IdP.
     *
     * Reached only from the CSRF-protected POST /signout (AuthController::signout), never from a GET, so a
     * cross-site page cannot sign a member out. The local session is ended first and unconditionally: an
     * IdP that publishes no single logout service, or whose metadata cannot be read just now, leaves the
     * local session ended and nothing more, which is all this service can honestly do about it. So does a
     * deployment with no signing key, because single logout needs a signed LogoutRequest, and the other two
     * implementations send none without one. Either way the member lands on /signed-out, the one address
     * sign-out ends at in all three implementations.
     */
    public function signOut(Request $request): RedirectResponse
    {
        $nameId = $request->session()->get(self::NAME_ID);
        $sessionIndex = $request->session()->get(self::SESSION_INDEX);

        self::endSession($request);

        if (! $this->saml->hasSigningKey()) {
            Log::info('No signing key is configured, so single logout is not offered and only the local session was ended.');

            return redirect(self::SIGNED_OUT);
        }

        try {
            $auth = $this->saml->auth();
            $url = $auth->logout(
                url(self::SIGNED_OUT),
                [],
                is_string($nameId) ? $nameId : null,
                is_string($sessionIndex) ? $sessionIndex : null,
                true
            );

            // Remembered in the fresh session so the IdP's LogoutResponse can be tied back to this request.
            $request->session()->put(self::LOGOUT_REQUEST_ID, $auth->getLastRequestID());

            return $url !== null ? redirect()->away($url) : redirect(self::SIGNED_OUT);
        } catch (\Throwable $exception) {
            Log::info('The IdP offers no single logout endpoint; ended the local session only.', [
                'reason' => $exception->getMessage(),
            ]);

            return redirect(self::SIGNED_OUT);
        }
    }

    /** Ends the member's session here, for the member's own sign-out and for a verified LogoutRequest. */
    public static function endSession(Request $request): void
    {
        $request->session()->flush();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
