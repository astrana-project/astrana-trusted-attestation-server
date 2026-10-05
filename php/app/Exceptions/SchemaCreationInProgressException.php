<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Another instance started creating the schema in an empty database at the same moment as this one, and
 * the schema was still incomplete after this instance had waited for it. The request is refused and the
 * next one checks again. Distinct from a ConfigurationException, because nothing in this deployment's
 * settings is wrong, and the condition clears by itself once the other instance has finished.
 *
 * Extends RuntimeException so existing handling is unchanged. The name records which kind of failure this is.
 */
final class SchemaCreationInProgressException extends RuntimeException {}
