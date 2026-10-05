<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\PreventRequestForgeryUnlessSignedOut;
use App\Services\MemberIdentityResolver;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\BuildsSamlLogoutMessages;
use Tests\TestCase;

/**
 * How sign-out is routed on a SAML deployment, so a cross-site page cannot sign a member out.
 *
 * The member's own sign-out is the POST /signout every deployment shares, and it is not exempt from the
 * CSRF check. The single logout service the IdP sends its messages to answers a GET for the HTTP-Redirect
 * binding and a POST for the HTTP-POST binding. The POST is exempt from the CSRF check, because the IdP
 * posts from its own site, and that is safe only because the service leaves the session alone unless the
 * message carries a signature that verifies, which is what the last test here shows through the whole
 * middleware stack. SamlControllerTest covers everything else the controller does with a logout message.
 *
 * A Feature test because it asks the router and the middleware what is configured, which means the
 * application has to have booted with the SAML routes registered.
 */
final class SamlSignOutRouteTest extends TestCase
{
    use BuildsSamlLogoutMessages;

    private const IDP_METADATA_URL = 'https://idp.example/metadata';

    protected function setUp(): void
    {
        // Set before the application boots: routes are registered during boot.
        putenv('TRUSTED_ATTESTATION_IAM_PROTOCOL=saml');
        $_ENV['TRUSTED_ATTESTATION_IAM_PROTOCOL'] = 'saml';
        $_GET = [];

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('TRUSTED_ATTESTATION_IAM_PROTOCOL');
        unset($_ENV['TRUSTED_ATTESTATION_IAM_PROTOCOL']);

        parent::tearDown();
    }

    #[Test]
    public function the_member_sign_out_is_under_the_csrf_check_and_the_single_logout_service_is_exempt(): void
    {
        $excluded = $this->app->make(PreventRequestForgery::class)->getExcludedPaths();

        self::assertNotContains('signout', $excluded);
        self::assertContains('auth/saml/logout', $excluded);
    }

    #[Test]
    public function a_post_to_the_single_logout_service_needs_no_token_while_the_member_sign_out_still_does(): void
    {
        // The testing kernel skips the token check outright, so the rule is read from the middleware's own
        // decision, with a member signed in both times. The IdP's POST to the single logout service is
        // exempt. The member's own sign-out is not, so a cross-site page still cannot sign a member out.
        $middleware = $this->app->make(PreventRequestForgeryUnlessSignedOut::class);
        $exempt = fn (Request $request): bool => (new \ReflectionMethod($middleware, 'inExceptArray'))
            ->invoke($middleware, $request);
        $signedInPost = function (string $path): Request {
            $session = new Store('ata_session', new ArraySessionHandler(60));
            $session->put(MemberIdentityResolver::SESSION_KEY, ['sub' => 'member-subject']);
            $request = Request::create($path, 'POST');
            $request->setLaravelSession($session);

            return $request;
        };

        self::assertTrue($exempt($signedInPost('/auth/saml/logout')));
        self::assertFalse($exempt($signedInPost('/signout')));
    }

    #[Test]
    public function a_bare_post_to_the_single_logout_service_leaves_the_member_signed_in(): void
    {
        // The route answers a POST now, and with no logout message in it the controller changes nothing.
        self::assertSame('saml', config('trusted_attestation.iam.protocol'));

        $response = $this->withSession([MemberIdentityResolver::SESSION_KEY => ['sub' => 'member-subject']])
            ->post('/auth/saml/logout');

        $response->assertRedirect('/');
        $response->assertSessionHas(MemberIdentityResolver::SESSION_KEY, ['sub' => 'member-subject']);
    }

    #[Test]
    public function a_logout_request_the_idp_posts_ends_the_session_through_the_whole_stack(): void
    {
        // The IdP's cross-site POST, as the browser delivers it: no CSRF token, the message in the form body,
        // through every middleware in the web group. Only the signature lets it end the session.
        [$idpCert, $idpKey] = self::selfSignedCertificate();
        config(['trusted_attestation.iam.saml.idp_metadata_url' => self::IDP_METADATA_URL]);
        Cache::put('trusted_attestation.saml.idp-metadata.'.hash('sha256', self::IDP_METADATA_URL), [
            'idp' => [
                'entityId' => self::IDP_ENTITY_ID,
                'singleSignOnService' => ['url' => 'https://idp.example/sso'],
                'singleLogoutService' => ['url' => 'https://idp.example/slo'],
                'x509cert' => $idpCert,
            ],
        ], 3600);

        $response = $this->withSession([MemberIdentityResolver::SESSION_KEY => ['sub' => 'member-subject']])
            ->post('/auth/saml/logout', [
                'SAMLRequest' => self::posted(self::enveloped(self::logoutRequestXml(), $idpKey, $idpCert)),
                'RelayState' => 'the-relay-state',
            ]);

        self::assertSame(302, $response->getStatusCode());
        self::assertStringStartsWith('https://idp.example/slo?', (string) $response->headers->get('Location'));
        $response->assertSessionMissing(MemberIdentityResolver::SESSION_KEY);
    }

    #[Test]
    public function a_bare_get_to_the_single_logout_service_leaves_the_member_signed_in(): void
    {
        $response = $this->withSession([MemberIdentityResolver::SESSION_KEY => ['sub' => 'member-subject']])
            ->get('/auth/saml/logout');

        $response->assertRedirect('/');
        $response->assertSessionHas(MemberIdentityResolver::SESSION_KEY, ['sub' => 'member-subject']);
    }

    #[Test]
    public function the_service_provider_metadata_sets_no_cookie(): void
    {
        // An identity provider administrator, or their tooling, reads the metadata anonymously. Like the
        // manifest it is stateless, so it carries no session cookie and no CSRF cookie. A persistent
        // session driver is configured so that a session cookie would be written if it were not.
        config(['session.driver' => 'file', 'app.url' => 'https://ata.example']);

        $response = $this->get('/auth/saml/metadata');

        $response->assertOk();
        self::assertSame([], $response->headers->getCookies());
    }
}
