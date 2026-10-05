<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The step that writes THIRD-PARTY-NOTICES.txt, which the licence page links to.
 *
 * scripts/generate-third-party-notices.php runs after every Composer install and again while the container
 * image is built. It writes the notices of the components bundled into the shared inputs (Bootstrap, in the
 * stylesheet), then, for every runtime Composer package in the lock file, the package's licence file as the
 * package ships it, and, in the image, for every Debian package the image's install layer added, that
 * package's copyright and licence files. Notices are a condition of most of these licences, so a package
 * with nothing to show stops the build instead of shipping incomplete notices. Run here against a small
 * vendor tree in a temporary folder.
 */
final class ThirdPartyNoticesTest extends TestCase
{
    private const SCRIPT = __DIR__.'/../../scripts/generate-third-party-notices.php';

    private string $folder;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/ata-notices-'.bin2hex(random_bytes(6));
        $this->write('attribution.json', json_encode([
            'project_name' => 'Astrana Trusted Attestation Server',
            'license' => ['notice' => 'Copyright (c) 2026 Darin Morris and contributors. Free and open source software, licensed under the MIT License.'],
        ]));
        $this->write('bundled/bootstrap.txt', "Bootstrap 5.3.8\nLicence: MIT\n\nThe MIT License (MIT)\n");
        $this->write('composer.lock', json_encode([
            'packages' => [
                ['name' => 'acme/rocket', 'version' => 'v2.1.0', 'license' => ['MIT'], 'source' => ['url' => 'https://github.com/acme/rocket.git']],
                ['name' => 'nette/utils', 'version' => 'v4.1.5', 'license' => ['BSD-3-Clause', 'GPL-2.0-only']],
            ],
            'packages-dev' => [
                ['name' => 'acme/test-only', 'version' => '1.0.0', 'license' => ['MIT']],
            ],
        ]));
        $this->write('vendor/acme/rocket/LICENSE', "Copyright (c) 2024 Acme Rockets\n\nPermission is hereby granted, rocket.\n");
        $this->write('vendor/nette/utils/license.md', "New BSD License\n\nCopyright (c) 2004, 2014 David Grudl\n");
        $this->write('vendor/acme/test-only/LICENSE', "Copyright (c) Test Only\n");
        $this->write('doc/libpq5/copyright', "Files: *\nCopyright: 1996-2025 The PostgreSQL Global Development Group\nLicense: PostgreSQL\n");
        $this->write('doc/msodbcsql18/LICENSE.txt', "MICROSOFT SOFTWARE LICENSE TERMS\n");
        $this->write('doc/msodbcsql18/RELEASE_NOTES', "Not a licence.\n");
        $this->write('debian-packages', "libpq5\n");
    }

    protected function tearDown(): void
    {
        $remove = static function (string $path) use (&$remove): void {
            if (is_dir($path)) {
                array_map($remove, glob($path.'/*') ?: []);
                rmdir($path);
            } else {
                unlink($path);
            }
        };
        $remove($this->folder);
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->folder.'/'.$relative;
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);
    }

    /** @return array{int, string} the exit code and the notices written, empty when none were */
    private function generate(string ...$extra): array
    {
        $options = [
            '--lock='.$this->folder.'/composer.lock',
            '--vendor='.$this->folder.'/vendor',
            '--attribution='.$this->folder.'/attribution.json',
            '--bundled='.$this->folder.'/bundled',
            '--doc-root='.$this->folder.'/doc',
            '--output='.$this->folder.'/THIRD-PARTY-NOTICES.txt',
            ...$extra,
        ];
        exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg(self::SCRIPT).' '.implode(' ', array_map('escapeshellarg', $options)).' 2>&1',
            $output,
            $exitCode
        );
        $written = $this->folder.'/THIRD-PARTY-NOTICES.txt';

        return [$exitCode, is_file($written) ? (string) file_get_contents($written) : ''];
    }

    #[Test]
    public function every_runtime_composer_package_is_listed_with_its_version_licence_and_licence_text(): void
    {
        [$exitCode, $notices] = $this->generate();

        self::assertSame(0, $exitCode);
        self::assertStringContainsString("acme/rocket v2.1.0\nLicence: MIT\nSource: https://github.com/acme/rocket.git", $notices);
        self::assertStringContainsString("Copyright (c) 2024 Acme Rockets\n\nPermission is hereby granted, rocket.", $notices);
        self::assertStringContainsString("nette/utils v4.1.5\nLicence: BSD-3-Clause or GPL-2.0-only", $notices);
        self::assertStringContainsString('Copyright (c) 2004, 2014 David Grudl', $notices);
    }

    #[Test]
    public function development_packages_are_left_out(): void
    {
        $notices = $this->generate()[1];

        self::assertStringNotContainsString('acme/test-only', $notices);
        self::assertStringNotContainsString('Test Only', $notices);
    }

    #[Test]
    public function the_notices_open_with_the_project_name_and_its_own_copyright_line(): void
    {
        $notices = $this->generate()[1];

        self::assertStringStartsWith(
            "Astrana Trusted Attestation Server, PHP implementation\nCopyright (c) 2026 Darin Morris and contributors.",
            $notices
        );
    }

    #[Test]
    public function the_components_bundled_into_the_shared_inputs_come_before_the_composer_packages(): void
    {
        $notices = $this->generate()[1];

        self::assertStringContainsString("Bootstrap 5.3.8\nLicence: MIT\n\nThe MIT License (MIT)", $notices);
        self::assertLessThan(strpos($notices, 'acme/rocket'), strpos($notices, 'Bootstrap 5.3.8'));
    }

    #[Test]
    public function a_composer_package_without_a_licence_file_stops_the_build_and_nothing_is_written(): void
    {
        unlink($this->folder.'/vendor/nette/utils/license.md');

        [$exitCode, $notices] = $this->generate();

        self::assertNotSame(0, $exitCode);
        self::assertSame('', $notices);
    }

    #[Test]
    public function without_a_debian_package_list_no_debian_package_is_listed(): void
    {
        $notices = $this->generate()[1];

        self::assertStringNotContainsString('Debian package added by the container image', $notices);
        self::assertStringNotContainsString('PostgreSQL Global Development Group', $notices);
    }

    #[Test]
    public function every_listed_debian_package_is_listed_with_its_copyright_file(): void
    {
        [$exitCode, $notices] = $this->generate('--debian-packages='.$this->folder.'/debian-packages');

        self::assertSame(0, $exitCode);
        self::assertStringContainsString("libpq5\nDebian package added by the container image\n", $notices);
        self::assertStringContainsString('Copyright: 1996-2025 The PostgreSQL Global Development Group', $notices);
    }

    #[Test]
    public function a_debian_package_shipping_a_licence_file_has_it_listed_and_its_other_documents_left_out(): void
    {
        $this->write('debian-packages', "libpq5\nmsodbcsql18\n");

        $notices = $this->generate('--debian-packages='.$this->folder.'/debian-packages')[1];

        self::assertStringContainsString('MICROSOFT SOFTWARE LICENSE TERMS', $notices);
        self::assertStringNotContainsString('Not a licence.', $notices);
    }

    #[Test]
    public function a_listed_debian_package_without_a_copyright_file_stops_the_build(): void
    {
        $this->write('debian-packages', "libpq5\nlibmissing1\n");

        [$exitCode, $notices] = $this->generate('--debian-packages='.$this->folder.'/debian-packages');

        self::assertNotSame(0, $exitCode);
        self::assertSame('', $notices);
    }

    #[Test]
    public function the_repositorys_own_runtime_packages_all_ship_a_licence_file(): void
    {
        // Composer and the image build run the script against the same lock file, so this fails here before
        // either would, when a new dependency ships no licence file.
        $root = dirname(__DIR__, 2);

        [$exitCode, $notices] = $this->generate(
            '--lock='.$root.'/composer.lock',
            '--vendor='.$root.'/vendor',
            '--attribution='.$root.'/contract/attribution.json',
            '--bundled='.dirname($root).'/shared/third-party/bundled',
        );

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Bootstrap', $notices);
        foreach (json_decode((string) file_get_contents($root.'/composer.lock'), true)['packages'] as $package) {
            self::assertStringContainsString($package['name'].' '.$package['version']."\n", $notices);
        }
    }
}
