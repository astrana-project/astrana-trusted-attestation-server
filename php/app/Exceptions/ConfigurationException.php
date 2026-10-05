<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A deployment's own configuration is wrong or unreadable: a malformed manifest, an unreadable
 * ui-strings or logo file, a missing issuer or IdP metadata URL, unusable local key material, a missing
 * schema with schema creation turned off. These are operator errors, raised mostly at startup, and the app refuses
 * to run rather than serve a half-configured page.
 *
 * Extends RuntimeException so existing handling -- the framework's, and the tests that assert on
 * RuntimeException -- is unchanged; the point is a name that says which kind of failure this is.
 */
final class ConfigurationException extends RuntimeException {}
