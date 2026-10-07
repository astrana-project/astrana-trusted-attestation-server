#!/usr/bin/env python3
"""Writes a review sheet for each translation of the interface text, for a native speaker to check without reading JSON.

    python3 scripts/translation-review.py [--locale fr] [--out folder] [--strings shared/ui/ui-strings.json]
        [--types shared/contract/relationship-types.json]

Each sheet is a Markdown file named after the locale. It lists every string the pages show, where it appears, the
English text and the translation, with placeholders such as {org} highlighted, then the name of each relationship
type, and the things a reviewer should check. It also lists any string or name with no translation, an empty
translation, or a placeholder the translation writes a different number of times from the English.

Without --locale it writes a sheet for every locale except English, which is the source. The sheets go into the
translation-review folder at the repository root, which Git ignores, unless --out names another folder.
"""

from __future__ import annotations

import argparse
import json
import pathlib
import re
import sys
from collections import Counter

ROOT = pathlib.Path(__file__).resolve().parents[1]
SOURCE = "en"
PLACEHOLDER = re.compile(r"(\{[A-Za-z_]+\})")
MARKDOWN_SPECIAL = re.compile(r"([\\`*_\[\]<|&])")

# The same list as TextDirection in each implementation, which sets dir="rtl" on the page for these languages.
RIGHT_TO_LEFT = {"ar", "he", "fa", "ur", "ps"}

# Where each string appears, in the order a member meets them, grouped by page. The test checks this against the
# English keys in the strings file, so a new string cannot be added without saying where it appears.
PLACES = (
    (
        "On the landing page and the self-service page",
        (
            ("language_label", "Hidden label that screen readers announce before the language in the language menu"),
            ("language_endonym", "The name of this language in the language menu, as its own speakers write it"),
            ("licence_link", "Link to the software's licence, in the footer at the bottom of the page"),
        ),
    ),
    (
        "On the landing page, where a member arrives before signing in",
        (
            ("landing_title", "Browser tab title, after the organisation's name, and the small label under that name"),
            ("signed_out", "Message shown after the member signs out"),
            ("landing_intro", "Paragraph that says what the page is for"),
            ("landing_sign_in", "Main button to sign in"),
            ("landing_learn_more", "Button that opens the Astrana Trusted Attestation project's website"),
        ),
    ),
    (
        "On the self-service page, where a signed-in member manages their relationships",
        (
            ("page_title", "Browser tab title, after the organisation's name, and the small label under that name"),
            ("signed_in_as", "Label above the member's name"),
            ("sign_out", "Button, styled as a link, that signs the member out"),
            ("no_relationships", "Message when the organisation has not given the member any relationships"),
            ("no_relationships_help", "Line under that message, where `{org}` links to the organisation's help page"),
            ("relationships", "Heading above the list of the member's relationships"),
            ("status_unkeyed", "Status label on a relationship that has no key yet"),
            ("status_active", "Status label on a relationship that is in force"),
            ("status_revoked", "Status label on a relationship that is revoked"),
            ("status_expired", "Status label on a relationship that has expired"),
            ("revoked_help", "Note under a revoked relationship"),
            ("expired_help", "Note under an expired relationship"),
            ("key_expires", "Label before a relationship's expiry date, followed by a colon and the date"),
            ("set_key_label", "Label above the box where the member pastes their key"),
            ("paste_key", "Name of the paste button inside the key box, read by screen readers and shown on hover"),
            ("set_key_button", "Main button that saves the key for a relationship"),
            ("more_actions", "Toggle that shows the buttons to revoke and remove a relationship"),
            ("revoke_help", "Warning above the buttons to revoke and remove a relationship"),
            ("revoke_button", "Button to revoke a relationship"),
            ("revoke_confirm", "Question in the browser's confirmation box after pressing the button to revoke"),
            ("revoked_message", "Message after a relationship is revoked"),
            ("remove_key_button", "Red button to remove a relationship"),
            ("remove_confirm", "Question in the browser's confirmation box after pressing the button to remove"),
            ("saved", "Message after the member saves a key"),
            ("cleared", "Message after the member saves an empty key box, which clears the key"),
            ("error_invalid_key", "Error when the key the member saves is not a valid key"),
            ("error_conflict", "Error when the key is already registered to another relationship"),
            ("error_not_granted", "Error when saving a key for a relationship the organisation has not granted"),
            ("error_generic", "Error when anything else goes wrong"),
        ),
    ),
)

