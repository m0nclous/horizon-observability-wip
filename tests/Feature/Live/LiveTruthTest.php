<?php

declare(strict_types=1);

use Carbon\CarbonInterface;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Tests\Integration\Live\Support\LiveState;
use DF\HorizonObservability\Tests\Support\Exposition;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Support\Facades\Date;

/*
| Honest numbers (owner decision 13), T12 QA round 1: on one Redis state, GET {base}/live must show the numbers the
| exporter exports for it and the off-mode tiles compute from them: Horizon status, pending, delayed and due,
| reserved, the oldest age and its level per queue, the wait thresholds, and per supervisor the processes and the
| maximum.
*/

pest()->group('redis');

beforeEach(function (): void {
    LiveState::freeze(LiveState::now());
    LiveState::horizon($this->app);
    LiveState::queues($this->app);
    LiveState::attempts();
    $this->app['env'] = 'local'; // every package endpoint is open in local without configuration
});

afterEach(function (): void {
    LiveState::freeze(null);
});

/**
 * The samples of a series with their labels.
 *
 * @return list<array{labels: array<string, string>, value: float}>
 */
function qaTruthSeries(Exposition $exposition, string $name): array
{
    $rows = [];

    foreach ($exposition->series($name) as $key => $value) {
        preg_match_all('/(\w+)="((?:[^"\\\\]|\\\\.)*)"/', $key, $matches, PREG_SET_ORDER);
        $labels = [];

        foreach ($matches as $match) {
            $labels[$match[1]] = stripcslashes($match[2]);
        }

        $rows[] = ['labels' => $labels, 'value' => $value];
    }

    return $rows;
}

/**
 * @return array<string, float> label value => summed sample value
 */
function qaTruthSumBy(Exposition $exposition, string $name, string $label): array
{
    $sums = [];

    foreach (qaTruthSeries($exposition, $name) as $row) {
        $key = $row['labels'][$label] ?? '';
        $sums[$key] = ($sums[$key] ?? 0.0) + $row['value'];
    }

    return $sums;
}

it('shows the numbers the exporter exports for the same Redis state', function (): void {
    $metrics = Exposition::parse((string) $this->get('/horizon/observability/metrics')->assertOk()->getContent());
    $live = $this->getJson('/horizon/observability/live')->assertOk()->json();

    $pending = qaTruthSumBy($metrics, 'ho_queue_pending_jobs', 'queue');
    $delayed = qaTruthSumBy($metrics, 'ho_queue_delayed_jobs', 'queue');
    $due = qaTruthSumBy($metrics, 'ho_queue_delayed_due_jobs', 'queue');
    $reserved = qaTruthSumBy($metrics, 'ho_queue_jobs_reserved', 'queue');
    $oldest = qaTruthSumBy($metrics, 'ho_queue_oldest_pending_job_age_seconds', 'queue');
    $thresholds = qaTruthSumBy($metrics, 'ho_horizon_wait_threshold_seconds', 'queue');

    expect($live['errors'])->toBe([])
        ->and((float) $live['horizon']['status'])->toBe($metrics->value('ho_horizon_status'))
        ->and((float) $live['pending']['value'])->toBe(array_sum($pending))
        ->and((float) $live['delayed']['value'])->toBe(array_sum($delayed) - array_sum($due))
        ->and((float) $live['delayed']['due'])->toBe(array_sum($due))
        ->and((float) $live['reserved']['value'])->toBe(array_sum($reserved));

    foreach ($live['queues'] as $queue) {
        $name = $queue['queue'];
        $limit = $thresholds[$name];
        // The off-mode level: (age / limit > 1) + (age / limit > 2) on the exported values (Waits::levels()).
        $level = (int) ($oldest[$name] / $limit > 1) + (int) ($oldest[$name] / $limit > 2);

        expect((float) $queue['pending'])->toBe($pending[$name], $name)
            ->and((float) $queue['delayed'])->toBe($delayed[$name] - $due[$name], $name)
            ->and((float) $queue['delayed_due'])->toBe($due[$name], $name)
            ->and((float) $queue['reserved'])->toBe($reserved[$name] ?? 0.0, $name)
            ->and((float) $queue['oldest_seconds'])->toBe(round($oldest[$name]), $name)
            ->and((float) $queue['wait_threshold_seconds'])->toBe($limit, $name)
            ->and($queue['level'])->toBe($level, $name);
    }

    $processes = qaTruthSumBy($metrics, 'ho_horizon_supervisor_processes', 'supervisor');
    $maximum = qaTruthSumBy($metrics, 'ho_horizon_supervisor_max_processes', 'supervisor');

    expect(array_column($live['supervisors'], 'name'))->toEqualCanonicalizing(array_keys($processes));

    foreach ($live['supervisors'] as $supervisor) {
        expect((float) $supervisor['processes'])->toBe($processes[$supervisor['name']], $supervisor['name'])
            ->and((float) $supervisor['max_processes'])->toBe($maximum[$supervisor['name']], $supervisor['name']);
    }

    // The tiles carry the same numbers (they are what the stream pushes).
    $tiles = [];

    foreach ($live['tiles'] as $tile) {
        $tiles[$tile['tile'].':'.$tile['key']] = $tile['value'];
    }

    expect($tiles['pending:'])->toBe(array_sum($pending))
        ->and($tiles['delayed:'])->toBe(array_sum($delayed) - array_sum($due));

    foreach ($processes as $name => $count) {
        expect($tiles['supervisor:'.$name])->toBe($count);
    }
});

