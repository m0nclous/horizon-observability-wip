<?php

declare(strict_types=1);

use DF\HorizonObservability\Redis\Keys;

/**
 * Redis Cluster hash slot (CRC16/XMODEM of the hash tag, mod 16384).
 */
function hashSlot(string $key): int
{
    $start = strpos($key, '{');

    if ($start !== false) {
        $end = strpos($key, '}', $start + 1);

        if ($end !== false && $end > $start + 1) {
            $key = substr($key, $start + 1, $end - $start - 1);
        }
    }

    $crc = 0;

    foreach (str_split($key) as $char) {
        $crc ^= ord($char) << 8;

        for ($bit = 0; $bit < 8; $bit++) {
            $crc = ($crc & 0x8000) !== 0 ? (($crc << 1) ^ 0x1021) & 0xFFFF : ($crc << 1) & 0xFFFF;
        }
    }

    return $crc % 16384;
}

it('names the storage keys as Appendix E and E-prime', function (): void {
    $keys = new Keys;

    expect([
        $keys->attempts(), $keys->durationBuckets(), $keys->durationSum(), $keys->waitBuckets(), $keys->waitSum(), $keys->created(),
        $keys->extremes(113333333, 'min'), $keys->extremes(113333333, 'max'), $keys->jobClasses(), $keys->meta(),
        $keys->batchLinks(), $keys->readyAt('9a3c'), $keys->readyAtPattern(), $keys->ring(340000000),
        $keys->liveSnapshot('abc'), $keys->liveSnapshotStale('abc'), $keys->liveSnapshotLock('abc'), $keys->liveLeader(),
        $keys->liveDelayedWindow(), $keys->livePattern(), $keys->pattern(),
    ])->toBe([
        '{horizon-observability}:v1:attempts',
        '{horizon-observability}:v1:duration:buckets',
        '{horizon-observability}:v1:duration:sum',
        '{horizon-observability}:v1:wait:buckets',
        '{horizon-observability}:v1:wait:sum',
        '{horizon-observability}:v1:created',
        '{horizon-observability}:v1:extremes:113333333:min',
        '{horizon-observability}:v1:extremes:113333333:max',
        '{horizon-observability}:v1:job_classes',
        '{horizon-observability}:v1:meta',
        '{horizon-observability}:v1:batch_links',
        '{horizon-observability}:v1:ready_at:9a3c',
        '{horizon-observability}:v1:ready_at:*',
        '{horizon-observability}:v1:live:ring:340000000',
        '{horizon-observability}:v1:live:snapshot:abc',
        '{horizon-observability}:v1:live:snapshot-stale:abc',
        '{horizon-observability}:v1:live:snapshot-lock:abc',
        '{horizon-observability}:v1:live:leader',
        '{horizon-observability}:v1:live:window:delayed',
        '{horizon-observability}:v1:live:*',
        '{horizon-observability}:v1:*',
    ]);
});

it('puts every key in one cluster slot (C10 "single slot")', function (): void {
    $keys = new Keys;
    $all = [
        ...$keys->all(), $keys->extremes(1, 'min'), $keys->extremes(2, 'max'), $keys->readyAt('uuid'),
        $keys->ring(7), $keys->liveSnapshot(sha1('x')), $keys->liveSnapshotStale(sha1('x')), $keys->liveSnapshotLock(sha1('x')),
    ];

    expect(array_unique(array_map(hashSlot(...), $all)))->toBe([hashSlot('{horizon-observability}')])
        ->and(hashSlot('{horizon-observability}:v1:meta'))->toBe(hashSlot('laravel_horizon:{horizon-observability}:v1:attempts'))
        ->and(hashSlot('123456789'))->toBe(12739);
});

it('lists the counter keys in reset-guard order and the fixed keys', function (): void {
    $keys = new Keys;

    expect($keys->counterKeys())->toBe([$keys->attempts(), $keys->durationBuckets(), $keys->durationSum(), $keys->waitBuckets(), $keys->waitSum(), $keys->created()])
        ->and($keys->all())->toBe([...$keys->counterKeys(), $keys->jobClasses(), $keys->meta(), $keys->batchLinks(), $keys->liveLeader(), $keys->liveDelayedWindow()]);
});

it('accepts only min and max extremes', function (): void {
    (new Keys)->extremes(1, 'avg');
})->throws(InvalidArgumentException::class);

it('uses a custom root', function (): void {
    $keys = new Keys('{test}:v1:');

    expect($keys->root())->toBe('{test}:v1:')
        ->and($keys->meta())->toBe('{test}:v1:meta');
});

it('joins and splits hash fields with the unit separator', function (): void {
    expect(Keys::field('redis', 'default', 'App\\Jobs\\A', 'completed'))->toBe("redis\x1fdefault\x1fApp\\Jobs\\A\x1fcompleted")
        ->and(Keys::splitField("redis\x1fdefault\x1fcompleted"))->toBe(['redis', 'default', 'completed'])
        ->and(Keys::EXTREMES_BUCKET_SECONDS)->toBe(15)
        ->and(Keys::RING_BUCKET_SECONDS)->toBe(5);
});
