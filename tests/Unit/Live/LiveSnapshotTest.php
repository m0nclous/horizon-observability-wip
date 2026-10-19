<?php

declare(strict_types=1);

use DF\HorizonObservability\Live\Captions;
use DF\HorizonObservability\Live\LiveScope;
use DF\HorizonObservability\Live\LiveSnapshot;

$dot = "\u{00B7}";
$dash = "\u{2014}";

function exampleScope(bool $jobFilter = false): LiveScope
{
    return new LiveScope(null, [['connection' => 'redis', 'queue' => 'default'], ['connection' => 'redis', 'queue' => 'emails']], $jobFilter, $jobFilter ? ['App\\Jobs\\A'] : null);
}

/**
 * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
 */
function exampleInputs(): array
{
    return [
        [
            ['name' => 'supervisor-default', 'connection' => 'redis', 'balance' => 'auto', 'processes' => 3, 'min_processes' => 1, 'max_processes' => 3, 'busy' => 2, 'queues' => ['default']],
        ],
        [
            ['connection' => 'redis', 'queue' => 'default', 'pending' => 7, 'reserved' => 2, 'delayed' => 0, 'delayed_due' => 0, 'oldest_seconds' => 17, 'wait_threshold_seconds' => 60, 'completed_per_minute' => 40, 'failed_per_minute' => 1.0, 'supervisors' => ['supervisor-default']],
            ['connection' => 'redis', 'queue' => 'emails', 'pending' => 0, 'reserved' => 1, 'delayed' => 0, 'delayed_due' => 0, 'oldest_seconds' => 0, 'wait_threshold_seconds' => 60, 'completed_per_minute' => 2, 'failed_per_minute' => 0.0, 'supervisors' => []],
        ],
    ];
}

function exampleSnapshot(): LiveSnapshot
{
    [$supervisors, $queues] = exampleInputs();

    return LiveSnapshot::compose(exampleScope(), 1792201716123, 1000, 1, $supervisors, $queues, ['completed' => 42, 'failed' => 1, 'retried' => 0])->withAge(140);
}

it('matches the example document of schema v1', function () use ($dot): void {
    expect(exampleSnapshot()->toArray())->toBe([
        'v' => 1,
        'generated_at' => 1792201716123,
        'age_ms' => 140,
        'ttl_ms' => 1000,
        'scope' => ['supervisors' => null, 'queues' => ['default', 'emails'], 'job_filter' => false],
        'horizon' => ['status' => 1, 'level' => 0, 'color' => 'green', 'caption' => 'Running'],
        'pending' => ['value' => 7, 'oldest_seconds' => 17, 'oldest_queue' => 'default', 'wait_ratio' => 0.28, 'level' => 0, 'color' => 'green', 'caption' => "Oldest: 17 s {$dot} default"],
        'delayed' => ['value' => 0, 'due' => 0],
        'reserved' => ['value' => 3],
        'throughput' => ['window_seconds' => 60, 'bucket_seconds' => 5, 'completed' => 42, 'failed' => 1, 'retried' => 0, 'completed_per_minute' => 42, 'failed_per_minute' => 1.0, 'caption' => 'RPM: 42'],
        'supervisors' => [
            ['name' => 'supervisor-default', 'display' => 'default', 'connection' => 'redis', 'balance' => 'auto', 'processes' => 3, 'min_processes' => 1, 'max_processes' => 3, 'busy' => 2, 'utilization' => 0.667, 'level' => 0, 'color' => 'green', 'caption' => "default {$dot} max 3", 'queues' => ['default']],
        ],
        'queues' => [
            ['connection' => 'redis', 'queue' => 'default', 'pending' => 7, 'reserved' => 2, 'delayed' => 0, 'delayed_due' => 0, 'oldest_seconds' => 17, 'wait_threshold_seconds' => 60, 'wait_ratio' => 0.28, 'level' => 0, 'color' => 'green', 'completed_per_minute' => 40, 'failed_per_minute' => 1.0, 'supervisors' => ['supervisor-default']],
            ['connection' => 'redis', 'queue' => 'emails', 'pending' => 0, 'reserved' => 1, 'delayed' => 0, 'delayed_due' => 0, 'oldest_seconds' => 0, 'wait_threshold_seconds' => 60, 'wait_ratio' => 0.0, 'level' => 0, 'color' => 'green', 'completed_per_minute' => 2, 'failed_per_minute' => 0.0, 'supervisors' => []],
        ],
        // The example's queue inputs carry no pools: unknown utilisation and no processes per queue
        'workload' => [
            ['queue' => 'default', 'utilization' => 1999, 'processes' => 0, 'pending' => 7, 'delayed' => 0, 'oldest_cell' => '0|17 s / 60 s'],
            ['queue' => 'emails', 'utilization' => 1999, 'processes' => 0, 'pending' => 0, 'delayed' => 0, 'oldest_cell' => '0|0 s / 60 s'],
        ],
        'tiles' => [
            ['tile' => 'pending', 'key' => '', 'caption' => "Oldest: 17 s {$dot} default", 'value' => 7.0, 'color' => 'green', 'level' => 0],
            ['tile' => 'delayed', 'key' => '', 'caption' => '', 'value' => 0.0, 'color' => 'text', 'level' => 0],
            ['tile' => 'supervisor', 'key' => 'supervisor-default', 'caption' => "default {$dot} max 3", 'value' => 3.0, 'color' => 'green', 'level' => 0],
            ['tile' => 'throughput', 'key' => '', 'caption' => 'RPM: 42', 'value' => 42.0, 'color' => 'text', 'level' => 0],
        ],
        'errors' => [],
    ])->and(exampleSnapshot()->generatedAtMs())->toBe(1792201716123);
});

