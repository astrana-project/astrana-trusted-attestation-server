# GitHub Copilot instructions

Guidance for Copilot and other AI coding tools working in this repository.

## What this is

This repository holds three implementations of the Astrana Trusted Attestation Server, in `dotnet/`, `java/` and `php/`,
built against one shared contract. On every request the contract and the shared interface define, a verifying Astrana
instance must not be able to tell which implementation answered. So make any change to observable behaviour in all
three.

## Layout

- `shared/contract/` holds `openapi.yaml`, `attribution.json`, `manifest.schema.json` and `relationship-types.json`.
- `shared/schema/` holds `schema-{postgres,mysql,mssql}.sql`.
- `shared/ui/` holds the SCSS source, the compiled CSS, the favicon and `ui-strings.json`.
- `shared/test/` holds the identity provider fixtures and the suite, whose three parts are the conformance, differential
  and accessibility suites.
- `shared/test-material/` holds the personas and Gherkin test cases.
- `docs/relationship-types.md` says what each relationship type means and `docs/adr/` holds the architecture decision
  records.

## Rules

Everything in [CONTRIBUTING.md](../CONTRIBUTING.md) applies to code you suggest or edit. Read its Code section before
changing anything. In particular:

- Keep code easy to understand and small.
- Change the sources in `shared/`, never an implementation's copy of them.
- Make an observable change in all three implementations at once, together with the contract or the shared interface in
  `shared/ui` and the conformance suite.
- Write the tests before the code.
- Keep third-party dependencies to a minimum.
- Do not weaken the security rules.
- Write a dated version heading in the affected changelogs.
- Run the formatters.
- Sign off every commit.

## Tests first

Work test first, always. Before you write or change any code, write the test that describes the behaviour you want, run
it and see it fail for the reason you expect. Then write the smallest code that makes it pass, and tidy the code only
while the tests pass.

- A bug fix starts with a test that reproduces the bug.
- A change to observable behaviour starts with the conformance or differential case in `shared/test`, as well as the
  unit tests in each implementation.
- Do not write code that no failing test asked for.
- Do not change a test to make it pass, unless a decision has changed the behaviour it describes.

## Accessibility

The pages must meet Web Content Accessibility Guidelines (WCAG) 2.1 AA. Colour is never the only carrier of meaning,
every control is reachable by keyboard and focus is visible.

## Documentation

Write for human readers, in plain British English. Expand an acronym the first time it appears in a document.

- Do not use em-dashes or semicolons, and do not write "e.g.", "i.e." or "etc.".
- Do not front a sentence with a label and a colon, such as "Note:". A line ending in a colon that introduces a list or
  a code block is fine.
- Do not write sentences about the document itself, or point to something described elsewhere without adding anything.
- Do not add status or history notes, such as "currently", "no longer" or "was changed".
- Do not put planning notes, task lists or commentary about the work into repository documents.

Write "Astrana Trusted Attestation" or "the Astrana Trusted Attestation Server" in full, never the acronym. Identifiers
such as `ata_session` and `astrana/ata-*` stay as they are.

Leave out filler words such as "deliberately", "simply", "by design" and "for now". Every sentence should give the
reader something they can use.

Decision records in `docs/adr/` follow the rules in the Architecture decision records section of
[CONTRIBUTING.md](../CONTRIBUTING.md).
