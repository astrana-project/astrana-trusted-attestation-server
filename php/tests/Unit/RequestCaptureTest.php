<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Middleware\LimitRequestBody;
use App\Support\RequestCapture;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\BuiltInServer;

/**
 * The request the front controller (public/index.php) hands to Laravel, with its body read once and never
 * more than one byte past the API's 64 kilobyte cap.
 *
 * Laravel reads the whole body while it builds the request, before any middleware runs, so a body of
 * hundreds of megabytes used up PHP's memory there and failed rather than being refused by
 * LimitRequestBody. The tests through PHP's built-in server show what only a real request shows: a body PHP
 * leaves unread (public/.user.ini), however it is sent, and a form posted to a page, which PHP parses whole.
 */
final class RequestCaptureTest extends TestCase
{
    private const USER_INI = __DIR__.'/../../public/.user.ini';

    /** A page that captures the request as the front controller does and answers what it holds. */
    private const CAPTURE_PAGE = <<<'PHP'
        <?php
        require %s;
        $request = App\Support\RequestCapture::capture();
        header('Content-Type: application/json');
        echo json_encode([
            'body' => strlen($request->getContent()),
            'key' => $request->json('public_key'),
            'form' => array_map(strlen(...), $request->request->all()),
            'memory' => memory_get_peak_usage(),
        ]);
        PHP;

    /** @var array<string, mixed> */
    private array $server;

    private string $file;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->file = (string) tempnam(sys_get_temp_dir(), 'body');
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        unlink($this->file);
    }

    private function capture(string $method, string $contentType, string $body): Request
    {
        file_put_contents($this->file, $body);
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['CONTENT_TYPE'] = $contentType;

        return RequestCapture::capture($this->file);
    }

    #[Test]
    public function a_body_is_read_no_further_than_one_byte_past_the_cap(): void
    {
        $request = $this->capture('POST', 'application/json', str_repeat(' ', 3 * LimitRequestBody::MAX_BYTES));

        self::assertSame(LimitRequestBody::MAX_BYTES + 1, strlen($request->getContent()));
    }

    #[Test]
    public function a_body_within_the_cap_is_read_whole_and_as_json(): void
    {
        $request = $this->capture('PUT', 'application/x-www-form-urlencoded', '{"public_key":"a+b/c="}');

        self::assertSame('{"public_key":"a+b/c="}', $request->getContent());
        self::assertSame('a+b/c=', $request->json('public_key'));
    }

    #[Test]
    public function a_request_made_afterwards_reads_no_body_from_the_capture(): void
    {
        $this->capture('POST', 'application/json', '{"public_key":"x"}');

        self::assertSame('', Request::create('/', 'POST')->getContent());
    }

    /** Starts PHP's built-in server with the server's PHP settings, serving the capture page. */
    private static function server(): BuiltInServer
    {
        $autoload = var_export((string) realpath(__DIR__.'/../../vendor/autoload.php'), true);

        return BuiltInServer::start(sprintf(self::CAPTURE_PAGE, $autoload), self::USER_INI);
    }

    /** @return array{body: int, key: mixed, form: array<string, int>, memory: int} */
    private static function captured(string $answer): array
    {
        [, $body] = explode("\r\n\r\n", $answer, 2);

        return json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function a_body_php_leaves_unread_is_capped_however_it_is_sent(): void
    {
        $body = '{"public_key":"x"'.str_repeat(' ', 70_000).'}';
        $large = '{"public_key":"x"'.str_repeat(' ', 20 * 1024 * 1024).'}';
        $server = self::server();

        try {
            $multipart = self::captured($server->post('multipart/form-data; boundary=x', $body, chunked: true));
            $chunked = self::captured($server->post('application/json', $large, chunked: true));
            $declared = self::captured($server->post('application/json', $large));
        } finally {
            $server->stop();
        }

        self::assertSame(LimitRequestBody::MAX_BYTES + 1, $multipart['body']);
        self::assertSame(LimitRequestBody::MAX_BYTES + 1, $chunked['body']);
        self::assertSame(LimitRequestBody::MAX_BYTES + 1, $declared['body']);
        self::assertLessThan(8 * 1024 * 1024, max($chunked['memory'], $declared['memory']));
    }

    #[Test]
    public function a_json_body_is_read_whatever_content_type_it_is_sent_under(): void
    {
        $server = self::server();

        try {
            $put = self::captured($server->request('PUT', '/', 'application/x-www-form-urlencoded', '{"public_key":"a+b/c="}'));
            $post = self::captured($server->request('POST', '/', 'text/plain', '{"public_key":"a+b/c="}'));
            $form = self::captured($server->request('POST', '/', 'application/x-www-form-urlencoded', '{"public_key":"a+b/c="}'));
            $multipart = self::captured($server->request('POST', '/', 'multipart/form-data; boundary=x', '{"public_key":"a+b/c="}', chunked: true));
        } finally {
            $server->stop();
        }

        foreach ([$put, $post, $form, $multipart] as $captured) {
            self::assertSame('a+b/c=', $captured['key']);
        }
    }

    #[Test]
    public function a_form_posted_to_a_page_is_parsed_whole_however_large(): void
    {
        // The cap is the API's alone. A SAML assertion an identity provider posts can be larger, and PHP
        // parses a posted form whole, up to its own post_max_size, as it always has.
        $server = self::server();

        try {
            $captured = self::captured($server->post('application/x-www-form-urlencoded', 'locale=fr&padding='.str_repeat('a', 100_000)));
        } finally {
            $server->stop();
        }

        self::assertSame(['locale' => 2, 'padding' => 100_000], $captured['form']);
        self::assertSame(LimitRequestBody::MAX_BYTES + 1, $captured['body']);
    }
}