it('encodes JSON with zero fractions and unescaped glyphs', function () use ($dot): void {
    $json = exampleSnapshot()->toJson();

    expect($json)->toContain('"failed_per_minute":1.0')
        ->and($json)->toContain('"value":7.0')
        ->and($json)->toContain('"Oldest: 17 s '.$dot.' default"');
});

it('encodes names that are not valid UTF-8 with U+FFFD instead of throwing', function (): void {
    $snapshot = LiveSnapshot::compose(new LiveScope(["supervisor-\xFF"], [['connection' => 'redis', 'queue' => "caf\xE9"]], false, null), 0, 1000, 1, [], [
        ['connection' => 'redis', 'queue' => "caf\xE9", 'pending' => 1, 'reserved' => 0, 'delayed' => 0, 'delayed_due' => 0, 'oldest_seconds' => 3.0, 'wait_threshold_seconds' => 60, 'completed_per_minute' => null, 'failed_per_minute' => null, 'supervisors' => []],
    ], null);
    $decoded = json_decode($snapshot->toJson(), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded['scope']['supervisors'])->toBe(["supervisor-\u{FFFD}"])
        ->and($decoded['scope']['queues'])->toBe(["caf\u{FFFD}"])
        ->and($decoded['queues'][0]['queue'])->toBe("caf\u{FFFD}")
        ->and(LiveSnapshot::fromArray($decoded)->pending['value'])->toBe(1);
});

it('reads its own JSON back', function (): void {
    $snapshot = exampleSnapshot();
    $decoded = json_decode($snapshot->toJson(), true, 512, JSON_THROW_ON_ERROR);

    expect(LiveSnapshot::fromArray($decoded))->toEqual($snapshot)
        ->and(LiveSnapshot::fromArray(json_decode(json_encode($snapshot->toArray()), true))->toArray())->toEqual($snapshot->toArray());
});

it('rejects documents that are not schema v1', function (array $change): void {
    LiveSnapshot::fromArray([...exampleSnapshot()->toArray(), ...$change]);
})->with([
    'version' => [['v' => 2]],
    'missing part' => [['pending' => null]],
    'bad type' => [['generated_at' => '1792201716123']],
    'bad row' => [['supervisors' => [['name' => 'x']]]],
    'not a list' => [['queues' => ['a' => []]]],
    'bad scope' => [['scope' => ['supervisors' => null, 'queues' => [1], 'job_filter' => false]]],
])->throws(UnexpectedValueException::class);

