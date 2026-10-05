<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Saml\IdpMetadataSource;
use Exception;
use OneLogin\Saml2\Constants;
use OneLogin\Saml2\IdPMetadataParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * How the identity provider's metadata is fetched. The metadata carries the certificate every assertion is
 * checked against, so whoever can answer the fetch decides which signatures are trusted. The fetch must
 * therefore verify the server's TLS certificate. OneLogin's parser skips that check unless it is told
 * otherwise, which is why the arguments passed to it are pinned here.
 */
final class IdpMetadataSourceTest extends TestCase
{
    private const URL = 'https://idp.example/metadata';

    #[Test]
    public function the_metadata_is_fetched_with_the_servers_certificate_verified(): void
    {
        $source = new class extends IdpMetadataSource
        {
            /** @var list<mixed> */
            public array $arguments = [];

            protected function parseRemote(mixed ...$arguments): array
            {
                $this->arguments = $arguments;

                return ['idp' => []];
            }
        };

        $source->fetch(self::URL);

        self::assertSame(
            [self::URL, null, null, Constants::BINDING_HTTP_REDIRECT, Constants::BINDING_HTTP_REDIRECT, true],
            $source->arguments
        );
    }

    #[Test]
    public function the_last_argument_is_still_the_parsers_peer_validation_switch(): void
    {
        // Pins the library's signature, so that an update which moved or renamed the switch is caught here
        // rather than silently turning the true above into some other setting.
        $parameters = (new ReflectionMethod(IdPMetadataParser::class, 'parseRemoteXML'))->getParameters();

        self::assertCount(6, $parameters);
        self::assertSame('validatePeer', $parameters[5]->getName());
        self::assertSame(
            ['url', 'entityId', 'desiredNameIdFormat', 'desiredSSOBinding', 'desiredSLOBinding'],
            array_map(static fn ($parameter): string => $parameter->getName(), array_slice($parameters, 0, 5))
        );
        self::assertNull($parameters[1]->getDefaultValue());
        self::assertNull($parameters[2]->getDefaultValue());
        self::assertSame(Constants::BINDING_HTTP_REDIRECT, $parameters[3]->getDefaultValue());
        self::assertSame(Constants::BINDING_HTTP_REDIRECT, $parameters[4]->getDefaultValue());
    }

    #[Test]
    public function the_fetch_itself_is_onelogins(): void
    {
        // Nothing listens on port 1, so the connection is refused at once. The message is OneLogin's own,
        // which shows the source hands the work to the library rather than fetching some other way.
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Error on parseRemoteXML.');

        (new IdpMetadataSource)->fetch('https://127.0.0.1:1/metadata');
    }
}
