"""Tests for translation-review.py. Run from the repository root with `python3 -m unittest discover -s scripts`."""

import contextlib
import importlib.util
import io
import json
import pathlib
import tempfile
import unittest

SCRIPTS = pathlib.Path(__file__).resolve().parent
STRINGS = SCRIPTS.parent / "shared" / "ui" / "ui-strings.json"
TYPES = SCRIPTS.parent / "shared" / "contract" / "relationship-types.json"

_spec = importlib.util.spec_from_file_location("translation_review", SCRIPTS / "translation-review.py")
review = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(review)

FIXTURE = {
    "_note": "Not a locale.",
    "en": {
        "sign_out": "Sign Out",
        "revoked_help": "This relationship is revoked. Only {org} can restore it.",
        "error_generic": "Something went wrong | try again.",
    },
    "fr": {
        "sign_out": "Se déconnecter",
        "revoked_help": "Cette relation est révoquée. Seul {org} peut la rétablir.",
        "error_generic": "Une erreur s'est produite | réessayez.",
    },
    "ar": {
        "sign_out": "تسجيل الخروج",
        "revoked_help": "تم إلغاء هذه العلاقة. لا يمكن استعادتها إلا من قِبل {org}.",
        "error_generic": "حدث خطأ.",
    },
    "de": {
        "sign_out": "Abmelden",
        "revoked_help": "Diese Beziehung wurde widerrufen.",
    },
}


TYPES_FIXTURE = [
    {"id": "advisor", "labels": {"en": "Advisor", "fr": "Conseiller", "ar": "مستشار"}},
    {"id": "tenant", "labels": {"en": "Tenant", "fr": "Locataire", "ar": "مستأجر", "de": ""}},
]


def sheet(locale: str) -> str:
    return review.review_sheet(FIXTURE, locale, TYPES_FIXTURE)


def place_of(key: str) -> str:
    return next(place for _, rows in review.PLACES for name, place in rows if name == key)


class ReviewSheet(unittest.TestCase):
    def test_a_row_carries_the_key_where_it_appears_the_english_and_the_translation(self):
        place = place_of("sign_out")
        self.assertIn(f"| `sign_out` | {place} | Sign Out | Se déconnecter |", sheet("fr"))

    def test_placeholders_are_highlighted_in_both_the_english_and_the_translation(self):
        text = sheet("fr")
        self.assertIn("Only `{org}` can restore it.", text)
        self.assertIn("Seul `{org}` peut la rétablir.", text)

    def test_a_vertical_bar_in_the_text_does_not_break_the_table(self):
        self.assertIn("Something went wrong \\| try again.", sheet("fr"))

    def test_the_heading_names_the_language_and_its_tag(self):
        self.assertIn("(fr)", sheet("fr").splitlines()[0])

    def test_the_list_of_things_to_check_covers_meaning_tone_formality_and_placeholders(self):
        text = sheet("fr").lower()
        for topic in ("meaning", "tone", "formality", "placeholder"):
            with self.subTest(topic=topic):
                self.assertIn(topic, text)

    def test_the_reviewer_is_asked_to_address_the_member_as_a_modern_membership_service_would(self):
        text = sheet("fr")
        self.assertIn("modern membership service", text)
        self.assertIn("never stiff", text)
        self.assertIn("gender is unknown", text)

    def test_a_right_to_left_language_is_asked_about_reading_direction(self):
        text = sheet("ar")
        self.assertIn("right to left", text)
        self.assertIn('<span dir="rtl">', text)

    def test_a_left_to_right_language_is_not_asked_about_reading_direction(self):
        text = sheet("fr")
        self.assertNotIn("right to left", text)
        self.assertNotIn('dir="rtl"', text)

    def test_a_missing_translation_is_called_out(self):
        text = sheet("de")
        self.assertIn("`error_generic` has no translation", text)

    def test_a_dropped_placeholder_is_called_out(self):
        self.assertIn("`revoked_help` is missing `{org}`", sheet("de"))

    def test_a_complete_translation_has_nothing_called_out(self):
        self.assertNotIn("## Found by the script", sheet("fr"))

    def test_a_placeholder_written_fewer_times_than_in_the_english_is_called_out(self):
        found = review.findings({"key": "{org} asks {org}."}, {"key": "{org} demande."})
        self.assertEqual(["`key` has `{org}` once, where the English has it twice."], found)

    def test_a_placeholder_written_more_times_than_in_the_english_is_called_out(self):
        found = review.findings({"key": "Only {org}."}, {"key": "{org}, {org}, {org}."})
        self.assertEqual(["`key` has `{org}` 3 times, where the English has it once."], found)

    def test_an_empty_translation_is_called_out(self):
        found = review.findings({"key": "Only {org}."}, {"key": " "})
        self.assertEqual(["`key` is empty."], found)

    def test_an_ampersand_is_escaped_so_it_cannot_start_a_character_reference(self):
        self.assertEqual(r"Terms \& conditions \&amp;", review.cell("Terms & conditions &amp;"))

    def test_each_relationship_type_has_a_row_with_its_english_and_translated_name(self):
        text = sheet("fr")
        self.assertIn("## Relationship types", text)
        self.assertIn("| `advisor` | Advisor | Conseiller |", text)
        self.assertIn("| `tenant` | Tenant | Locataire |", text)

    def test_a_relationship_type_name_is_marked_right_to_left_for_a_right_to_left_language(self):
        self.assertIn('| `advisor` | Advisor | <span dir="rtl">مستشار</span> |', sheet("ar"))

    def test_a_missing_or_empty_relationship_type_name_is_called_out(self):
        text = sheet("de")
        self.assertIn("The relationship type `advisor` has no translation", text)
        self.assertIn("The relationship type `tenant` is empty.", text)


