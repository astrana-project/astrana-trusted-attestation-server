<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The manifest is the health probe, and there is no other.
 *
 * Laravel registers a framework health route at /up unless told not to. The .NET and Java implementations
 * have no such path, and decision record 32 makes /.well-known/ata-manifest.json the liveness and
 * readiness probe on every stack. So /up has to answer like any other unknown path here, or this stack
 * alone would advertise a second probe, one that says nothing about the configuration being complete.
 *
 * A Feature test because it asks the router. It touches no database.
 */
final class FrameworkHealthRouteTest extends TestCase
{
    #[Test]
    public function the_framework_health_route_is_not_served(): void
    {
        $response = $this->get('/up');

        self::assertNotSame(200, $response->getStatusCode());
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function the_manifest_still_answers_as_the_probe(): void
    {
        $this->get('/.well-known/ata-manifest.json')->assertOk();
    }
}
