<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * The language switcher's POST, end to end.
 *
 * What the other two implementations do here is pinned to the attribute: the cookie is named ata_locale,
 * carries the bare locale tag, lives a year, is HttpOnly, Secure and SameSite=Lax on the whole site, and is
 * only ever set for a locale this instance offers. The redirect goes back to the page the member was on and
 * nowhere off this origin. The route needs no CSRF token and no session, because the public landing page
 * carries neither.
 *
 * A Feature test because it drives the router and the middleware stack. It touches no database.
 */
final class SetLanguageTest extends TestCase
{
    private function localeCookie(TestResponse $response): ?Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === 'ata_locale') {
                return $cookie;
            }
        }

        return null;
    }

    #[Test]
    public function an_offered_locale_is_stored_in_a_plain_cookie_with_the_agreed_attributes(): void
    {
        $response = $this->post('/set-language', ['locale' => 'fr', 'next' => '/me']);

        $response->assertRedirect('/me');

        $cookie = $this->localeCookie($response);
        self::assertNotNull($cookie, 'no ata_locale cookie was set');
        // The bare tag, not an encrypted blob: every implementation reads the same value.
        self::assertSame('fr', $cookie->getValue());
        self::assertTrue($cookie->isHttpOnly());
        self::assertTrue($cookie->isSecure());
        self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
        self::assertSame('/', $cookie->getPath());
        self::assertNull($cookie->getDomain());
        // One year, give or take the second the request took.
        self::assertEqualsWithDelta(365 * 24 * 60 * 60, $cookie->getMaxAge(), 5);
    }

    #[Test]
    public function the_cookie_holds_the_shipped_spelling_not_the_posted_one(): void
    {
        // The other two implementations store the offered locale as the strings file spells it.
        self::assertSame('fr', $this->localeCookie($this->post('/set-language', ['locale' => 'FR', 'next' => '/']))?->getValue());
        self::assertSame('zh-Hant', $this->localeCookie($this->post('/set-language', ['locale' => 'ZH-hant', 'next' => '/']))?->getValue());
    }

    #[Test]
    public function the_redirect_is_relative_as_the_other_implementations_answer(): void
    {
        $response = $this->post('/set-language', ['locale' => 'fr', 'next' => '/me?x=1']);

        self::assertSame('/me?x=1', $response->headers->get('Location'));
    }

    #[Test]
    public function a_locale_this_instance_does_not_offer_sets_no_cookie_but_still_returns_to_the_page(): void
    {
        config(['trusted_attestation.manifest.supported_locales' => ['en', 'fr']]);

        $response = $this->post('/set-language', ['locale' => 'de', 'next' => '/me']);

        $response->assertRedirect('/me');
        self::assertNull($this->localeCookie($response), 'a cookie was set for a locale not offered');
    }

    #[Test]
    public function an_unshipped_or_missing_locale_sets_no_cookie(): void
    {
        self::assertNull($this->localeCookie($this->post('/set-language', ['locale' => 'cy', 'next' => '/'])));
        self::assertNull($this->localeCookie($this->post('/set-language', ['next' => '/'])));
    }

    #[Test]
    public function a_region_tag_is_not_stored_because_it_is_not_what_the_switcher_offers(): void
    {
        // The switcher only ever posts an offered tag. Anything else is a hand-made request, and the
        // cookie is validated on write exactly as the other two implementations validate it.
        self::assertNull($this->localeCookie($this->post('/set-language', ['locale' => 'fr-CA', 'next' => '/'])));
    }

    public static function unsafeReturnPaths(): iterable
    {
        yield 'protocol-relative' => ['//evil.example/'];
        yield 'backslash' => ['/\\evil.example'];
        yield 'backslash later in the path' => ['/me\\x'];
        yield 'absolute' => ['https://evil.example/'];
        yield 'relative without slash' => ['me'];
        yield 'empty' => [''];
        yield 'a tab' => ["/me\t"];
        yield 'a line feed' => ["/me\nX: y"];
        yield 'DEL' => ["/me\x7F"];
    }

    #[Test]
    #[DataProvider('unsafeReturnPaths')]
    public function a_return_path_that_could_leave_this_origin_lands_on_the_landing_page(string $next): void
    {
        $response = $this->post('/set-language', ['locale' => 'fr', 'next' => $next]);

        self::assertSame('/', $response->headers->get('Location'));
        // The choice itself is still honoured: a bad return path is not a reason to drop the language.
        self::assertNotNull($this->localeCookie($response));
    }

    #[Test]
    public function no_return_path_at_all_lands_on_the_landing_page(): void
    {
        self::assertSame('/', $this->post('/set-language', ['locale' => 'fr'])->headers->get('Location'));
    }

    #[Test]
    public function a_padded_locale_is_refused_as_it_is_on_the_other_implementations(): void
    {
        // The framework's input trimming leaves this route alone (bootstrap/app.php), so " fr" is matched
        // as posted and sets nothing, where trimmed it would have been accepted here and refused there.
        $response = $this->post('/set-language', ['locale' => ' fr', 'next' => '/']);

        $response->assertRedirect('/');
        self::assertNull($this->localeCookie($response));
    }

    #[Test]
    public function the_query_string_is_not_read(): void
    {
        // Only the posted form. A locale or a return path in the query string is ignored, so a link cannot
        // plant a cookie or choose where the member lands.
        $response = $this->post('/set-language?locale=fr&next=/me');

        $response->assertRedirect('/');
        self::assertNull($this->localeCookie($response));
    }

    #[Test]
    public function a_json_body_is_not_a_form_and_lands_on_the_landing_page_with_no_cookie(): void
    {
        $response = $this->postJson('/set-language', ['locale' => 'fr', 'next' => '/me']);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/', $response->headers->get('Location'));
        self::assertNull($this->localeCookie($response));
    }

    #[Test]
    public function an_empty_post_is_the_same_redirect_not_an_error(): void
    {
        $response = $this->call('POST', '/set-language', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], '');

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/', $response->headers->get('Location'));
        self::assertNull($this->localeCookie($response));
    }

    #[Test]
    public function a_locale_or_return_path_posted_as_an_array_is_the_same_redirect_not_an_error(): void
    {
        // The input bag's get() throws on an array value, which would turn a malformed form into an error
        // page. The raw form is read instead, so this is the ordinary fallback.
        $response = $this->post('/set-language', ['locale' => ['fr'], 'next' => ['/me']]);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/', $response->headers->get('Location'));
        self::assertNull($this->localeCookie($response));
    }

    #[Test]
    public function the_route_needs_no_csrf_token(): void
    {
        // The testing kernel skips token verification outright, so the exemption is read from the
        // middleware's own excluded list rather than observed through a 419.
        $excluded = $this->app->make(PreventRequestForgery::class)->getExcludedPaths();

        self::assertContains('set-language', $excluded);
    }

    #[Test]
    public function the_locale_cookie_is_exempt_from_encryption(): void
    {
        // A plain value is what the shared suite and the other implementations read back. Encrypted, the
        // same cookie would be an opaque blob to them.
        self::assertTrue($this->app->make(EncryptCookies::class)->isDisabled('ata_locale'));
    }

    #[Test]
    public function the_response_carries_no_session_cookie(): void
    {
        // Exactly one cookie, the locale, as the other two implementations answer. The anonymous landing
        // page has no session, and a signed-in member keeps the one they already hold.
        $response = $this->post('/set-language', ['locale' => 'fr', 'next' => '/']);

        $names = array_map(static fn (Cookie $c): string => $c->getName(), $response->headers->getCookies());
        self::assertSame(['ata_locale'], $names);
    }
}
