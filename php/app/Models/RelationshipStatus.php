<?php

declare(strict_types=1);

namespace App\Models;

/**
 * What a relationship's standing is, as the contract spells it on the wire.
 *
 * A backed enum, so the wire value is the case's own value rather than a mapping kept alongside it:
 * these strings go to every verifying peer, and a rename is a breaking change, not a refactor.
 */
enum RelationshipStatus: string
{
    /**
     * Granted by the organisation, but the member has not set a key for it yet.
     *
     * The normal state immediately after a grant, and the reason public_key is nullable at all. Never
     * appears in an attest response: a key has to exist before anyone can ask about one.
     */
    case Unkeyed = 'unkeyed';

    case Active = 'active';
    case Revoked = 'revoked';
    case Expired = 'expired';
}
