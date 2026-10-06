<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The step that adds to sbom.json what the container image installs outside Composer.
 *
 * The CycloneDX generator reads only the Composer lock file, so the Debian packages the Dockerfile installs
 * for the PostgreSQL driver and intl would be missing from the bill of materials. The published image
 * supports MySQL and PostgreSQL only, so Microsoft's ODBC driver and the sqlsrv and pdo_sqlsrv extensions,
 * which only an image built with WITH_SQLSRV=true installs, are listed with the optional scope and nothing
 * the application ships depends on them. scripts/add-native-sbom-components.php adds all of these after
 * every regeneration, with the versions the Dockerfile pins and the Debian release its runtime image names,
 * so the two cannot drift. Run here on copies in a temporary folder.
 */
final class NativeSbomComponentsTest extends TestCase
{
    private const SCRIPT = __DIR__.'/../../scripts/add-native-sbom-components.php';

    private const ROOT = 'astrana/trusted-attestation-server-1.0.0.0';

    private const DOCKERFILE = <<<'DOCKERFILE'
        FROM php:8.4-cli AS shared-assets
        FROM php:8.4-cli-trixie
        ARG WITH_SQLSRV=false
        ARG SQLSRV_VERSION=5.13.3
        ARG PDO_SQLSRV_VERSION=5.13.2
        ARG MSODBCSQL18_VERSION=18.7.1.1-1
        DOCKERFILE;