it('picks the worst queue by wait ratio, then age, then name', function () use ($dot): void {
    $queue = static fn (string $name, int $age, ?int $threshold): array => [
        'connection' => 'redis', 'queue' => $name, 'pending' => 1, 'reserved' => 0, 'delayed' => 0, 'delayed_due' => 0,
        'oldest_seconds' => $age, 'wait_threshold_seconds' => $threshold, 'completed_per_minute' => null, 'failed_per_minute' => null, 'supervisors' => [],
    ];
    $snapshot = LiveSnapshot::compose(exampleScope(), 0, 1000, 1, [], [
        $queue('no-threshold', 500, null),
        $queue('fast', 30, 10),
        $queue('slow', 180, 90),
        $queue('also-fast', 30, 10),
    ], null);

    expect($snapshot->pending['oldest_queue'])->toBe('also-fast')
        ->and($snapshot->pending['wait_ratio'])->toBe(3.0)
        ->and($snapshot->pending['level'])->toBe(2)
        ->and($snapshot->pending['color'])->toBe('red')
        ->and($snapshot->pending['caption'])->toBe("Oldest: 30 s {$dot} also-fast")
        ->and($snapshot->pending['value'])->toBe(4)
        ->and($snapshot->queues[0]['wait_ratio'])->toBeNull()
        ->and($snapshot->queues[0]['level'])->toBe(0)
        ->and($snapshot->queues[2]['level'])->toBe(1);
});

it('levels the wait from the exact age and shows it rounded', function (float $age, int $seconds, int $level, float $ratio) use ($dot): void {
    $snapshot = LiveSnapshot::compose(exampleScope(), 0, 1000, 1, [], [[
        'connection' => 'redis', 'queue' => 'default', 'pending' => 1, 'reserved' => 0, 'delayed' => 0, 'delayed_due' => 0,
        'oldest_seconds' => $age, 'wait_threshold_seconds' => 10, 'completed_per_minute' => null, 'failed_per_minute' => null, 'supervisors' => [],
    ]], null);

    expect($snapshot->queues[0])->toMatchArray(['oldest_seconds' => $seconds, 'wait_ratio' => $ratio, 'level' => $level, 'color' => Captions::color($level)])
        ->and($snapshot->pending)->toMatchArray(['oldest_seconds' => $seconds, 'wait_ratio' => $ratio, 'level' => $level, 'color' => Captions::color($level)])
        ->and($snapshot->pending['caption'])->toBe("Oldest: {$seconds} s {$dot} default");
})->with([
    'just under the limit' => [9.6, 10, 0, 0.96],
    'at the limit' => [10.0, 10, 0, 1.0],
    'just over the limit' => [10.4, 10, 1, 1.04],
    'a millisecond over the limit' => [10.001, 10, 1, 1.0],
    'just under twice the limit' => [19.6, 20, 1, 1.96],
    'just over twice the limit' => [20.4, 20, 2, 2.04],
]);

it('captions the worst wait from the exact age, rounded once, like the off-mode tile', function (float $age, int $seconds, string $caption) use ($dot): void {
    $snapshot = LiveSnapshot::compose(exampleScope(), 0, 1000, 1, [], [[
        'connection' => 'redis', 'queue' => 'default', 'pending' => 1, 'reserved' => 0, 'delayed' => 0, 'delayed_due' => 0,
        'oldest_seconds' => $age, 'wait_threshold_seconds' => null, 'completed_per_minute' => null, 'failed_per_minute' => null, 'supervisors' => [],
    ]], null);

    expect($snapshot->pending)->toMatchArray(['oldest_seconds' => $seconds, 'oldest_queue' => 'default', 'caption' => "Oldest: {$caption} {$dot} default"])
        ->and($snapshot->tiles()[0]['caption'])->toBe("Oldest: {$caption} {$dot} default");
})->with([
    // Off mode: `round(age)` s when `0 < age < 120`, `round(age / 60)` min from 120 s, on the exported age.
    'waits less than half a second' => [0.3, 0, '0 s'],
    'seconds' => [89.6, 90, '90 s'],
    'rounds to 120 below the limit' => [119.6, 120, '120 s'],
    'the limit' => [120.0, 120, '2 min'],
    '2.49 minutes' => [149.6, 150, '2 min'],
    '3.495 minutes' => [209.7, 210, '3 min'],
    '3.5 minutes' => [210.0, 210, '4 min'],
]);

it('ranks two queues of the same rounded age by the exact age', function (): void {
    $queue = static fn (string $name, int|float $age): array => [
        'connection' => 'redis', 'queue' => $name, 'pending' => 1, 'reserved' => 0, 'delayed' => 0, 'delayed_due' => 0,
        'oldest_seconds' => $age, 'wait_threshold_seconds' => null, 'completed_per_minute' => null, 'failed_per_minute' => null, 'supervisors' => [],
    ];

    expect(LiveSnapshot::compose(exampleScope(), 0, 1000, 1, [], [$queue('a', 17.2), $queue('b', 17.4)], null)->pending['oldest_queue'])->toBe('b')
        ->and(LiveSnapshot::compose(exampleScope(), 0, 1000, 1, [], [$queue('b', 17.0), $queue('a', 17)], null)->pending['oldest_queue'])->toBe('a');
});

