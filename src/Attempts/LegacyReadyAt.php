<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Attempts;

use DateInterval;
use DateTimeInterface;
use DF\HorizonObservability\Horizon\Topology;
use DF\HorizonObservability\Redis\CircuitBreaker;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Support\FailureReporter;
use Illuminate\Contracts\Container\Container;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\InteractsWithTime;
use Throwable;

/**
 * `JobQueued` listener of legacy stacks (no payload `delay`): stores the readiness of delayed Redis jobs for the
 * record `EVAL` (Arch Q6.8, C18, A01). Registered only when `Versions::payloadCarriesDelay()` is false.
 *
 * For a job on a `redis` queue connection with a delay of more than 0 seconds and a non-empty id, it writes
 * `ready_at:<id>` = the Unix second the job becomes due, with the TTL delay + 1 day, through the circuit breaker.
 * The delay (seconds, `DateTimeInterface` or `DateInterval`) is normalised like `InteractsWithTime::secondsUntil()`
 * inside the guarded block (A01), so a Carbon delay never throws into the code that dispatches. The worker's
 * `record_attempt.lua` reads and deletes the key in the same `EVAL` (no extra round trip).
 *
 * @internal
 */
final readonly class LegacyReadyAt
{
    use InteractsWithTime;

    /**
     * Extra TTL of a `ready_at` key beyond the delay: a due job may wait for a worker.
     */
    public const EXTRA_TTL_SECONDS = 86400;

    public function __construct(private Container $container, private FailureReporter $reporter) {}

    public function onQueued(JobQueued $event): void
    {
        try {
            $id = is_string($event->id) || is_int($event->id) ? (string) $event->id : '';
            $connection = $event->connectionName;

            if ($id === '' || ! is_string($connection) || $this->container->make(Topology::class)->redisConnection($connection) === null) {
                return;
            }

            $delay = $event->delay;

            if (! $delay instanceof DateTimeInterface && ! $delay instanceof DateInterval && ! is_numeric($delay)) {
                return; // no delay (null), or nothing the queue could have delayed by
            }

            $seconds = $this->secondsUntil($delay);

            if ($seconds <= 0) {
                return;
            }

            $key = $this->container->make(Keys::class)->readyAt($id);
            $readyAt = $this->currentTime() + $seconds;
            $clients = $this->container->make(RedisClients::class);

            $this->container->make(CircuitBreaker::class)->attempt('ready_at', static function () use ($clients, $key, $readyAt, $seconds): void {
                $clients->storage()->setex($key, $seconds + self::EXTRA_TTL_SECONDS, $readyAt);
            });
        } catch (Throwable $e) {
            $this->reporter->report('ready_at', $e);
        }
    }
}
