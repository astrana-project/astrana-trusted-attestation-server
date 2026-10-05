<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ConfigurationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Throwable;

/**
 * The hardening headers every response carries.
 *
 * Their whole justification is that the three implementations must not differ and that a mistyped header
 * name is a header that silently does nothing -- exactly the kind of thing that regresses when middleware
 * ordering changes and no test is watching. The conformance suite checks them from outside; this pins
 * them from within, on the one page that needs no session.
 */
final class SecurityHeadersTest extends TestCase
{
    #[Test]
    public function the_landing_page_carries_the_hardening_headers(): void
    {
        $response = $this->get('/');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'no-referrer');

        // Deliberately 0, not "1; mode=block": the legacy XSS auditor is a liability, not a protection,
        // and every one of the three switches it off explicitly.
        $response->assertHeader('X-XSS-Protection', '0');
    }

    #[Test]
    public function responses_are_not_cached(): void
    {
        // A member's page must not sit in a shared cache; no-store is what keeps it out of one.
        $cacheControl = (string) $this->get('/')->headers->get('Cache-Control');

        self::assertStringContainsString('no-store', $cacheControl);
    }

    /** @return iterable<string, array{Throwable, int}> */
    public static function bootTimeRefusals(): iterable
    {
        yield 'a request forwarded over plain HTTP' => [new HttpException(421, ''), 421];
        yield 'a configuration error' => [new ConfigurationException('The configured manifest is invalid.'), 500];
    }

    #[Test]
    #[DataProvider('bootTimeRefusals')]
    public function a_refusal_raised_before_any_middleware_runs_carries_the_headers_and_no_body_or_content_type(
        Throwable $refusal,
        int $status,
    ): void {
        // The service provider raises these while the application boots, so the kernel hands them straight
        // to the exception handler and no middleware ever sees the response. This is that same call.
        $defaultMimetype = ini_get('default_mimetype');

        try {
            $response = $this->app->make(ExceptionHandler::class)->render(Request::create('/'), $refusal);

            self::assertSame($status, $response->getStatusCode());
            self::assertSame('', $response->getContent());
            self::assertFalse($response->headers->has('Content-Type'));
            self::assertSame('', ini_get('default_mimetype'));
            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
            self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
            self::assertSame('0', $response->headers->get('X-XSS-Protection'));
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        } finally {
            ini_set('default_mimetype', (string) $defaultMimetype);
        }
    }
}
