<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Attempts\AttemptStatus;
use DF\HorizonObservability\Attempts\AttemptStore;
use DF\HorizonObservability\Collectors\AttemptsCollector;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Metrics\ScrapeContext;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Storage;
use DF\HorizonObservability\Tests\TestCase;
use DF\HorizonObservability\Tests\Truth\Support\Model;
use DF\HorizonObservability\Tests\Truth\Support\PrivateRedis;
use DF\HorizonObservability\Tests\Truth\Support\Samples;
use DF\HorizonObservability\Tests\Truth\Support\Scenario;
use DF\HorizonObservability\Tests\Truth\Support\TruthJob;

/*
| The first value of a new counter series (the pilot's Completed and Failed tiles dropped it).
|
| Prometheus cannot count the first sample of a series: increase() and the dashboard's exact differences start
| from it. The exporter therefore exports a series created less than AttemptsCollector::NEW_SERIES_HOLD_SECONDS
| (90 s) ago as 0, so every scraper records a 0 before the real value, which then counts in full. The creation time
| and the age are on one clock, the storage Redis' (ServerClock: TIME inside record_attempt.lua and new_series.lua;
| pinned in these tests as Storage's `server:`), so the worker's and the replicas' clocks never enter. The budget: one
| scraper's successful scrapes read the counters less than 90 s apart (S <= 60 s, less than 15 s off the slots). A
| released series loses its creation time and is never held again. A series without a creation time (the counters
| a new scrape job or a late replica finds, data from before the creation times) is exported as it is: its old
| total must not count as new.
| Evidence on a real Prometheus v3.15.0: tests/Truth/probes/first-value and probes/replica-clocks (README there).
*/
pest()->group('redis', 'truth');

afterEach(function (): void {
    Scenario::stop();
});

/**
 * @return array{completed: int, durations: int, waits: int}
 */
function firstValueCounts(Scenario $scenario, string $class = 'Truth\\Invoice', string $queue = 'default'): array
{
    $samples = new Samples($scenario->scrape());
    $series = ['connection' => 'redis', 'queue' => $queue, 'job_class' => $class];

    return [
        'completed' => (int) $samples->value(Metric::JobsTotal->value, [...$series, 'status' => 'completed']),
        'durations' => (int) $samples->value(Metric::JobDuration->value.'_count', $series),
        'waits' => (int) $samples->value(Metric::JobWait->value.'_count', ['connection' => 'redis', 'queue' => $queue]),
    ];
}

it('restates the collector hold in the model and in the HELP texts of the held families', function (Metric $metric): void {
    expect(Model::HOLD_SECONDS)->toBe(AttemptsCollector::NEW_SERIES_HOLD_SECONDS)
        ->and($metric->help())->toContain('a new series reads 0 for its first '.AttemptsCollector::NEW_SERIES_HOLD_SECONDS.' seconds');
})->with([Metric::JobsTotal, Metric::JobDuration, Metric::JobWait]);

it('exports a new series as 0 for the hold, then with every attempt it was created with', function (): void {
    $scenario = Scenario::start($this);

    foreach (range(1, 3) as $ignored) {
        $scenario->dispatch(new TruthJob('Truth\\Invoice'));
    }

    $scenario->advance(2)->work();
    $zero = ['completed' => 0, 'durations' => 0, 'waits' => 0];

    expect(AttemptsCollector::NEW_SERIES_HOLD_SECONDS)->toBe(90)
        ->and(firstValueCounts($scenario))->toBe($zero)
        ->and(firstValueCounts($scenario->advance(30)))->toBe($zero)
        ->and(firstValueCounts($scenario->advance(AttemptsCollector::NEW_SERIES_HOLD_SECONDS - 30.001)))->toBe($zero)
        ->and(firstValueCounts($scenario->advance(0.001)))->toBe(['completed' => 3, 'durations' => 3, 'waits' => 3]);
});

