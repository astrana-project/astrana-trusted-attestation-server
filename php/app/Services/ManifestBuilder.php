<?php

declare(strict_types=1);

namespace App\Services;

use App\Contract\RelationshipTypeCatalog;

/**
 * Builds and validates the public manifest served at /.well-known/ata-manifest.json.
 *
 * Built from configuration, never from the database. It describes the organisation, not any member, which
 * is also why it needs no authentication and carries no privacy concern.
 *
 * Validation is hand-written rather than driven by contract/manifest.schema.json, because pulling in a JSON
 * Schema engine would work against the "no exotic dependency" bar the rest of this design holds to, for a
 * document with thirteen fields. The schema file remains the canonical shape, and these rules check all
 * of it: the required fields, the https:// addresses, the locale keys, the default locale entries, the
 * vocabulary, the logo format and size, and the jurisdiction codes, each listed once.
 */
final class ManifestBuilder
{
    /** The longest logo, in characters, the contract schema allows for either logo field. */
    public const MAX_LOGO_LENGTH = 65536;

    private const LOCALE_TAG = '/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8})*$/';

    private const JURISDICTION_CODE = '/^[A-Z]{2}(-[A-Z0-9]{1,3})?$/';

    public function __construct(private readonly RelationshipTypeCatalog $catalog) {}

    /**
     * The manifest as it is served. Key order matches the example in shared/contract/manifest.schema.json,
     * and null-valued optional fields are omitted entirely rather than serialised as null.
     */
    public function build(): array
    {
        $config = config('trusted_attestation.manifest');

        $manifest = [
            // An integer, not a URL segment. The manifest's own path is never versioned -- that is the
            // point of a well-known document. This is what tells a consumer how to parse the rest.
            'manifest_version' => (int) $config['manifest_version'],
            'default_locale' => (string) $config['default_locale'],
            'name' => $config['name'],
        ];

        foreach (['description', 'website', 'support_url'] as $optional) {
            if (! empty($config[$optional])) {
                $manifest[$optional] = $config[$optional];
            }
        }

        $logo = LogoData::resolve($config['logo_data'] ?? null, LogoData::LIGHT_SETTING);
        if ($logo !== null) {
            $manifest['logo_data'] = $logo;
        }

        $logoDark = LogoData::resolve($config['logo_data_dark'] ?? null, LogoData::DARK_SETTING);
        if ($logoDark !== null) {
            $manifest['logo_data_dark'] = $logoDark;
        }

        if (! empty($config['privacy_notice_url'])) {
            $manifest['privacy_notice_url'] = $config['privacy_notice_url'];
        }

        if (! empty($config['jurisdictions'])) {
            $manifest['jurisdictions'] = array_values($config['jurisdictions']);
        }

        $manifest['relationship_types'] = array_values($config['relationship_types']);
        $manifest['enrollment_url'] = (string) $config['enrollment_url'];
        $manifest['attestation_url'] = (string) $config['attestation_url'];

        return $manifest;
    }

    /** @return list<string> the reasons the manifest is unusable, empty when it is fine */
    public function validate(array $manifest): array
    {
        $errors = [];
        $defaultLocale = $manifest['default_locale'] ?? '';

        $this->validateVersion($errors, $manifest['manifest_version'] ?? null);
        $this->validateDefaultLocale($errors, $defaultLocale);

        $this->validateLocalized($errors, 'name', $manifest['name'] ?? null, $defaultLocale, true, false);
        $this->validateLocalized($errors, 'description', $manifest['description'] ?? null, $defaultLocale, false, false);
        $this->validateLocalized($errors, 'website', $manifest['website'] ?? null, $defaultLocale, false, true);
        $this->validateLocalized($errors, 'support_url', $manifest['support_url'] ?? null, $defaultLocale, false, true);
        $this->validateLocalized($errors, 'privacy_notice_url', $manifest['privacy_notice_url'] ?? null, $defaultLocale, false, true);

        $this->validateRelationshipTypes($errors, $manifest['relationship_types'] ?? []);
        $this->validateLogo($errors, 'logo_data', $manifest['logo_data'] ?? null);
        $this->validateLogo($errors, 'logo_data_dark', $manifest['logo_data_dark'] ?? null);
        $this->validateLogoDarkHasLight($errors, $manifest['logo_data_dark'] ?? null, $manifest['logo_data'] ?? null);

        $this->validateHttpsUrl($errors, 'enrollment_url', $manifest['enrollment_url'] ?? null);
        $this->validateHttpsUrl($errors, 'attestation_url', $manifest['attestation_url'] ?? null);

        $this->validateJurisdictions($errors, $manifest['jurisdictions'] ?? []);

        return $errors;
    }

    /** @param list<string> $errors */
    private function validateVersion(array &$errors, mixed $version): void
    {
        if ($version !== 1) {
            $errors[] = 'manifest_version must be 1, was '.var_export($version, true).'.';
        }
    }

    /** @param list<string> $errors */
    private function validateDefaultLocale(array &$errors, mixed $defaultLocale): void
    {
        if (! is_string($defaultLocale) || preg_match(self::LOCALE_TAG, $defaultLocale) !== 1) {
            $errors[] = 'default_locale "'.self::describe($defaultLocale).'" is not a locale tag.';
        }
    }

