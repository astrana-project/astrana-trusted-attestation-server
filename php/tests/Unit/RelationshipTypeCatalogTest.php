<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Contract\RelationshipTypeCatalog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RelationshipTypeCatalogTest extends TestCase
{
    private function catalog(): RelationshipTypeCatalog
    {
        return new RelationshipTypeCatalog(dirname(__DIR__, 2).'/contract/relationship-types.json');
    }

    #[Test]
    public function it_loads_the_governed_vocabulary_from_the_contract_file(): void
    {
        // Twenty-five values as of schema_version 1. This assertion is meant to fail when the contract
        // file changes: extending the enum is a change to the shared file and a feature release of all three
        // (decision record 14 in docs/adr), and every implementation and the definitions in
        // docs/relationship-types.md have to move together.
        self::assertCount(25, $this->catalog()->ids());
        self::assertSame(1, $this->catalog()->schemaVersion());
        self::assertContains('employee', $this->catalog()->ids());
        self::assertContains('licensed_professional', $this->catalog()->ids());
    }

    #[Test]
    public function it_rejects_values_outside_the_vocabulary(): void
    {
        $catalog = $this->catalog();

        self::assertFalse($catalog->isGoverned('patient'));      // excluded: normally private
        self::assertFalse($catalog->isGoverned('other'));        // there is no fallback value, ever
        self::assertFalse($catalog->isGoverned('board_member')); // a named subclass, not atomic
        self::assertFalse($catalog->isGoverned(null));
        self::assertFalse($catalog->isGoverned(''));
    }

    #[Test]
    public function matching_is_case_sensitive(): void
    {
        // The identifiers are machine-readable keys, not display text. "Employee" is not "employee".
        self::assertTrue($this->catalog()->isGoverned('employee'));
        self::assertFalse($this->catalog()->isGoverned('Employee'));
    }

    #[Test]
    public function labels_fall_back_from_region_to_language_to_english(): void
    {
        $catalog = $this->catalog();

        self::assertSame('Employee', $catalog->label('employee', 'en'));
        self::assertSame('Employee', $catalog->label('employee', 'en-GB'));

        // A locale the contract translates is returned in that language, and a region variant falls back to
        // it: fr-CA has no entry of its own, so it resolves to the fr label rather than to English.
        self::assertSame('Employé', $catalog->label('employee', 'fr'));
        self::assertSame('Employé', $catalog->label('employee', 'fr-CA'));

        // A locale the contract does not carry at all falls back to English rather than inventing a
        // translation. (Welsh is deliberately not in the shipped set.)
        self::assertSame('Employee', $catalog->label('employee', 'cy'));
        self::assertSame('Employee', $catalog->label('employee', null));
    }

    #[Test]
    public function an_unknown_id_labels_as_itself_rather_than_throwing(): void
    {
        self::assertSame('nonsense', $this->catalog()->label('nonsense', 'en'));
    }

    #[Test]
    public function locale_fallbacks_accept_both_tag_and_locale_spellings(): void
    {
        self::assertSame(['fr-CA', 'fr'], RelationshipTypeCatalog::localeFallbacks('fr-CA'));
        self::assertSame(['fr-CA', 'fr'], RelationshipTypeCatalog::localeFallbacks('fr_CA'));
        self::assertSame(['fr'], RelationshipTypeCatalog::localeFallbacks('fr'));
        self::assertSame([], RelationshipTypeCatalog::localeFallbacks(null));
    }
}
