package org.astrana.trustedattestation.data;

/** What a relationship's standing is, as the contract spells it on the wire. */
public enum RelationshipStatus {

    /**
     * Granted by the organisation, but the member has not set a key for it yet.
     *
     * <p>The normal state immediately after a grant, and the reason {@code public_key} is nullable at
     * all. Never appears in an attest response: a key has to exist before anyone can ask about one.
     */
    UNKEYED("unkeyed"),

    ACTIVE("active"),
    REVOKED("revoked"),
    EXPIRED("expired");

    private final String wireValue;

    RelationshipStatus(String wireValue) {
        this.wireValue = wireValue;
    }

    /**
     * The contract's enum value. Held separately from {@link #name()} deliberately: these strings go to
     * every verifying peer, so they must not follow this enum's Java naming conventions around.
     */
    public String wireValue() {
        return wireValue;
    }
}