    /** @param list<string> $errors */
    private function validateRelationshipTypes(array &$errors, mixed $types): void
    {
        if (! is_array($types) || $types === []) {
            $errors[] = 'relationship_types must list at least one value: an Astrana Trusted Attestation '
                .'instance that attests to nothing has nothing to serve.';

            return;
        }

        foreach ($types as $type) {
            if (! $this->catalog->isGoverned(is_string($type) ? $type : null)) {
                $errors[] = 'relationship_types contains "'.self::describe($type)
                    .'", which is not in the governed enum. Adding a value is a change to the shared '
                    .'vocabulary, not per-organisation configuration.';
            }
        }

        if (count(array_unique($types)) !== count($types)) {
            $errors[] = 'relationship_types contains duplicates.';
        }
    }

    /**
     * Either logo, light or dark. Optional, but if it is set it has to be embedded. A URL here would defeat
     * the reason the field carries the image rather than a link to it. The length cap keeps the manifest
     * small, because every verifying instance fetches it.
     *
     * @param  list<string>  $errors
     */
    private function validateLogo(array &$errors, string $field, mixed $logo): void
    {
        if ($logo === null) {
            return;
        }

        if (is_string($logo) && strlen($logo) > self::MAX_LOGO_LENGTH) {
            $errors[] = $field.' is '.strlen($logo).' characters, over the limit of '.self::MAX_LOGO_LENGTH
                .': keep the logo icon-sized, because it inflates the manifest directly.';
        }

        if (! is_string($logo) || preg_match(LogoData::PATTERN, $logo) !== 1) {
            $errors[] = $field.' must be a base64 image data URI (for example "data:image/png;base64,..."), '
                .'not a URL: the logo is embedded so that rendering it needs no request to the organisation.';
        }
    }

    /**
     * The optional dark-mode variant is only meaningful alongside a light logo: the light logo is the
     * default and the fallback everything renders when no dark preference applies, so a dark logo without a
     * light one would silently never show. Caught here rather than surprising an operator who set only the
     * dark variant and saw the generic mark.
     *
     * @param  list<string>  $errors
     */
    private function validateLogoDarkHasLight(array &$errors, mixed $logoDark, mixed $logo): void
    {
        if ($logoDark !== null && $logo === null) {
            $errors[] = 'logo_data_dark is set without logo_data: the light logo is the default and fallback, '
                .'so a dark-only logo would never be shown. Set logo_data as well.';
        }
    }

    /** @param list<string> $errors */
    private function validateJurisdictions(array &$errors, mixed $jurisdictions): void
    {
        if (! is_array($jurisdictions)) {
            return;
        }

        foreach ($jurisdictions as $jurisdiction) {
            if (! is_string($jurisdiction) || preg_match(self::JURISDICTION_CODE, $jurisdiction) !== 1) {
                $errors[] = 'jurisdictions contains "'.self::describe($jurisdiction)
                    .'", which is not an ISO 3166-1 alpha-2 or ISO 3166-2 subdivision code.';
            }
        }

        // Compared as strings, exactly. A value that is not a string has been named above already.
        $codes = array_filter($jurisdictions, 'is_string');
        if (count(array_unique($codes)) !== count($codes)) {
            $errors[] = 'jurisdictions contains duplicates.';
        }
    }

    /**
     * How a rejected value is named back to the operator: itself when it is a string, otherwise its type.
     *
     * Quoting a value that is not a string produces "Array" or nothing at all, which tells the reader
     * less than the type does about what they have actually written in their configuration.
     */
    private static function describe(mixed $value): string
    {
        return is_string($value) ? $value : gettype($value);
    }

    private function validateLocalized(
        array &$errors,
        string $field,
        mixed $value,
        string $defaultLocale,
        bool $required,
        bool $urls
    ): void {
        if (! is_array($value) || $value === []) {
            if ($required) {
                $errors[] = $field.' is required and must be a locale-keyed object.';
            }

            return;
        }

        // The fallback rule must never have to guess: whatever default_locale names has to be present, or
        // a consumer whose preferred locale is missing has nowhere to fall back to.
        if (! array_key_exists($defaultLocale, $value)) {
            $errors[] = $field.' has no entry for the default locale "'.$defaultLocale.'".';
        }

        foreach ($value as $locale => $text) {
            if (! is_string($locale) || preg_match(self::LOCALE_TAG, $locale) !== 1) {
                $errors[] = $field.' has key "'.$locale.'", which is not a locale tag.';
            }

            if (! is_string($text) || trim($text) === '') {
                $errors[] = $field.'.'.$locale.' is empty.';
            } elseif ($urls) {
                $this->validateHttpsUrl($errors, $field.'.'.$locale, $text);
            }
        }
    }

    private function validateHttpsUrl(array &$errors, string $field, mixed $value): void
    {
        if (! is_string($value) || trim($value) === '') {
            $errors[] = $field.' is required.';

            return;
        }

        $parts = parse_url($value);

        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '') {
            $errors[] = $field.' must be an absolute https:// URL, was "'.$value.'".';
        }
    }
}
