<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Attempts\AttemptStatus;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Live\AttemptRing;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Storage;
use DF\HorizonObservability\Tests\Support\RedisCommands;

/*
| The live ring buffer written inside record_attempt.lua (Addendum R4.5, D'): live:ring:<unix div 5> hashes of
| conn\x1fqueue\x1fstatus => attempts, TTL 180 s, read by T12 over the 12 complete buckets before the current one.
| Writing it costs no extra round trip; with live disabled the EVAL writes no ring key.
*/

pest()->group('redis');

beforeEach(function (): void {
    $this->keys = new Keys;
    // 1 700 000 010 is a multiple of 5: the current bucket of the reader is 340 000 002.
    $this->reader = CarbonImmutable::createFromTimestamp(1_700_000_012.5);
    $this->at = static fn (float $seconds): CarbonImmutable => CarbonImmutable::createFromTimestamp($seconds);

    /*
     * The window of a reader at $now: the sum of the 12 complete buckets before the current one, per queue and
     * status, from the raw ring keys (the reader itself, AttemptRing::window(), belongs to T12).
     */
    $this->window = static function (CarbonImmutable $now): array {
        $current = intdiv($now->getTimestamp(), Keys::RING_BUCKET_SECONDS);
        $window = [];

        foreach (range($current - AttemptRing::WINDOW_BUCKETS, $current - 1) as $bucket) {
            foreach (Storage::hash((new Keys)->ring($bucket)) as $field => $count) {
                [$connection, $queue, $status] = explode('|', $field);
                $window[$connection.'|'.$queue][$status] = ($window[$connection.'|'.$queue][$status] ?? 0) + (int) $count;
            }
        }

        ksort($window);

        return array_map(static function (array $statuses): array {
            ksort($statuses);

            return $statuses;
        }, $window);
    };
});

it('gives the exact window counts for attempts in known buckets', function (): void {
    $plan = [
        // [unix seconds, queue, status, attempts]
        [1_700_000_009.999, 'default', AttemptStatus::Completed, 3],  // last complete bucket
        [1_699_999_950.0, 'default', AttemptStatus::Failed, 2],       // first bucket of the window
        [1_699_999_975.5, 'slow', AttemptStatus::Completed, 4],       // inside
        [1_699_999_975.5, 'slow', AttemptStatus::TimedOut, 1],
        [1_699_999_949.999, 'default', AttemptStatus::Completed, 5],  // the bucket before the window
        [1_700_000_010.0, 'default', AttemptStatus::Completed, 6],    // the current, incomplete bucket
    ];

    foreach ($plan as [$seconds, $queue, $status, $count]) {
        foreach (range(1, $count) as $ignored) {
            Storage::record(status: $status, duration: null, now: ($this->at)($seconds), queue: $queue);
        }
    }

    expect(($this->window)($this->reader))->toBe([
        'redis|default' => ['completed' => 3, 'failed' => 2],
        'redis|slow' => ['completed' => 4, 'timed_out' => 1],
    ])->and(Storage::hash($this->keys->ring(340_000_002)))->toBe(['redis|default|completed' => '6'])
        ->and(Storage::hash($this->keys->ring(339_999_989)))->toBe(['redis|default|completed' => '5']);
});

it('keys the bucket by the attempt end like AttemptRing::key()', function (): void {
    $ring = app(AttemptRing::class);

    foreach ([1_700_000_000.0, 1_700_000_004.999, 1_700_000_005.0] as $seconds) {
        Storage::record(duration: null, now: ($this->at)($seconds));
    }

    expect(Storage::hash($ring->key(1_700_000_000)))->toBe(['redis|default|completed' => '2'])
        ->and(Storage::hash($ring->key(1_700_000_005)))->toBe(['redis|default|completed' => '1']);
});

it('expires ring buckets after 180 s', function (): void {
    Storage::record(now: $this->reader);
    $key = $this->keys->ring(340_000_002);

    expect(Storage::ttl($key))->toBeGreaterThan(170)->toBeLessThanOrEqual(AttemptRing::TTL_SECONDS);
});

it('a disabled live mode writes no ring key', function (): void {
    config()->set('horizon-observability.live.enabled', false);
    $this->app->forgetInstance(Config::class);
    $commands = RedisCommands::start($this->app);

    Storage::record(now: $this->reader);

    expect(array_filter(Storage::packageKeys(), static fn (string $key): bool => str_contains($key, ':live:')))->toBe([])
        ->and(Storage::hash($this->keys->attempts()))->toBe(['redis|default|App\\Jobs\\SendInvoice|completed' => '1'])
        ->and($commands->names(RedisClients::STORAGE))->toBe(['EVAL'])
        ->and(Storage::evalKeyCounts($commands->commands(RedisClients::STORAGE)))->toBe([11]);
});
