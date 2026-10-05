<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contract\Attribution;
use App\Contract\RelationshipTypeCatalog;
use App\Exceptions\ConfigurationException;
use App\Security\TlsRequirement;
use App\Services\AuditLog;
use App\Services\AuditWriter;
use App\Services\DbTransactionRunner;
use App\Services\EloquentRelationshipStore;
use App\Services\LocalizationSettings;
use App\Services\ManifestBuilder;
use App\Services\Oidc\OidcClient;
use App\Services\RelationshipStore;
use App\Services\SchemaInitializer;
use App\Services\TransactionRunner;
use App\Services\UiStrings;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

final class TrustedAttestationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Loaded once. Failing here means the contract file did not ship, which is a packaging fault,
        // not a runtime condition to degrade around.
        $this->app->singleton(RelationshipTypeCatalog::class, fn (): RelationshipTypeCatalog => new RelationshipTypeCatalog);
        $this->app->singleton(UiStrings::class, fn (): UiStrings => new UiStrings);

        // The locales this instance offers: the org's configured set intersected with the locales the app
        // ships strings for (empty config = all shipped). Shared by the page controller's locale resolution
        // and the language switcher. Endonyms drive the default alphabetical order, so a speaker finds
        // their language by its own native name.
        $this->app->singleton(LocalizationSettings::class, function (): LocalizationSettings {
            $strings = $this->app->make(UiStrings::class);
            $shipped = $strings->supported();

            $endonyms = [];
            foreach ($shipped as $locale) {
                $endonyms[$locale] = $strings->get('language_endonym', $locale);
            }

            $configured = config('trusted_attestation.manifest.supported_locales', []);

            return new LocalizationSettings(
                is_array($configured) ? array_values(array_filter($configured, 'is_string')) : [],
                $shipped,
                (string) config('trusted_attestation.manifest.default_locale', 'en'),
                $endonyms,
            );
        });

        // The fixed footer. Not in configuration at all: decision record 33 in docs/adr requires it on every
        // deployment and puts it beyond the organisation's reach, so nothing here could remove it.
        $this->app->singleton(Attribution::class, fn (): Attribution => new Attribution);

        // The write path: its decisions live in RelationshipService, over a store and a transaction runner
        // that these implementations satisfy at run time and hand-written fakes satisfy under test.
        $this->app->bind(AuditLog::class, AuditWriter::class);
        $this->app->bind(RelationshipStore::class, EloquentRelationshipStore::class);
        $this->app->bind(TransactionRunner::class, DbTransactionRunner::class);

        $this->app->singleton(OidcClient::class, fn (): OidcClient => new OidcClient(
            rtrim((string) config('trusted_attestation.iam.issuer'), '/'),
            (string) config('trusted_attestation.iam.client_id'),
            (string) config('trusted_attestation.iam.client_secret'),
            (bool) config('trusted_attestation.iam.require_https_metadata'),
            config('trusted_attestation.iam.discovery_url') ?: null,
        ));
    }

    public function boot(): void
    {
        // Console commands must not be blocked by these: an operator has to be able to run artisan
        // against a half-configured instance in order to fix it.
        if ($this->app->runningInConsole()) {
            return;
        }

        // Every Astrana Trusted Attestation instance serves HTTPS, which is not configurable, so generated
        // URLs are https regardless of what the application can see of the connection. Behind a
        // TLS-terminating proxy it otherwise emits http:// links from an https:// front door, which breaks
        // the sign-in redirect.
        URL::forceScheme('https');

        $this->refuseUnparsedSettings();
        $this->enforceTls();
        $this->validateManifest();
        $this->assertIamReachable();
        $this->app->make(SchemaInitializer::class)->ensureSchema();
    }

    /**
     * A setting whose value did not parse (a boolean of "no", a retention of "abc", a manifest name that
     * is not JSON) stops the instance, naming the setting, rather than running on the default as if the
     * operator had typed nothing. config/trusted_attestation.php records each one while it is read; this
     * is where the record becomes a refusal. First, because the checks that follow read those settings.
     */
    private function refuseUnparsedSettings(): void
    {
        $problems = config('trusted_attestation.configuration_problems', []);

        if (is_array($problems) && $problems !== []) {
            throw new ConfigurationException(
                'The configuration could not be read:'.PHP_EOL.'  - '.implode(PHP_EOL.'  - ', $problems)
            );
        }
    }

    /**
     * The rule itself lives in App\Security\TlsRequirement, matching the other two implementations.
     * What stays here is only the part that needs the framework: reading configuration, reading the
     * request, and turning a refusal into a response.
     *
     * The configuration is checked first, so a misconfigured instance is reported as one rather than as
     * a refused request. A refused request is answered HTTP 421 - Misdirected Request with no body, and
     * the exception handler in bootstrap/app.php gives it the security headers.
     */
    private function enforceTls(): void
    {
        $terminatedByProxy = (bool) config('trusted_attestation.tls.terminated_by_proxy');
        $request = $this->app['request'];

        TlsRequirement::enforce($terminatedByProxy, (string) config('app.url'));

        // Every header line, not the first: a request carrying two is refused (see TlsRequirement).
        if (TlsRequirement::refusesPlainRequest($terminatedByProxy, $request->isSecure())
            || TlsRequirement::refusesForwardedRequest($terminatedByProxy, $request->headers->all('X-Forwarded-Proto'))) {
            abort(421, '');
        }
    }

    /**
     * The IdP is read once on boot, so a misconfigured one stops the instance rather than surfacing at
     * the first member's login.
     *
     * Same reasoning as the manifest and TLS checks above, and it was the one missing: this application
     * started cleanly against a completely unreachable IdP and served every anonymous path normally.
     * An operator has no signal at all in that state -- the instance is up, the manifest is right,
     * /attest answers -- and the failure arrives one member at a time.
     *
     * Only on the OIDC path. A SAML deployment has no discovery document to read, and its metadata is
     * fetched by a different route.
     */
    private function assertIamReachable(): void
    {
        if (config('trusted_attestation.iam.protocol') === 'saml') {
            return;
        }

        $this->app->make(OidcClient::class)->assertReachable();
    }

    /**
     * Validated on boot, so an instance with a broken manifest never reaches the point of serving one.
     * Same reasoning as the TLS check: better found at startup than by a verifying peer whose lookup
     * fails.
     */
    private function validateManifest(): void
    {
        $manifests = $this->app->make(ManifestBuilder::class);
        $errors = $manifests->validate($manifests->build());

        if ($errors !== []) {
            throw new ConfigurationException(
                'The configured manifest (/.well-known/ata-manifest.json) is invalid:'.PHP_EOL.'  - '
                .implode(PHP_EOL.'  - ', $errors)
            );
        }
    }
}