CHECKS = (
    "The meaning matches the English for the place the text appears, with nothing added or left out.",
    "The tone matches the English, which is plain, calm and polite. Buttons are short commands. Use the capital "
    "letters your language normally uses, not the English capitals on buttons.",
    "The text addresses the member the way a modern membership service in your language normally does, never stiff, "
    "with one level of formality through every string. Where the wording depends on the member's gender, use the "
    "form your language usually uses when the gender is unknown.",
    "Every placeholder in curly brackets, such as `{org}`, is kept exactly as written and not translated. Move it to "
    "wherever your grammar needs it.",
    "`{org}` becomes the organisation's name, exactly as the organisation writes it. The sentence must read correctly "
    "whatever the name is, without changing the name, so avoid wording that depends on its gender, number or case.",
    "Astrana and Astrana Trusted Attestation are names and stay as they are.",
    "Text on buttons and status labels stays about as short as the English, so that it fits on a phone screen.",
)

RIGHT_TO_LEFT_CHECK = (
    "Your language reads right to left, and the pages set that direction for you. Check that each sentence reads "
    "correctly when `{org}`, often a name in Latin letters, sits inside it, and that the punctuation is the form your "
    "language uses."
)

TYPES_HEADING = "Relationship types, each named on the relationships of that type on the self-service page"

SENDING = (
    "To send corrections, change only this language's entries in `shared/ui/ui-strings.json`, or its labels in "
    "`shared/contract/relationship-types.json` for the relationship types, keep the keys and the placeholders as they "
    "are, and open a pull request. The section Reviewing a translation in CONTRIBUTING.md has the details."
)


def load_strings(path: pathlib.Path) -> dict:
    return json.loads(path.read_bytes())


def load_types(path: pathlib.Path) -> list[dict]:
    return json.loads(path.read_bytes())["types"]


def type_labels(types: list[dict], locale: str) -> dict:
    """Each relationship type's name in one locale, keyed by the type, leaving out the types with no name in it."""
    return {kind["id"]: kind["labels"][locale] for kind in types if locale in kind["labels"]}


def locales(strings: dict) -> list[str]:
    """The locales to review, which is every locale in the file except English and the file's own notes."""
    return [locale for locale in strings if locale != SOURCE and not locale.startswith("_")]


def cell(text: str) -> str:
    """Table cell text with Markdown escaped and each placeholder shown as code."""
    parts = PLACEHOLDER.split(text.replace("\n", " "))
    return "".join(
        f"`{part}`" if PLACEHOLDER.fullmatch(part) else MARKDOWN_SPECIAL.sub(r"\\\1", part) for part in parts
    )


def times(count: int) -> str:
    return {1: "once", 2: "twice"}.get(count, f"{count} times")


def placeholder_finding(subject: str, name: str, expected: int, actual: int) -> str:
    if actual == 0:
        return f"{subject} is missing `{name}`, which the English has."
    if expected == 0:
        return f"{subject} has `{name}`, which the English does not."
    return f"{subject} has `{name}` {times(actual)}, where the English has it {times(expected)}."


def findings(english: dict, translation: dict, subject: str = "`{}`") -> list[str]:
    """What a machine can see is wrong: no translation, an empty one, or a placeholder written a different number of
    times from the English. The subject names each entry in the messages, with {} standing for its key."""
    found = []
    for key, text in english.items():
        name = subject.format(key)
        if key not in translation:
            found.append(f"{name} has no translation, so the page shows the English text.")
        elif not translation[key].strip():
            found.append(f"{name} is empty.")
        else:
            expected, actual = Counter(PLACEHOLDER.findall(text)), Counter(PLACEHOLDER.findall(translation[key]))
            found += [
                placeholder_finding(name, placeholder, expected[placeholder], actual[placeholder])
                for placeholder in sorted(expected | actual)
                if expected[placeholder] != actual[placeholder]
            ]
    return found


