<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\MemberIdentityResolver;
use App\Services\Saml\PostBindingMessage;
use App\Services\Saml\SamlClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\LogoutRequest;
use OneLogin\Saml2\LogoutResponse;
use OneLogin\Saml2\Utils;

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

    private const NAME_ID = 'trusted_attestation.saml.name_id';

    private const SESSION_INDEX = 'trusted_attestation.saml.session_index';

    private const LOGOUT_REQUEST_ID = 'trusted_attestation.saml.logout_request_id';

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

    /**
     * The single logout service: where the IdP sends its LogoutResponse after the member's own sign-out,
     * or a LogoutRequest when the member signed out of another service at the IdP.
     *
     * It answers both bindings an IdP uses for these messages. Over HTTP-Redirect the message arrives as a
     * GET with a detached signature over the query string, which the library checks. Over HTTP-POST it
     * arrives as a cross-site POST with the signature enveloped in the XML, which PostBindingMessage checks
     * before the library sees it, since the library only reads the detached kind. Neither request can carry
     * a CSRF token, so the service never acts on the request alone. A bare request, or any message that does
     * not validate, changes nothing and lands on the landing page. The rule is the same on both bindings:
     *
     * - A LogoutResponse never touches the session, which the member's own POST already ended. It is checked
     *   against the LogoutRequest this browser sent and the outcome is logged, and it lands on /signed-out
     *   whether or not it validates.
     * - A LogoutRequest ends the session only when it carries a signature that verifies against the IdP's
     *   certificate from its metadata. An unsigned one is refused, since anyone could have written it and
     *   neither binding gives this service any other way to know the IdP sent it.
     * - A LogoutRequest over the Redirect binding is refused when its SigAlg names no algorithm or one weaker
     *   than SHA-256, as a sign-in signed so is.
     * - A verified LogoutRequest ends the session only when it is addressed to this server's single logout
     *   address, its NotOnOrAfter time, if it has one, has not passed, and its NameID names the member signed
     *   in here. Any other is answered with the Requester status and the session stays.
     * - Without a signing key a LogoutRequest is answered HTTP 404 with no body, as if the address were
     *   absent, because its answer must be a signed LogoutResponse and so single logout is not offered and
     *   not in the metadata. The session is left as it was. The other two implementations answer the same.
     */
    public function singleLogout(Request $request): RedirectResponse
    {
        if (self::carriesLogoutRequest($request) && ! $this->saml->hasSigningKey()) {
            Log::warning('A SAML LogoutRequest arrived, but no signing key is configured to answer it, so single logout is not offered and the request was refused.');

            abort(Response::HTTP_NOT_FOUND, '');
        }

        if ($request->isMethod('POST')) {
            return $this->singleLogoutOverPost($request);
        }

        if ($request->query('SAMLResponse') !== null) {
            return $this->completeSignOut($request);
        }

        if ($request->query('SAMLRequest') !== null && $request->query('Signature') !== null) {
            return $this->answerLogoutRequest($request);
        }

        Log::warning('A single logout request carried no signed SAML logout message, so nothing was changed.');

        return redirect('/');
    }

    /**
     * Whether the request carries a LogoutRequest, in its query or its body whatever the method, as the other
     * two implementations look for one. An empty SAMLRequest counts, so the parameter's presence is tested:
     * Laravel turns an empty input into null on its way in.
     */
    private static function carriesLogoutRequest(Request $request): bool
    {
        return $request->query->has('SAMLRequest') || $request->request->has('SAMLRequest');
    }

    private function completeSignOut(Request $request): RedirectResponse
    {
        $requestId = $request->session()->pull(self::LOGOUT_REQUEST_ID);

        try {
            $auth = $this->saml->auth();
            $auth->processSLO(true, is_string($requestId) ? $requestId : null, false, null, true);
        } catch (\Throwable $exception) {
            Log::warning('A SAML LogoutResponse could not be processed.', ['reason' => $exception->getMessage()]);

            return redirect(self::SIGNED_OUT);
        }

        // A LogoutResponse that fails validation changes nothing, because the member's own POST already
        // ended the session here. It still lands on /signed-out, which is true of this server, rather than
        // on the landing page as if the sign-out had not happened.
        if ($auth->getErrors() !== []) {
            Log::warning('A SAML LogoutResponse failed validation.', [
                'errors' => $auth->getErrors(),
                'reason' => $auth->getLastErrorReason(),
            ]);
        }

        return redirect(self::SIGNED_OUT);
    }

    private function answerLogoutRequest(Request $request): RedirectResponse
    {
        try {
            $auth = $this->saml->auth();
            $logoutRequest = $auth->buildLogoutRequest($auth->getSettings(), (string) $request->query('SAMLRequest'));
            $relayState = $request->query('RelayState');

            // The library checks the signature with the algorithm SigAlg names, and with SHA-1 when it names
            // none, so a request naming none or a weak one is refused before its signature is checked.
            if (! SamlClient::isStrongAlgorithm((string) $request->query('SigAlg'))) {
                return $this->refuseLogoutRequest($auth, $logoutRequest, $relayState, 'it was not signed with SHA-256 or stronger');
            }

            // The detached signature over the query string, checked first, so that nothing is answered for a
            // request the IdP did not sign. The library's own checks run after the other refusals.
            if (! Utils::validateBinarySign('SAMLRequest', $_GET, $auth->getSettings()->getIdPData())) {
                Log::warning('A SAML LogoutRequest failed validation, so the session was left as it was.', [
                    'reason' => 'Signature validation failed. Logout Request rejected',
                ]);

                return redirect('/');
            }

            return $this->answerSignedLogoutRequest($request, $auth, $logoutRequest, $relayState);
        } catch (\Throwable $exception) {
            Log::warning('A SAML LogoutRequest could not be processed.', ['reason' => $exception->getMessage()]);

            return redirect('/');
        }
    }

    /**
     * The answer to a LogoutRequest whose signature has verified, on either binding. It ends the session, and
     * the IdP gets a Success response, when the request is addressed to this server's single logout address,
     * its NotOnOrAfter time, if it has one, has not passed, it passes the library's checks, and it names the
     * member signed in here, or nobody is signed in. A request that fails the library's checks changes
     * nothing. Any other is answered with the Requester status and the session stays, as Spring Security
     * answers a request naming another member in the Java implementation, so a genuine LogoutRequest for one
     * member, replayed from another member's browser, taken from another server or sent late, signs nobody
     * out. The session index is not compared, as Java does not compare it.
     */
    private function answerSignedLogoutRequest(Request $request, Auth $auth, LogoutRequest $logoutRequest, mixed $relayState): RedirectResponse
    {
        // Before the library's checks, which would refuse the same two faults without an answer to the IdP.
        $refusal = self::addressingFault($auth, $logoutRequest);
        if ($refusal !== null) {
            return $this->refuseLogoutRequest($auth, $logoutRequest, $relayState, $refusal);
        }

        if (! $logoutRequest->isValid()) {
            Log::warning('A SAML LogoutRequest failed validation, so the session was left as it was.', [
                'reason' => $logoutRequest->getError(),
            ]);

            return redirect('/');
        }

        if (! self::namesTheSignedInMember($request, $auth, $logoutRequest)) {
            return $this->refuseLogoutRequest($auth, $logoutRequest, $relayState, 'it named a different member from the one signed in');
        }

        self::endSession($request);

        return redirect()->away($this->logoutResponseUrl($auth, (string) $logoutRequest->id, $relayState, Constants::STATUS_SUCCESS));
    }

    /** Answers the LogoutRequest with the Requester status, leaving the session as it was, and logs why. */
    private function refuseLogoutRequest(Auth $auth, LogoutRequest $logoutRequest, mixed $relayState, string $reason): RedirectResponse
    {
        Log::warning("A SAML LogoutRequest was refused because {$reason}, so the session was left as it was.", [
            'reason' => $reason,
        ]);

        return redirect()->away($this->logoutResponseUrl($auth, (string) $logoutRequest->id, $relayState, Constants::STATUS_REQUESTER));
    }

    /**
     * Why the LogoutRequest is not for this server now, or null when it is: it has to name this server's single
     * logout address as its Destination, exactly, since a signed request has to say where it is going, and its
     * NotOnOrAfter time, if it has one, must not have passed, with no allowance for clock difference, as the
     * library checks it. A time that cannot be read has passed. A request that cannot be read is left to the
     * library's checks, which refuse it.
     */
    private static function addressingFault(Auth $auth, LogoutRequest $logoutRequest): ?string
    {
        try {
            $root = Utils::loadXML(new \DOMDocument, $logoutRequest->getXML())->documentElement;
        } catch (\Throwable) {
            return null;
        }

        if ($root->getAttribute('Destination') !== $auth->getSettings()->getSPData()['singleLogoutService']['url']) {
            return "it was not addressed to this server's single logout address";
        }

        if ($root->hasAttribute('NotOnOrAfter') && self::hasPassed($root->getAttribute('NotOnOrAfter'))) {
            return 'its NotOnOrAfter time had passed';
        }

        return null;
    }

    private static function hasPassed(string $time): bool
    {
        try {
            return Utils::parseSAML2Time($time) <= time();
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * Whether the LogoutRequest's NameID is the one the member signed in with, which acs() keeps in the
     * session, compared exactly, as Java compares it. With nobody signed in it is taken as named, because
     * there is no member it could name wrongly.
     */
    private static function namesTheSignedInMember(Request $request, Auth $auth, LogoutRequest $logoutRequest): bool
    {
        if (! $request->session()->has(MemberIdentityResolver::SESSION_KEY)) {
            return true;
        }

        $signedIn = $request->session()->get(self::NAME_ID);

        return is_string($signedIn)
            && $signedIn === LogoutRequest::getNameId($logoutRequest->getXML(), $auth->getSettings()->getSPkey());
    }

    /**
     * The HTTP-POST binding. The message is the plain XML, base64-encoded, in the SAMLRequest or SAMLResponse
     * form field. The library's processSLO reads only the query string and only the detached signature, so
     * this path verifies the enveloped signature itself and then hands the message to the library's
     * LogoutRequest or LogoutResponse for the checks that do not depend on the binding: destination, issuer,
     * NotOnOrAfter, and for a response the InResponseTo and the status.
     */
    private function singleLogoutOverPost(Request $request): RedirectResponse
    {
        if ($request->post('SAMLResponse') !== null) {
            return $this->completePostedSignOut($request);
        }

        if ($request->post('SAMLRequest') !== null) {
            return $this->answerPostedLogoutRequest($request);
        }

        Log::warning('A single logout request carried no SAML logout message, so nothing was changed.');

        return redirect('/');
    }

    private function completePostedSignOut(Request $request): RedirectResponse
    {
        // Pulled whichever way this ends, as the Redirect path does, so a request id answers at most once.
        $requestId = $request->session()->pull(self::LOGOUT_REQUEST_ID);

        try {
            $auth = $this->saml->auth();
            $encoded = $this->verifiedPostedMessage($request, 'SAMLResponse', PostBindingMessage::LOGOUT_RESPONSE, $auth);
            if ($encoded === null) {
                return redirect(self::SIGNED_OUT);
            }

            $logoutResponse = $auth->buildLogoutResponse($auth->getSettings(), $encoded);
            $reason = self::logoutResponseFailure($logoutResponse, is_string($requestId) ? $requestId : null);
        } catch (\Throwable $exception) {
            Log::warning('A SAML LogoutResponse could not be processed.', ['reason' => $exception->getMessage()]);

            return redirect(self::SIGNED_OUT);
        }

        // Nothing to undo on failure: the member's own POST already ended the session, so this lands on
        // /signed-out either way, as the Redirect path does.
        if ($reason !== null) {
            Log::warning('A SAML LogoutResponse failed validation.', ['reason' => $reason]);
        }

        return redirect(self::SIGNED_OUT);
    }

    private function answerPostedLogoutRequest(Request $request): RedirectResponse
    {
        try {
            $auth = $this->saml->auth();
            $encoded = $this->verifiedPostedMessage($request, 'SAMLRequest', PostBindingMessage::LOGOUT_REQUEST, $auth);
            if ($encoded !== null) {
                $logoutRequest = $auth->buildLogoutRequest($auth->getSettings(), $encoded);

                return $this->answerSignedLogoutRequest($request, $auth, $logoutRequest, $request->post('RelayState'));
            }
        } catch (\Throwable $exception) {
            Log::warning('A SAML LogoutRequest could not be processed.', ['reason' => $exception->getMessage()]);
        }

        // Refused, with the reason logged, so the session is left as it was.
        return redirect('/');
    }

    /**
     * Why a LogoutResponse is not accepted, or null when it passes the library's checks and reports Success,
     * the same two tests processSLO applies on the Redirect path.
     */
    private static function logoutResponseFailure(LogoutResponse $logoutResponse, ?string $requestId): ?string
    {
        if (! $logoutResponse->isValid($requestId)) {
            return (string) $logoutResponse->getError();
        }

        return $logoutResponse->getStatus() === Constants::STATUS_SUCCESS ? null : 'the status is not Success';
    }

    /**
     * The posted form field, once PostBindingMessage has verified its enveloped signature and found the kind
     * of message the field is meant to carry. Null, with the reason logged, when it is refused.
     */
    private function verifiedPostedMessage(Request $request, string $field, string $kind, Auth $auth): ?string
    {
        $encoded = $request->post($field);
        $message = PostBindingMessage::verify(
            is_string($encoded) ? $encoded : '',
            SamlClient::signingCertificates($auth->getSettings()),
        );

        if ($message->verified() && $message->kind === $kind) {
            return $encoded;
        }

        Log::warning("A posted SAML {$kind} was refused, so nothing was changed.", [
            'reason' => $message->reason ?? "the {$field} field carries a {$message->kind}, not a {$kind}",
        ]);

        return null;
    }

    /**
     * Where a verified LogoutRequest sends the member next: the IdP's single logout response address, over
     * the Redirect binding, carrying a LogoutResponse with the given status, signed when this service provider
     * holds a key, and the RelayState the IdP sent. Built from the same library calls processSLO makes. The
     * library only builds a Success response, so any other status replaces it in the XML before encoding.
     */
    private function logoutResponseUrl(Auth $auth, string $inResponseTo, mixed $relayState, string $status): string
    {
        $builder = $auth->buildLogoutResponse($auth->getSettings());
        $builder->build($inResponseTo);
        $xml = str_replace('"'.Constants::STATUS_SUCCESS.'"', '"'.$status.'"', $builder->getXML());
        $logoutResponse = base64_encode($auth->getSettings()->shouldCompressResponses() ? (string) gzdeflate($xml) : $xml);

        $parameters = ['SAMLResponse' => $logoutResponse];
        if (is_string($relayState) && $relayState !== '') {
            $parameters['RelayState'] = $relayState;
        }

        // SigAlg before Signature: the signature covers the query string in this order.
        $security = $auth->getSettings()->getSecurityData();
        if (! empty($security['logoutResponseSigned'])) {
            $signature = $auth->buildResponseSignature($logoutResponse, $parameters['RelayState'] ?? null, $security['signatureAlgorithm']);
            $parameters['SigAlg'] = $security['signatureAlgorithm'];
            $parameters['Signature'] = $signature;
        }

        return (string) $auth->redirectTo((string) $auth->getSLOResponseUrl(), $parameters, true);
    }

    private static function endSession(Request $request): void
    {
        $request->session()->flush();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
