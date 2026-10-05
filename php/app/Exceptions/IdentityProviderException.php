<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The exchange with the identity provider failed at runtime: its discovery document or JWKS could not
 * be read or was unusable, the authorization-code exchange was refused, or a returned token did not
 * verify (wrong issuer, wrong audience, bad nonce, missing signing keys). Distinct from a
 * ConfigurationException, which is about this deployment's own settings rather than what the IdP
 * returned.
 *
 * Extends RuntimeException so existing handling is unchanged; the name records where the fault lies.
 */
final class IdentityProviderException extends RuntimeException {}
