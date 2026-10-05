<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A body-less error carries no Content-Type, matching .NET and Java.
 *
 * The middleware has two halves: it removes the header from the response, and it empties the current
 * request's default_mimetype so PHP's SAPI does not stamp text/html back on at send time. Only the first
 * half is observable here -- the SAPI default is a real-HTTP behaviour the testing kernel does not run --
 * so the end-to-end "no Content-Type over the wire" is pinned by the differential suite
 * (shared/test/differential.py) and conformance. What this guards is the decision: strip on an empty-bodied error,
 * never on a response that carries data.
 */
final class StripErrorContentTypeTest extends TestCase
{
    #[Test]
    public function a_body_less_error_carries_no_content_type(): void
    {
        foreach (['/api/v1/nonsense', '/api/v1/me'] as $path) {
            $response = $this->get($path);

            self::assertGreaterThanOrEqual(400, $response->getStatusCode());
            self::assertSame('', $response->getContent());
            $response->assertHeaderMissing('Content-Type');
        }
    }

    #[Test]
    public function a_path_the_routers_never_serve_as_a_file_is_a_bare_404_with_the_security_headers(): void
    {
        // server.php, .htaccess and web.config hand these to the application rather than answering them
        // themselves, so the application's 404 is what a client sees on every deployment.
        foreach (['/robots.txt', '/favicon.ico', '/../.env', '/nested/../trusted-attestation.css', '/.htaccess'] as $path) {
            $response = $this->get($path);

            $response->assertNotFound();
            self::assertSame('', $response->getContent(), $path);
            $response->assertHeaderMissing('Content-Type');
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $response->assertHeader('X-Frame-Options', 'DENY');
            $response->assertHeader('Referrer-Policy', 'no-referrer');
            $response->assertHeader('X-XSS-Protection', '0');
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        }
    }

    #[Test]
    public function a_wrong_method_error_carries_no_content_type(): void
    {
        $response = $this->delete('/api/v1/attest');

        $response->assertStatus(405);
        self::assertSame('', $response->getContent());
        $response->assertHeaderMissing('Content-Type');
    }

    #[Test]
    public function a_response_that_carries_data_keeps_its_content_type(): void
    {
        // The strip is scoped to empty-bodied errors, so a real payload is untouched -- the manifest stays
        // application/json and the landing page stays text/html.
        $this->get('/.well-known/ata-manifest.json')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json');

        self::assertStringContainsString('text/html', (string) $this->get('/')->headers->get('Content-Type'));
    }
}
