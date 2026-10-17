<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Fixtures\Attempts;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobQueued;
use ReflectionClass;
use RuntimeException;

/**
 * Real queue events of the installed Laravel, whatever its line.
 */
final class Events
{
    /**
     * `JobAttempted` with `exception` (13.x) or `exceptionOccurred` (11.23-12.x).
     */
    public static function attempted(Job $job, bool $threw = false, string $connectionName = 'redis'): JobAttempted
    {
        if (property_exists(JobAttempted::class, 'exception')) {
            return new JobAttempted($connectionName, $job, $threw ? new RuntimeException('Attempt failed.') : null);
        }

        return new JobAttempted($connectionName, $job, $threw);
    }

    /**
     * `JobQueued` built by property (its constructor gained `queue` within the supported lines).
     */
    public static function queued(mixed $connectionName, mixed $id, mixed $delay, object|string $job = 'App\\Jobs\\SendInvoice'): JobQueued
    {
        $event = (new ReflectionClass(JobQueued::class))->newInstanceWithoutConstructor();
        $values = ['connectionName' => $connectionName, 'queue' => 'default', 'id' => $id, 'job' => $job, 'payload' => '{}', 'delay' => $delay];

        foreach ($values as $property => $value) {
            if (property_exists($event, $property)) {
                $event->{$property} = $value;
            }
        }

        return $event;
    }
}
