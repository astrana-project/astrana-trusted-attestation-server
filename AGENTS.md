# Agent instructions

These instructions are for every artificial intelligence (AI) coding tool working in this repository. Everything in
[CONTRIBUTING.md](CONTRIBUTING.md) applies to the code and documents you write or edit, so read it before changing
anything.

## The repository

This repository contains three implementations of the Astrana Trusted Attestation Server, in `dotnet/`, `java/` and
`php/`, built against one shared contract in `shared/`. The [layout](README.md#layout) in the README shows where
everything is.

## Acting for someone

Commit, push, open a pull request or create an issue only when the person you are working for asks you to. Never delete
a file unless they tell you to. When you open a pull request, credit yourself as
[AI coding assistants](CONTRIBUTING.md#ai-coding-assistants) describes.

## Working test first

Work test first, always. This is stricter than CONTRIBUTING.md, which recommends it for people. Before you write or
change any code, write the test that describes the behaviour you want, run it and see it fail for the reason you expect.
Then write the smallest code that makes it pass, and tidy the code only while the tests pass. A bug fix starts with the
test that reproduces the bug. Do not write code that no failing test asked for.

## Where the rules are

- The tests each change needs, in [Tests](CONTRIBUTING.md#tests)
- Making an observable change in all three implementations, in
  [What a pull request needs](CONTRIBUTING.md#what-a-pull-request-needs)
- The strictest security posture becoming the shared one, in the
  [decision record on parity](docs/adr/0005-three-implementations-held-to-behavioural-parity.md#decision)
- Code style, `shared/` as the single source, dependencies and the security rules, in [Code](CONTRIBUTING.md#code)
- Formatting, in [Formatting](CONTRIBUTING.md#formatting)
- Signing off commits, in [Developer Certificate of Origin](CONTRIBUTING.md#developer-certificate-of-origin)
- Changelogs and versions, in [Changelog and version](CONTRIBUTING.md#changelog-and-version)
- Secrets, in [Secrets](CONTRIBUTING.md#secrets)
- Reporting a vulnerability, in [Reporting a vulnerability](SECURITY.md#reporting-a-vulnerability)
- Accessibility, in [Code](CONTRIBUTING.md#code)
- Decision records, in [Architecture decision records](CONTRIBUTING.md#architecture-decision-records) and the
  [index](docs/adr/README.md)
- Writing documentation, in [Documentation](CONTRIBUTING.md#documentation)