it('does not hold later attempts, a new status of a known series or the waits of a known queue', function (): void {
    $scenario = Scenario::start($this);
    $scenario->dispatch(new TruthJob('Truth\\Invoice'))->work()->advance(AttemptsCollector::NEW_SERIES_HOLD_SECONDS);

    expect(firstValueCounts($scenario))->toBe(['completed' => 1, 'durations' => 1, 'waits' => 1]);

    $scenario->dispatch(new TruthJob('Truth\\Invoice'))
        ->dispatch(new TruthJob('Truth\\Invoice', ['fail']))
        ->dispatch(new TruthJob('Truth\\Fresh'))
        ->work();
    $samples = new Samples($scenario->scrape());
    $invoice = ['connection' => 'redis', 'queue' => 'default', 'job_class' => 'Truth\\Invoice'];
    $fresh = ['connection' => 'redis', 'queue' => 'default', 'job_class' => 'Truth\\Fresh'];

    expect($samples->value(Metric::JobsTotal->value, [...$invoice, 'status' => 'completed']))->toBe(2.0)
        // The first failure of a known series: zero-filled before, so increase() counts it.
        ->and($samples->value(Metric::JobsTotal->value, [...$invoice, 'status' => 'failed']))->toBe(1.0)
        ->and($samples->value(Metric::JobDuration->value.'_count', $invoice))->toBe(3.0)
        // A new class on a known queue: its series wait, the queue's waits do not.
        ->and($samples->value(Metric::JobsTotal->value, [...$fresh, 'status' => 'completed']))->toBe(0.0)
        ->and($samples->value(Metric::JobDuration->value.'_count', $fresh))->toBe(0.0)
        ->and($samples->value(Metric::JobWait->value.'_count', ['connection' => 'redis', 'queue' => 'default']))->toBe(4.0);
});

it('exports series without a creation time as they are, so old totals never count as new', function (): void {
    $scenario = Scenario::start($this);

    foreach (range(1, 4) as $ignored) {
        $scenario->dispatch(new TruthJob('Truth\\Invoice'));
    }

    $scenario->work();
    // Counters without creation times: a scrape job added later or a late replica finds them like this.
    Storage::store()->reader()->del((new Keys)->created());

    expect(firstValueCounts($scenario))->toBe(['completed' => 4, 'durations' => 4, 'waits' => 4]);
});

/**
 * The storage Redis' own clock now (`TIME`), Unix seconds.
 */
function firstValueServerTime(): float
{
    $reply = Storage::store()->reader()->eval("local t = redis.call('TIME') return {t[1], t[2]}", 0);

    return is_array($reply) ? (float) $reply[0] + (float) $reply[1] / 1e6 : throw new LogicException('TIME failed.');
}

/**
 * Record one attempt on the production path: the server clock not pinned (`TIME`), the worker's clock `$now`.
 */
function firstValueRecordUnpinned(CarbonImmutable $now, string $queue = 'default', ?float $startedAt = null, ?float $readyAt = null, string $jobClass = 'App\\Jobs\\SendInvoice'): void
{
    expect(Storage::clock()->pinned())->toBeNull()
        ->and(Storage::store()->record('redis', $queue, $jobClass, AttemptStatus::Completed, 0.2, $startedAt, $readyAt, null, $now))->toBeTrue();
}

/**
 * The attempts collector's `queue_jobs_total` on the production path: the server clock not pinned (`TIME`), the
 * exporter's clock `$now`.
 *
 * @return array<string, int|float>
 */
function firstValueScrapeUnpinned(CarbonImmutable $now): array
{
    expect(Storage::clock()->pinned())->toBeNull();

    return Storage::samples(app(AttemptsCollector::class)->collect(new ScrapeContext($now, 8.0)))['queue_jobs_total'];
}

/**
 * The attempts collector's `queue_jobs_total` and its failures on the production path (nothing pinned).
 *
 * @return array{0: array<string, int|float>, 1: list<string>}
 */
function firstValueScrapeWithFailures(): array
{
    expect(Storage::clock()->pinned())->toBeNull();
    $context = new ScrapeContext(CarbonImmutable::now(), 8.0);
    $samples = Storage::samples(app(AttemptsCollector::class)->collect($context));

    return [$samples['queue_jobs_total'] ?? [], array_map(static fn (array $failure): string => $failure['source'].': '.$failure['exception']->getMessage(), $context->takeFailures())];
}

/**
 * A started private Redis (PrivateRedis) as the package's storage connection, with `$credentials` (an ACL user that
 * `$prepare` creates); skipped where there is none, a failure on CI.
 *
 * @param  array<string, string>  $credentials
 * @param  (callable(PrivateRedis): void)|null  $prepare
 */