    private string $folder;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/ata-sbom-'.bin2hex(random_bytes(6));
        mkdir($this->folder);
    }

    protected function tearDown(): void
    {
        foreach (['Dockerfile', 'sbom.json'] as $file) {
            @unlink($this->folder.'/'.$file);
        }
        @rmdir($this->folder);
    }

    /** A bill of materials as the generator writes it, cut down to the root and one library. */
    private static function generated(): string
    {
        return json_encode([
            'bomFormat' => 'CycloneDX',
            'specVersion' => '1.6',
            'metadata' => ['component' => ['bom-ref' => self::ROOT, 'properties' => []]],
            'components' => [['bom-ref' => 'brick/math-1.0.0.0', 'name' => 'math']],
            'dependencies' => [
                ['ref' => 'brick/math-1.0.0.0'],
                ['ref' => self::ROOT, 'dependsOn' => ['brick/math-1.0.0.0']],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /** @return array{int, string} the exit code and the bill of materials afterwards */
    private function merge(string $dockerfile, string $sbom): array
    {
        file_put_contents($this->folder.'/Dockerfile', $dockerfile);
        file_put_contents($this->folder.'/sbom.json', $sbom);

        exec(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg(self::SCRIPT).' '
            .escapeshellarg($this->folder.'/Dockerfile').' '.escapeshellarg($this->folder.'/sbom.json').' 2>&1',
            $output,
            $exitCode
        );

        return [$exitCode, (string) file_get_contents($this->folder.'/sbom.json')];
    }

    /** @return array<string, array<string, mixed>> the components after the merge, by bom-ref */
    private function components(string $sbom): array
    {
        return array_column(json_decode($sbom, true)['components'], null, 'bom-ref');
    }

    /** @return array<string, array<string, mixed>> the Debian packages after the merge, by package name */
    private function debianPackages(string $sbom): array
    {
        $debian = array_filter($this->components($sbom), static fn (array $c): bool => str_starts_with($c['purl'] ?? '', 'pkg:deb/debian/'));

        return array_column($debian, null, 'name');
    }

    #[Test]
    public function the_sql_server_components_are_listed_as_optional_with_the_versions_the_dockerfile_pins(): void
    {
        [$exitCode, $sbom] = $this->merge(self::DOCKERFILE, self::generated());
        $components = $this->components($sbom);

        self::assertSame(0, $exitCode);
        self::assertSame('pkg:generic/sqlsrv@5.13.3?download_url=https://pecl.php.net/get/sqlsrv-5.13.3.tgz', $components['pecl/sqlsrv-5.13.3']['purl']);
        self::assertSame('pkg:generic/pdo_sqlsrv@5.13.2?download_url=https://pecl.php.net/get/pdo_sqlsrv-5.13.2.tgz', $components['pecl/pdo_sqlsrv-5.13.2']['purl']);
        self::assertSame('pkg:deb/microsoft/msodbcsql18@18.7.1.1-1?arch=amd64&distro=debian-13', $components['deb/msodbcsql18-18.7.1.1-1']['purl']);
        foreach (['pecl/sqlsrv-5.13.3', 'pecl/pdo_sqlsrv-5.13.2', 'deb/msodbcsql18-18.7.1.1-1'] as $ref) {
            self::assertSame('optional', $components[$ref]['scope']);
            self::assertStringContainsString('WITH_SQLSRV=true', $components[$ref]['description']);
        }
    }

    #[Test]
    public function the_debian_packages_the_image_installs_are_listed_with_their_licences_and_the_images_release(): void
    {
        $packages = $this->debianPackages($this->merge(self::DOCKERFILE, self::generated())[1]);

        self::assertArrayHasKey('libpq5', $packages);
        foreach ($packages as $name => $package) {
            self::assertArrayNotHasKey('scope', $package);
            self::assertSame('Debian', $package['supplier']['name']);
            self::assertNotEmpty($package['licenses']);
            self::assertSame("pkg:deb/debian/$name@{$package['version']}?arch=amd64&distro=debian-13", $package['purl']);
        }
        self::assertSame('PostgreSQL', $packages['libpq5']['licenses'][0]['expression']);
    }

    #[Test]
    public function the_application_depends_on_the_debian_packages_and_not_on_the_optional_components(): void
    {
        [, $sbom] = $this->merge(self::DOCKERFILE, self::generated());
        $dependsOn = array_column(json_decode($sbom, true)['dependencies'], 'dependsOn', 'ref');
        $debianRefs = array_column($this->debianPackages($sbom), 'bom-ref');
        sort($debianRefs);

        self::assertSame(['brick/math-1.0.0.0', ...$debianRefs], $dependsOn[self::ROOT]);
        self::assertSame(['deb/msodbcsql18-18.7.1.1-1'], $dependsOn['pecl/sqlsrv-5.13.3']);
        self::assertSame(['deb/msodbcsql18-18.7.1.1-1'], $dependsOn['pecl/pdo_sqlsrv-5.13.2']);
    }

    #[Test]
    public function running_it_again_changes_nothing_and_a_new_pin_replaces_the_old_entry(): void
    {
        $once = $this->merge(self::DOCKERFILE, self::generated())[1];
        self::assertSame($once, $this->merge(self::DOCKERFILE, $once)[1]);

        $bumped = $this->components($this->merge(str_replace('5.13.3', '5.14.0', self::DOCKERFILE), $once)[1]);
        self::assertArrayHasKey('pecl/sqlsrv-5.14.0', $bumped);
        self::assertArrayNotHasKey('pecl/sqlsrv-5.13.3', $bumped);
        self::assertCount(count($this->components($once)), $bumped);
    }

    #[Test]
    public function a_dockerfile_that_installs_sql_server_by_default_is_refused_and_the_file_left_alone(): void
    {
        foreach (['ARG WITH_SQLSRV=true', ''] as $replacement) {
            [$exitCode, $sbom] = $this->merge(str_replace('ARG WITH_SQLSRV=false', $replacement, self::DOCKERFILE), self::generated());

            self::assertNotSame(0, $exitCode);
            self::assertSame(self::generated(), $sbom);
        }
    }

    #[Test]
    public function a_runtime_image_on_another_debian_release_is_refused_and_the_file_left_alone(): void
    {
        // The Debian package versions are those of one release, so a new release means rebuilding the image
        // and recording its versions afresh.
        foreach (['php:8.4-cli-bookworm', 'php:8.4-cli'] as $image) {
            [$exitCode, $sbom] = $this->merge(str_replace("FROM php:8.4-cli-trixie\n", "FROM $image\n", self::DOCKERFILE), self::generated());

            self::assertNotSame(0, $exitCode);
            self::assertSame(self::generated(), $sbom);
        }
    }

    #[Test]
    public function a_runtime_image_pinned_by_digest_is_read_by_its_tag(): void
    {
        // The Dockerfile pins each base image by digest and keeps the tag, which still names the Debian release.
        $digest = '@sha256:'.str_repeat('0', 64);
        $pinned = str_replace("FROM php:8.4-cli-trixie\n", "FROM php:8.4-cli-trixie$digest\n", self::DOCKERFILE);

        [$exitCode, $sbom] = $this->merge($pinned, self::generated());
        self::assertSame(0, $exitCode);
        self::assertSame($this->merge(self::DOCKERFILE, self::generated())[1], $sbom);

        $otherRelease = str_replace("FROM php:8.4-cli-trixie\n", "FROM php:8.4-cli-bookworm$digest\n", self::DOCKERFILE);
        [$exitCode, $sbom] = $this->merge($otherRelease, self::generated());
        self::assertNotSame(0, $exitCode);
        self::assertSame(self::generated(), $sbom);
    }

    #[Test]
    public function a_dockerfile_without_one_of_the_pins_is_refused_and_the_file_left_alone(): void
    {
        [$exitCode, $sbom] = $this->merge(str_replace("ARG PDO_SQLSRV_VERSION=5.13.2\n", '', self::DOCKERFILE), self::generated());

        self::assertNotSame(0, $exitCode);
        self::assertSame(self::generated(), $sbom);
    }

    #[Test]
    public function the_committed_bill_of_materials_already_carries_the_dockerfiles_pins(): void
    {
        // Fails when the Dockerfile's pins change, or the bill of materials is regenerated, without the
        // step being run afterwards.
        $root = dirname(__DIR__, 2);
        $committed = (string) file_get_contents($root.'/sbom.json');

        [$exitCode, $sbom] = $this->merge((string) file_get_contents($root.'/Dockerfile'), $committed);

        self::assertSame(0, $exitCode);
        self::assertSame($committed, $sbom);
    }
}
