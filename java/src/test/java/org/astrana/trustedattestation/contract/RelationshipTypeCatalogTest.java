package org.astrana.trustedattestation.contract;

import static org.assertj.core.api.Assertions.assertThat;

import org.junit.jupiter.api.Test;

class RelationshipTypeCatalogTest {

    private final RelationshipTypeCatalog catalog = new RelationshipTypeCatalog();

    @Test
    void loadsTheGovernedVocabularyFromTheContractFile() {
        // Twenty-five values as of schema_version 1. This assertion is meant to fail when the contract
        // file changes: extending the enum is a change to the shared file and a feature release of all three
        // (decision record 14 in docs/adr), and every implementation and the definitions in
        // docs/relationship-types.md have to move together.
        assertThat(catalog.ids()).hasSize(25);
        assertThat(catalog.schemaVersion()).isEqualTo(1);
        assertThat(catalog.ids()).contains("employee", "licensed_professional", "citizen");
    }

    @Test
    void rejectsValuesOutsideTheVocabulary() {
        assertThat(catalog.isGoverned("patient")).isFalse(); // excluded: normally private
        assertThat(catalog.isGoverned("other")).isFalse(); // there is no fallback value, ever
        assertThat(catalog.isGoverned("board_member")).isFalse(); // a named subclass, not atomic
        assertThat(catalog.isGoverned(null)).isFalse();
        assertThat(catalog.isGoverned("")).isFalse();
    }

    @Test
    void matchingIsCaseSensitive() {
        // The identifiers are machine-readable keys, not display text. "Employee" is not "employee".
        assertThat(catalog.isGoverned("employee")).isTrue();
        assertThat(catalog.isGoverned("Employee")).isFalse();
    }

    @Test
    void labelsFallBackFromRegionToLanguageToEnglish() {
        assertThat(catalog.label("employee", "en")).isEqualTo("Employee");
        assertThat(catalog.label("employee", "en-GB")).isEqualTo("Employee");

        // A locale the contract translates is returned in that language, and a region variant falls back to
        // it: fr-CA has no entry of its own, so it resolves to the fr label rather than to English.
        assertThat(catalog.label("employee", "fr")).isEqualTo("Employé");
        assertThat(catalog.label("employee", "fr-CA")).isEqualTo("Employé");

        // A locale the contract does not carry at all falls back to English rather than inventing a
        // translation. (Welsh is deliberately not in the shipped set.)
        assertThat(catalog.label("employee", "cy")).isEqualTo("Employee");
        assertThat(catalog.label("employee", null)).isEqualTo("Employee");
    }

    @Test
    void anUnknownIdLabelsAsItselfRatherThanThrowing() {
        assertThat(catalog.label("nonsense", "en")).isEqualTo("nonsense");
    }

    @Test
    void localeFallbacksAcceptBothTagAndLocaleSpellings() {
        assertThat(RelationshipTypeCatalog.localeFallbacks("fr-CA")).containsExactly("fr-CA", "fr");
        assertThat(RelationshipTypeCatalog.localeFallbacks("fr_CA")).containsExactly("fr-CA", "fr");
        assertThat(RelationshipTypeCatalog.localeFallbacks("fr")).containsExactly("fr");
        assertThat(RelationshipTypeCatalog.localeFallbacks(null)).isEmpty();
    }
}
