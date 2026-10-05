<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\TransactionRunner;
use Closure;

/** Runs the operation inline. The commit and rollback are the database's concern, and there is none here. */
final class ImmediateTransactionRunner implements TransactionRunner
{
    public function run(Closure $operation): mixed
    {
        return $operation();
    }
}