it('reads the time from Laravel\'s clock like the exporter, so Date handlers and test clocks move both', function (): void {
    // Laravel's clock 30 s ahead of CarbonImmutable's, on every Carbon version: Date::useCallable() shifts every
    // date Laravel creates. On Carbon 2, Carbon::setTestNow() and CarbonImmutable::setTestNow() are two such clocks.
    Date::useCallable(static fn (CarbonInterface $date): CarbonInterface => $date->addSeconds(30));

    try {
        $metrics = Exposition::parse((string) $this->get('/horizon/observability/metrics')->assertOk()->getContent());
        $live = $this->getJson('/horizon/observability/live')->assertOk()->json();
    } finally {
        Date::useDefault();
    }

    $oldest = qaTruthSumBy($metrics, 'ho_queue_oldest_pending_job_age_seconds', 'queue');
    $shown = [];

    foreach ($live['queues'] as $queue) {
        $shown[$queue['queue']] = (float) $queue['oldest_seconds'];
    }

    ksort($oldest);
    ksort($shown);

    // default: the job pushed 17 s before NOW waits 47 s on Laravel's clock, for the exporter and for /live.
    expect($oldest['default'])->toBe(47.0)
        ->and($live['generated_at'])->toBe(1792201746123)
        ->and($live['pending']['caption'])->toBe('Oldest: 47 s '."\u{00B7}".' default')
        ->and($shown)->toBe(array_map(round(...), array_intersect_key($oldest, $shown)));
});

it('counts in the ring exactly the attempts the counters count', function (): void {
    $metrics = Exposition::parse((string) $this->get('/horizon/observability/metrics')->assertOk()->getContent());
    $client = RedisKeys::client();
    $prefix = TestCase::redisPrefix().'horizon:';
    $ring = [];

    // Every ring bucket that LiveState wrote (current, the 12 of the window and the one before), not only the window.
    for ($bucket = intdiv((int) LiveState::NOW, 5) - 14; $bucket <= intdiv((int) LiveState::NOW, 5); $bucket++) {
        $fields = $client->hgetall($prefix.(new Keys)->ring($bucket));

        foreach (is_array($fields) ? $fields : [] as $field => $count) {
            [, $queue, $status] = Keys::splitField((string) $field);
            $ring[$queue.':'.$status] = ($ring[$queue.':'.$status] ?? 0) + (int) $count;
        }
    }

    $counters = [];

    foreach (qaTruthSeries($metrics, 'ho_queue_jobs_total') as $row) {
        if ($row['value'] > 0) {
            $key = $row['labels']['queue'].':'.$row['labels']['status'];
            $counters[$key] = ($counters[$key] ?? 0) + (int) $row['value'];
        }
    }

    ksort($ring);
    ksort($counters);

    expect($ring)->toBe($counters);
});

it('does not show 0 pending jobs for a selected supervisor while Horizon is stopped and its queue grows', function (): void {
    // Horizon stopped: no master and no supervisor in Horizon's repositories, while `default` (supervisor-default's
    // queue in config, and in the dashboard's Queue variable through last_over_time) keeps 3 ready jobs.
    RedisKeys::delete(TestCase::redisPrefix().'horizon:');
    $metrics = Exposition::parse((string) $this->get('/horizon/observability/metrics')->assertOk()->getContent());
    $live = $this->getJson('/horizon/observability/live?var-supervisor=supervisor-default&var-queue=$__all')->assertOk()->json();

    expect($metrics->value('ho_horizon_status'))->toBe(-1.0)
        ->and($metrics->value('ho_queue_pending_jobs', ['connection' => 'redis', 'queue' => 'default']))->toBe(3.0)
        // Either the honest number or null (the poll tile then falls back to the Prometheus value through merge);
        // a 0 hides a growing queue in the moment the tile matters most.
        ->and($live['pending']['value'])->not->toBe(0);
});
