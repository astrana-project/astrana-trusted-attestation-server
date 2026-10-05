<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The transaction runner the application actually uses: Laravel's DB::transaction, which commits when the
 * operation returns and rolls back when it throws. A KeyConflictException thrown by the store on a lost
 * unique-index race travels out through here, rolling the update and its audit row back together.
 */
final class DbTransactionRunner implements TransactionRunner
{
    public function run(Closure $operation): mixed
    {
        return DB::transaction($operation);
    }
}
