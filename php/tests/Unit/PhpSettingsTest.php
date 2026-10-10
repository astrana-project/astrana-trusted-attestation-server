<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuiltInServer;

/**
 * The PHP settings the server runs with, from public/.user.ini and, in the container image, container.ini: an
 * error goes to the log and is never shown to whoever sent the request, no answer names PHP's version, and PHP
 * leaves every request body for the server to read.
 *
 * PHP with no php.ini of its own, as in the official images the container is built on, shows errors in the
 * page and adds an X-Powered-By header naming its version. Laravel turns both off once it starts, but a
 * warning PHP raises while it reads the request comes before any script runs, so only PHP's own settings
 * keep it out of the answer. PHP also reads a multipart body itself before any script runs, and keeps none
 * of it for the script. A request body over 64 kilobytes then reached the server as an empty one, and was
 * judged a key not on record rather than refused. The container image reads both files as part of its
 * php.ini. PHP under FastCGI (IIS, PHP-FPM) reads public/.user.ini from the public folder, and Apache's PHP
 * module reads the same settings from public/.htaccess. Only php.ini can stop PHP adding the header, so on a
 * hosting account public/.htaccess removes it under Apache and public/web.config empties it under IIS.
 */
final class PhpSettingsTest extends TestCase
{
    private const PHP = __DIR__.'/../..';

    private const USER_INI = self::PHP.'/public/.user.ini';

    private const CONTAINER_INI = self::PHP.'/container.ini';

    /** A page that answers how many query variables PHP kept, and nothing else. */
    private const QUERY_COUNT_PAGE = '<?php header("Content-Type: text/plain"); echo count($_GET);';

    /** A page that answers how many bytes of the request body it could read, and nothing else. */
    private const BODY_LENGTH_PAGE = '<?php header("Content-Type: text/plain"); echo strlen((string) file_get_contents("php://input"));';

    #[Test]
    public function a_warning_php_raises_while_reading_the_request_is_logged_and_not_shown(): void
    {
        [$head, $body, $log] = $this->answerToTooManyQueryVariables();

        self::assertMatchesRegularExpression('#^Content-Type: text/plain#mi', $head);
        self::assertSame('1000', $body);
        self::assertStringContainsString('Input variables exceeded 1000', $log);
    }

    #[Test]
    public function the_answer_to_a_request_php_warns_about_does_not_name_php(): void
    {
        [$head] = $this->answerToTooManyQueryVariables();

        self::assertDoesNotMatchRegularExpression('#^X-Powered-By:#mi', $head);
    }

    #[Test]
    public function a_multipart_body_is_left_for_the_server_to_read_whatever_its_framing(): void
    {
        $body = '{"public_key":"x"'.str_repeat(' ', 70_000).'}';
        $server = BuiltInServer::start(self::BODY_LENGTH_PAGE, self::USER_INI);

        try {
            $declared = $server->post('multipart/form-data; boundary=x', $body);
            $chunked = $server->post('multipart/form-data; boundary=x', $body, chunked: true);
        } finally {
            $server->stop();
        }

        self::assertStringEndsWith("\r\n\r\n".strlen($body), $declared);
        self::assertStringEndsWith("\r\n\r\n".strlen($body), $chunked);
    }

    #[Test]
    public function the_container_image_reads_the_same_settings(): void
    {
        $dockerfile = (string) file_get_contents(self::PHP.'/Dockerfile');

        self::assertMatchesRegularExpression('#^COPY php/public/\.user\.ini \$PHP_INI_DIR/conf\.d/\S+\.ini$#m', $dockerfile);
        self::assertMatchesRegularExpression('#^COPY php/container\.ini \$PHP_INI_DIR/conf\.d/\S+\.ini$#m', $dockerfile);
    }

    #[Test]
    public function apache_reads_the_same_settings_from_htaccess(): void
    {
        preg_match_all('/^\s*([a-z_]+)\s*=\s*(\S+)\s*$/m', (string) file_get_contents(self::USER_INI), $ini, PREG_SET_ORDER);
        preg_match('#<IfModule mod_php\.c>(.*?)</IfModule>#s', (string) file_get_contents(self::PHP.'/public/.htaccess'), $block);
        preg_match_all('/^\s*php_flag\s+([a-z_]+)\s+(\S+)\s*$/m', $block[1] ?? '', $htaccess, PREG_SET_ORDER);

        $settings = static fn (array $matches): array => array_combine(
            array_column($matches, 1),
            array_map(strtolower(...), array_column($matches, 2)),
        );

        self::assertNotEmpty($ini);
        self::assertSame($settings($ini), $settings($htaccess));
    }

    #[Test]
    public function a_hosting_account_keeps_the_version_out_of_the_header_php_adds(): void
    {
        preg_match('#<IfModule mod_headers\.c>(.*?)</IfModule>#s', (string) file_get_contents(self::PHP.'/public/.htaccess'), $headers);
        self::assertMatchesRegularExpression('#^\s*Header always unset X-Powered-By\s*$#m', $headers[1] ?? '');

        self::assertMatchesRegularExpression(
            '#<match serverVariable="RESPONSE_X_Powered_By" pattern="\.\+" />\s*<action type="Rewrite" value="" />#',
            (string) file_get_contents(self::PHP.'/public/web.config'),
        );
    }

    /**
     * Sends more query variables than PHP keeps (max_input_vars, 1,000 by default), which makes PHP warn
     * before the page runs, to a server with the container image's settings, and answers the head, the body
     * and the server's log. With errors shown, the warning leads the answer, and the page's own header can
     * no longer be sent.
     *
     * @return array{string, string, string}
     */
    private function answerToTooManyQueryVariables(): array
    {
        $query = http_build_query(array_fill_keys(array_map(static fn (int $n): string => "v{$n}", range(1, 1001)), '1'));
        $server = BuiltInServer::start(self::QUERY_COUNT_PAGE, self::USER_INI, self::CONTAINER_INI);

        try {
            $answer = $server->request('GET', '/?'.$query);
            $log = $server->log();
        } finally {
            $server->stop();
        }

        [$head, $body] = explode("\r\n\r\n", $answer, 2);

        return [$head, $body, $log];
    }
}
