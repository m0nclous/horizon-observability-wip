<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Attempts\AttemptStatus;
use DF\HorizonObservability\Collectors\AttemptsCollector;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Storage;
use DF\HorizonObservability\Tests\Support\HorizonState;
use DF\HorizonObservability\Tests\Truth\Support\Invariants;
use DF\HorizonObservability\Tests\Truth\Support\Model;
use DF\HorizonObservability\Tests\Truth\Support\Samples;
use DF\HorizonObservability\Tests\Truth\Support\Scenario;
use DF\HorizonObservability\Tests\Truth\Support\TruthJob;

/*
| Adversarial probes, first round: the two exporter changes that came with this suite (the hold of new counter
| series, then 75 s, and horizon_supervisor_queues) and the suite's own claims. Each case states what the exporter
| must do. Failing at 063b66a (the findings of this round): numeric queue names, a repeated queue in a balance=false
| option, and the waits-per-attempts invariant while a new class on a known queue is held. The other cases pass and
| stay as regression tests: the 15 s skew budget of the hold at 60 s scrapes, and a pruned class that returns.
*/

pest()->group('redis', 'truth');

afterEach(function (): void {
    Scenario::stop();
});

it('keeps numeric queue names in horizon_supervisor_queues (a spread renumbers integer array keys)', function (): void {
    $scenario = Scenario::start($this);
    HorizonState::using(app())
        ->master('host-a', 'running', ['host-a:supervisor-shards', 'host-a:supervisor-pool'])
        ->supervisor('host-a:supervisor-shards', HorizonState::options('host-a:supervisor-shards', queue: '1,2,7'), ['redis:1' => 1, 'redis:2' => 2, 'redis:7' => 1])
        ->supervisor('host-a:supervisor-pool', HorizonState::options('host-a:supervisor-pool', queue: '0,5', balance: false), ['redis:0,5' => 2]);
    $samples = new Samples($scenario->scrape());

    // The processes keep the numeric pool labels (their older code casts the keys)...
    expect($samples->series(Metric::SupervisorProcesses->value))->toBe([
        Model::key(['supervisor' => 'supervisor-pool', 'queue' => '0,5']) => 2.0,
        Model::key(['supervisor' => 'supervisor-shards', 'queue' => '1']) => 1.0,
        Model::key(['supervisor' => 'supervisor-shards', 'queue' => '2']) => 2.0,
        Model::key(['supervisor' => 'supervisor-shards', 'queue' => '7']) => 1.0,
    ])
        // ...and so must the queue map: queue "1" is not queue "0", and every pool has a processes series.
        ->and($samples->series(Metric::SupervisorQueues->value))->toBe([
            Model::key(['supervisor' => 'supervisor-pool', 'queue' => '0', 'pool' => '0,5']) => 1.0,
            Model::key(['supervisor' => 'supervisor-pool', 'queue' => '5', 'pool' => '0,5']) => 1.0,
            Model::key(['supervisor' => 'supervisor-shards', 'queue' => '1', 'pool' => '1']) => 1.0,
            Model::key(['supervisor' => 'supervisor-shards', 'queue' => '2', 'pool' => '2']) => 1.0,
            Model::key(['supervisor' => 'supervisor-shards', 'queue' => '7', 'pool' => '7']) => 1.0,
        ])
        ->and(Invariants::violations($samples))->toBe([]);
});

it('maps the queues of a balance=false pool whose queue option repeats a queue to its real pool label', function (): void {
    // Horizon's single pool takes the option as it is ("mail,sms,mail"), so that is the processes label.
    $scenario = Scenario::start($this);
    HorizonState::using(app())
        ->master('host-a', 'running', ['host-a:supervisor-pool'])
        ->supervisor('host-a:supervisor-pool', HorizonState::options('host-a:supervisor-pool', queue: 'mail,sms,mail', balance: false), ['redis:mail,sms,mail' => 2]);
    $samples = new Samples($scenario->scrape());

    expect($samples->series(Metric::SupervisorQueues->value))->toBe([
        Model::key(['supervisor' => 'supervisor-pool', 'queue' => 'mail', 'pool' => 'mail,sms,mail']) => 1.0,
        Model::key(['supervisor' => 'supervisor-pool', 'queue' => 'sms', 'pool' => 'mail,sms,mail']) => 1.0,
    ])->and(Invariants::violations($samples))->toBe([]);
});

