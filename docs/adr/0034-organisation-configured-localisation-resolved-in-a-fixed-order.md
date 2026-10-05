# 34. Organisation-configured localisation, resolved in a fixed order

Accepted on 2026-10-05.

## Context

Organisations operate in several jurisdictions and languages, and the pages are read by members of the public. The
language a page renders in, and its direction, have to be decided the same way by all three implementations, or the same
request gives different pages. The organisation's own text has to be available in the languages it serves.

## Decision

The organisation declares the locales it supports, in order, and the pages offer those of them that the shared strings
file ships. An organisation that declares none offers every shipped locale, ordered by each language's own name with its
default first. A request's locale is resolved in a fixed order. First a locale the member chose with the page's own
switcher, held in the `ata_locale` cookie
([record 21](0021-one-session-cookie-identifies-the-member-and-it-never-crosses-sites.md)) and validated against the
offered list. Then a locale claim from the identity provider. Then the full Accept-Language list. Then the
organisation's default. A tag from any of those sources is matched to the offered set by one rule. An exact match first.
Then, for a language shipped in more than one script, the script. The tag either names the script or implies it through
its region. For Chinese, a region of Taiwan, Hong Kong or Macao means the traditional script, and any other region, or
none, the simplified one. Then the first offered locale with the same language, so `fr-CA` finds `fr`. The page's `lang`
attribute carries the full resolved tag, `zh-Hans` and not `zh`. Text direction comes from an explicit list of
right-to-left languages, Arabic, Hebrew, Persian, Urdu and Pashto, not from the runtime's culture data. Every string
exists in English, and where a locale has no translation for a string, the page shows the English one. The
organisation's own text in the manifest is per locale too
([record 30](0030-the-manifest-is-the-public-discovery-document.md)).

## Consequences

- The same request renders the same page in the same language in all three. That needs the script rule, because left to
  themselves the frameworks resolve a request for Chinese as written in Taiwan differently, Spring and Laravel by
  language prefix to the simplified script, and ASP.NET Core by its culture data to the traditional one.
- No query parameter sets the locale, so that a crafted link cannot.
- The shipped translations are machine drafts, for a native speaker to read before an organisation goes live, because
  the string set is small enough for that to be the organisation's job rather than the project's.
- Machine-translating the organisation's text inside the server was rejected, because bundling a translation dependency
  into three implementations works against [record 6](0006-keep-third-party-dependencies-to-a-minimum.md). The
  organisation's name is never machine-translated, because it is a proper noun. Where the manifest has no text in the
  language an Astrana instance needs, that instance decides what to show, and discloses any fallback or machine
  translation.
