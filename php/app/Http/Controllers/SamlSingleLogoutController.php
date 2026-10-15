<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\MemberIdentityResolver;
use App\Services\Saml\PostBindingMessage;
use App\Services\Saml\SamlClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use OneLogin\Saml2\Auth;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\LogoutRequest;
use OneLogin\Saml2\LogoutResponse;
use OneLogin\Saml2\Utils;

/**
 * SAML 2.0 single logout: the answers to the logout messages an identity provider sends, kept apart from the
 * sign-in in SamlController. The member's own sign-out starts in SamlController::signOut, and the identity
 * provider's reply to it arrives here.
 */
final class SamlSingleLogoutController extends Controller
{
    public function __construct(private readonly SamlClient $saml) {}

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

        return match (true) {
            $request->isMethod('POST') => $this->singleLogoutOverPost($request),
            $request->query('SAMLResponse') !== null => $this->completeSignOut($request),
            $request->query('SAMLRequest') !== null && $request->query('Signature') !== null => $this->answerLogoutRequest($request),
            default => self::ignoreUnsignedMessage(),
        };
    }

    private static function ignoreUnsignedMessage(): RedirectResponse
    {
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
        $requestId = $request->session()->pull(SamlController::LOGOUT_REQUEST_ID);

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

            return $this->refusedRedirectSignature($request, $auth, $logoutRequest, $relayState)
                ?? $this->answerSignedLogoutRequest($request, $auth, $logoutRequest, $relayState);
        } catch (\Throwable $exception) {
            Log::warning('A SAML LogoutRequest could not be processed.', ['reason' => $exception->getMessage()]);

            return redirect('/');
        }
    }

    /**
     * The answer to a LogoutRequest over the Redirect binding whose signature is weak or does not verify, or
     * null when it is strong and verifies. The library checks the signature with the algorithm SigAlg names,
     * and with SHA-1 when it names none, so a request naming none or a weak one is refused before its signature
     * is checked. The detached signature over the query string is checked next, so that nothing is answered for
     * a request the IdP did not sign. The library's own checks run after the other refusals.
     */
    private function refusedRedirectSignature(Request $request, Auth $auth, LogoutRequest $logoutRequest, mixed $relayState): ?RedirectResponse
    {
        if (! SamlClient::isStrongAlgorithm((string) $request->query('SigAlg'))) {
            return $this->refuseLogoutRequest($auth, $logoutRequest, $relayState, 'it was not signed with SHA-256 or stronger');
        }

        if (! Utils::validateBinarySign('SAMLRequest', $_GET, $auth->getSettings()->getIdPData())) {
            Log::warning('A SAML LogoutRequest failed validation, so the session was left as it was.', [
                'reason' => 'Signature validation failed. Logout Request rejected',
            ]);

            return redirect('/');
        }

        return null;
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

        return self::namesTheSignedInMember($request, $auth, $logoutRequest)
            ? $this->endSessionAndAnswerSuccess($request, $auth, $logoutRequest, $relayState)
            : $this->refuseLogoutRequest($auth, $logoutRequest, $relayState, 'it named a different member from the one signed in');
    }

    /** Ends the member's session here and answers the LogoutRequest with the Success status. */
    private function endSessionAndAnswerSuccess(Request $request, Auth $auth, LogoutRequest $logoutRequest, mixed $relayState): RedirectResponse
    {
        SamlController::endSession($request);

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
        $root = self::rootOf($logoutRequest);

        return match (true) {
            $root === null => null,
            $root->getAttribute('Destination') !== $auth->getSettings()->getSPData()['singleLogoutService']['url'] => "it was not addressed to this server's single logout address",
            $root->hasAttribute('NotOnOrAfter') && self::hasPassed($root->getAttribute('NotOnOrAfter')) => 'its NotOnOrAfter time had passed',
            default => null,
        };
    }

    /** The LogoutRequest's root element, or null when its XML cannot be read. */
    private static function rootOf(LogoutRequest $logoutRequest): ?\DOMElement
    {
        try {
            return Utils::loadXML(new \DOMDocument, $logoutRequest->getXML())->documentElement;
        } catch (\Throwable) {
            return null;
        }
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

        $signedIn = $request->session()->get(SamlController::NAME_ID);

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
        $requestId = $request->session()->pull(SamlController::LOGOUT_REQUEST_ID);

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
}
