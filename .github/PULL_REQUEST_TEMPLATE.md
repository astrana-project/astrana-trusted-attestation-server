<!-- Thanks for contributing. Keep the pull request focused. It will be squash-merged into master (see CONTRIBUTING.md). -->

## Summary

<!-- What does this change, and why? -->

## Linked issue

<!-- Such as "Closes #123" -->

## Type of change

- [ ] Bug fix
- [ ] New feature
- [ ] Contract change (affects observable behaviour)
- [ ] Documentation or tooling only

## Checklist

- [ ] Tests added or updated, and the affected implementation's unit tests pass
- [ ] The conformance, differential and accessibility suites pass
- [ ] If this changes the contract or a shared input (`shared/`), **all three implementations** (.NET, Java, PHP) are
      updated together and still behave identically
- [ ] If the change is released, each affected implementation's `CHANGELOG.md` has a **dated version heading** for it,
      with no `Unreleased` section, and the version follows
      [Changelog and version](https://github.com/astrana-project/astrana-trusted-attestation-server/blob/master/CONTRIBUTING.md#changelog-and-version)
      in CONTRIBUTING.md, with the version set in `java/pom.xml` for Java and the implementation's `sbom.json`
      regenerated as its README describes
- [ ] Documentation and decision records updated if the contract or behaviour changed
- [ ] All commits are signed off under the
      [Developer Certificate of Origin](https://github.com/astrana-project/astrana-trusted-attestation-server/blob/master/DCO)
      (`git commit -s`)
- [ ] I understand this pull request will be **squash-merged** into `master`
