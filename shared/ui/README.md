# The shared interface

All three implementations of the Astrana Trusted Attestation Server serve the files in this folder unchanged, so a
member sees the same page whichever one answered.
[Decision record 33](../../docs/adr/0033-one-accessible-interface-shared-by-all-three-implementations.md) says why.

| File                           | What it is                                                                                                                                                                                                                                                                                                                                                 |
| ------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `src/trusted-attestation.scss` | The source of the one stylesheet, Bootstrap's Sass with only the parts the pages use, and the palette darkened so that every colour meets Web Content Accessibility Guidelines (WCAG) 2.1 AA in light and dark mode.                                                                                                                                       |
| `dist/trusted-attestation.css` | The compiled stylesheet, committed so that no implementation needs the Node toolchain. Rebuild it with `npm ci` then `npm run build` here whenever the source changes.                                                                                                                                                                                     |
| `theme-overrides.css`          | The file an organisation edits to restyle the pages. It holds CSS custom properties and loads after the stylesheet. A commented example covers the buttons, the font and the backgrounds, and the contrast each must keep. It ships with nothing set.                                                                                                      |
| `favicon.svg`                  | The favicon, also the generic mark the pages show until the organisation sets a logo in its manifest.                                                                                                                                                                                                                                                      |
| `ui-strings.json`              | Every string the pages show, keyed by locale. English is complete. Where a locale has no translation for a string, the page shows the English one. The other translations were drafted by machine, for a native speaker to review before an organisation goes live. [Reviewing a translation](../../CONTRIBUTING.md#reviewing-a-translation) explains how. |

Each implementation copies these in at build time and serves them from its own origin. .NET copies the stylesheet, the
favicon and the overrides into its `wwwroot` and embeds the strings file in its assembly, Java packages them into its
resources and PHP copies them into `public/` and `resources/`. Nothing is fetched from a content delivery network and no
font is loaded from the network. Developers edit the files here, never an implementation's copy. An organisation
deploying the .NET or PHP server edits its deployed copy of `theme-overrides.css` and `favicon.svg`, with no rebuild.
Java packages both into its jar, so a Java deployment cannot edit them in place. An organisation running Java edits them
here and rebuilds, or has its proxy serve its own copies at the same addresses. Each installation page describes the
steps.

## The markup the landing page and the self-service page carry

The three template engines render the same document.

- `<meta name="viewport" content="width=device-width, initial-scale=1">`.
- `<html lang="…" dir="…">`, where `lang` is the full resolved locale tag, `zh-Hans` and not `zh`, and `dir` is `rtl`
  for Arabic, Hebrew, Persian, Urdu and Pashto.
  [Decision record 34](../../docs/adr/0034-organisation-configured-localisation-resolved-in-a-fixed-order.md) sets the
  order in which the locale is resolved.
- The organisation's name as the page heading and in the page's `<title>`. The landing page carries no `robots` tag. The
  self-service page carries `<meta name="robots" content="noindex, nofollow">`.
- On the self-service page, `<meta name="csrf-token" content="…">`, the framework's anti-forgery token, which the page's
  script sends in the `X-CSRF-TOKEN` header when the member revokes a relationship.
- The organisation's logo from the manifest with empty `alt` text, since the heading already names the organisation.
  When the manifest sets a dark variant, the logo is a `<picture>` whose `<source media="(prefers-color-scheme: dark)">`
  carries the dark image and whose `<img>` carries the light one as the fallback, so the browser chooses with no script.
  Until the organisation sets a logo, the pages show the generic mark.
- The stylesheet, then `theme-overrides.css`, both from the same origin, and `favicon.svg` as the icon.
- A `<footer>` element with the project name linked to the Astrana project and a Licence link to the licence page,
  rendered from `shared/contract/attribution.json`, with the licence link's label from the strings file.

The licence page states the software's own licence and trademark notice, so it is always in English and carries
`lang="en" dir="ltr"`. It has the project name as its heading and title, and no organisation name or logo. It uses the
same stylesheets, icon and footer, with the licence link's English label from `attribution.json`. It also links to
`/THIRD-PARTY-NOTICES.txt`, the notices for the third-party software that implementation includes.

The landing page and the licence page work without JavaScript, and so do signing out and the language switcher. Saving,
revoking and removing on the self-service page need it. On the self-service page the paste button appears only when the
browser can read the clipboard, and the More Actions toggle only when JavaScript runs. Without JavaScript every action
stays visible. Every page is usable at a 320 pixel viewport and at 200 percent text size with no horizontal scrolling,
every control is reachable by keyboard with visible focus, and colour never carries meaning on its own. The
accessibility suite in `shared/test` checks the rules a machine can detect.