def translated_cell(translation: dict, key: str, right_to_left: bool) -> str:
    translated = cell(translation[key]) if key in translation else ""
    return f'<span dir="rtl">{translated}</span>' if right_to_left and translated else translated


def table(rows: tuple, english: dict, translation: dict, right_to_left: bool) -> list[str]:
    lines = ["| Key | Where it appears | English | Translation |", "| --- | --- | --- | --- |"]
    for key, place in rows:
        translated = translated_cell(translation, key, right_to_left)
        lines.append(f"| `{key}` | {place} | {cell(english.get(key, ''))} | {translated} |")
    return lines


def types_table(english: dict, translation: dict, right_to_left: bool) -> list[str]:
    lines = ["| Type | English | Translation |", "| --- | --- | --- |"]
    for key, text in english.items():
        lines.append(f"| `{key}` | {cell(text)} | {translated_cell(translation, key, right_to_left)} |")
    return lines


def review_sheet(strings: dict, locale: str, types: list[dict] = ()) -> str:
    english, translation = strings[SOURCE], strings[locale]
    english_types, translated_types = type_labels(types, SOURCE), type_labels(types, locale)
    right_to_left = locale in RIGHT_TO_LEFT
    name = translation.get("language_endonym")
    lines = [
        f"# Interface text review for {name} ({locale})" if name else f"# Interface text review ({locale})",
        "",
        "The Astrana Trusted Attestation Server shows the text below on its pages. A machine drafted the translation, "
        "so it needs a native speaker to check it. Each row gives where the text appears, the English and the "
        "translation.",
        "",
        "## What to check",
        "",
    ]
    lines += [f"- {check}" for check in CHECKS + ((RIGHT_TO_LEFT_CHECK,) if right_to_left else ())]
    found = findings(english, translation)
    found += findings(english_types, translated_types, "The relationship type `{}`")
    if found:
        lines += ["", "## Found by the script", ""] + [f"- {finding}" for finding in found]
    for heading, rows in PLACES:
        lines += ["", f"## {heading}", ""] + table(rows, english, translation, right_to_left)
    if english_types:
        lines += ["", f"## {TYPES_HEADING}", ""] + types_table(english_types, translated_types, right_to_left)
    lines += ["", "## Sending corrections", "", SENDING, ""]
    return "\n".join(lines)


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description="Write a Markdown review sheet for each translation.")
    parser.add_argument("--locale", action="append", help="the locale to write a sheet for, such as fr (repeatable)")
    parser.add_argument("--out", type=pathlib.Path, default=ROOT / "translation-review", help="the sheets' folder")
    parser.add_argument("--strings", type=pathlib.Path, default=ROOT / "shared" / "ui" / "ui-strings.json")
    parser.add_argument("--types", type=pathlib.Path, default=ROOT / "shared" / "contract" / "relationship-types.json")
    args = parser.parse_args(argv)

    strings = load_strings(args.strings)
    types = load_types(args.types)
    available = locales(strings)
    wanted = args.locale or available
    if SOURCE in wanted:
        print("English is the source text, so it has no review sheet.", file=sys.stderr)
        return 1
    unknown = [locale for locale in wanted if locale not in available]
    if unknown:
        print(f"Unknown locale {', '.join(unknown)}. The locales are {', '.join(sorted(available))}.", file=sys.stderr)
        return 1

    args.out.mkdir(parents=True, exist_ok=True)
    for locale in wanted:
        (args.out / f"{locale}.md").write_bytes(review_sheet(strings, locale, types).encode("utf-8"))
    print(f"Wrote {len(wanted)} review {'sheet' if len(wanted) == 1 else 'sheets'} to {args.out}.")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
