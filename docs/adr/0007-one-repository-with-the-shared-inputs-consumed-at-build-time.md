# 7. One repository, with the shared inputs consumed at build time

Accepted on 2026-10-05.

## Context

If the contract and each implementation had a repository of its own, each would need its own copy of the contract, the
schemas, the stylesheet and the strings, kept identical by hand and by a script that fails when they drift. A contract
change would be several separate edits, with no way to land it as one unit with the three implementation changes that
satisfy it. The suite also expects the contract and all three implementations side by side in a fixed layout.

## Decision

The project lives in one public repository, `github.com/astrana-project/astrana-trusted-attestation-server`, with a
folder per implementation, `shared/` for the inputs all three consume, and `docs/` for the documentation and the
decision records. Each implementation copies the shared inputs in at build time, through embedded resources and a
build-time copy in .NET, Maven resources in Java and a Composer script in PHP, and the copies are not committed. The
public repository carries no history from earlier repositories.

## Consequences

- A shared change is one change in one pull request with one pipeline, and the copies cannot drift because there is one
  source.
- The root holds mixed toolchains. A contributor to one stack clones all three and, for a shared change, runs all three
  toolchains.
- Separate repositories were rejected because their copies drift and a contract change cannot land as one unit. A
  subfolder of an organisation-wide Astrana monorepo was rejected because this project has its own release cadence and
  its own licence and trademark boundary. Symlinks were rejected as fragile on Windows. A sync script whose output is
  committed was rejected because the committed copies can still drift. An `src/` folder for the three implementations
  was rejected because `shared/` and the documentation under `docs/` are source too.
