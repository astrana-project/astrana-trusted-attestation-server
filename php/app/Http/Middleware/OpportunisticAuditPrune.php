<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs prune_audit_log opportunistically, on incoming requests.
 *
 * Pruning is on by default rather than left to the host to remember (decision record 18 in docs/adr). Retention
 * being "the host's decision" only protects anyone if the decision actually gets made, and the smaller
 * organisations least likely to have a compliance team are exactly the ones most likely never to prune at
 * all, which is backwards, since retention length is what scales breach exposure.
 *
 * This stack cannot assume a persistent process the way the .NET and Java implementations can, and a
 * database-native scheduler is not a reliable default either, because the MySQL event scheduler is often
 * restricted on shared hosts, and PostgreSQL has none built in. So pruning is checked on a small random
 * fraction of requests and runs inline when it is due, the same pattern WordPress's own scheduler has
 * used at scale on the cheapest shared hosting for years. Nothing extra is installed, no crontab entry,
 * no second process, nothing beyond the single application already deployed.
 *
 * The trade-off is that this cannot honour a specific time of day. It fires on whichever qualifying
 * request happens to arrive after the interval has elapsed. An organisation that runs its own database
 * job instead sets TRUSTED_ATTESTATION_AUDIT_PRUNE_ENABLED to false so the two do not both run.
 */
final class OpportunisticAuditPrune
{
    private const LAST_PRUNED_KEY = 'trusted_attestation.audit.last_pruned_at';

    /**
     * Held by the one request that is checking whether a prune is due. Cache::add writes only when the key
     * is absent, so of two requests arriving together exactly one gets past it. Released when the check
     * is over, and short-lived in case a worker dies holding it.
     */
    private const CLAIM_KEY = 'trusted_attestation.audit.prune_claim';

    private const CLAIM_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! config('trusted_attestation.audit.prune_enabled')) {
            return $response;
        }

        // Most requests pay nothing at all: only a small sample even looks at the timestamp. One in 200
        // still fires many times a day on any instance with traffic, and on a near-idle instance the
        // audit log is not growing either.
        $probability = max(1, (int) config('trusted_attestation.audit.check_probability'));
        if (random_int(1, $probability) !== 1) {
            return $response;
        }

        $this->pruneIfDue();

        return $response;
    }

    private function pruneIfDue(): void
    {
        $intervalHours = max(1, (int) config('trusted_attestation.audit.interval_hours'));
        $retentionDays = (int) config('trusted_attestation.audit.retention_days');

        // Every cache call sits inside the try. A cache that is down or misconfigured is a reason to skip
        // this check and say so in the log, never a 500 for the member whose request happened to be
        // sampled. The pages and the attestation lookup do not depend on pruning.
        try {
            if (! Cache::add(self::CLAIM_KEY, true, self::CLAIM_SECONDS)) {
                return;
            }

            try {
                $lastPruned = Cache::get(self::LAST_PRUNED_KEY);

                if (is_string($lastPruned) && Carbon::parse($lastPruned)->addHours($intervalHours)->isFuture()) {
                    return;
                }

                // Recorded before the prune runs, so a failing prune is retried at the next interval,
                // not on every subsequent request.
                Cache::put(self::LAST_PRUNED_KEY, Carbon::now('UTC')->toIso8601String(), now()->addDays(30));
            } finally {
                Cache::forget(self::CLAIM_KEY);
            }
        } catch (\Throwable $exception) {
            Log::error('The audit prune could not use the cache, so this request did not check whether a prune is due.', [
                'reason' => $exception->getMessage(),
            ]);

            return;
        }

        $this->prune($retentionDays);
    }

    private function prune(int $retentionDays): void
    {
        try {
            // The logic itself stays in the database, same as revoke and extend. Only the schedule that
            // triggers it lives in the application. The three engines spell a procedure call
            // differently, and the procedure is identical.
            $call = DB::connection()->getDriverName() === 'sqlsrv'
                ? 'EXEC prune_audit_log @retention_days = ?'
                : 'CALL prune_audit_log(?)';

            DB::statement($call, [$retentionDays]);

            Log::info('Audit prune completed.', ['retention_days' => $retentionDays]);
        } catch (\Throwable $exception) {
            // A failed prune must not affect the response. The pages and the attestation lookup do not
            // depend on it, and the next interval will try again.
            Log::error('Audit prune failed. It will be retried at the next interval.', [
                'reason' => $exception->getMessage(),
            ]);
        }
    }
}
