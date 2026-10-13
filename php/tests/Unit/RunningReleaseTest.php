<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ConfigurationException;
use App\Support\RunningRelease;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The version the self-service page shows in its footer (decision record 47 in docs/adr), read from the top heading of
 * CHANGELOG.md, the version the release is tagged and published under.
 */
final class RunningReleaseTest extends TestCase
{
    private string $changelog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->changelog = sys_get_temp_dir().'/ata-changelog-'.bin2hex(random_bytes(6)).'.md';
    }

    protected function tearDown(): void
    {
        @unlink($this->changelog);

        parent::tearDown();
    }

    private function releaseFrom(string $text): RunningRelease
    {
        file_put_contents($this->changelog, $text);

        return new RunningRelease($this->changelog);
    }

    #[Test]
    public function the_version_is_the_top_heading_of_the_changelog(): void
    {
        $release = $this->releaseFrom(
            "# Changelog\n\n## [1.2.3] - 2026-10-06\n\n### Fixed\n\n## [1.2.2] - 2026-10-05\n"
        );

        self::assertSame('1.2.3', $release->version);
    }

    #[Test]
    public function the_label_is_v_followed_by_the_full_version(): void
    {
        self::assertSame('v1.2.3', $this->releaseFrom("## [1.2.3] - 2026-10-06\n")->label());
    }

    #[Test]
    public function it_links_to_the_fixed_releases_page(): void
    {
        self::assertSame('https://www.astrana.org/releases', RunningRelease::RELEASES_URL);
    }

    #[Test]
    public function a_changelog_with_no_version_heading_is_refused(): void
    {
        $this->expectException(ConfigurationException::class);

        $this->releaseFrom("# Changelog\n");
    }

    #[Test]
    public function a_missing_changelog_is_refused(): void
    {
        $this->expectException(ConfigurationException::class);

        new RunningRelease($this->changelog);
    }
}
