<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Support;

use Illuminate\Contracts\Queue\Job;
use stdClass;
use Throwable;

/**
 * Queue events with the property shapes of every supported Laravel line (Arch Q6.2, Appendix F), so each shim branch
 * runs on every CI leg whatever Laravel is installed.
 */
final class FakeEvents
{
    /**
     * `JobAttempted` of Laravel 11.23-12.x: `bool $exceptionOccurred`.
     */
    public static function attempted12(Job $job, bool $exceptionOccurred = false, string $connectionName = 'redis'): stdClass
    {
        $event = new stdClass;
        $event->connectionName = $connectionName;
        $event->job = $job;
        $event->exceptionOccurred = $exceptionOccurred;

        return $event;
    }

    /**
     * `JobAttempted` of Laravel 13.x: `?Throwable $exception`.
     */
    public static function attempted13(Job $job, ?Throwable $exception = null, string $connectionName = 'redis'): stdClass
    {
        $event = new stdClass;
        $event->connectionName = $connectionName;
        $event->job = $job;
        $event->exception = $exception;

        return $event;
    }

    /**
     * `JobTimedOut`: without `timeout` before Laravel 13.25, with it from 13.25 (pass a value).
     */
    public static function timedOut(Job $job, ?int $timeout = null, string $connectionName = 'redis'): stdClass
    {
        $event = new stdClass;
        $event->connectionName = $connectionName;
        $event->job = $job;

        if ($timeout !== null) {
            $event->timeout = $timeout;
        }

        return $event;
    }
}
