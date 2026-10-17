<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Integration\Live\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DF\HorizonObservability\Attempts\AttemptStatus;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Storage;
use DF\HorizonObservability\Tests\Support\HorizonState;
use DF\HorizonObservability\Tests\Support\QueuePayloads;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Carbon;

/**
 * A known live state over the workbench topology (redis: default, slow, reports, mail, sms; waits default 10 s,
 * slow 30 s, others 60 s) at {@see self::NOW}, written the way Horizon, Laravel's RedisQueue and the attempt
 * recorder write it. The expected values are spelled out next to each write.
 */
final class LiveState
{
    /**
     * 2026-10-17 ... a multiple of 5 plus 1.123: the current ring bucket starts at NOW - 1.123.
     */
    public const NOW = 1792201716.123;

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestamp(self::NOW);
    }

    /**
     * Freeze the test clocks at `$time`, or unfreeze them with null: Laravel's (`now()`, `Date`), which the package
     * reads, and CarbonImmutable's, which Horizon's repositories read. They are one clock on Carbon 3 and two on
     * Carbon 2 (the Laravel 11 lowest leg), where setting only one of them would let the exporter, the live path and
     * Horizon read different times.
     */
    public static function freeze(?CarbonInterface $time): void
    {
        Carbon::setTestNow($time);
        CarbonImmutable::setTestNow($time);
    }

    public static function redis(Application $app): Connection
    {
        return $app->make('redis')->connection('default');
    }

    /**
     * Horizon running with the three workbench supervisors on master host-a.
     */
    public static function horizon(Application $app, string $status = 'running'): void
    {
        HorizonState::using($app)
            ->master('host-a', $status, ['host-a:supervisor-default', 'host-a:supervisor-slow', 'host-a:supervisor-pool'])
            // 3 processes of max 3; busy: 2 reserved on default -> 2/3 = 67 % -> level 0
            ->supervisor('host-a:supervisor-default', HorizonState::options('host-a:supervisor-default', queue: 'default', maxProcesses: 3), ['redis:default' => 3])
            // balance=auto: one pool per queue; busy 0 of 2 -> level 0
            ->supervisor('host-a:supervisor-slow', HorizonState::options('host-a:supervisor-slow', queue: 'slow,reports', maxProcesses: 2), ['redis:slow' => 1, 'redis:reports' => 1])
            // balance=false: one pool "mail,sms" (C14); busy: mail 1 + sms 1 = 2/2 = 100 % -> level 2
            ->supervisor('host-a:supervisor-pool', HorizonState::options('host-a:supervisor-pool', queue: 'mail,sms', balance: false, maxProcesses: 2), ['redis:mail,sms' => 2]);
    }

    /**
     * The queues:
     * - default: ready jobs pushed 17 s and 5 s ago, one due delayed job (due 3 s ago), one delayed for 300 s, two
     *   reserved (SendInvoice, Export): pending 3, delayed 1, due 1, reserved 2, oldest 17 s, ratio 1.7 (level 1);
     * - slow: a retry 45 s old (attempts 1, which C8 does not count) at the head, then a fresh job 12 s old: pending
     *   2, oldest 12 s (the first fresh job), ratio 0.4;
     * - reports: empty;
     * - mail: one reserved SendInvoice;
     * - sms: one reserved Welcome, one delayed for 60 s.
     */
    public static function queues(Application $app): void
    {
        $redis = self::redis($app);
        $now = self::NOW;

        QueuePayloads::push($redis, 'default', self::job('App\\Jobs\\SendInvoice', $now - 17));
        QueuePayloads::push($redis, 'default', self::job('App\\Jobs\\SendInvoice', $now - 5));
        QueuePayloads::delay($redis, 'default', self::job('App\\Jobs\\SendReminder', $now - 600), $now - 3);
        QueuePayloads::delay($redis, 'default', self::job('App\\Jobs\\SendReminder', $now - 600), $now + 300);
        QueuePayloads::reserve($redis, 'default', self::job('App\\Jobs\\SendInvoice', $now - 30), $now + 60);
        QueuePayloads::reserve($redis, 'default', self::job('App\\Jobs\\Export', $now - 30), $now + 60);

        QueuePayloads::push($redis, 'slow', self::job('App\\Jobs\\Export', $now - 45, ['attempts' => 1]));
        QueuePayloads::push($redis, 'slow', self::job('App\\Jobs\\Export', $now - 12));

        QueuePayloads::reserve($redis, 'mail', self::job('App\\Jobs\\SendInvoice', $now - 30), $now + 60);
        QueuePayloads::reserve($redis, 'sms', self::job('App\\Notifications\\Welcome', $now - 30), $now + 60);
        QueuePayloads::delay($redis, 'sms', self::job('App\\Notifications\\Welcome', $now - 30), $now + 60);
    }

    /**
     * Attempts in the ring around NOW (window [NOW - 61.123, NOW - 1.123)):
     * - default: 40 completed, 1 failed, 1 timed out, 3 released;
     * - slow: 2 completed; mail: 1 retried;
     * - outside the window: 5 completed before it, 6 in the current bucket.
     */
    public static function attempts(): void
    {
        $at = static fn (float $seconds): CarbonImmutable => CarbonImmutable::createFromTimestamp($seconds);
        $start = self::NOW - 61.123;

        foreach ([
            [$start + 0.0, 'default', AttemptStatus::Completed, 20],
            [$start + 59.0, 'default', AttemptStatus::Completed, 20],
            [$start + 30.0, 'default', AttemptStatus::Failed, 1],
            [$start + 31.0, 'default', AttemptStatus::TimedOut, 1],
            [$start + 32.0, 'default', AttemptStatus::Released, 3],
            [$start + 33.0, 'slow', AttemptStatus::Completed, 2],
            [$start + 34.0, 'mail', AttemptStatus::Retried, 1],
            [$start - 0.5, 'default', AttemptStatus::Completed, 5],
            [self::NOW, 'default', AttemptStatus::Completed, 6],
        ] as [$seconds, $queue, $status, $count]) {
            foreach (range(1, $count) as $ignored) {
                Storage::record(status: $status, duration: null, now: $at($seconds), queue: $queue);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function job(string $class, float $pushedAt, array $overrides = []): array
    {
        return QueuePayloads::job($class, ['createdAt' => (int) floor($pushedAt), 'pushedAt' => sprintf('%.4F', $pushedAt), ...$overrides]);
    }
}