it('keeps the invariants while a new class on a known queue is held (waits never exceed attempts)', function (): void {
    $scenario = Scenario::start($this);
    $scenario->dispatch(new TruthJob('Truth\\Invoice'))->work()->advance(AttemptsCollector::NEW_SERIES_HOLD_SECONDS);

    expect(Invariants::violations(new Samples($scenario->scrape())))->toBe([]);

    // A deploy brings a new class to the known queue: its attempts are held, the queue's waits are not.
    foreach (range(1, 3) as $ignored) {
        $scenario->dispatch(new TruthJob('Truth\\Fresh'));
    }

    $samples = new Samples($scenario->work()->advance(1)->scrape());

    expect($samples->value(Metric::JobsTotal->value, ['connection' => 'redis', 'queue' => 'default', 'job_class' => 'Truth\\Fresh', 'status' => 'completed']))->toBe(0.0)
        ->and($samples->value(Metric::JobWait->value.'_count', ['connection' => 'redis', 'queue' => 'default']))->toBe(4.0)
        // The suite claims its invariants hold at every scrape of every scenario (tests/Truth/README.md).
        ->and(Invariants::violations($samples))->toBe([]);
});

it('exports a new series as 0 on one scrape every 60 s when the worker clock is 15 s behind the exporter', function (float $phase): void {
    // AttemptsCollector::NEW_SERIES_HOLD_SECONDS documents 15 s for jitter and clock skew on top of the 60 s interval.
    $created = 1_800_000_000.0;
    Storage::record(status: AttemptStatus::Completed, duration: 0.2, now: CarbonImmutable::createFromTimestamp($created - 15));
    $series = 'connection=redis,queue=default,job_class=App\\Jobs\\SendInvoice,status=completed';
    $values = [];

    for ($scrape = $created + $phase; $scrape < $created + 200; $scrape += 60) {
        $values[] = Storage::scrape(CarbonImmutable::createFromTimestamp($scrape))['samples']['queue_jobs_total'][$series];
    }

    expect($values[0])->toBe(0)
        ->and(array_slice($values, -1))->toBe([1]);
})->with(['phase 0' => 0.0, 'phase 30 s' => 30.0, 'phase 59.999 s' => 59.999]);

it('holds a class again when it returns after its idle fields were pruned, so its first attempts count', function (): void {
    config()->set('horizon-observability.max_job_classes', 1);
    $start = CarbonImmutable::createFromTimestamp(1_800_000_000);
    $series = static fn (string $class): string => 'connection=redis,queue=default,job_class='.$class.',status=completed';

    Storage::record(jobClass: 'App\\Jobs\\Old', now: $start);
    // 31 days later the full class set makes room for a new class: Old leaves it, its fields go at the next scrape.
    $later = $start->addDays(31);
    Storage::record(jobClass: 'App\\Jobs\\New', now: $later);
    $first = Storage::scrape($later->addSecond())['samples']['queue_jobs_total'];

    expect($first)->not->toHaveKey($series('App\\Jobs\\Old'))
        ->and(Storage::hash((new Keys)->created()))->not->toHaveKey('redis|default|App\\Jobs\\Old');

    // Old comes back when New has been idle for 31 days: a new series again, held, then counted in full.
    $back = $later->addDays(31);
    Storage::record(jobClass: 'App\\Jobs\\Old', now: $back);
    Storage::record(jobClass: 'App\\Jobs\\Old', now: $back);

    expect(Storage::scrape($back->addSecond())['samples']['queue_jobs_total'][$series('App\\Jobs\\Old')])->toBe(0)
        ->and(Storage::scrape($back->addSeconds(AttemptsCollector::NEW_SERIES_HOLD_SECONDS))['samples']['queue_jobs_total'][$series('App\\Jobs\\Old')])->toBe(2);
});
