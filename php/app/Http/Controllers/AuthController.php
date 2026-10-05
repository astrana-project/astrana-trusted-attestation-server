<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\MemberIdentityResolver;
use App\Services\Oidc\OidcClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Sign-in and sign-out against the organisation's own identity provider.
 *
 * Astrana Trusted Attestation keeps no user list of its own and issues no credentials. What lands in the
 * session is the member's subject, display name and locale from a verified ID token (see
 * MemberIdentityResolver::sessionClaims), plus the ID token sign-out needs, and nothing else. No
 * password, no local account, no other claims.
 */
final class AuthController extends Controller
{
    /** Where a failed login lands: the page, with enough for it to say so. */
    private const LOGIN_FAILED = '/me?error=login';

    private const STATE = 'trusted_attestation.oidc.state';

    private const NONCE = 'trusted_attestation.oidc.nonce';

    private const VERIFIER = 'trusted_attestation.oidc.code_verifier';

    private const INTENDED = 'trusted_attestation.oidc.intended';

    private const ID_TOKEN = 'trusted_attestation.oidc.id_token';

    // OidcClient is resolved lazily, per request (see oidc()), rather than constructor-injected. A SAML
    // deployment never speaks OIDC, its OIDC issuer is documented as unused and may be unset, and OidcClient
    // throws without one. Constructor injection would resolve it for every request to this controller --
    // including /auth/login and /signout, whose SAML branch never touches it -- so a valid SAML config would
    // 500 the entire login/logout surface before it could redirect to the SAML flow.

    /** Starts the login flow for whichever protocol the org's IAM speaks. */
    public function login(Request $request): RedirectResponse
    {
        if (self::usesSaml()) {
            return redirect('/auth/saml/login?next='.urlencode(self::safeLocalPath($request->query('next'))));
        }

        $state = OidcClient::randomValue();
        $nonce = OidcClient::randomValue();
        $verifier = OidcClient::randomValue();

        $request->session()->put(self::STATE, $state);
        $request->session()->put(self::NONCE, $nonce);
        $request->session()->put(self::VERIFIER, $verifier);
        $request->session()->put(self::INTENDED, self::safeLocalPath($request->query('next')));

        return redirect()->away(
            $this->oidc()->authorizationUrl(self::redirectUri(), $state, $nonce, $verifier)
        );
    }

    /** Completes the flow. Nothing reaches the session until every check has passed. */
    public function callback(Request $request): RedirectResponse
    {
        if (self::usesSaml()) {
            // A SAML deployment has no OIDC callback -- its assertion arrives at /auth/saml/acs -- so this
            // route is not part of its login flow. Refuse rather than resolve an OIDC client it never
            // configured.
            return redirect(self::LOGIN_FAILED);
        }

        $session = $request->session();

        $state = $session->pull(self::STATE);
        $nonce = $session->pull(self::NONCE);
        $verifier = $session->pull(self::VERIFIER);
        $intended = $session->pull(self::INTENDED, '/me');

        if ($request->has('error')) {
            Log::warning('The IdP refused a login.', ['error' => $request->query('error')]);

            return redirect(self::LOGIN_FAILED);
        }

        // Ties this callback to the browser that started the flow. A callback arriving without matching
        // state is not this member's login, whatever it carries.
        if (! is_string($state) || $state === '' || ! hash_equals($state, (string) $request->query('state'))) {
            Log::warning('Rejected an OIDC callback whose state did not match the session.');

            return redirect(self::LOGIN_FAILED);
        }

        $code = (string) $request->query('code');
        if ($code === '' || ! is_string($verifier) || ! is_string($nonce)) {
            return redirect(self::LOGIN_FAILED);
        }

        try {
            $tokens = $this->oidc()->exchangeCode($code, self::redirectUri(), $verifier);
            $claims = $this->oidc()->verifyIdToken($tokens['id_token'], $nonce);

            // Some providers put the email address, the display name or the locale in UserInfo rather than
            // in the ID token. Verified ID token claims win over anything UserInfo says.
            if (isset($tokens['access_token']) && is_string($tokens['access_token'])) {
                $claims = array_merge($this->oidc()->userInfo($tokens['access_token']), $claims);
            }
        } catch (\Throwable $exception) {
            Log::warning('An OIDC login failed verification.', ['reason' => $exception->getMessage()]);

            return redirect(self::LOGIN_FAILED);
        }

        // Only the subject, the display name and the locale, never the whole claim set.
        $kept = MemberIdentityResolver::sessionClaims($claims);

        // A verified token that names no usable subject (the configured claim missing, empty, whitespace
        // or not a scalar) is not a sign-in. Refused here, before anything reaches the session, as the
        // other two implementations refuse it, rather than establishing a session that is signed in as
        // nobody and sends /me back to sign in without end.
        if (MemberIdentityResolver::subjectOf($kept) === null) {
            Log::warning('An OIDC login carried no usable subject claim.', [
                'claim' => config('trusted_attestation.iam.subject_claim'),
            ]);

            return redirect(self::LOGIN_FAILED);
        }

        // A new session id at the moment privileges change, so a session fixed before login cannot be
        // used after it.
        $session->regenerate();

        $session->put(MemberIdentityResolver::SESSION_KEY, $kept);

        // Kept only so logout can tell the IdP which session is ending. Nothing else reads it.
        if (isset($tokens['id_token'])) {
            $session->put(self::ID_TOKEN, $tokens['id_token']);
        }

        return redirect(self::safeLocalPath($intended));
    }

