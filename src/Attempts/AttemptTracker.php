<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Attempts;

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Queues\PayloadClock;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Support\FailureReporter;
use DF\HorizonObservability\Support\Versions;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Queue\MaxAttemptsExceededException;
use Throwable;
use WeakMap;

/**
 * Event subscriber that records the attempts of `RedisJob`s that passed `JobProcessing`.
 *
 * State machine, per job object (a `WeakMap`, so nothing outlives the job):
 * - `JobProcessing`: only a `RedisJob` (what Horizon and Laravel's Redis queue pop) while `collectors.attempts` is
 *   on. The start (monotonic and wall clock) and the readiness of a first attempt are kept; no Redis call.
 * - `JobExceptionOccurred` with `MaxAttemptsExceededException`: the attempt is recorded without a duration.
 * - `JobAttempted`: ignored without a state or once finished; otherwise finished and recorded with its outcome.
 * - `JobTimedOut`: ignored without a state or once finished; otherwise finished and recorded as `timed_out` with
 *   the duration capped at the timeout. A late `JobTimedOut` after `JobAttempted`, or a `JobAttempted` after
 *   `JobTimedOut` (`killOnTimeout=false`), finds the attempt finished: one record per attempt.
 *
 * So `SyncJob`s (sync, deferred and background connections, nested `dispatchSync()`) and jobs of other drivers never
 * touch Redis. The record goes through {@see AttemptStore}'s private storage client, never through a connection the
 * job may be using when `JobTimedOut` fires from the `SIGALRM` handler.
 *
 * The provider subscribes it when `collectors.attempts` is on, which resolves it at boot: the constructor touches
 * neither Redis nor the DB and resolves no {@see Config}; collaborators come from the container per event. Every
 * listener catches everything, so nothing reaches the worker or the dispatcher.
 *
 * @internal
 */
final class AttemptTracker
{
    /**
     * @var WeakMap<object, AttemptState>
     */
    private WeakMap $states;

    public function __construct(private readonly Container $container, private readonly FailureReporter $reporter)
    {
        $this->states = new WeakMap;
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            JobProcessing::class => 'onProcessing',
            JobExceptionOccurred::class => 'onExceptionOccurred',
            JobAttempted::class => 'onAttempted',
            JobTimedOut::class => 'onTimedOut',
        ];
    }

    public function onProcessing(JobProcessing $event): void
    {
        try {
            $job = $event->job;

            if (! $job instanceof RedisJob || ! $this->container->make(Config::class)->collectors['attempts']) {
                return;
            }

            $payload = $job->payload();
            $first = PayloadClock::isFirstAttempt($payload);
            $id = $job->getJobId();
            $uuid = $job->uuid();
            $legacyKey = $first && is_string($id) && $id !== '' && ! $this->container->make(Versions::class)->payloadCarriesDelay()
                ? $this->container->make(Keys::class)->readyAt($id)
                : null;

            $this->states[$job] = new AttemptState(
                startedNs: hrtime(true),
                startedAt: now()->getPreciseTimestamp(3) / 1000,
                readyAt: $first ? PayloadClock::readyAt($payload) : null,
                uuid: is_string($uuid) ? $uuid : null,
                legacyKey: $legacyKey,
            );
        } catch (Throwable $e) {
            $this->reporter->report('tracker', $e);
        }
    }

    public function onExceptionOccurred(JobExceptionOccurred $event): void
    {
        try {
            $state = $this->state($event->job);

            if ($state instanceof AttemptState && $event->exception instanceof MaxAttemptsExceededException) {
                $state->noDuration = true;
            }
        } catch (Throwable $e) {
            $this->reporter->report('tracker', $e);
        }
    }

    public function onAttempted(JobAttempted $event): void
    {
        try {
            $job = $event->job;
            $state = $this->state($job);

            if (! $state instanceof AttemptState || $state->finished || ! $job instanceof RedisJob) {
                return;
            }

            $state->finished = true;

            $this->record($job, $state, AttemptOutcome::of($event), $state->noDuration ? null : $state->elapsedSeconds());
        } catch (Throwable $e) {
            $this->reporter->report('tracker', $e);
        }
    }

    public function onTimedOut(JobTimedOut $event): void
    {
        try {
            $job = $event->job;
            $state = $this->state($job);

            if (! $state instanceof AttemptState || $state->finished || ! $job instanceof RedisJob) {
                return;
            }

            $state->finished = true;
            $elapsed = $state->elapsedSeconds();
            $timeout = AttemptOutcome::timeoutSeconds($event, $job);

            $this->record($job, $state, AttemptStatus::TimedOut, $state->noDuration ? null : ($timeout === null ? $elapsed : min($elapsed, $timeout)));
        } catch (Throwable $e) {
            $this->reporter->report('tracker', $e);
        }
    }

    private function state(mixed $job): ?AttemptState
    {
        return is_object($job) ? ($this->states[$job] ?? null) : null;
    }

    private function record(RedisJob $job, AttemptState $state, AttemptStatus $status, ?float $duration): void
    {
        $displayName = $job->payload()['displayName'] ?? null;
        $waits = $state->recordsWait();
        $id = $job->getJobId();

        $this->container->make(AttemptStore::class)->record(
            connection: (string) $job->getConnectionName(),
            queue: (string) $job->getQueue(),
            jobClass: JobClassLabel::normalize(is_string($displayName) ? $displayName : null),
            status: $status,
            durationSeconds: $duration,
            startedAt: $waits ? $state->startedAt : null,
            readyAt: $waits ? $state->readyAt : null,
            uuid: $state->legacyKey !== null && is_string($id) ? $id : null,
            now: now()->toImmutable(),
        );
    }
}
