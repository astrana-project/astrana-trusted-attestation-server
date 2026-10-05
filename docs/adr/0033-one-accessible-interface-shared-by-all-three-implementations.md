# 33. One accessible interface shared by all three implementations

Accepted on 2026-10-05.

## Context

The server shows people three pages, a public landing page, the member's self-service page and a licence page. Members
may use assistive technology, and public bodies are required to meet accessibility standards. Parity
([record 5](0005-three-implementations-held-to-behavioural-parity.md)) has to reach the pages as well as the API.

## Decision

The pages meet the Web Content Accessibility Guidelines (WCAG) 2.1 at level AA. Colour never carries meaning on its own.
One stylesheet, built once from Bootstrap's Sass with only the parts that are used, and one strings file in `shared/ui`
serve all three implementations, each rendering with its own template engine. Dark mode follows the system preference
with no toggle. The landing page, the licence page, signing out and the language switcher work without JavaScript. The
self-service page calls the same API any other caller uses, so its actions need script
([record 20](0020-a-member-can-clear-an-attestation-key-revoke-or-remove-a-relationship-only-the-organisation-can-restore-one.md)),
and it carries that script inline. The paste button appears only when the browser offers a way to read the clipboard,
and the disclosure that hides revoke and remove appears only when script runs. The licence page is rendered in English
with no switcher, because it states the software's licence and trademark notice in the words they were written in. It
renders them from `shared/contract/attribution.json` and links to the third-party notices at `/THIRD-PARTY-NOTICES.txt`.
Every page carries the same footer, rendered from the same file, with the project's name linked to the Astrana project's
website and a link to the licence page, and the server has no setting to configure or remove it. The landing page may be
indexed, with the organisation's name in its title, and the self-service page carries `noindex, nofollow`.

## Consequences

- The page a member sees is the same whichever implementation served it. On every change to an implementation or to the
  shared inputs, the accessibility part of the suite checks the landing page and the self-service page against the rules
  a machine can detect. That is not a formal audit.
- The stylesheet replaces Bootstrap's stock colours with darker shades of the same hues, so the pages look like
  Bootstrap while clearing AA with some margin.
- The stylesheet needs a Node.js build, whose compiled output is committed so that no implementation needs the toolchain
  ([record 6](0006-keep-third-party-dependencies-to-a-minimum.md)). Organisations theme by overriding custom properties
  in a file that .NET and PHP read without a rebuild. Java packages the file in its jar, so a change there needs a
  rebuild, or a proxy that serves the organisation's own copy at the same address.
- The attribution reads the same in all three and changes in one place. The licence link's label is the only footer text
  that is translated, and outside the licence page it comes from the shared strings file. The footer is the software's
  attribution, not the organisation's branding, which comes from the manifest. The cost is that an organisation cannot
  rebrand the pages as entirely its own.
- The landing page is the front door a prospective member searches for. Keeping the self-service page out of search
  indexes keeps a member's name and relationships out of one even if a page fetched with their session reaches one.
- Level AA is the level that Section 508 in the United States and EN 301 549 in the European Union point at, which is
  why it is the bar and not level A or AAA.
- Status shown by colour alone was rejected because it fails the accessibility bar. A removable footer was rejected
  because the footer is the software's attribution. A full home-screen icon set was rejected because a member visits the
  page rarely. A hand-written stylesheet with no framework was rejected because Bootstrap's components already work with
  a keyboard and a screen reader, and writing them by hand leaves more to get right and keep right. A theme toggle was
  rejected because the system preference already says what the member wants, and a toggle needs script or a cookie on
  pages that otherwise need neither. Placeholder text as a label was rejected because it disappears as the member types
  and rarely meets the contrast bar. A contrast checker built into the implementations was rejected because the
  accessibility part of the suite already checks contrast, and it would be code in all three for one check.
