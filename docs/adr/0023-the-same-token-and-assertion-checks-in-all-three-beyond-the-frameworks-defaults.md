# 23. The same token and assertion checks in all three, beyond the frameworks' defaults

Accepted on 2026-10-05.

## Context

A real identity provider only ever mints valid tokens, so the checks that matter are the ones a forged or misdirected
token meets. Each stack's OpenID Connect and SAML libraries validate a token well enough for its own users, but the
three differ in what they check by default, and a token one implementation accepts and another refuses is a divergence
([record 5](0005-three-implementations-held-to-behavioural-parity.md)) in the most sensitive place there is.

## Decision

Every implementation applies the same checks, in its own code where the framework does not. For an OpenID Connect ID
token, a token with more than one audience must carry an authorised party (`azp`), any `azp` must name this client, the
subject (`sub`), the issued-at time (`iat`) and the expiry are required rather than optional, the clock skew is 60
seconds and a nonce is required and matched to the sign-in that started it. Two habits the standards allow are
tolerated. One is a JSON Web Key without the optional `alg` parameter. The other is an issuer that differs from the
configured one only by a trailing slash. For a SAML assertion, a response with no `InResponseTo` is refused and a
subject attribute with more than one value falls back to the NameID
([record 2](0002-members-sign-in-through-the-organisations-identity-and-access-management-system.md)). The signature
method and every digest method must be SHA-256, the 256-bit Secure Hash Algorithm, or stronger, and the clock skew is
180 seconds.

## Consequences

- The same token or assertion is accepted or refused on every stack at the same boundary.
- Left to themselves, the frameworks differ. Some check the audience but not the authorised party, some validate an
  expiry only when one is present, some allow several minutes of skew and some reject an unsolicited SAML response only
  when it carries an `InResponseTo` to reject.
- Tolerating the two habits keeps one stack from refusing what another accepts.
- A provider that signs with SHA-256 over a SHA-1 digest, which is only as strong as the digest, is refused until it is
  reconfigured. That is a cost an organisation with an old provider pays.
- The SAML skew is wider than the OpenID Connect skew because the SAML ecosystem settled on a wider timing budget, and
  pinning a value that matches it is what keeps the three alike.
- Relying on each framework's defaults was rejected because they differ, and tightening only the strictest stack was
  rejected because the other two would then accept what it refuses.