class CommandLine(unittest.TestCase):
    def run_script(self, *args: str) -> tuple[int, pathlib.Path, str]:
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        folder = pathlib.Path(temporary.name)
        strings = folder / "ui-strings.json"
        strings.write_bytes(json.dumps(FIXTURE, ensure_ascii=False).encode("utf-8"))
        types = folder / "relationship-types.json"
        types.write_bytes(json.dumps({"types": TYPES_FIXTURE}, ensure_ascii=False).encode("utf-8"))
        out = folder / "sheets"
        errors = io.StringIO()
        with contextlib.redirect_stderr(errors), contextlib.redirect_stdout(io.StringIO()):
            code = review.main(["--strings", str(strings), "--types", str(types), "--out", str(out), *args])
        return code, out, errors.getvalue()

    def test_writes_one_sheet_for_every_locale_except_english(self):
        code, out, _ = self.run_script()
        self.assertEqual(0, code)
        self.assertEqual(["ar.md", "de.md", "fr.md"], sorted(path.name for path in out.iterdir()))

    def test_locale_writes_only_that_language(self):
        code, out, _ = self.run_script("--locale", "fr")
        self.assertEqual(0, code)
        self.assertEqual(["fr.md"], [path.name for path in out.iterdir()])

    def test_sheets_have_unix_line_endings_and_no_byte_order_mark(self):
        _, out, _ = self.run_script("--locale", "ar")
        data = (out / "ar.md").read_bytes()
        self.assertFalse(data.startswith(b"\xef\xbb\xbf"))
        self.assertNotIn(b"\r", data)
        self.assertIn("تسجيل الخروج", data.decode("utf-8"))

    def test_the_sheet_lists_the_relationship_types_from_the_types_file(self):
        _, out, _ = self.run_script("--locale", "fr")
        self.assertIn("| `tenant` | Tenant | Locataire |", (out / "fr.md").read_text(encoding="utf-8"))

    def test_an_unknown_locale_fails_and_lists_the_ones_there_are(self):
        code, _, errors = self.run_script("--locale", "xx")
        self.assertNotEqual(0, code)
        self.assertIn("ar, de, fr", errors)

    def test_english_is_refused_because_it_is_the_source(self):
        code, _, errors = self.run_script("--locale", "en")
        self.assertNotEqual(0, code)
        self.assertIn("English", errors)


class RepositoryStrings(unittest.TestCase):
    """Keeps the descriptions of where each string appears in step with the strings file itself."""

    def test_every_english_key_says_where_it_appears(self):
        keys = set(json.loads(STRINGS.read_bytes())["en"])
        described = {key for _, rows in review.PLACES for key, _ in rows}
        self.assertEqual(set(), keys - described, "keys with no description")
        self.assertEqual(set(), described - keys, "descriptions of keys the strings file no longer has")

    def test_every_locale_in_the_repository_produces_a_sheet(self):
        strings = review.load_strings(STRINGS)
        types = review.load_types(TYPES)
        for locale in review.locales(strings):
            with self.subTest(locale=locale):
                text = review.review_sheet(strings, locale, types)
                self.assertIn(f"({locale})", text)
                self.assertIn("| `advisor` | Advisor |", text)


if __name__ == "__main__":
    unittest.main()
