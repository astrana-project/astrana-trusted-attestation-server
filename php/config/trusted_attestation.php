<?php

declare(strict_types=1);

use App\Support\EnvironmentSettings;

/*
 * Everything IT staff configure. Deployment is three things (decision records 1, 12 and 16 in docs/adr): deploy the
 * app, create an empty database, and set the database and IAM connection details. The app creates its own
 * schema on first run.
 *
 * The database connection itself is Laravel's own config/database.php. Only what is specific to Astrana
 * Trusted Attestation is here.
 *
 * The typed settings are read through EnvironmentSettings rather than env() and a cast, because env()
 * leaves "no" or "abc" as text and a cast would read them as true and 0. A value that does not parse is
 * recorded under configuration_problems at the end of this file, and the service provider refuses every
 * request while that list is not empty, naming the setting.
 */
$settings = new EnvironmentSettings;

return [

    'database' => [
        /*
         * Which engine's schema file is applied on first run follows DB_CONNECTION (sqlsrv | pgsql |
         * mysql), read from the live connection by SchemaInitializer, so there is no second setting to
         * keep in step.
         *
         * An organisation whose database administrator applies shared/schema/*.sql by hand can turn this
         * off. The application then refuses every request while the schema is missing rather than
         * creating it.
         */
        'create_schema_on_startup' => $settings->bool('TRUSTED_ATTESTATION_CREATE_SCHEMA', true),
    ],

    'iam' => [
        /*
         * oidc or saml. Astrana Trusted Attestation speaks the protocol, not the vendor, so this is the
         * only thing that changes between an organisation on Entra ID and one on Active Directory
         * Federation Services. Everything downstream reads claims by configured name and cannot tell
         * which protocol produced them.
         *
         * Read without regard to case, as on the other two implementations, and lower-cased once here so
         * every place that reads it compares the same value.
         */
        'protocol' => strtolower((string) env('TRUSTED_ATTESTATION_IAM_PROTOCOL', 'oidc')),

        // Any standards-compliant OIDC provider. Unused when the protocol is saml.
        'issuer' => env('TRUSTED_ATTESTATION_IAM_ISSUER', ''),

        /*
         * Where to fetch the discovery document, when that is not simply the issuer's well-known path.
         *
         * Needed only where the identity provider is reachable from this server under a different name
         * than the one it puts in its tokens, such as an internal hostname behind a gateway, or a container
         * network. The issuer above is still what tokens are validated against. This only says where to
         * read the document.
         */
        'discovery_url' => env('TRUSTED_ATTESTATION_IAM_DISCOVERY_URL'),
        // Required under OpenID Connect, with no default, so leaving it out stops the instance at start.
        'client_id' => env('TRUSTED_ATTESTATION_IAM_CLIENT_ID', ''),
        'client_secret' => env('TRUSTED_ATTESTATION_IAM_CLIENT_SECRET', ''),

        // Extra scopes beyond openid, profile and email.
        'additional_scopes' => $settings->list('TRUSTED_ATTESTATION_IAM_SCOPES'),

        /*
         * Claim carrying the member's iam_subject_id. OIDC "sub" by default.
         *
         * Under SAML this names a SAML attribute instead, and falls back to the assertion's NameID when
         * no attribute of that name is present, which is the usual case.
         */
        'subject_claim' => env('TRUSTED_ATTESTATION_IAM_SUBJECT_CLAIM', 'sub'),

        // Shown on the self-service page only. Never stored.
        'name_claim' => env('TRUSTED_ATTESTATION_IAM_NAME_CLAIM', 'name'),

        /*
         * Whether the identity provider's discovery document must be fetched over HTTPS. Only ever turned
         * off to talk to a local development identity provider. This server's own TLS requirement is
         * separate and cannot be turned off.
         */
        'require_https_metadata' => $settings->bool('TRUSTED_ATTESTATION_IAM_REQUIRE_HTTPS_METADATA', true),

        /*
         * SAML 2.0. Unused when the protocol is oidc.
         *
         * Everything about the IdP comes from its published metadata, exactly as the OIDC path reads
         * everything from the discovery document. The SP keypair signs AuthnRequests and logout messages;
         * most IdPs advertise WantAuthnRequestsSigned, and single logout needs a signature regardless.
         */
        'saml' => [
            // This SP's entity ID: the identifier an IdP administrator registers. Defaults to this app's own
            // metadata URL, which is the convention most IdPs expect.
            'entity_id' => env('TRUSTED_ATTESTATION_SAML_ENTITY_ID', ''),

            'idp_metadata_url' => env('TRUSTED_ATTESTATION_SAML_IDP_METADATA_URL', ''),

            'sp_certificate_path' => env('TRUSTED_ATTESTATION_SAML_SP_CERTIFICATE'),
            'sp_private_key_path' => env('TRUSTED_ATTESTATION_SAML_SP_PRIVATE_KEY'),
        ],
    ],

    'tls' => [
        /*
         * Astrana Trusted Attestation refuses every request without TLS. Set this only when a reverse
         * proxy terminates TLS in front of it, which on shared hosting is the normal case, since the
         * panel's own web server holds the certificate. There is no auto-detection and no plain-HTTP
         * fallback.
         */
        'terminated_by_proxy' => $settings->bool('TRUSTED_ATTESTATION_TLS_TERMINATED_BY_PROXY', false),
    ],

    'audit' => [
        /*
         * Audit logging itself is mandatory and not host-configurable. This disables only the prune job.
         */
        'prune_enabled' => $settings->bool('TRUSTED_ATTESTATION_AUDIT_PRUNE_ENABLED', true),

        // Default 5 years. Set it to the retention the law requires where the organisation operates or is
        // registered, which may be longer or shorter, within 1 to 36500 days (a century), the same range
        // the other two implementations accept.
        'retention_days' => $settings->int('TRUSTED_ATTESTATION_AUDIT_RETENTION_DAYS', 1825, 1, 36500),

        /*
         * How often the opportunistic prune may run, in hours, from 1 to 8760 (a year). Unlike the .NET
         * and Java implementations, this one cannot honour a specific time of day: it fires on whichever
         * qualifying request happens to arrive after the interval elapsed.
         */
        'interval_hours' => $settings->int('TRUSTED_ATTESTATION_AUDIT_INTERVAL_HOURS', 24, 1, 8760),

        /*
         * Fraction of incoming requests that pay the cost of checking whether a prune is due. One in 200
         * keeps the overhead invisible while still firing many times a day on any instance with traffic.
         * 1 checks on every request.
         */
        'check_probability' => $settings->int('TRUSTED_ATTESTATION_AUDIT_CHECK_PROBABILITY', 200, 1, PHP_INT_MAX),
    ],

    /*
     * Served at /.well-known/ata-manifest.json. Validated on every request, so an invalid manifest stops
     * the application rather than being found by a verifying Astrana instance whose lookup fails.
     *
     * The locale-keyed fields are JSON objects in .env, since a flat environment variable cannot express
     * a map. For example:
     *   TRUSTED_ATTESTATION_MANIFEST_NAME='{"en":"Acme Bank","fr":"Banque Acme"}'
     */
    'manifest' => [
        'manifest_version' => 1,
        'default_locale' => env('TRUSTED_ATTESTATION_MANIFEST_DEFAULT_LOCALE', 'en'),

        /*
         * The locales the organisation offers on its pages, comma-separated, in the order the language
         * switcher lists them. Empty means every locale the application ships strings for, with the
         * default locale first and the rest ordered by each language's own name. Configured values with
         * no shipped strings are ignored. The setting limits every source the locale is resolved from,
         * and the switcher appears only when more than one locale remains. It shapes the pages and is not
         * part of the served manifest.
         */
        'supported_locales' => $settings->list('TRUSTED_ATTESTATION_MANIFEST_SUPPORTED_LOCALES'),
        'name' => $settings->localeMap('TRUSTED_ATTESTATION_MANIFEST_NAME'),
        'description' => $settings->localeMap('TRUSTED_ATTESTATION_MANIFEST_DESCRIPTION'),
        'website' => $settings->localeMap('TRUSTED_ATTESTATION_MANIFEST_WEBSITE'),

        /*
         * Optional. A contact or support page, so a member who holds no relationship yet can be pointed
         * somewhere for help. Locale-keyed exactly like website; falls back to website on the self-service
         * page when it is not set.
         */
        'support_url' => $settings->localeMap('TRUSTED_ATTESTATION_MANIFEST_SUPPORT_URL'),

        /*
         * Optional. Either a path to an image file or a data URI, and the manifest always carries the
         * encoded form. Keep the data URI under 65,536 characters, about 48 kilobytes of image, because
         * the manifest schema sets that limit. Not locale-keyed, because a logo has no text to translate.
         * This is the light-mode and default logo, the one rendered whenever no dark preference applies.
         */
        'logo_data' => env('TRUSTED_ATTESTATION_MANIFEST_LOGO'),

        /*
         * Optional dark-mode logo, in the same embedded form as logo_data. When set, a member whose
         * device prefers a dark colour scheme sees this. Everyone else sees logo_data, which stays the
         * fallback, so a dark logo without a light one is a configuration error.
         */
        'logo_data_dark' => env('TRUSTED_ATTESTATION_MANIFEST_LOGO_DARK'),
        'privacy_notice_url' => $settings->localeMap('TRUSTED_ATTESTATION_MANIFEST_PRIVACY_NOTICE_URL'),
        'jurisdictions' => $settings->list('TRUSTED_ATTESTATION_MANIFEST_JURISDICTIONS'),
        'relationship_types' => $settings->list('TRUSTED_ATTESTATION_MANIFEST_RELATIONSHIP_TYPES'),
        'enrollment_url' => env('TRUSTED_ATTESTATION_MANIFEST_ENROLLMENT_URL', ''),
        'attestation_url' => env('TRUSTED_ATTESTATION_MANIFEST_ATTESTATION_URL', ''),
    ],

    /*
     * Every setting above whose value did not parse, each named. Last, so every reader has run. Not a
     * setting: the service provider refuses every request while this is not empty.
     */
    'configuration_problems' => $settings->problems(),
];
