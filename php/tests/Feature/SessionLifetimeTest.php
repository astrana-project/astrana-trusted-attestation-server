<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The session's idle lifetime, which the three implementations must agree on.
 *
 * .NET and Java expire an idle session after eight hours. Laravel's shipped default is two, which would have
 * a member signed out of this stack mid-afternoon while the other two kept them in. The cookie itself stays
 * a browser-session cookie, as the conformance suite checks.
 *
 * A Feature test because it reads configuration through the container.
 */
final class SessionLifetimeTest extends TestCase
{
    #[Test]
    public function the_idle_lifetime_is_eight_hours(): void
    {
        self::assertSame(480, config('session.lifetime'));
    }

    #[Test]
    public function the_cookie_stays_a_browser_session_cookie(): void
    {
        self::assertTrue((bool) config('session.expire_on_close'));
    }
}
