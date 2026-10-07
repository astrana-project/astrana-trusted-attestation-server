# Agent instructions

These instructions are for every artificial intelligence (AI) coding tool working in this repository. Everything in
[CONTRIBUTING.md](CONTRIBUTING.md) applies to the code and documents you write or edit, so read it before changing
anything.

## The repository

This repository holds three implementations of the Astrana Trusted Attestation Server, in `dotnet/`, `java/` and `php/`,
built against one shared contract in `shared/`. The [layout](README.md#layout) in the README shows where everything is.

## Acting for someone

Commit, push, open a pull request or create an issue only when the person you are working for asks you to. Never delete
a file unless they tell you to. When you open a pull request, credit yourself as
[AI coding assistants](CONTRIBUTING.md#ai-coding-assistants) describes.

## Where the rules are

- Working test first, in [Tests](CONTRIBUTING.md#tests)
- Making an observable change in all three implementations, in
  [What a pull request needs](CONTRIBUTING.md#what-a-pull-request-needs)
- The strictest security posture becoming the shared one, in
  [decision record 5](docs/adr/0005-three-implementations-held-to-behavioural-parity.md#decision)
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
