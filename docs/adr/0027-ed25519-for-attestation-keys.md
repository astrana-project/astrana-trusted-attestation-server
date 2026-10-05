# 27. Ed25519 for attestation keys

Accepted on 2026-10-05.

## Context

The member's Astrana instance signs a fresh challenge from the verifying Astrana instance with the relationship's
private key, and the server stores and looks up the public key
([record 3](0003-one-attestation-keypair-per-relationship.md) and
[record 4](0004-attestation-is-anonymous-and-answers-for-one-relationship.md)). The signature scheme had to be chosen
for the whole exchange. The choice falls to this project, though Astrana implements the signing and the checking.

## Decision

Attestation keys are Ed25519 keys, as standardised in Request for Comments (RFC) 8032, with 32-byte public keys and
64-byte signatures. The member's Astrana instance makes the signature and the verifying Astrana instance checks it. The
server's own part is to store the key and check its format.

## Consequences

- The keys are small. A 32-byte key, against several hundred bytes for a Rivest-Shamir-Adleman (RSA) key of comparable
  strength, keeps the unique key index compact at millions of rows. Signatures, 64 bytes against several hundred, never
  reach the server.
- Verification is fast, and the verifying Astrana instance runs it every time a member proves a relationship. A periodic
  re-check is a lookup of the key, with no new signature.
- The challenge and its freshness belong to Astrana. The verifying instance asks for a signature over a random value of
  at least 32 bytes, fresh for each proof and never reused, and refuses a signature over any other value. The server
  never sees the challenge or the signature.
- Signatures are deterministic. Signing does not depend on a fresh random number, so a reused or predictable nonce
  cannot leak the private key, a failure that has compromised keys of the Elliptic Curve Digital Signature Algorithm
  (ECDSA) in practice.
- It offers few choices and is hard to misuse, and audited implementations are widely available. The server needs none
  of them, because it stores keys and never verifies a signature, so the choice adds no dependency to it
  ([record 6](0006-keep-third-party-dependencies-to-a-minimum.md)).
- It is in wide production use, in Secure Shell (SSH) keys among others, and no practical attack on the scheme itself
  has been published.
- Like all elliptic-curve cryptography in current use it is not secure against a large quantum computer. No algorithm
  identifier is stored with the key, so moving to another scheme would be a breaking change to the schema and the
  contract. That is an accepted cost.
- RSA was rejected for far larger keys and signatures and much slower key generation and signing. ECDSA was rejected for
  its dependence on per-signature randomness.