function firstValuePrivateRedis(TestCase $test, array $credentials = [], ?callable $prepare = null): PrivateRedis
{
    $redis = PrivateRedis::create();

    if (! $redis instanceof PrivateRedis) {
        expect(PrivateRedis::onCi())->toBeFalse(PrivateRedis::unavailable());
        $test->markTestSkipped(PrivateRedis::unavailable());
    }

    $redis->start();

    try {
        if ($prepare !== null) {
            $prepare($redis);
        }
    } catch (Throwable $e) {
        $redis->close();

        throw $e;
    }

    config()->set('database.redis.truth-private', ['host' => '127.0.0.1', 'port' => $redis->port, 'database' => 0, ...$credentials]);
    config()->set('horizon-observability.redis.connection', 'truth-private');

    foreach ([Config::class, RedisClients::class, AttemptStore::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    return $redis;
}

/**
 * How far a scrape at `$slot` (seconds after the creation) reads the counters off its slot, within ±j, j = 15 s less
 * 1 ms (the budget: one scraper's successful scrapes read the counters less than 90 s apart, i.e. S <= 60 s and less
 * than 15 s off the slots). `worst`: the slots whose early read comes before the creation are early, every later one
 * is late, so the first read that sees the series comes as late as the budget allows (S + 2j after the creation,
 * less 1 ms).
 */
function firstValueDeviation(string $pattern, int $k, float $slot): float
{
    $j = 14.999;

    return match ($pattern) {
        'none' => 0.0,
        'worst' => $slot < $j ? -$j : $j,
        'early-late' => $k % 2 === 0 ? -$j : $j,
        'late-early' => $k % 2 === 0 ? $j : -$j,
        default => throw new LogicException($pattern),
    };
}

it('exports a new series as 0 on its first scrape within the budget, whatever the worker and replica clocks say', function (int $interval, float $phase, string $pattern, array $clocks): void {
    // One clock decides the hold: the storage Redis' (ServerClock, pinned here as `server:`). The worker that
    // records the first attempt and the two replicas behind the one scrape target, which answer the scrapes in turn,
    // have clocks of their own, off the server's by the given offsets.
    $hold = AttemptsCollector::NEW_SERIES_HOLD_SECONDS;
    $created = 1_800_000_000;
    $at = static fn (float $seconds): CarbonImmutable => CarbonImmutable::createFromTimestamp($seconds);
    Storage::record(status: AttemptStatus::Completed, duration: 0.2, now: $at($created + $clocks['worker']), server: $created);
    $series = 'connection=redis,queue=default,job_class=App\\Jobs\\SendInvoice,status=completed';
    $reads = [];

    // Counter reads on a fixed grid of slots, each one off its slot by the pattern, in the order they happen (on the
    // server clock; alternating deviations at short intervals reorder the grid).
    for ($k = -2; ($slot = $phase + $k * $interval) < $hold + 30 + 3 * $interval; $k++) {
        $read = $created + $slot + firstValueDeviation($pattern, $k, $slot);

        if ($read >= $created) {
            $reads[] = $read; // before the attempt, the series does not exist yet
        }
    }

    sort($reads);
    $values = [];

    foreach ($reads as $index => $read) {
        $replica = $clocks['replicas'][$index % 2];
        $value = Storage::scrape($at($read + $replica), server: $read)['samples']['queue_jobs_total'][$series];

        // The rule, restated: held while the age on the server clock is below the hold.
        expect($value)->toBe($read - $created < $hold ? 0 : 1, sprintf('read at +%.3f s via the replica %+d s', $read - $created, $replica));
        $values[] = $value;
    }

    // The guarantee: the first sample of the series is 0, then the full count, never decreasing.
    $sorted = $values;
    sort($sorted);

    expect($values[0])->toBe(0)
        ->and(end($values))->toBe(1)
        ->and($values)->toBe($sorted);
})->with(function (): array {
    $cases = [];
    $clocks = [
        'worker -1 h, replicas 0 and +15 s' => ['worker' => -3600, 'replicas' => [0, 15]],
        'worker in sync, replicas -15 s and +15 s' => ['worker' => 0, 'replicas' => [-15, 15]],
        'worker +1 h, replicas +1 h and -1 h' => ['worker' => 3600, 'replicas' => [3600, -3600]],
    ];

    foreach ([5, 10, 15, 30, 45, 60] as $interval) {
        // A slot right at the creation, mid-interval, right before the next slot, and j less 1 ms after the creation
        // (with `worst`, that read comes 1 ms before the creation and the next one S + 2j - 1 ms after it).
        foreach ([0.0, $interval / 2, $interval - 0.001, 14.998] as $phase) {
            foreach (['none', 'worst', 'early-late', 'late-early'] as $pattern) {
                foreach ($clocks as $name => $offsets) {
                    $cases[sprintf('S=%d phase=%.3f %s, %s', $interval, $phase, $pattern, $name)] = [$interval, $phase, $pattern, $offsets];
                }
            }
        }
    }

    return $cases;
});

it('holds the worst case of the budget by 1 ms: the first read 89.999 s after the creation on the server clock', function (): void {
    // S = 60 s: the read before the creation 15 s early (1 ms before it), the next one 15 s late: 60 + 30 - 0.001.
    // The worker's clock is an hour ahead, the exporter's an hour behind: neither enters.
    $created = 1_800_000_000;
    Storage::record(now: CarbonImmutable::createFromTimestamp($created + 3600), server: $created);
    $series = 'connection=redis,queue=default,job_class=App\\Jobs\\SendInvoice,status=completed';
    $scrape = static fn (float $read): int|float => Storage::scrape(CarbonImmutable::createFromTimestamp($read - 3600), server: $read)['samples']['queue_jobs_total'][$series];

    expect($scrape($created + 89.999))->toBe(0)
        ->and($scrape($created + 90.0))->toBe(1);
});

it('holds a series created during the scrape, after its request time', function (): void {
    // The scrape's request time is the exporter's clock for every collector; the counters are read up to the scrape
    // budget (8 s at most) later. The hold is read after them on the server clock, so a series created in between
    // is a new one there, whatever the request time says.
    $request = 1_800_000_000;
    Storage::record(now: CarbonImmutable::createFromTimestamp($request + 7.9), server: $request + 7.9);
    $series = 'connection=redis,queue=default,job_class=App\\Jobs\\SendInvoice,status=completed';

    expect(Storage::scrape(CarbonImmutable::createFromTimestamp($request), server: $request + 7.95)['samples']['queue_jobs_total'][$series])->toBe(0)
        ->and(Storage::scrape(CarbonImmutable::createFromTimestamp($request + 90), server: $request + 97.95)['samples']['queue_jobs_total'][$series])->toBe(1);
});

it('stamps the creation time with the server clock, never the worker\'s (TIME, not pinned)', function (int $offset): void {
    // The production path: no pinned clock, so record_attempt.lua reads TIME.
    $before = firstValueServerTime();
    firstValueRecordUnpinned(CarbonImmutable::now()->addSeconds($offset));
    firstValueRecordUnpinned(CarbonImmutable::now()->addSeconds($offset), 'mail', microtime(true) - 2, microtime(true) - 3);
    $after = firstValueServerTime();
    $created = Storage::hash((new Keys)->created());

    expect(array_keys($created))->toBe(['redis|default|App\\Jobs\\SendInvoice', 'redis|mail', 'redis|mail|App\\Jobs\\SendInvoice'])
        ->and(array_filter($created, static fn (string $at): bool => (float) $at < $before || (float) $at > $after))->toBe([])
        ->and(array_map(static fn (string $at): int => preg_match('/^\d+\.\d{6}$/', $at), $created))->toBe(array_fill_keys(array_keys($created), 1));
})->with(['worker an hour ahead' => 3600, 'worker an hour behind' => -3600, 'worker in sync' => 0]);

it('measures the age on the server clock, never the exporter\'s (TIME, not pinned)', function (): void {
    // The production path again: TIME on both sides; the exporter's clock (the scrape's request time) is skewed by an
    // hour either way. The creation time is moved back on the server clock to age the series.
    $series = 'connection=redis,queue=default,job_class=App\\Jobs\\SendInvoice,status=completed';
    $field = Keys::field('redis', 'default', 'App\\Jobs\\SendInvoice');
    $created = (new Keys)->created();
    firstValueRecordUnpinned(CarbonImmutable::now()->addHour());
    $scrape = static fn (int $skew): int|float => firstValueScrapeUnpinned(CarbonImmutable::now()->addSeconds($skew))[$series];

    expect($scrape(3600))->toBe(0)
        ->and($scrape(-3600))->toBe(0);

    Storage::store()->reader()->hset($created, $field, (string) (firstValueServerTime() - AttemptsCollector::NEW_SERIES_HOLD_SECONDS + 1));

    expect($scrape(3600))->toBe(0)
        ->and($scrape(-3600))->toBe(0);

    Storage::store()->reader()->hset($created, $field, (string) (firstValueServerTime() - AttemptsCollector::NEW_SERIES_HOLD_SECONDS - 0.5));

    // Released: the creation time is gone, so no later scrape and no clock can hold the series again.
    expect($scrape(-3600))->toBe(1)
        ->and(Storage::hash($created))->not->toHaveKey('redis|default|App\\Jobs\\SendInvoice')
        ->and($scrape(3600))->toBe(1)
        ->and($scrape(0))->toBe(1);
});

it('never holds a released series again, whatever the server clock does later', function (): void {
    // A counter exported with its value must never drop back to 0 (a fake reset): the release deletes the creation
    // time, so even a server clock stepped back by minutes or hours finds nothing to hold.
    $created = 1_800_000_000;
    Storage::record(server: $created);
    $series = 'connection=redis,queue=default,job_class=App\\Jobs\\SendInvoice,status=';
    $scrape = static fn (float $server): array => Storage::scrape(server: $server)['samples']['queue_jobs_total'];

    expect($scrape($created + 90)[$series.'completed'])->toBe(1)
        ->and(Storage::hash((new Keys)->created()))->toBe([])
        ->and($scrape($created + 10)[$series.'completed'])->toBe(1)
        ->and($scrape($created - 3600)[$series.'completed'])->toBe(1);

    // A new status of the released series is no new series: counted at once (zero-filled before).
    Storage::record(status: AttemptStatus::Failed, server: $created + 20);

    expect($scrape($created + 21)[$series.'failed'])->toBe(1)
        ->and(Storage::hash((new Keys)->created()))->toBe([]);
});

it('holds a creation time ahead of the server clock until the clock is the hold past it, and releases one the hold or more ahead', function (): void {
    // Only a step back of the server clock puts a creation time ahead of it. A series 30 s ahead is held, at most
    // for the hold past its creation time; one 90 s or more ahead is released at once, and stays released.
    $created = 1_800_000_000;
    Storage::record(jobClass: 'App\\Jobs\\Ahead', server: $created);
    Storage::record(jobClass: 'App\\Jobs\\FarAhead', server: $created + 200);
    $values = static function (float $server): array {
        $samples = Storage::scrape(server: $server)['samples']['queue_jobs_total'];

        return [
            'ahead' => $samples['connection=redis,queue=default,job_class=App\\Jobs\\Ahead,status=completed'],
            'far ahead' => $samples['connection=redis,queue=default,job_class=App\\Jobs\\FarAhead,status=completed'],
        ];
    };

    expect($values($created - 30))->toBe(['ahead' => 0, 'far ahead' => 1])
        ->and($values($created + 89.999))->toBe(['ahead' => 0, 'far ahead' => 1])
        ->and($values($created + 90))->toBe(['ahead' => 1, 'far ahead' => 1])
        ->and($values($created + 200))->toBe(['ahead' => 1, 'far ahead' => 1])
        ->and(Storage::hash((new Keys)->created()))->toBe([]);
});

it('deletes creation times that are not numbers instead of holding their series', function (): void {
    $created = 1_800_000_000;
    Storage::record(server: $created);
    Storage::store()->reader()->hset((new Keys)->created(), Keys::field('redis', 'default', 'App\\Jobs\\SendInvoice'), 'garbage');

    expect(Storage::scrape(server: $created + 1)['samples']['queue_jobs_total']['connection=redis,queue=default,job_class=App\\Jobs\\SendInvoice,status=completed'])->toBe(1)
        ->and(Storage::hash((new Keys)->created()))->toBe([]);
});

it('records every attempt and never lowers a counter when the server refuses TIME (an ACL without @fast)', function (): void {
    // A restrictive ACL may allow the package's commands but not TIME. Recording must not break (the record EVAL
    // writes no creation time and the series is not held); the scrape marks the attempts collector as failed and
    // holds what still has a creation time, so nothing exported drops back to 0.
    $redis = firstValuePrivateRedis($this, ['username' => 'ho', 'password' => 'secret'], static function (PrivateRedis $redis): void {
        expect($redis->command('ACL', 'SETUSER', 'ho', 'on', '>secret', '~*', '+@all', '-time'))->toBe('+OK');
    });

    try {
        $field = Keys::field('redis', 'default', 'App\\Jobs\\SendInvoice');
        firstValueRecordUnpinned(CarbonImmutable::now());

        expect(Storage::hash((new Keys)->attempts()))->toBe(['redis|default|App\\Jobs\\SendInvoice|completed' => '1'])
            ->and(Storage::hash((new Keys)->created()))->toBe([]);

        [$samples, $failures] = firstValueScrapeWithFailures();

        expect($samples['connection=redis,queue=default,job_class=App\\Jobs\\SendInvoice,status=completed'])->toBe(1)
            ->and($failures)->toHaveCount(1)
            ->and($failures[0])->toStartWith('attempts:hold: Lua script new_series failed:')->toContain('TIME failed');

        // A creation time from before the ACL: held, not released, as long as TIME is refused.
        Storage::store()->reader()->hset((new Keys)->created(), Keys::field('redis', 'default', 'App\\Jobs\\Older'), '1');
        firstValueRecordUnpinned(CarbonImmutable::now(), jobClass: 'App\\Jobs\\Older');
        [$samples, $failures] = firstValueScrapeWithFailures();

        expect($samples['connection=redis,queue=default,job_class=App\\Jobs\\Older,status=completed'])->toBe(0)
            ->and($samples['connection=redis,queue=default,job_class=App\\Jobs\\SendInvoice,status=completed'])->toBe(1)
            ->and($failures)->toHaveCount(1)
            ->and(array_keys(Storage::hash((new Keys)->created())))->toBe(['redis|default|App\\Jobs\\Older'])
            ->and($field)->not->toBe('');
    } finally {
        $redis->close();
    }
});

it('runs the scripts of the hold on a Redis that replicates scripts verbatim (Redis 6.2, lua-replicate-commands no)', function (): void {
    // TIME, INFO and HGETALL are non-deterministic; a Redis before 7 that replicates scripts verbatim refuses writes
    // after them unless the script asks for effects replication first. Redis 7+ and Valkey always replicate effects
    // (no such option): there the case does not apply. CI runs it on the Redis 6.2 legs (TRUTH_REDIS_IMAGE).
    $redis = firstValuePrivateRedis($this);

    try {
        $reply = $redis->command('CONFIG', 'SET', 'lua-replicate-commands', 'no');

        if ($reply !== '+OK') {
            $this->markTestSkipped(sprintf('%s always replicates script effects (%s).', $redis->describe(), $reply));
        }

        $series = 'connection=redis,queue=default,job_class=App\\Jobs\\SendInvoice,status=completed';
        firstValueRecordUnpinned(CarbonImmutable::now(), startedAt: microtime(true) - 1, readyAt: microtime(true) - 2);
        [$held, $failures] = firstValueScrapeWithFailures();

        expect($failures)->toBe([])
            ->and($held[$series])->toBe(0)
            ->and(Storage::hash((new Keys)->meta()))->toHaveKey('run_id');

        Storage::store()->reader()->hset((new Keys)->created(), Keys::field('redis', 'default', 'App\\Jobs\\SendInvoice'), (string) (firstValueServerTime() - AttemptsCollector::NEW_SERIES_HOLD_SECONDS - 1));
        [$released, $failures] = firstValueScrapeWithFailures();

        expect($failures)->toBe([])
            ->and($released[$series])->toBe(1)
            // The class series is released and its creation time deleted; the queue's wait histogram is still held.
            ->and(array_keys(Storage::hash((new Keys)->created())))->toBe(['redis|default']);
    } finally {
        $redis->close();
    }
});

it('holds the series of a counter reset again, so the counts after it start at 0', function (): void {
    $scenario = Scenario::start($this);
    $scenario->dispatch(new TruthJob('Truth\\Invoice'))->work()->advance(AttemptsCollector::NEW_SERIES_HOLD_SECONDS);

    expect(firstValueCounts($scenario)['completed'])->toBe(1);

    // A restored dataset: another run id in meta makes the reset guard delete the counters and creation times.
    Storage::store()->reader()->hset((new Keys)->meta(), 'run_id', 'a-restored-dataset');
    $scenario->scrape();
    $scenario->dispatch(new TruthJob('Truth\\Invoice'))->dispatch(new TruthJob('Truth\\Invoice'))->work();

    expect(firstValueCounts($scenario)['completed'])->toBe(0)
        ->and(firstValueCounts($scenario->advance(AttemptsCollector::NEW_SERIES_HOLD_SECONDS))['completed'])->toBe(2);
});
