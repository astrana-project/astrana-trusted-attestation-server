<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\OpportunisticAuditPrune;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pruning that nobody has to remember to set up.
 *
 * This stack cannot assume a persistent process the way .NET and Java can, and a database-native
 * scheduler is not a reliable default either -- the MySQL event scheduler is often restricted on shared
 * hosts and PostgreSQL has none. So pruning rides on incoming requests, sampled so that most requests
 * pay nothing.
 *
 * The reason it is worth testing carefully is that every failure mode here is silent. A prune that never
 * fires leaves an audit log growing without limit, and retention length is what scales breach exposure.
 * A prune that fires on every request is a needless query on the verification hot path. Neither shows up
 * as an error anywhere; the log just quietly does the wrong thing for months.
 *
 * No database: the procedure call is intercepted and inspected. What is being tested is when the call is
 * made and with what, not what the procedure does with it. shared/test/role-checks.py covers that against
 * all three engines.
 *
 * A Feature test rather than a Unit one because it fakes the cache, the database and the logger, all
 * of which are facades and need the application container. Nothing here reaches a real database.
 */
final class OpportunisticAuditPruneTest extends TestCase
{
    private const LAST_PRUNED_KEY = 'trusted_attestation.audit.last_pruned_at';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Always sample, so the tests below are about the interval rather than about luck. The sampling
        // itself gets its own test.
        config()->set('trusted_attestation.audit.prune_enabled', true);
        config()->set('trusted_attestation.audit.check_probability', 1);
        config()->set('trusted_attestation.audit.interval_hours', 24);
        config()->set('trusted_attestation.audit.retention_days', 1825);
    }

    /**
     * Runs one request through the middleware and reports every procedure call it made.
     *
     * @return list<array{sql: string, bindings: array<int, mixed>}>
     */
    private function requestsMade(string $driver = 'pgsql', ?\Throwable $failWith = null): array
    {
        $calls = [];

        $connection = Mockery::mock();
        $connection->shouldReceive('getDriverName')->andReturn($driver);

        // A whole new instance swapped in, rather than another DB::shouldReceive on the existing one.
        // Mockery accumulates expectations and the earliest match wins, so a second call in the same
        // test would keep running the first call's closure -- appending to an array nobody reads while
        // the current one stays empty. The same trap Http::fake sets in IdTokenVerificationTest.
        $db = Mockery::mock();
        $db->shouldReceive('connection')->andReturn($connection);
        $db->shouldReceive('statement')->andReturnUsing(
            function (string $sql, array $bindings) use (&$calls, $failWith): bool {
                $calls[] = ['sql' => $sql, 'bindings' => $bindings];

                if ($failWith !== null) {
                    throw $failWith;
                }

                return true;
            }
        );

        DB::swap($db);

        $middleware = new OpportunisticAuditPrune;
        $middleware->handle(Request::create('/api/v1/attest'), fn () => new Response('', 200));

        return $calls;
    }

    // ---------------------------------------------------------------------------------------------
    // When it runs
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function it_prunes_when_nothing_has_pruned_before(): void
    {
        // A fresh deployment has no record of a previous prune. That must mean "due now", not "never":
        // an instance that only prunes once it has already pruned would never start.
        self::assertCount(1, $this->requestsMade());
    }

    #[Test]
    public function it_does_not_prune_again_within_the_interval(): void
    {
        Cache::put(self::LAST_PRUNED_KEY, Carbon::now('UTC')->subHours(1)->toIso8601String());

        self::assertSame([], $this->requestsMade());
    }

    #[Test]
    public function it_prunes_again_once_the_interval_has_elapsed(): void
    {
        Cache::put(self::LAST_PRUNED_KEY, Carbon::now('UTC')->subHours(25)->toIso8601String());

        self::assertCount(1, $this->requestsMade());
    }

    #[Test]
    public function it_does_nothing_at_all_when_pruning_is_turned_off(): void
    {
        // An organisation that prunes from its own job turns this off so the two do not both run.
        config()->set('trusted_attestation.audit.prune_enabled', false);

        self::assertSame([], $this->requestsMade());
        self::assertNull(Cache::get(self::LAST_PRUNED_KEY), 'it claimed a run it never made');
    }

    #[Test]
    public function most_requests_pay_nothing(): void
    {
        // The sampling is the reason this is affordable on the verification hot path. With a one-in-many
        // probability, a handful of requests should almost never all fire -- and the odds of this being
        // wrong by chance are smaller than the odds of the check itself being wrong.
        config()->set('trusted_attestation.audit.check_probability', 100_000);

        $fired = 0;
        for ($i = 0; $i < 5; $i++) {
            Cache::flush();
            $fired += count($this->requestsMade());
        }

        self::assertSame(0, $fired, 'the sampling is not sampling; every request would pay for a prune');
    }

    // ---------------------------------------------------------------------------------------------
    // What it runs
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function it_passes_the_configured_retention_to_the_procedure(): void
    {
        config()->set('trusted_attestation.audit.retention_days', 30);

        $calls = $this->requestsMade();

        self::assertSame([30], $calls[0]['bindings']);
    }

    #[Test]
    public function it_calls_the_procedure_the_way_each_engine_spells_it(): void
    {
        // The three engines differ here and the procedure does not. Getting this wrong means pruning
        // silently never works on one engine, which nothing else would reveal.
        //
        // Flushed between each, because a prune claims the interval when it runs -- without this only
        // the first driver is actually exercised and the other two assert against an empty list.
        foreach (['pgsql' => 'CALL', 'mysql' => 'CALL', 'sqlsrv' => 'EXEC'] as $driver => $keyword) {
            Cache::flush();
            $calls = $this->requestsMade($driver);

            self::assertCount(1, $calls, "no prune was attempted on {$driver}");
            self::assertStringContainsString("{$keyword} prune_audit_log", $calls[0]['sql']);
        }
    }

    #[Test]
    public function the_logic_stays_in_the_database(): void
    {
        // Only a procedure call, never a DELETE composed here. Retention is one of the things the
        // database owns, same as revoke and extend; only the schedule lives in the application.
        $sql = $this->requestsMade()[0]['sql'];

        self::assertStringNotContainsString('DELETE', strtoupper($sql));
    }

    // ---------------------------------------------------------------------------------------------
    // When it goes wrong
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function a_failing_prune_does_not_reach_the_member(): void
    {
        // The member-facing and verification paths do not depend on pruning having succeeded. Letting a
        // prune failure surface as a 500 would take the whole instance down over housekeeping.
        $connection = Mockery::mock();
        $connection->shouldReceive('getDriverName')->andReturn('pgsql');

        $db = Mockery::mock();
        $db->shouldReceive('connection')->andReturn($connection);
        $db->shouldReceive('statement')->andThrow(new \RuntimeException('the database went away'));
        DB::swap($db);

        $response = (new OpportunisticAuditPrune)->handle(
            Request::create('/api/v1/attest'),
            fn () => new Response('body', 200)
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('body', $response->getContent());
    }

    #[Test]
    public function a_request_arriving_while_another_holds_the_claim_does_not_prune(): void
    {
        // Two requests sampled at the same moment: the claim is taken with an atomic add, so the second one
        // finds it held and leaves without reading the timestamp, let alone pruning. The first releases
        // it when its check is over.
        Cache::put('trusted_attestation.audit.prune_claim', true, 60);

        self::assertSame([], $this->requestsMade(), 'it pruned while another request held the claim');
        self::assertNull(Cache::get(self::LAST_PRUNED_KEY), 'it recorded a run it never made');
    }

    #[Test]
    public function the_claim_is_released_once_the_check_is_over(): void
    {
        $this->requestsMade();

        self::assertNull(Cache::get('trusted_attestation.audit.prune_claim'), 'the claim outlived the check');
        self::assertNotNull(Cache::get(self::LAST_PRUNED_KEY));
    }

    #[Test]
    public function a_failing_cache_is_logged_and_never_reaches_the_member(): void
    {
        // Every cache call sits inside the try: a cache store that is down turns into a log line and a
        // skipped check, not a 500 on whichever request happened to be sampled.
        Log::spy();

        $cache = Mockery::mock();
        $cache->shouldReceive('add')->andThrow(new \RuntimeException('the cache went away'));
        Cache::swap($cache);

        $db = Mockery::mock();
        $db->shouldNotReceive('statement');
        DB::swap($db);

        $response = (new OpportunisticAuditPrune)->handle(
            Request::create('/api/v1/attest'),
            fn () => new Response('body', 200)
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('body', $response->getContent());
        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message): bool => str_contains($message, 'could not use the cache'))
            ->once();
    }

    #[Test]
    public function a_failing_prune_is_retried_at_the_next_interval_not_on_every_request(): void
    {
        // The run is claimed before it is attempted, deliberately. If the claim were only written on
        // success, a database that is refusing connections would turn every subsequent request into
        // another attempt -- most load at exactly the moment the instance can least afford it.
        $this->requestsMade(failWith: new \RuntimeException('the database went away'));

        self::assertNotNull(
            Cache::get(self::LAST_PRUNED_KEY),
            'a failed prune left no claim, so the next request would try again immediately'
        );

        self::assertSame([], $this->requestsMade(), 'it attempted a second prune straight away');
    }
}
