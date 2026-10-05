<?php

declare(strict_types=1);

namespace App\Services;

use Closure;

/**
 * Runs an operation inside one database transaction, committing on success and rolling back on any
 * exception it throws.
 *
 * Behind an interface so the write path's atomic unit -- find the relationship, check the key, write the
 * audit row and update, all or nothing -- can be unit-tested by running the operation directly. It plays
 * the same part as the Spring TransactionTemplate and the .NET transaction runner the other two
 * implementations compose the same logic on, so the three write paths are shaped the same way rather than
 * only behaving the same.
 *
 * @template T
 */
interface TransactionRunner
{
    /**
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(Closure $operation): mixed;
}
