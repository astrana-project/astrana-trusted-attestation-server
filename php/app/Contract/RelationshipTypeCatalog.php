<?php

declare(strict_types=1);

namespace App\Contract;

use App\Exceptions\ConfigurationException;

/**
 * The governed relationship_type vocabulary, loaded from the repo's copy of the contract's
 * relationship-types.json.
 *
 * The list is deliberately not restated in PHP. Extending it is a change to the shared file and a feature
 * release of all three implementations, never per-org configuration (decision record 14 in docs/adr), and all three
 * import the same file so the vocabulary cannot drift between them.
 */
final class RelationshipTypeCatalog
{
    private const RESOURCE = 'contract/relationship-types.json';

    private readonly int $schemaVersion;

    /** @var list<string> */
    private readonly array $ids;

    /** @var array<string, array<string, string>> */
    private readonly array $labelsById;

    public function __construct(?string $path = null)
    {
        $path ??= base_path(self::RESOURCE);

        $raw = @file_get_contents($path);
        if ($raw === false) {
            // A packaging fault, not a runtime condition to degrade around.
            throw new ConfigurationException(
                self::problem('could not be read. It is copied from the contract repo and must ship '
                    .'with the application.')
            );
        }

        $document = json_decode($raw, true);
        if (! is_array($document) || ! isset($document['types']) || ! is_array($document['types'])) {
            throw new ConfigurationException(self::problem('is not valid, or defines no types.'));
        }

        $ids = [];
        $labels = [];

        foreach ($document['types'] as $type) {
            $id = $type['id'] ?? null;
            if (! is_string($id) || trim($id) === '') {
                throw new ConfigurationException(self::problem('has a type with no id.'));
            }

            if (isset($labels[$id])) {
                throw new ConfigurationException(self::problem('lists "'.$id.'" more than once.'));
            }

            $labels[$id] = is_array($type['labels'] ?? null) ? $type['labels'] : [];
            $ids[] = $id;
        }

        if ($ids === []) {
            throw new ConfigurationException(self::problem('defines no types.'));
        }

        $this->schemaVersion = (int) ($document['schema_version'] ?? 0);
        $this->ids = $ids;
        $this->labelsById = $labels;
    }

    public function schemaVersion(): int
    {
        return $this->schemaVersion;
    }

    /** Every governed identifier, in the order the contract file lists them. @return list<string> */
    public function ids(): array
    {
        return $this->ids;
    }

    /**
     * Whether a value is part of the governed vocabulary. Matching is case-sensitive: the identifiers are
     * fixed machine-readable keys, never display text.
     */
    public function isGoverned(?string $value): bool
    {
        return $value !== null && array_key_exists($value, $this->labelsById);
    }

    /**
     * The canonical display label in the requested locale, falling back to the more general language
     * (fr-CA to fr), then to English, then to the identifier itself.
     *
     * Labels are maintained centrally in the contract file rather than translated per instance, so the
     * same relationship reads the same way regardless of which org issued it.
     */
    public function label(string $id, ?string $locale): string
    {
        $labels = $this->labelsById[$id] ?? null;
        if ($labels === null) {
            return $id;
        }

        foreach (self::localeFallbacks($locale) as $candidate) {
            if (isset($labels[$candidate])) {
                return $labels[$candidate];
            }
        }

        return $labels['en'] ?? $id;
    }

    /** @return list<string> */
    public static function localeFallbacks(?string $locale): array
    {
        if ($locale === null || trim($locale) === '') {
            return [];
        }

        // Language tags use '-'; some sources spell them with '_'. Accept either.
        $normalised = str_replace('_', '-', $locale);
        $separator = strpos($normalised, '-');

        return $separator > 0
            ? [$normalised, substr($normalised, 0, $separator)]
            : [$normalised];
    }

    /**
     * Prefixes a complaint with the file it is about.
     *
     * <p>These messages are the whole diagnosis when the app refuses to start, and every one of them has
     * to name the file -- so the naming lives here rather than being repeated at each throw, where one
     * of them would eventually be written without it.
     */
    private static function problem(string $detail): string
    {
        return 'Contract file "'.self::RESOURCE.'" '.$detail;
    }
}
