<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Security\TlsRequirement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The rule this enforces is that there is no way to end up serving plain HTTP by accident, so the tests
 * that matter are the ones where something looks configured but is not.
 *
 * The case that matters most is an explicit opt-out, which slips past a check that only looks for the
 * presence of key material.
 */
final class TlsRequirementTest extends TestCase
{
    #[Test]
    public function an_https_application_url_is_accepted(): void
    {
        TlsRequirement::enforce(false, 'https://ata.example');

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function an_http_application_url_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Refusing to serve without TLS/');

        TlsRequirement::enforce(false, 'http://ata.example');
    }

    #[Test]
    public function an_empty_application_url_is_refused(): void
    {
        // An unconfigured instance is the case this exists for: nothing set, nothing serving TLS, and
        // no default that quietly allows it.
        $this->expectException(RuntimeException::class);

        TlsRequirement::enforce(false, '');
    }

    #[Test]
    public function the_scheme_is_matched_without_regard_to_case(): void
    {
        TlsRequirement::enforce(false, 'HTTPS://ata.example');

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function a_proxy_terminating_tls_is_accepted_but_only_when_said_explicitly(): void
    {
        // The same URL refused above becomes acceptable once the operator states that something in
        // front terminates TLS. Nothing infers this; it has to be declared.
        TlsRequirement::enforce(true, 'http://ata.example');

        $this->expectNotToPerformAssertions();
    }

    /** @return iterable<string, array{bool, bool, bool}> */
    public static function plainRequests(): iterable
    {
        yield 'no proxy declared, request over TLS' => [false, true, false];
        yield 'no proxy declared, request over plain HTTP' => [false, false, true];

        // Behind a declared proxy the connection the application sees is the proxy's, so its scheme says
        // nothing. The forwarded header is judged instead.
        yield 'proxy declared, connection over plain HTTP' => [true, false, false];
        yield 'proxy declared, connection over TLS' => [true, true, false];
    }

    #[Test]
    #[DataProvider('plainRequests')]
    public function without_a_proxy_a_request_that_did_not_arrive_over_tls_is_refused(
        bool $terminatedByProxy,
        bool $arrivedOverTls,
        bool $expected
    ): void {
        self::assertSame($expected, TlsRequirement::refusesPlainRequest($terminatedByProxy, $arrivedOverTls));
    }

    /**
     * The header as a list of lines, which is how the request carries it.
     *
     * @return iterable<string, array{bool, list<string>, bool}>
     */
    public static function forwardedRequests(): iterable
    {
        yield 'proxy says https' => [true, ['https'], false];
        yield 'proxy says https with the spaces the framework trims' => [true, [' https '], false];
        yield 'proxy says http' => [true, ['http'], true];
        yield 'proxy says HTTPS in capitals, which is not the exact token' => [true, ['HTTPS'], true];
        yield 'proxy sends an empty header, which the framework applies as not https' => [true, [''], true];
        yield 'proxy says nothing' => [true, [], false];

        // More than one value is refused whatever the values are: as two tokens on one line, which a
        // client can produce by sending its own header ahead of the proxy's, or as two lines.
        yield 'two tokens, https first' => [true, ['https, http'], true];
        yield 'two tokens, https last' => [true, ['http,https'], true];
        yield 'two tokens, both https' => [true, ['https,https'], true];
        yield 'two lines, both https' => [true, ['https', 'https'], true];
        yield 'two lines, https then http' => [true, ['https', 'http'], true];

        // With no proxy declared, the forwarded header is not trusted for anything, so it cannot be
        // used to refuse a request either. The configuration check is what guards that deployment.
        yield 'no proxy declared, header says http' => [false, ['http'], false];
        yield 'no proxy declared, two lines' => [false, ['https', 'http'], false];
    }

    /** @param list<string> $forwardedProtoLines */
    #[Test]
    #[DataProvider('forwardedRequests')]
    public function it_accepts_exactly_one_value_of_exactly_https_and_refuses_anything_else_the_proxy_sends(
        bool $terminatedByProxy,
        array $forwardedProtoLines,
        bool $expected
    ): void {
        self::assertSame(
            $expected,
            TlsRequirement::refusesForwardedRequest($terminatedByProxy, $forwardedProtoLines)
        );
    }
}
