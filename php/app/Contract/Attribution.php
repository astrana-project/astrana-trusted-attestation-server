<?php

declare(strict_types=1);

namespace App\Contract;

use App\Exceptions\ConfigurationException;

/**
 * The software's own attribution, loaded from this application's copy of the contract's attribution.json:
 * the footer every page shows and the content of the licence page.
 *
 * Decision record 33 in docs/adr requires it on every deployment and does not let the organisation running the instance
 * configure it away, so it is not in config/trusted_attestation.php at all. The same file is imported by all
 * three implementations, so the notice reads identically wherever it appears. Organisation branding is a
 * separate thing entirely, and comes from the manifest.
 */
final class Attribution
{
    private const RESOURCE = 'contract/attribution.json';

    public readonly string $projectName;

    public readonly string $projectUrl;

    /** Where the source code and documentation live. */
    public readonly string $sourceUrl;

    /** The one-line notice: the copyright line and the licence, shown first on the licence page. */
    public readonly string $licenseNotice;

    /**
     * The short text the footer links with (for example, "Licence"), kept separate from the fuller
     * $licenseNotice. Falls back to the notice when the contract file sets no label, so a footer is never
     * empty.
     */
    public readonly string $licenseLabel;

    /** Where the footer's licence link goes. Null renders the label as plain text. */
    public readonly ?string $licenseUrl;

    /**
     * The licence text, one entry per paragraph, as the licence page shows it.
     *
     * @var list<string>
     */
    public readonly array $licenseText;

    /** The trademark notice the licence page shows after the licence text. */
    public readonly string $trademarkNotice;

    public function __construct(?string $path = null)
    {
        $path ??= base_path(self::RESOURCE);

        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new ConfigurationException(
                self::problem('could not be read. It is copied from shared/contract at build time and must '
                    .'ship with the application.')
            );
        }

        $document = json_decode($raw, true);
        if (! is_array($document)) {
            throw new ConfigurationException(self::problem('is not valid JSON.'));
        }

        $this->projectName = (string) ($document['project_name'] ?? '');
        $this->projectUrl = (string) ($document['project_url'] ?? '');
        $this->sourceUrl = (string) ($document['source_url'] ?? '');

        $license = is_array($document['license'] ?? null) ? $document['license'] : [];
        $this->licenseNotice = (string) ($license['notice'] ?? '');

        $label = $license['label'] ?? null;
        $this->licenseLabel = is_string($label) && trim($label) !== ''
            ? $label
            : $this->licenseNotice;

        $url = $license['url'] ?? null;
        $this->licenseUrl = is_string($url) && trim($url) !== '' ? $url : null;

        $paragraphs = $license['text'] ?? [];
        $this->licenseText = is_array($paragraphs)
            ? array_values(array_map(static fn ($paragraph) => (string) $paragraph, $paragraphs))
            : [];
        $this->trademarkNotice = (string) ($license['trademark_notice'] ?? '');

        if ($this->projectName === '' || $this->projectUrl === '') {
            throw new ConfigurationException(
                self::problem('must name the project and link to it.')
            );
        }
    }

    /**
     * Prefixes a complaint with the file it is about.
     *
     * These messages are the whole diagnosis when the application refuses to start, and every one of them
     * has to name the file, so the naming lives here rather than being repeated at each throw.
     */
    private static function problem(string $detail): string
    {
        return 'Contract file "'.self::RESOURCE.'" '.$detail;
    }
}
