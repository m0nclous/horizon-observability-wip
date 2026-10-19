<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Attempts;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobAttempted;

/**
 * Laravel 11.23 / 12 / 13 shim of the attempt events. Properties are read by name, so one build runs on every
 * supported version.
 *
 * Its tests (`AttemptOutcomeTest`) cover both event shapes, with and without `timeout`.
 *
 * @internal
 */
final class AttemptOutcome
{
    public static function of(JobAttempted $event): AttemptStatus
    {
        $job = $event->job;

        return match (true) {
            $job->hasFailed() => AttemptStatus::Failed,
            $job->isReleased() && self::threw($event) => AttemptStatus::Retried,
            $job->isReleased() => AttemptStatus::Released,
            default => AttemptStatus::Completed,
        };
    }

    /**
     * 11.23-12.x: bool `$exceptionOccurred`; 13.x: `?Throwable $exception`. Both are declared public properties.
     */
    public static function threw(object $event): bool
    {
        return property_exists($event, 'exception')
            ? $event->exception !== null
            : (bool) ($event->exceptionOccurred ?? false);
    }

    /**
     * 13.25+: `JobTimedOut::$timeout` (includes the supervisor `--timeout`); earlier: the job's own timeout.
     */
    public static function timeoutSeconds(object $event, Job $job): ?float
    {
        $timeout = property_exists($event, 'timeout') ? $event->timeout : null;
        $timeout ??= $job->timeout();

        return is_numeric($timeout) && $timeout > 0 ? (float) $timeout : null;
    }
}
