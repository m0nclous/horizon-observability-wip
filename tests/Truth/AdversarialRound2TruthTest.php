<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Attempts\AttemptStatus;
use DF\HorizonObservability\Collectors\AttemptsCollector;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Storage;
use DF\HorizonObservability\Tests\Support\HorizonState;
use DF\HorizonObservability\Tests\Truth\Support\Invariants;
use DF\HorizonObservability\Tests\Truth\Support\Model;
use DF\HorizonObservability\Tests\Truth\Support\Samples;
use DF\HorizonObservability\Tests\Truth\Support\Scenario;

/*
| Adversarial probes, second round: the fixes of the first round (the 90 s hold sized for a clock budget, the
| absolute age, the `+` merge of the pools) and the clocks the hold depends on. Each case states what the exporter
| must do. Failing at c288694 (the findings of this round):
| - the hold compares the worker's clock with the clock of the replica that answers the scrape, so two replicas
|   behind the one scrape target (one target per app environment, as the main README asks) whose clocks differ by
|   more than the scrape interval (inside the stated budget: a worker 15 s behind one replica and in sync with the
|   other) export N, 0, N at the end of the hold: a counter decrease that increase() takes for a reset and counts N
|   twice (on Prometheus v3.15.0: increase() 6.02 for 3 attempts, resets() 1; probes/replica-clocks);
| - a worker clock more than the hold ahead makes an already exported counter drop back to 0 for 180 s later on (the
|   README then said such a creation time "is not trusted"; it was trusted once the exporter's clock came within 90 s
|   of it), again a fake reset;
| - while a deploy changes a balance=false queue option, both masters' pools work the common queues, but
|   horizon_supervisor_queues keeps only the first master's pool, so the processes per queue miss the other's.
| The other cases pass and stay as regression tests.
|
| Fixed after this round: the hold runs on the storage Redis' clock (ServerClock: TIME inside record_attempt.lua for
| the creation time and inside new_series.lua for the age), which releases a series for good by deleting its creation
| time; horizon_supervisor_queues keeps every master's pools. The hold cases therefore pass the server clock
| explicitly (Storage's `server:`, the real time of each event) next to the worker's and the replicas' own clocks
| (`now:`), which no longer enter.
*/

pest()->group('redis', 'truth');

afterEach(function (): void {
    Scenario::stop();
});

const ADV2_SERIES = 'connection=redis,queue=default,job_class=App\\Jobs\\SendInvoice,status=completed';

/**
 * Counter samples in scrape order that are lower than the sample before them.
 *
 * @param  array<string, int|float>  $values  scrape label => value, in scrape order
 * @return list<string>
 */
function adv2Decreases(array $values): array
{
    $decreases = [];
    $previous = null;

    foreach ($values as $at => $value) {
        if ($previous !== null && $value < $previous[1]) {
            $decreases[] = sprintf('%s after %s at %s: %s -> %s', ADV2_SERIES, $previous[0], $at, $previous[1], $value);
        }

        $previous = [$at, $value];
    }

    return $decreases;
}

it('keeps a counter monotonic when two replicas behind the one scrape target answer in turn, clocks 15 s apart', function (int $interval, int $phase): void {
    // One logical scrape target per app (Service DNS, load balancer): whichever replica answers, the series must be
    // continuous. The worker that records the first attempt is in sync with replica A and 15 s behind replica B
    // (within the stated budget: worker clocks <= 15 s off the exporter's).
    $created = 1_800_000_000;
    Storage::record(status: AttemptStatus::Completed, duration: 0.2, now: CarbonImmutable::createFromTimestamp($created), server: $created);
    $skew = ['A' => 0, 'B' => 15];
    $values = [];

    for ($k = 0, $real = $created + $phase; $real < $created + 200; $k++, $real += $interval) {
        $replica = $k % 2 === 0 ? 'A' : 'B';
        $values[sprintf('+%d s via %s', $real - $created, $replica)] = Storage::scrape(CarbonImmutable::createFromTimestamp($real + $skew[$replica]), server: $real)['samples']['queue_jobs_total'][ADV2_SERIES];
    }

    // One decision for both replicas, at 90 s on the server clock: 0 before, the count from then on.
    $expected = [];

    foreach (array_keys($values) as $index => $at) {
        $expected[$at] = $phase + $index * $interval < AttemptsCollector::NEW_SERIES_HOLD_SECONDS ? 0 : 1;
    }

    expect(adv2Decreases($values))->toBe([])
        ->and(reset($values))->toBe(0)
        ->and(end($values))->toBe(1)
        ->and($values)->toBe($expected);
})->with(['S=5 s, phase 1 s' => [5, 1], 'S=10 s, phase 6 s' => [10, 6]]);

