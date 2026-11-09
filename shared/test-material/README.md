# Test material

`personas.md` describes 249 people in plain language. `test-scenarios.md` is its technical companion, giving each
scenario's stack, identity provider, database, relationship type, jurisdiction and locale, and what it tests.
`personas.json` holds the same people as data. Nothing generates it, so when you change `personas.md` or
`test-scenarios.md`, make the same change in `personas.json`.

`test-cases.txt` holds the Gherkin scenarios for behaviour the personas cannot represent on their own, such as
concurrency, database role permissions, malformed input and refusing to start without TLS. It refers to personas by name
where one applies.

`invalid-manifests.json` holds manifests that every implementation's manifest validator must refuse. Each
implementation's unit tests read it, so that the three refuse the same manifests. When you add a rule to the validators,
add a manifest that breaks it.