it('falls back to the oldest queue without thresholds', function () use ($dot): void {
    [, $queues] = exampleInputs();
    $queues[0]['wait_threshold_seconds'] = null;
    $queues[1]['wait_threshold_seconds'] = null;
    $queues[1]['oldest_seconds'] = 200;

    $snapshot = LiveSnapshot::compose(exampleScope(), 0, 1000, 1, [], $queues, null);

    expect($snapshot->pending['caption'])->toBe("Oldest: 3 min {$dot} emails")
        ->and($snapshot->pending['level'])->toBe(0)
        ->and($snapshot->pending['wait_ratio'])->toBeNull();
});

it('reports nothing waiting as "Oldest: —"', function () use ($dash): void {
    [, $queues] = exampleInputs();
    $queues[0]['oldest_seconds'] = 0;

    $snapshot = LiveSnapshot::compose(exampleScope(), 0, 1000, 1, [], $queues, null);

    expect($snapshot->pending)->toBe(['value' => 7, 'oldest_seconds' => 0, 'oldest_queue' => null, 'wait_ratio' => null, 'level' => 0, 'color' => 'green', 'caption' => "Oldest: {$dash}"]);
});

it('nulls pending and delayed under a job or batch filter', function () use ($dash): void {
    [$supervisors, $queues] = exampleInputs();
    $snapshot = LiveSnapshot::compose(exampleScope(jobFilter: true), 0, 1000, 1, $supervisors, $queues, null);

    expect($snapshot->pending)->toBe(['value' => null, 'oldest_seconds' => 0, 'oldest_queue' => null, 'wait_ratio' => null, 'level' => 0, 'color' => 'green', 'caption' => "Oldest: {$dash}"])
        ->and($snapshot->delayed)->toBe(['value' => null, 'due' => null])
        ->and($snapshot->reserved)->toBe(['value' => 3])
        ->and($snapshot->scope['job_filter'])->toBeTrue()
        ->and($snapshot->tiles()[0]['value'])->toBeNull()
        ->and($snapshot->tiles()[1]['value'])->toBeNull()
        ->and($snapshot->throughput['caption'])->toBe("RPM: {$dash}");
});

it('shows supervisors at 0 processes and red while Horizon is not running', function (int $status, string $caption): void {
    [$supervisors, $queues] = exampleInputs();
    $snapshot = LiveSnapshot::compose(exampleScope(), 0, 1000, $status, $supervisors, $queues, null);

    expect($snapshot->horizon)->toBe(['status' => $status, 'level' => 2, 'color' => 'red', 'caption' => $caption])
        ->and($snapshot->supervisors[0]['processes'])->toBe(0)
        ->and($snapshot->supervisors[0]['level'])->toBe(2)
        ->and($snapshot->supervisors[0]['color'])->toBe('red')
        ->and($snapshot->tiles()[2]['value'])->toBe(0.0);
})->with([
    'paused' => [0, 'Paused'],
    'stopped' => [-1, 'Stopped'],
]);

it('colours supervisors by utilisation and sorts them by display name', function () use ($dot): void {
    $supervisor = static fn (string $name, int $busy, int $max): array => ['name' => $name, 'connection' => 'redis', 'balance' => 'auto', 'processes' => $max, 'min_processes' => 1, 'max_processes' => $max, 'busy' => $busy, 'queues' => []];
    $snapshot = LiveSnapshot::compose(exampleScope(), 0, 1000, 1, [
        $supervisor('supervisor-slow', 9, 10),
        $supervisor('pool', 7, 10),
        $supervisor('supervisor-default', 5, 0),
        $supervisor('supervisor-busy', 30, 10),
    ], [], null);

    expect(array_column($snapshot->supervisors, 'display'))->toBe(['busy', 'default', 'pool', 'slow'])
        ->and(array_column($snapshot->supervisors, 'utilization'))->toBe([1.0, 0.0, 0.7, 0.9])
        ->and(array_column($snapshot->supervisors, 'color'))->toBe(['red', 'green', 'yellow', 'red'])
        ->and(array_column($snapshot->tiles(), 'key'))->toBe(['', '', 'supervisor-busy', 'supervisor-default', 'pool', 'supervisor-slow', ''])
        ->and($snapshot->supervisors[2]['caption'])->toBe("pool {$dot} max 10");
});

