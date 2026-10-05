<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contract\Attribution;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The software's own licence page, rendered end to end.
 *
 * Unlike the model-level PageControllerTest, this one issues a real request and asserts on the response:
 * the page is deliberately anonymous (a licence is public), so an unauthenticated GET must return 200 and
 * carry the contract's project name and licence notice -- the single source of truth for both -- rather
 * than any wording an implementation invented. The route the footer links to has to actually answer.
 */
final class LicensePageTest extends TestCase
{
    #[Test]
    public function the_licence_page_is_public_and_shows_the_project_name_and_licence_notice(): void
    {
        $attribution = $this->app->make(Attribution::class);

        $response = $this->get('/license');

        $response->assertOk();
        $response->assertSee($attribution->projectName, false);
        $response->assertSee($attribution->licenseNotice, false);
    }

    #[Test]
    public function the_licence_page_sets_no_cookie(): void
    {
        // Anonymous and stateless, like the landing page: no session cookie and no CSRF cookie, as the
        // .NET implementation answers. A persistent session driver is configured so that a session cookie
        // would be written if the page were not on the stateless list.
        config(['session.driver' => 'file']);

        $response = $this->get('/license');

        $response->assertOk();
        self::assertSame([], $response->headers->getCookies());
    }

    #[Test]
    public function the_licence_page_shows_the_licence_text_the_trademark_notice_and_the_source_address(): void
    {
        $attribution = $this->app->make(Attribution::class);

        $response = $this->get('/license');

        $this->assertCount(3, $attribution->licenseText);
        $response->assertSee('Permission is hereby granted', false);
        $response->assertSee('The name Astrana and the Astrana logos and other brand features are trademarks of Darin Morris', false);
        $response->assertSee($attribution->sourceUrl, false);
    }

    #[Test]
    public function the_licence_page_links_to_the_third_party_notices_in_the_web_root(): void
    {
        // The file is written into public/ by scripts/generate-third-party-notices.php, after every Composer
        // install and in the container image, so every way of deploying serves it at the same address, as
        // the .NET and Java implementations do.
        $response = $this->get('/license');

        $response->assertSee('<a href="'.url('/THIRD-PARTY-NOTICES.txt').'">THIRD-PARTY-NOTICES.txt</a>', false);
    }
}
