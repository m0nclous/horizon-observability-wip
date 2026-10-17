<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Attempts\AttemptStatus;
use DF\HorizonObservability\Live\AttemptRing;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Storage;
use DF\HorizonObservability\Tests\Support\RedisCommands;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;

/*
| The ring reader (Addendum R4.5, D'): AttemptRing::window() sums the 12 complete 5-second buckets before the current
| one with one ring_read.lua EVAL on the reader of the storage connection. The writer is record_attempt.lua (T04,
| RingTest); here the attempts go through the real AttemptStore so reader and writer share the contract.
*/

pest()->group('redis');

beforeEach(function (): void {
    // 1 700 000 010 is a multiple of 5: the current bucket is 340 000 002, the window [1 699 999 950, 1 700 000 010).
    $this->now = CarbonImmutable::createFromTimestamp(1_700_000_012.5);
    $this->ring = app(AttemptRing::class);
});

it('sums the attempts of the 12 complete buckets before the current one', function (): void {
    $plan = [
        // [unix seconds, queue, status, attempts]
        [1_700_000_009.999, 'default', AttemptStatus::Completed, 3],   // the last complete bucket
        [1_699_999_950.0, 'default', AttemptStatus::Failed, 2],        // the first bucket of the window
        [1_699_999_975.5, 'slow', AttemptStatus::Completed, 4],
        [1_699_999_976.0, 'slow', AttemptStatus::Completed, 1],        // the same field in two buckets
        [1_699_999_990.0, 'slow', AttemptStatus::TimedOut, 1],
        [1_699_999_991.0, 'default', AttemptStatus::Released, 2],
        [1_699_999_949.999, 'default', AttemptStatus::Completed, 5],   // the bucket before the window
        [1_700_000_010.0, 'default', AttemptStatus::Completed, 6],     // the current, incomplete bucket
    ];

    foreach ($plan as [$seconds, $queue, $status, $count]) {
        foreach (range(1, $count) as $ignored) {
            Storage::record(status: $status, duration: null, now: CarbonImmutable::createFromTimestamp($seconds), queue: $queue);
        }
    }

    expect($this->ring->window($this->now))->toBe([
        "redis\x1fdefault" => ['completed' => 3, 'failed' => 2, 'released' => 2],
        "redis\x1fslow" => ['completed' => 5, 'timed_out' => 1],
    ]);
});

it('reads with one EVAL of the 12 window keys on the storage reader', function (): void {
    $commands = RedisCommands::start($this->app);

    expect($this->ring->window($this->now))->toBe([])
        ->and($commands->names())->toBe(['EVAL'])
        ->and($commands->commands()[0]['connection'])->toBe(RedisClients::READER.'horizon')
        ->and(Storage::evalKeyCounts($commands->commands()))->toBe([12])
        ->and($this->ring->windowKeys($this->now))->toBe(array_map((new Keys)->ring(...), range(339_999_990, 340_000_001)));
});

it('skips fields that are not connection, queue and status and values that are not numbers', function (): void {
    $key = TestCase::redisPrefix().'horizon:'.(new Keys)->ring(339_999_995);
    RedisKeys::client()->hmset($key, [
        "redis\x1fdefault\x1fcompleted" => '2',
        "redis\x1fdefault" => '7',
        'garbage' => '1',
        "redis\x1fdefault\x1ffailed" => 'x',
    ]);
    RedisKeys::client()->expire($key, 60);

    expect($this->ring->window($this->now))->toBe(["redis\x1fdefault" => ['completed' => 2]]);
});

it('keeps queue names with separators of their own apart from the status', function (): void {
    Storage::record(duration: null, now: CarbonImmutable::createFromTimestamp(1_700_000_000.0), queue: 'emails:high');

    expect($this->ring->window($this->now))->toBe(["redis\x1femails:high" => ['completed' => 1]]);
});
