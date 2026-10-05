<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\SamlController;
use App\Services\MemberIdentityResolver;
use App\Services\Saml\SamlClient;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use Tests\Support\BuildsSamlLogoutMessages;
use Tests\TestCase;

/**
 * The SAML login controller's own decisions, isolated from a live IdP.
 *
 * The cryptographic validation of a signed assertion is the OneLogin library's, exercised by its
 * maintainers and driven end to end by the conformance suite against a real IdP. What belongs to this
 * implementation, and what is pinned here, is the orchestration around it: that starting a login sends the member to the IdP with
 * a signed AuthnRequest and remembers the request id (so the eventual assertion can be tied back to the
 * request this browser made), that the SP metadata is served with the right content type, that logout ends
 * the local session whether or not the IdP offers single logout, and that an assertion that cannot be
 * processed lands on the failed-login page rather than throwing.
 *
 * HONEST LIMIT: the acs() success path -- a signed assertion accepted, claims established, RelayState
 * honoured -- cannot be reached without a genuinely signed assertion or a live IdP, which is integration
 * territory; the conformance suite covers it against Keycloak. Only acs()'s reachable refusal branch is
 * exercised here, which is why this controller does not reach the happy-path lines of acs(). What acs()
 * stores in the session comes from sessionClaims(), which is public and static and pinned directly below.
 *
 * The IdP metadata the SP is built from is seeded into the cache the client reads (keyed exactly as the
 * client keys it), so the controller runs with no network. A Feature test because it leans on redirect()/
 * url()/config()/response() and the cache -- all facades needing the container.
 */
final class SamlControllerTest extends TestCase
{
    use BuildsSamlLogoutMessages;

    private const APP_URL = 'https://ata.example';

    private const IDP_METADATA_URL = 'https://idp.example/metadata';

    private const SIGNATURE_ALGORITHM = 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';

    /** The IdP's signing certificate, seeded into its metadata. */
    private string $idpCert;

    /** The IdP's signing key, matching the certificate. */
    private string $idpKey;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // OneLogin reads the assertion from the $_POST superglobal, and logout messages from $_GET, directly;
        // keep both clean between tests so one test's message cannot leak into another's.
        unset($_POST['SAMLResponse'], $_POST['RelayState']);
        $_GET = [];

        config([
            'app.url' => self::APP_URL,
            'trusted_attestation.iam.protocol' => 'saml',
            'trusted_attestation.iam.subject_claim' => 'sub',
            'trusted_attestation.iam.saml.idp_metadata_url' => self::IDP_METADATA_URL,
            'trusted_attestation.iam.saml.entity_id' => '',
            'trusted_attestation.iam.saml.sp_certificate_path' => null,
            'trusted_attestation.iam.saml.sp_private_key_path' => null,
        ]);

