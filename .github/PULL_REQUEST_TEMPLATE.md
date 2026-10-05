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
      with no `Unreleased` section. A shared or observable change bumps `MINOR` or `MAJOR` to the same version in all
      three. A fix to one implementation only bumps that implementation's `PATCH`. A change to documentation, tests or
      tooling only leaves the changelogs alone
- [ ] Documentation and decision records updated if the contract or behaviour changed
- [ ] All commits are signed off under the
      [Developer Certificate of Origin](https://github.com/astrana-project/astrana-trusted-attestation-server/blob/master/DCO)
      (`git commit -s`)
- [ ] I understand this pull request will be **squash-merged** into `master`