it('is unavailable with null values and text colour after a failed build', function (): void {
    $snapshot = LiveSnapshot::unavailable(exampleScope(), 5, 1000, [['source' => 'storage', 'message' => 'RedisException: Connection refused']]);

    expect($snapshot->horizon)->toBe(['status' => null, 'level' => 0, 'color' => 'text', 'caption' => 'Live unavailable'])
        ->and($snapshot->pending['caption'])->toBe('Live unavailable')
        ->and($snapshot->pending['value'])->toBeNull()
        ->and($snapshot->pending['color'])->toBe('text')
        ->and($snapshot->delayed)->toBe(['value' => null, 'due' => null])
        ->and($snapshot->reserved)->toBe(['value' => null])
        ->and($snapshot->throughput['caption'])->toBe('Live unavailable')
        ->and($snapshot->errors)->toBe([['source' => 'storage', 'message' => 'RedisException: Connection refused']])
        ->and($snapshot->tiles())->toBe([
            ['tile' => 'pending', 'key' => '', 'caption' => 'Live unavailable', 'value' => null, 'color' => 'text', 'level' => 0],
            ['tile' => 'delayed', 'key' => '', 'caption' => '', 'value' => null, 'color' => 'text', 'level' => 0],
            ['tile' => 'throughput', 'key' => '', 'caption' => 'Live unavailable', 'value' => null, 'color' => 'text', 'level' => 0],
        ])
        ->and(LiveSnapshot::fromArray($snapshot->toArray()))->toEqual($snapshot);
});

it('marks an unreadable Horizon state as unavailable but keeps the queues', function (): void {
    [, $queues] = exampleInputs();
    $snapshot = LiveSnapshot::compose(exampleScope(), 0, 1000, null, [], $queues, null, [['source' => 'horizon', 'message' => 'RedisException: timeout']]);

    expect($snapshot->horizon['caption'])->toBe('Live unavailable')
        ->and($snapshot->pending['value'])->toBe(7)
        ->and($snapshot->errors)->toHaveCount(1);
});

it('has an empty snapshot for stubs and empty scopes', function () use ($dash): void {
    $snapshot = LiveSnapshot::empty(new LiveScope(null, [], false, null), 7, 0);

    expect($snapshot->horizon['caption'])->toBe('Stopped')
        ->and($snapshot->pending['value'])->toBe(0)
        ->and($snapshot->pending['caption'])->toBe("Oldest: {$dash}")
        ->and($snapshot->delayed)->toBe(['value' => 0, 'due' => 0])
        ->and($snapshot->reserved)->toBe(['value' => 0])
        ->and($snapshot->throughput['completed'])->toBeNull()
        ->and($snapshot->supervisors)->toBe([])
        ->and($snapshot->queues)->toBe([])
        ->and($snapshot->scope)->toBe(['supervisors' => null, 'queues' => [], 'job_filter' => false])
        ->and(array_column($snapshot->tiles(), 'tile'))->toBe(['pending', 'delayed', 'throughput']);
});

it('derives the throughput rates over the 60 s window', function (): void {
    $snapshot = LiveSnapshot::compose(exampleScope(), 0, 1000, 1, [], [], ['completed' => 7, 'failed' => 3, 'retried' => 2]);

    expect($snapshot->throughput)->toBe(['window_seconds' => 60, 'bucket_seconds' => 5, 'completed' => 7, 'failed' => 3, 'retried' => 2, 'completed_per_minute' => 7, 'failed_per_minute' => 3.0, 'caption' => 'RPM: 7']);
});

it('never reports a negative age', function (): void {
    expect(exampleSnapshot()->withAge(-5)->ageMs)->toBe(0);
});

it('shows the busy workers of the selected classes on the supervisor tiles under a job filter', function (?int $status, array $expected): void {
    [$supervisors, $queues] = exampleInputs();
    $snapshot = LiveSnapshot::compose(exampleScope(jobFilter: true), 0, 1000, $status, $supervisors, $queues, null);
    $values = array_column(array_filter($snapshot->tiles(), static fn (array $row): bool => $row['tile'] === 'supervisor'), 'value', 'key');

    expect($values)->toBe($expected)
        ->and($snapshot->supervisors[0]['processes'])->toBe($status === 1 ? 3 : 0);
})->with([
    'running: busy' => [1, ['supervisor-default' => 2.0]],
    'paused: 0' => [0, ['supervisor-default' => 0.0]],
    'state unknown: 0' => [null, ['supervisor-default' => 0.0]],
]);