    /**
     * Ends the session here and at the IdP, so "sign out" means what a member expects rather than leaving
     * them silently signed straight back in on the next visit.
     *
     * A CSRF-protected POST for both protocols. Under SAML the session is ended here too, on this POST,
     * rather than by handing off to a GET a cross-site page could trigger.
     *
     * With no live session there is nothing to end, here or at the identity provider, so the answer is
     * the redirect to /signed-out, and the anti-forgery check does not apply (see
     * PreventRequestForgeryUnlessSignedOut), because with nothing to end the anti-forgery token cannot
     * matter (decision record 24 in docs/adr).
     */
    public function signout(Request $request): RedirectResponse
    {
        if (app(MemberIdentityResolver::class)->subject($request) === null) {
            return redirect(self::SIGNED_OUT);
        }

        if (self::usesSaml()) {
            return app(SamlController::class)->signOut($request);
        }

        $idToken = $request->session()->get(self::ID_TOKEN);

        $request->session()->flush();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // /signed-out, not /: the member gets visible confirmation, and a dedicated path is the only
        // post-logout URI shape the IdP's exact matching lets an operator register cleanly.
        //
        // The local session is already ended, which is the part this service answers for. If the
        // discovery document cannot be read just now, or the end-session address cannot be built from it,
        // the member still lands on /signed-out, with the reason in the log, rather than on an error page
        // for a sign-out that did happen here. The SAML branch does the same.
        try {
            $endSession = $this->oidc()->endSessionUrl(
                is_string($idToken) ? $idToken : null,
                url(self::SIGNED_OUT)
            );
        } catch (\Throwable $exception) {
            Log::warning('The IdP could not be told about a sign-out; ended the local session only.', [
                'reason' => $exception->getMessage(),
            ]);

            $endSession = null;
        }

        return $endSession !== null ? redirect()->away($endSession) : redirect(self::SIGNED_OUT);
    }

    private static function usesSaml(): bool
    {
        return config('trusted_attestation.iam.protocol') === 'saml';
    }

    /**
     * The OIDC client, resolved on demand rather than constructor-injected, so it is only ever built on the
     * OIDC paths -- never for a SAML deployment, which may leave the (there-unused) OIDC issuer unset.
     */
    private function oidc(): OidcClient
    {
        return app(OidcClient::class);
    }

    /**
     * Exact, and built from the app's own URL rather than anything the request supplies -- the OAuth 2.1
     * profile requires exact redirect URI matching, and a redirect URI derived from an attacker-supplied
     * header would not be exact in any useful sense.
     */
    private static function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/').'/auth/callback';
    }
}
