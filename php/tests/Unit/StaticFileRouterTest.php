<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\StaticFileRouter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the built-in server's router may serve from public/.
 *
 * Joining the decoded request path onto public/ and asking is_file would accept "..", so a request for
 * /../.env would name a file outside public/. Every path that must name no file is pinned by value here,
 * against a real directory, because a traversal that works is invisible to any test that only requests the
 * stylesheet.
 */
final class StaticFileRouterTest extends TestCase
{
    private string $root;

    private string $public;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/ata-router-'.bin2hex(random_bytes(6));
        $this->public = $this->root.'/public';

        mkdir($this->public.'/nested', 0775, true);
        file_put_contents($this->public.'/trusted-attestation.css', 'body{}');
        file_put_contents($this->public.'/favicon.svg', '<svg/>');
        file_put_contents($this->public.'/nested/logo.png', 'png');
        file_put_contents($this->public.'/.htaccess', 'Require all denied');
        file_put_contents($this->public.'/robots.txt', 'User-agent: *');
        file_put_contents($this->public.'/favicon.ico', 'ico');
        file_put_contents($this->public.'/notes.unknown', 'x');
        file_put_contents($this->root.'/.env', 'APP_KEY=secret');
    }

    protected function tearDown(): void
    {
        foreach (['/public/nested/logo.png', '/public/trusted-attestation.css', '/public/favicon.svg', '/public/.htaccess',
            '/public/robots.txt', '/public/favicon.ico', '/public/notes.unknown', '/.env'] as $file) {
            @unlink($this->root.$file);
        }
        @rmdir($this->public.'/nested');
        @rmdir($this->public);
        @rmdir($this->root);
    }

    #[Test]
    public function a_file_under_public_is_served_from_its_real_path(): void
    {
        self::assertSame(realpath($this->public.'/trusted-attestation.css'), StaticFileRouter::file($this->public, '/trusted-attestation.css'));
        self::assertSame(realpath($this->public.'/nested/logo.png'), StaticFileRouter::file($this->public, '/nested/logo.png'));
    }

    #[Test]
    public function the_root_and_an_application_path_are_not_files(): void
    {
        self::assertNull(StaticFileRouter::file($this->public, '/'));
        self::assertNull(StaticFileRouter::file($this->public, '/me'));
        self::assertNull(StaticFileRouter::file($this->public, '/nested'));
    }

    /**
     * Decoded request paths, as server.php hands them over after urldecode: "..", "%2e%2e" and "..%2f" all
     * arrive as a ".." segment, and "%5c" as a backslash.
     *
     * @return iterable<string, array{string}>
     */
    public static function traversals(): iterable
    {
        yield 'plain ..' => ['/../.env'];
        yield '%2e%2e decoded' => ['/'.urldecode('%2e%2e').'/.env'];
        yield '..%2f decoded' => ['/'.urldecode('..%2f').'.env'];
        yield 'nested then up' => ['/nested/../../.env'];
        yield 'backslash separator' => ['/..\\.env'];
        yield 'a null byte' => ["/trusted-attestation.css\0.env"];
    }

    #[Test]
    #[DataProvider('traversals')]
    public function a_path_that_climbs_out_of_public_names_no_file(string $path): void
    {
        // It goes to the application instead, which answers 404.
        self::assertNull(StaticFileRouter::file($this->public, $path));
    }

    #[Test]
    public function a_path_that_climbs_back_into_public_still_names_no_file(): void
    {
        // The file exists and the path resolves inside public/, but a ".." segment is never served,
        // wherever it leads, as under .htaccess and web.config.
        self::assertNull(StaticFileRouter::file($this->public, '/nested/../trusted-attestation.css'));
    }

    #[Test]
    public function robots_txt_and_favicon_ico_name_no_file_even_when_a_file_of_that_name_exists(): void
    {
        // Laravel skeleton files the other two implementations do not serve, so a request for them goes to
        // the application and answers 404 on every stack.
        self::assertNull(StaticFileRouter::file($this->public, '/robots.txt'));
        self::assertNull(StaticFileRouter::file($this->public, '/favicon.ico'));
        self::assertNull(StaticFileRouter::file($this->public, '/Favicon.ICO'));
    }

    #[Test]
    public function a_segment_with_a_leading_dot_is_never_served_as_a_file(): void
    {
        // .htaccess stays private. The well-known manifest path carries a leading dot too, and it names no
        // file under public/ either, so it reaches the application, which serves it.
        self::assertNull(StaticFileRouter::file($this->public, '/.htaccess'));
        self::assertNull(StaticFileRouter::file($this->public, '/.well-known/ata-manifest.json'));
    }

    #[Test]
    public function the_content_type_comes_from_the_shipped_extensions_only(): void
    {
        self::assertSame('text/css', StaticFileRouter::contentType($this->public.'/trusted-attestation.css'));
        self::assertSame('image/svg+xml', StaticFileRouter::contentType($this->public.'/favicon.svg'));
        self::assertSame('image/png', StaticFileRouter::contentType($this->public.'/NESTED/LOGO.PNG'));
        self::assertNull(StaticFileRouter::contentType($this->public.'/notes.unknown'));
    }
}