it('never lowers an exported counter later when the worker clock was more than the hold ahead', function (int $ahead): void {
    // The README: "a creation time far in the future is not trusted". It must stay untrusted: a counter that was
    // exported with its value must not drop back to 0 when the exporter's clock comes within the hold of it.
    $real = 1_800_000_000;
    Storage::record(status: AttemptStatus::Completed, duration: 0.2, now: CarbonImmutable::createFromTimestamp($real + $ahead), server: $real);
    $values = [];

    for ($t = $real + 1; $t < $real + $ahead + 2 * AttemptsCollector::NEW_SERIES_HOLD_SECONDS + 30; $t += 10) {
        $values[sprintf('+%d s', $t - $real)] = Storage::scrape(CarbonImmutable::createFromTimestamp($t), server: $t)['samples']['queue_jobs_total'][ADV2_SERIES];
    }

    // The worker's clock never enters: held for the hold after the creation on the server clock, then counted.
    $expected = [];

    foreach (array_keys($values) as $index => $at) {
        $expected[$at] = 1 + $index * 10 < AttemptsCollector::NEW_SERIES_HOLD_SECONDS ? 0 : 1;
    }

    expect(adv2Decreases($values))->toBe([])
        ->and($values)->toBe($expected);
})->with(['ahead 120 s' => 120, 'ahead 1 h' => 3600]);

it('holds a new series for the hold at most after its creation by the exporter clock when the worker is in sync', function (): void {
    // Regression: the hold ends 90 s after the creation (the first scrape at or after it reads the full count).
    $created = 1_800_000_000;
    Storage::record(now: CarbonImmutable::createFromTimestamp($created));
    $at = static fn (float $seconds): int|float => Storage::scrape(CarbonImmutable::createFromTimestamp($seconds))['samples']['queue_jobs_total'][ADV2_SERIES];

    expect($at($created + AttemptsCollector::NEW_SERIES_HOLD_SECONDS - 0.001))->toBe(0)
        ->and($at($created + AttemptsCollector::NEW_SERIES_HOLD_SECONDS))->toBe(1);
});

it('attributes the processes of both masters to a queue while a deploy changes a balance=false queue option', function (): void {
    // During a deploy the old master (mail,sms) and the new one (mail,sms,push) both run supervisor-pool: two pools
    // work mail and sms. horizon_supervisor_queues is keyed by (supervisor, queue, pool), so both pools fit; with only
    // the first master's pool, the processes per queue join (Truth README, decision 2) misses the new master's
    // processes for mail and sms.
    $scenario = Scenario::start($this);
    HorizonState::using(app())
        ->master('host-a', 'running', ['host-a:supervisor-pool'])
        ->supervisor('host-a:supervisor-pool', HorizonState::options('host-a:supervisor-pool', queue: 'mail,sms', balance: false), ['redis:mail,sms' => 2])
        ->master('host-b', 'running', ['host-b:supervisor-pool'], pid: 2000)
        ->supervisor('host-b:supervisor-pool', HorizonState::options('host-b:supervisor-pool', queue: 'mail,sms,push', balance: false), ['redis:mail,sms,push' => 3], pid: 2001);
    $samples = new Samples($scenario->scrape());

    // Processes per queue, as the dashboard joins them: the sum over the pools mapped to the queue.
    $processes = $samples->series(Metric::SupervisorProcesses->value);
    $perQueue = [];

    foreach ($samples->labelSets(Metric::SupervisorQueues->value) as $labels) {
        $perQueue[$labels['queue']] = ($perQueue[$labels['queue']] ?? 0.0) + ($processes[Model::key(['supervisor' => $labels['supervisor'], 'queue' => $labels['pool']])] ?? 0.0);
    }

    ksort($perQueue);

    expect($perQueue)->toBe(['mail' => 5.0, 'push' => 3.0, 'sms' => 5.0])
        ->and(Invariants::violations($samples))->toBe([]);
});

it('maps the trimmed queues of a spaced queue option to the pool label Horizon wrote', function (): void {
    // Regression: Horizon labels a balance=false pool with the option as written ("mail, sms"); the queues are the
    // trimmed names.
    $scenario = Scenario::start($this);
    HorizonState::using(app())
        ->master('host-a', 'running', ['host-a:supervisor-pool'])
        ->supervisor('host-a:supervisor-pool', HorizonState::options('host-a:supervisor-pool', queue: 'mail, sms', balance: false), ['redis:mail, sms' => 2]);
    $samples = new Samples($scenario->scrape());

    expect($samples->series(Metric::SupervisorQueues->value))->toBe([
        Model::key(['supervisor' => 'supervisor-pool', 'queue' => 'mail', 'pool' => 'mail, sms']) => 1.0,
        Model::key(['supervisor' => 'supervisor-pool', 'queue' => 'sms', 'pool' => 'mail, sms']) => 1.0,
    ])->and(Invariants::violations($samples))->toBe([]);
});