        [$this->idpCert, $this->idpKey] = self::selfSignedCertificate();
        $this->seedIdpMetadata([
            'idp' => [
                'entityId' => 'https://idp.example/saml',
                'singleSignOnService' => [
                    'url' => 'https://idp.example/sso',
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'singleLogoutService' => [
                    'url' => 'https://idp.example/slo',
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'x509cert' => $this->idpCert,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        unset($_POST['SAMLResponse'], $_POST['RelayState']);
        $_GET = [];
        parent::tearDown();
    }

    /**
     * A logout message as the IdP sends it over the HTTP-Redirect binding: deflated, base64-encoded, and,
     * when $signed, signed over the query string with the IdP's key. Placed in $_GET, where OneLogin reads
     * it, and returned as the query for the request.
     *
     * @return array<string, string>
     */
    private function redirectBindingMessage(string $parameter, string $xml, bool $signed): array
    {
        $query = [$parameter => base64_encode((string) gzdeflate($xml))];

        if ($signed) {
            $query['SigAlg'] = self::SIGNATURE_ALGORITHM;
            $signedQuery = $parameter.'='.urlencode($query[$parameter]).'&SigAlg='.urlencode($query['SigAlg']);
            openssl_sign($signedQuery, $signature, $this->idpKey, OPENSSL_ALGO_SHA256);
            $query['Signature'] = base64_encode($signature);
        }

        $_GET = $query;

        return $query;
    }

    /** A message signed by the IdP with an enveloped signature, as it sends one over the HTTP-POST binding. */
    private function signedByIdp(
        string $xml,
        string $signatureAlgorithm = XMLSecurityKey::RSA_SHA256,
        string $digestAlgorithm = XMLSecurityDSig::SHA256,
    ): string {
        return self::enveloped($xml, $this->idpKey, $this->idpCert, $signatureAlgorithm, $digestAlgorithm);
    }

    private function seedIdpMetadata(array $parsed): void
    {
        Cache::put('trusted_attestation.saml.idp-metadata.'.hash('sha256', self::IDP_METADATA_URL), $parsed, 3600);
    }

    private function controller(): SamlController
    {
        return new SamlController(new SamlClient);
    }

    private function newSession(): Store
    {
        return new Store('ata_session', new ArraySessionHandler(60));
    }

    /**
     * @param  array<string, string>  $query
     * @param  array<string, string>  $post
     */
    private function request(Store $session, array $query = [], array $post = []): Request
    {
        $request = Request::create('/auth/saml/x', empty($post) ? 'GET' : 'POST', array_merge($query, $post));
        $request->setLaravelSession($session);

        return $request;
    }

    private function pathOf(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /** The site root, which redirect('/') renders as the bare origin when the controller is driven directly. */
    private function assertLandsOnTheLandingPage(string $url): void
    {
        self::assertContains($this->pathOf($url), ['', '/'], "expected the landing page, was {$url}");
    }

    /**
     * Posts a LogoutRequest as the IdP would over the HTTP-POST binding and asserts it is refused for the
     * given reason: the member stays signed in, the browser lands on the landing page, and the log says why.
     */
    private function assertPostedLogoutRequestIsRefused(string $xml, string $reasonFragment): void
    {
        Log::spy();
        $session = $this->newSession();
        $session->put(MemberIdentityResolver::SESSION_KEY, ['sub' => 'member-subject']);

        $response = $this->controller()->singleLogout($this->request($session, [], ['SAMLRequest' => self::posted($xml)]));

        $this->assertLandsOnTheLandingPage($response->getTargetUrl());
        self::assertSame(['sub' => 'member-subject'], $session->get(MemberIdentityResolver::SESSION_KEY));
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []): bool => str_contains((string) ($context['reason'] ?? ''), $reasonFragment))
            ->once();
    }

    // -- login ---------------------------------------------------------------------------------------

    #[Test]
    public function login_sends_the_member_to_the_idp_and_remembers_the_request_id(): void
    {
        // The AuthnRequest is aimed at the IdP's SSO endpoint read from its metadata, and the request id is
        // stashed so the returning assertion can be checked to be an answer to this browser's request --
        // without that stash a captured assertion could be replayed into this session. It is held in a
        // dedicated SameSite=None;Secure cookie rather than the session, because the assertion comes back as
        // a cross-site top-level POST on which the SameSite=Lax session cookie is not sent; the session
        // would be absent at the ACS and every real login rejected. (See SamlController::REQUEST_ID_COOKIE.)
        $session = $this->newSession();
        $response = $this->controller()->login($this->request($session, ['next' => '/me']));

        $target = $response->getTargetUrl();
        self::assertStringStartsWith('https://idp.example/sso?', $target);
        // The redirect carries a SAMLRequest, which is the AuthnRequest itself.
        parse_str((string) parse_url($target, PHP_URL_QUERY), $params);
        self::assertNotEmpty($params['SAMLRequest']);

        $cookie = null;
        foreach ($response->headers->getCookies() as $candidate) {
            if ($candidate->getName() === 'ata_saml_authn') {
                $cookie = $candidate;
            }
        }
        self::assertNotNull($cookie, 'the login did not set the request-id cookie');
        self::assertIsString($cookie->getValue());
        self::assertNotSame('', $cookie->getValue());
        // The property that makes it survive the IdP's cross-site POST back to the ACS.
        self::assertSame('none', $cookie->getSameSite());
        self::assertTrue($cookie->isSecure());
    }

    // -- metadata ------------------------------------------------------------------------------------

    #[Test]
    public function metadata_is_served_as_xml(): void
    {
        // An IdP administrator has to read this to register the SP at all, so it is public and must be
        // served as XML rather than as an HTML-typed page a strict IdP tool would refuse to parse.
        $response = $this->controller()->metadata();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/xml', $response->headers->get('Content-Type'));
        self::assertStringContainsString('AssertionConsumerService', (string) $response->getContent());
    }

    // -- acs (only the reachable refusal branch; see the class docblock) -----------------------------

    #[Test]
    public function an_assertion_that_cannot_be_processed_lands_on_login_failed(): void
    {
        // No SAMLResponse at all: the library cannot process a response that is not there, the exception
        // is caught, and the member lands on the failed-login page rather than seeing a 500. Nothing
        // reaches the session. The accepted-assertion path needs a signed assertion and is covered by the
        // conformance suite instead.
        $session = $this->newSession();
        $session->put('trusted_attestation.saml.request_id', 'the-request-id');

        $response = $this->controller()->acs($this->request($session));

        self::assertSame('/me?error=login', $this->pathOf($response->getTargetUrl()));
        self::assertNull($session->get(MemberIdentityResolver::SESSION_KEY));
    }

    #[Test]
    public function a_malformed_saml_response_lands_on_login_failed(): void
    {
        // A response that is present but not a valid assertion -- garbage base64, a replayed body, an
        // unsigned one -- is refused the same way. Fed through the $_POST superglobal the library reads.
        $_POST['SAMLResponse'] = base64_encode('<not-a-saml-response/>');

        $session = $this->newSession();
        $session->put('trusted_attestation.saml.request_id', 'the-request-id');

        $response = $this->controller()->acs($this->request($session));

        self::assertSame('/me?error=login', $this->pathOf($response->getTargetUrl()));
        self::assertNull($session->get(MemberIdentityResolver::SESSION_KEY));
    }

    #[Test]
    public function an_assertion_answering_no_request_this_sp_made_is_refused_unprocessed(): void
    {
        // No stored request id: the browser never started a login here, so the response is unsolicited
        // (IdP-initiated) or a captured assertion replayed straight at the consumer. It is refused before
        // the library processes it -- the case OneLogin's rejectUnsolicitedResponsesWithInResponseTo does
        // not cover, since that flag only fires when an InResponseTo is present. A well-formed-looking
        // SAMLResponse is supplied so the refusal cannot be the missing-body one; the guard fires anyway.
        Log::spy();

        $_POST['SAMLResponse'] = base64_encode('<whatever/>');
        $session = $this->newSession(); // deliberately no request id stashed

        $response = $this->controller()->acs($this->request($session, [], ['SAMLResponse' => 'x']));

        self::assertSame('/me?error=login', $this->pathOf($response->getTargetUrl()));
        self::assertNull($session->get(MemberIdentityResolver::SESSION_KEY));

        // The distinct log message proves the unsolicited guard fired rather than the library being handed
        // the response and failing to parse it -- i.e. processResponse was never reached. Remove the guard
        // and this response would instead be processed and logged as "could not be processed", failing here.
        Log::shouldHaveReceived('warning')
            ->with('A SAML assertion arrived that answers no authentication request this service made.')
            ->once();
    }

    // -- what an accepted assertion leaves in the session ---------------------------------------------

    #[Test]
    public function an_accepted_assertion_leaves_only_the_subject_name_and_locale_in_the_session(): void
    {
        // The assertion carries the subject as an attribute, a display name, a locale and directory
        // attributes the page never reads. Only the first three are kept: storing every attribute again
        // fails here.
        config([
            'trusted_attestation.iam.subject_claim' => 'email',
            'trusted_attestation.iam.name_claim' => 'displayName',
        ]);

        $claims = SamlController::sessionClaims([
            'email' => ['alice@example.org'],
            'displayName' => ['Alice Anderson'],
            'locale' => ['fr-CA'],
            'department' => ['Finance'],
            'memberOf' => ['staff', 'board'],
        ], 'opaque-name-id');

        self::assertEquals(
            ['email' => 'alice@example.org', 'displayName' => 'Alice Anderson', 'locale' => 'fr-CA'],
            $claims,
        );
    }

    #[Test]
    public function the_subject_falls_back_to_the_name_id_and_nothing_else_does(): void
    {
        // No attribute of the configured subject name, which is the usual case: the NameID stands in for
        // the subject. A multivalued attribute reads as absent, and the display name does not borrow the
        // NameID.
        $claims = SamlController::sessionClaims([
            'sub' => ['one', 'two'],
            'department' => ['Finance'],
        ], 'the-name-id');

        self::assertSame(['sub' => 'the-name-id'], $claims);
    }

    #[Test]
    public function an_assertion_with_no_attribute_of_the_subject_name_and_an_empty_name_id_names_no_subject(): void
    {
        // What the consumer refuses before anything reaches the session: with neither the configured
        // attribute nor a NameID there is nobody to sign in. The same check the OIDC callback applies to a
        // token, so neither protocol can establish a session keyed by nobody.
        $claims = SamlController::sessionClaims(['department' => ['Finance']], '');

        self::assertNull(MemberIdentityResolver::subjectOf($claims));
        self::assertNull(MemberIdentityResolver::subjectOf(SamlController::sessionClaims(['sub' => ['  ']], '  ')));
        self::assertSame('the-name-id', MemberIdentityResolver::subjectOf(SamlController::sessionClaims([], 'the-name-id')));
    }

    #[Test]
    public function a_multivalued_locale_is_read_as_its_first_value(): void
    {
        // The one exception to "multivalued reads as absent", the same in all three implementations: a
        // locale only chooses a language, so the first value the identity provider lists is used. A
        // multivalued display name still reads as absent.
        $claims = SamlController::sessionClaims([
            'name' => ['Alice', 'Alicia'],
            'locale' => ['fr-CA', 'en'],
        ], 'the-name-id');

        self::assertSame(['sub' => 'the-name-id', 'locale' => 'fr-CA'], $claims);
    }

    #[Test]
    public function a_logout_response_that_cannot_be_read_also_lands_on_the_signed_out_page(): void
    {
        $session = $this->newSession();
        $session->put(MemberIdentityResolver::SESSION_KEY, ['sub' => 'member-subject']);

        $response = $this->controller()->singleLogout($this->request($session, ['SAMLResponse' => 'not a SAML message']));

        self::assertSame('/signed-out', $this->pathOf($response->getTargetUrl()));
        self::assertSame(['sub' => 'member-subject'], $session->get(MemberIdentityResolver::SESSION_KEY));
    }

    // -- logout --------------------------------------------------------------------------------------

    #[Test]
    public function sign_out_ends_the_local_session_and_sends_the_member_to_the_idp_single_logout(): void
    {
        // The member's own sign-out, reached from the CSRF-protected POST /signout: the local session is
        // ended and the member is sent on to the IdP's SLO endpoint, carrying the name id and session index
        // stashed at login so the IdP knows which session ends. The LogoutRequest id is remembered in the
        // fresh session, so the IdP's answer can be tied back to it.
        $session = $this->newSession();
        $session->put(MemberIdentityResolver::SESSION_KEY, ['sub' => 'member-subject']);
        $session->put('trusted_attestation.saml.name_id', 'the-name-id');
        $session->put('trusted_attestation.saml.session_index', 'the-session-index');

        $response = $this->controller()->signOut($this->request($session, [], ['_token' => 'the-token']));

        self::assertStringStartsWith('https://idp.example/slo?', $response->getTargetUrl());
        parse_str((string) parse_url($response->getTargetUrl(), PHP_URL_QUERY), $params);
        self::assertNotEmpty($params['SAMLRequest']);
        self::assertNull($session->get(MemberIdentityResolver::SESSION_KEY));
        self::assertNotEmpty($session->get('trusted_attestation.saml.logout_request_id'));
    }

    #[Test]
    public function a_bare_cross_site_get_to_the_single_logout_service_does_not_end_the_session(): void
    {
        // The single logout service is a GET a cross-site page can trigger. With no SAML logout message it
        // changes nothing: the member stays signed in.
        $session = $this->newSession();
        $session->put(MemberIdentityResolver::SESSION_KEY, ['sub' => 'member-subject']);

        $response = $this->controller()->singleLogout($this->request($session));

        $this->assertLandsOnTheLandingPage($response->getTargetUrl());
        self::assertSame(['sub' => 'member-subject'], $session->get(MemberIdentityResolver::SESSION_KEY));
    }

    #[Test]
    public function an_unsigned_logout_request_does_not_end_the_session(): void
    {
        // Anyone can write an unsigned LogoutRequest and send a member's browser to it. Without a signature
        // the redirect binding gives no way to know the IdP sent it, so it is refused unprocessed.
        $session = $this->newSession();
        $session->put(MemberIdentityResolver::SESSION_KEY, ['sub' => 'member-subject']);
        $query = $this->redirectBindingMessage('SAMLRequest', self::logoutRequestXml(), false);

        $response = $this->controller()->singleLogout($this->request($session, $query));

        $this->assertLandsOnTheLandingPage($response->getTargetUrl());
        self::assertSame(['sub' => 'member-subject'], $session->get(MemberIdentityResolver::SESSION_KEY));
    }

    #[Test]
    public function a_logout_request_with_a_forged_signature_does_not_end_the_session(): void
    {
        // Signed, but not by the IdP: the library verifies the signature against the certificate in the
        // IdP's metadata, and a key the IdP does not hold fails it.
        Log::spy();
        [, $this->idpKey] = self::selfSignedCertificate();

        $session = $this->newSession();
        $session->put(MemberIdentityResolver::SESSION_KEY, ['sub' => 'member-subject']);
        $query = $this->redirectBindingMessage('SAMLRequest', self::logoutRequestXml(), true);

        $response = $this->controller()->singleLogout($this->request($session, $query));

        $this->assertLandsOnTheLandingPage($response->getTargetUrl());
        self::assertSame(['sub' => 'member-subject'], $session->get(MemberIdentityResolver::SESSION_KEY));
        // Refused by the signature check itself, not by some earlier failure to read the message.
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'failed validation')
                && str_contains((string) $context['reason'], 'Signature validation failed'))
            ->once();
    }

    #[Test]
    public function a_logout_request_signed_by_the_idp_ends_the_session_and_answers_it(): void
    {
        // The member signed out of another service at the IdP, which tells this one. A LogoutRequest the
        // IdP signed ends the local session, and the member is sent back with a LogoutResponse.
        $session = $this->newSession();
        $session->put(MemberIdentityResolver::SESSION_KEY, ['sub' => 'member-subject']);
        $query = $this->redirectBindingMessage('SAMLRequest', self::logoutRequestXml(), true);

        $response = $this->controller()->singleLogout($this->request($session, $query));

        self::assertStringStartsWith('https://idp.example/slo?', $response->getTargetUrl());
        parse_str((string) parse_url($response->getTargetUrl(), PHP_URL_QUERY), $params);
        self::assertNotEmpty($params['SAMLResponse']);
        self::assertNull($session->get(MemberIdentityResolver::SESSION_KEY));
    }

    #[Test]
    public function a_valid_logout_response_completes_the_sign_out_on_the_signed_out_page(): void
    {
        // The IdP's answer to the member's own sign-out, matching the LogoutRequest this browser sent. The
        // session was already ended on the POST, so this only lands the member on /signed-out.
        $session = $this->newSession();
        $session->put('trusted_attestation.saml.logout_request_id', 'ONELOGIN_the-request');
        $query = $this->redirectBindingMessage('SAMLResponse', self::logoutResponseXml('ONELOGIN_the-request'), true);

        $response = $this->controller()->singleLogout($this->request($session, $query));

        self::assertSame('/signed-out', $this->pathOf($response->getTargetUrl()));
    }

    #[Test]
    public function a_logout_response_answering_another_request_never_touches_the_session(): void
    {
        // A LogoutResponse that answers a different LogoutRequest fails validation, and in any case a
        // response never ends a session: a member who is signed in stays signed in. Both failure branches
        // land on /signed-out, as a valid response does.
        Log::spy();

        $session = $this->newSession();
        $session->put(MemberIdentityResolver::SESSION_KEY, ['sub' => 'member-subject']);
        $session->put('trusted_attestation.saml.logout_request_id', 'ONELOGIN_the-request');
        $query = $this->redirectBindingMessage('SAMLResponse', self::logoutResponseXml('ONELOGIN_another'), true);

        $response = $this->controller()->singleLogout($this->request($session, $query));

        self::assertSame('/signed-out', $this->pathOf($response->getTargetUrl()));
        self::assertSame(['sub' => 'member-subject'], $session->get(MemberIdentityResolver::SESSION_KEY));
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'failed validation')
                && str_contains((string) $context['reason'], 'InResponseTo'))
            ->once();
    }

    // -- single logout over the HTTP-POST binding ----------------------------------------------------

    #[Test]
    public function a_logout_request_posted_by_the_idp_ends_the_session_and_answers_it(): void
    {
        // Keycloak posts by default. The LogoutRequest arrives as a cross-site POST with its signature
        // enveloped in the XML. It ends the local session and the member is sent back to the IdP's single
        // logout address with a LogoutResponse and the RelayState the IdP sent. The Destination names this
        // address, and the library's destination check passes it as it does on the Redirect binding.
        $session = $this->newSession();
        $session->put(MemberIdentityResolver::SESSION_KEY, ['sub' => 'member-subject']);
        $xml = $this->signedByIdp(self::logoutRequestXml(self::APP_URL.'/auth/saml/logout'));

        $response = $this->controller()->singleLogout($this->request($session, [], [
            'SAMLRequest' => self::posted($xml),
            'RelayState' => 'the-relay-state',
        ]));

        self::assertStringStartsWith('https://idp.example/slo?', $response->getTargetUrl());
        parse_str((string) parse_url($response->getTargetUrl(), PHP_URL_QUERY), $params);
        self::assertNotEmpty($params['SAMLResponse']);
        // The answer names the request it answers. The library's signer gives the root a fresh ID, so it is
        // read back from the signed message rather than assumed.
        preg_match('/ ID="([^"]+)"/', $xml, $id);
        self::assertStringContainsString('InResponseTo="'.$id[1].'"', (string) gzinflate((string) base64_decode($params['SAMLResponse'])));
        self::assertSame('the-relay-state', $params['RelayState']);
        self::assertNull($session->get(MemberIdentityResolver::SESSION_KEY));
    }

    #[Test]
    public function a_logout_response_posted_by_the_idp_completes_the_sign_out(): void
    {
        // The IdP's answer to the member's own sign-out, posted rather than redirected, matching the
        // LogoutRequest this browser sent. It lands on /signed-out with nothing logged as a failure, which
        // is what tells it apart from a refused response landing on the same page.
        Log::spy();
        $session = $this->newSession();
        $session->put('trusted_attestation.saml.logout_request_id', 'ONELOGIN_the-request');
        $xml = $this->signedByIdp(self::logoutResponseXml('ONELOGIN_the-request', self::APP_URL.'/auth/saml/logout'));

        $response = $this->controller()->singleLogout($this->request($session, [], ['SAMLResponse' => self::posted($xml)]));

        self::assertSame('/signed-out', $this->pathOf($response->getTargetUrl()));
        self::assertNull($session->get('trusted_attestation.saml.logout_request_id'));
        Log::shouldNotHaveReceived('warning');
    }

    #[Test]
    public function a_posted_logout_response_answering_another_request_never_touches_the_session(): void
    {
        // Signed by the IdP, so the enveloped signature verifies, but it answers a different LogoutRequest.
        // The library's InResponseTo check still runs after the signature, and a response never ends a
        // session anyway. It lands on /signed-out, as on the Redirect binding.
        Log::spy();
        $session = $this->newSession();
        $session->put(MemberIdentityResolver::SESSION_KEY, ['sub' => 'member-subject']);
        $session->put('trusted_attestation.saml.logout_request_id', 'ONELOGIN_the-request');
        $xml = $this->signedByIdp(self::logoutResponseXml('ONELOGIN_another'));

        $response = $this->controller()->singleLogout($this->request($session, [], ['SAMLResponse' => self::posted($xml)]));

        self::assertSame('/signed-out', $this->pathOf($response->getTargetUrl()));
        self::assertSame(['sub' => 'member-subject'], $session->get(MemberIdentityResolver::SESSION_KEY));
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => str_contains((string) $context['reason'], 'InResponseTo'))
            ->once();
    }

    #[Test]
    public function an_unsigned_posted_logout_response_lands_on_signed_out_without_touching_the_session(): void
    {
        // Refused before the library sees it, and nothing to undo: the member's own POST already ended the
        // session, so the browser lands on /signed-out as on the Redirect binding.
        Log::spy();
        $session = $this->newSession();
        $session->put(MemberIdentityResolver::SESSION_KEY, ['sub' => 'member-subject']);

        $response = $this->controller()->singleLogout($this->request($session, [], [
            'SAMLResponse' => self::posted(self::logoutResponseXml('ONELOGIN_the-request')),
        ]));

        self::assertSame('/signed-out', $this->pathOf($response->getTargetUrl()));
        self::assertSame(['sub' => 'member-subject'], $session->get(MemberIdentityResolver::SESSION_KEY));
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => str_contains((string) $context['reason'], 'not signed'))
            ->once();
    }

    #[Test]
    public function an_unsigned_posted_logout_request_does_not_end_the_session(): void
    {
        $this->assertPostedLogoutRequestIsRefused(self::logoutRequestXml(), 'the message is not signed');
    }

    #[Test]
    public function a_posted_logout_request_signed_with_another_key_does_not_end_the_session(): void
    {
        [$otherCert, $otherKey] = self::selfSignedCertificate();

        $this->assertPostedLogoutRequestIsRefused(
            self::enveloped(self::logoutRequestXml(), $otherKey, $otherCert),
            'does not verify against the identity provider',
        );
    }

    #[Test]
    public function a_signed_logout_request_wrapped_inside_an_unsigned_one_does_not_end_the_session(): void
    {
        // Signature wrapping: a LogoutRequest the IdP really signed, nested inside an unsigned one naming
        // another member. The one signature verifies, but it is not on the root, so the whole document is
        // refused rather than the outer NameID being acted on.
        $this->assertPostedLogoutRequestIsRefused(
            self::wrappedInUnsignedLogoutRequest($this->signedByIdp(self::logoutRequestXml())),
            'the signature is not on the root element',
        );
    }

    #[Test]
    public function a_posted_logout_request_whose_signature_covers_only_an_inner_element_does_not_end_the_session(): void
    {
        // The other shape of wrapping: the signature sits on the root but its reference points at an inner
        // element, so the root, and the NameID on it, were never signed.
        $this->assertPostedLogoutRequestIsRefused(
            self::signedOverAnInnerElement(self::logoutRequestXml(), $this->idpKey, $this->idpCert),
            'does not reference the root element',
        );
    }

    #[Test]
    public function a_posted_logout_request_signed_with_sha1_does_not_end_the_session(): void
    {
        $this->assertPostedLogoutRequestIsRefused(
            $this->signedByIdp(self::logoutRequestXml(), XMLSecurityKey::RSA_SHA1),
            'rsa-sha1 is weaker than RSA with SHA-256',
        );
    }

    #[Test]
    public function a_posted_logout_request_with_a_sha1_digest_does_not_end_the_session(): void
    {
        $this->assertPostedLogoutRequestIsRefused(
            $this->signedByIdp(self::logoutRequestXml(), XMLSecurityKey::RSA_SHA256, XMLSecurityDSig::SHA1),
            'sha1 is weaker than SHA-256',
        );
    }

    #[Test]
    public function a_posted_logout_request_carrying_two_signatures_does_not_end_the_session(): void
    {
        $this->assertPostedLogoutRequestIsRefused(
            self::withTwoSignatures($this->signedByIdp(self::logoutRequestXml())),
            '2 signatures where one is allowed',
        );
    }

    #[Test]
    public function a_posted_logout_request_with_a_doctype_is_refused(): void
    {
        $this->assertPostedLogoutRequestIsRefused(
            self::withDoctype($this->signedByIdp(self::logoutRequestXml())),
            'DOCTYPE',
        );
    }

    #[Test]
    public function a_posted_logout_request_that_fails_the_library_checks_does_not_end_the_session(): void
    {
        // The enveloped signature verifies, but the message names another issuer. The library's own checks
        // still run after the signature, and refuse it.
        $this->assertPostedLogoutRequestIsRefused(
            $this->signedByIdp(self::logoutRequestXml(null, 'the-name-id', 'https://someone-else.example/saml')),
            'Invalid issuer',
        );
    }

    #[Test]
    public function a_bare_post_to_the_single_logout_service_does_not_end_the_session(): void
    {
        // The POST is exempt from the anti-forgery check, so any page can post here. With no SAML logout
        // message it changes nothing.
        $session = $this->newSession();
        $session->put(MemberIdentityResolver::SESSION_KEY, ['sub' => 'member-subject']);

        $response = $this->controller()->singleLogout($this->request($session, [], ['anything' => 'else']));

        $this->assertLandsOnTheLandingPage($response->getTargetUrl());
        self::assertSame(['sub' => 'member-subject'], $session->get(MemberIdentityResolver::SESSION_KEY));
    }

    #[Test]
    public function sign_out_against_an_idp_with_no_single_logout_still_ends_the_local_session(): void
    {
        // An IdP that publishes no single logout service leaves the local session ended and nothing more,
        // which is all this app can honestly do -- and it must not surface as an error to the member.
        [$idpCert] = self::selfSignedCertificate();
        $this->seedIdpMetadata([
            'idp' => [
                'entityId' => 'https://idp.example/saml',
                'singleSignOnService' => [
                    'url' => 'https://idp.example/sso',
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'x509cert' => $idpCert,
            ],
        ]);

        $session = $this->newSession();
        $session->put(MemberIdentityResolver::SESSION_KEY, ['sub' => 'member-subject']);

        $response = $this->controller()->signOut($this->request($session, [], ['_token' => 'the-token']));

        // The signed-out confirmation, ended locally -- not an IdP SLO redirect and not the failed-login
        // page. The same address every sign-out lands on, whichever protocol signed the member in.
        self::assertSame('/signed-out', $this->pathOf($response->getTargetUrl()));
        self::assertStringNotContainsString('/slo', $response->getTargetUrl());
        self::assertNull($session->get(MemberIdentityResolver::SESSION_KEY));
    }
}