it('lists every supervisor under a job filter, at 0 without workers busy with the selected classes, as the off-mode tile', function (): void {
    $supervisor = static fn (string $name, int $busy): array => ['name' => $name, 'connection' => 'redis', 'balance' => 'auto', 'processes' => 3, 'min_processes' => 1, 'max_processes' => 3, 'busy' => $busy, 'queues' => ['default']];
    $supervisors = [$supervisor('supervisor-default', 2), $supervisor('supervisor-slow', 0)];
    $values = static fn (LiveSnapshot $snapshot): array => array_column(array_filter($snapshot->tiles(), static fn (array $row): bool => $row['tile'] === 'supervisor'), 'value', 'key');

    expect($values(LiveSnapshot::compose(exampleScope(jobFilter: true), 0, 1000, 1, $supervisors, [], null)))->toBe(['supervisor-default' => 2.0, 'supervisor-slow' => 0.0])
        // Paused: every supervisor at 0, as the off-mode tile shows the reserved jobs times Horizon running
        ->and($values(LiveSnapshot::compose(exampleScope(jobFilter: true), 0, 1000, 0, $supervisors, [], null)))->toBe(['supervisor-default' => 0.0, 'supervisor-slow' => 0.0])
        // Without a filter every supervisor is listed with its processes
        ->and($values(LiveSnapshot::compose(exampleScope(), 0, 1000, 1, $supervisors, [], null)))->toBe(['supervisor-default' => 3.0, 'supervisor-slow' => 3.0]);
});

it('tells snapshots with live data from failed builds', function (): void {
    [$supervisors, $queues] = exampleInputs();
    $scope = exampleScope();

    expect(exampleSnapshot()->isAvailable())->toBeTrue()
        ->and(LiveSnapshot::empty($scope, 0, 1000)->isAvailable())->toBeTrue()
        ->and(LiveSnapshot::compose($scope, 0, 1000, null, $supervisors, $queues, null)->isAvailable())->toBeTrue()
        ->and(LiveSnapshot::compose($scope, 0, 1000, 1, $supervisors, [], null)->withQueuesUnavailable()->isAvailable())->toBeTrue()
        ->and(LiveSnapshot::compose($scope, 0, 1000, null, $supervisors, [], null)->withQueuesUnavailable()->isAvailable())->toBeFalse()
        ->and(LiveSnapshot::unavailable($scope, 0, 1000, [])->isAvailable())->toBeFalse();
});

it('keeps Horizon, the supervisors and the throughput when the queues are unknown', function (): void {
    [$supervisors] = exampleInputs();
    $errors = [['source' => 'queue:redis:default', 'message' => 'RedisException: Connection refused']];
    $snapshot = LiveSnapshot::compose(exampleScope(), 7, 1000, 1, $supervisors, [], ['completed' => 6, 'failed' => 0, 'retried' => 0], $errors)
        ->withQueuesUnavailable();

    expect($snapshot->pending)->toBe(['value' => null, 'oldest_seconds' => 0, 'oldest_queue' => null, 'wait_ratio' => null, 'level' => 0, 'color' => 'text', 'caption' => 'Live unavailable'])
        ->and($snapshot->delayed)->toBe(['value' => null, 'due' => null])
        ->and($snapshot->reserved)->toBe(['value' => null])
        ->and($snapshot->queues)->toBe([])
        ->and($snapshot->horizon['caption'])->toBe('Running')
        ->and($snapshot->throughput['caption'])->toBe('RPM: 6')
        ->and($snapshot->errors)->toBe($errors)
        ->and($snapshot->supervisors[0])->toMatchArray(['processes' => 3, 'busy' => 0, 'utilization' => 0.0, 'level' => 0, 'color' => 'text'])
        ->and(LiveSnapshot::fromArray(json_decode($snapshot->toJson(), true))->toArray())->toBe($snapshot->toArray());
});

it('keeps the red supervisors of a stopped Horizon when the queues are unknown', function (): void {
    [$supervisors] = exampleInputs();

    $snapshot = LiveSnapshot::compose(exampleScope(), 0, 1000, -1, $supervisors, [], null)->withQueuesUnavailable();

    expect($snapshot->supervisors[0])->toMatchArray(['processes' => 0, 'level' => 2, 'color' => 'red']);
});
