<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ApplicationPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApplicationPathTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function absolutePaths(): array
    {
        return [
            'Linux' => ['/etc/ata/sp.key'],
            'Windows drive with a backslash' => ['C:\\keys\\sp.key'],
            'Windows drive with a slash' => ['d:/keys/sp.key'],
            'network share' => ['\\\\fileserver\\keys\\sp.key'],
            'root of the current drive' => ['\\keys\\sp.key'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function relativePaths(): array
    {
        return [
            'plain' => ['saml/sp.key'],
            'Windows separators' => ['saml\\sp.key'],
            'a drive letter with no separator' => ['C:sp.key'],
            'a colon later in the name' => ['saml/a:b.key'],
        ];
    }

    #[Test]
    #[DataProvider('absolutePaths')]
    public function an_absolute_path_is_used_as_it_is(string $path): void
    {
        $this->assertTrue(ApplicationPath::isAbsolute($path));
        $this->assertSame($path, ApplicationPath::resolve($path, '/var/www/ata'));
    }

    #[Test]
    #[DataProvider('relativePaths')]
    public function a_relative_path_is_read_from_the_application_folder(string $path): void
    {
        $this->assertFalse(ApplicationPath::isAbsolute($path));
        $this->assertSame(
            '/var/www/ata'.DIRECTORY_SEPARATOR.$path,
            ApplicationPath::resolve($path, '/var/www/ata/'),
        );
    }

    #[Test]
    public function a_leading_dot_segment_stays_relative(): void
    {
        $this->assertSame(
            '/var/www/ata'.DIRECTORY_SEPARATOR.'./logo.svg',
            ApplicationPath::resolve('./logo.svg', '/var/www/ata'),
        );
    }
}
