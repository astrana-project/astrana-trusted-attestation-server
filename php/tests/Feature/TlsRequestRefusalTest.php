<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Providers\TrustedAttestationServiceProvider;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Which requests the service provider refuses while the application boots, and how the refusal looks.
 *
 * The provider skips these checks for console commands, and the test runner is one, so the check is
 * called directly with the request bound in the container, as it is under a web server. The refusal is
 * then rendered by the same exception handler a web request reaches.
 */
final class TlsRequestRefusalTest extends TestCase
{
    /** @param array<string, string> $server */
    private function boot(bool $terminatedByProxy, string $url, array $server = []): void
    {
        config([
            'app.url' => 'https://ata.example',
            'trusted_attestation.tls.terminated_by_proxy' => $terminatedByProxy,
        ]);
        $this->app->instance('request', Request::create($url, 'GET', [], [], [], $server));

        $provider = new TrustedAttestationServiceProvider($this->app);
        (new ReflectionMethod($provider, 'enforceTls'))->invoke($provider);
    }

    /** @return iterable<string, array{bool, string, array<string, string>}> */
    public static function servedRequests(): iterable
    {
        yield 'no proxy declared, request over TLS' => [false, 'https://ata.example/', []];
        yield 'proxy declared, forwarded as https' => [true, 'http://ata.example/', ['HTTP_X_FORWARDED_PROTO' => 'https']];
        yield 'proxy declared, no forwarded header' => [true, 'http://ata.example/', []];
    }

    /** @param array<string, string> $server */
    #[Test]
    #[DataProvider('servedRequests')]
    public function a_request_that_arrived_over_tls_or_through_the_declared_proxy_is_served(
        bool $terminatedByProxy,
        string $url,
        array $server,
    ): void {
        $this->boot($terminatedByProxy, $url, $server);

        $this->expectNotToPerformAssertions();
    }

    /** @return iterable<string, array{bool, string, array<string, string>}> */
    public static function refusedRequests(): iterable
    {
        yield 'no proxy declared, request over plain HTTP' => [false, 'http://ata.example/', []];
        yield 'proxy declared, forwarded as http' => [true, 'http://ata.example/', ['HTTP_X_FORWARDED_PROTO' => 'http']];
    }

    /** @param array<string, string> $server */
    #[Test]
    #[DataProvider('refusedRequests')]
    public function a_request_that_travelled_over_plain_http_is_refused_with_421_the_headers_and_nothing_else(
        bool $terminatedByProxy,
        string $url,
        array $server,
    ): void {
        $defaultMimetype = ini_get('default_mimetype');

        try {
            $this->boot($terminatedByProxy, $url, $server);
            self::fail('The request was not refused.');
        } catch (HttpException $refusal) {
            $response = $this->app->make(ExceptionHandler::class)->render($this->app['request'], $refusal);
        } finally {
            ini_set('default_mimetype', (string) $defaultMimetype);
        }

        self::assertSame(421, $response->getStatusCode());
        self::assertSame('', $response->getContent());
        self::assertFalse($response->headers->has('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame('0', $response->headers->get('X-XSS-Protection'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
