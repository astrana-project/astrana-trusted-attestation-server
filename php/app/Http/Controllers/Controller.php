<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Security\SafeReturnPath;

abstract class Controller
{
    /** Where every sign-out ends, whichever protocol the identity provider speaks and whatever it answered. */
    protected const SIGNED_OUT = '/signed-out';

    /**
     * A post-login redirect target that cannot leave this origin. The open-redirect rule itself lives in
     * {@see SafeReturnPath}, where it is unit-tested exhaustively; this is only the controllers' shorthand.
     */
    protected static function safeLocalPath(mixed $candidate, string $fallback = '/me'): string
    {
        return SafeReturnPath::sanitize($candidate, $fallback);
    }
}
