<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Http;

use DF\HorizonObservability\Config\ExceptionMode;
use DF\HorizonObservability\Horizon\JobStore;
use DF\HorizonObservability\Queues\DelayedJobStore;
use LogicException;

/**
 * Row formatting of the job lists, unchanged from the pilot: times are Unix milliseconds
 * (`(int) round(seconds * 1000)`), `runtime` is seconds rounded to 3 decimals, `horizon_path` is the job's page
 * relative to Horizon's URL.
 *
 * {@see self::fields()} is the public field contract, which the Grafana contract tests read; every formatted row
 * has exactly these keys in this order. A time the store could not read (a hash written without it) gives null
 * instead of a value computed from 0, and so does a time whose milliseconds are no integer (finite, but corrupt).
 *
 * @phpstan-import-type JobStoreRow from JobStore
 * @phpstan-import-type DelayedRow from DelayedJobStore
 *
 * @internal
 */
final class JobRows
{
    /**
     * The fields of every row of a list, in output order (unchanged from the pilot).
     *
     * @return list<string>
     */
    public static function fields(JobList $list): array
    {
        return match ($list) {
            JobList::Pending => ['id', 'name', 'queue', 'queued_at', 'horizon_path'],
            JobList::Completed => ['id', 'name', 'queue', 'queued_at', 'completed_at', 'runtime', 'horizon_path'],
            JobList::Failed => ['id', 'name', 'queue', 'attempts', 'failed_at', 'runtime', 'exception', 'horizon_path'],
            JobList::Delayed => ['id', 'name', 'queue', 'attempts', 'queued_at', 'available_at'],
        };
    }

    /**
     * A pending, completed or failed row ({@see JobStore}); the exception is summarised by `$mode`.
     *
     * @param  JobStoreRow  $row
     * @return array<string, string|int|float|null>
     */
    public static function horizon(JobList $list, array $row, ExceptionMode $mode): array
    {
        return match ($list) {
            JobList::Pending => [
                'id' => $row['id'],
                'name' => $row['name'],
                'queue' => $row['queue'],
                'queued_at' => self::milliseconds($row['created_at']),
                'horizon_path' => $list->horizonPath($row['id']),
            ],
            JobList::Completed => [
                'id' => $row['id'],
                'name' => $row['name'],
                'queue' => $row['queue'],
                'queued_at' => self::milliseconds($row['created_at']),
                'completed_at' => self::milliseconds($row['completed_at']),
                'runtime' => self::runtime($row['reserved_at'], $row['completed_at']),
                'horizon_path' => $list->horizonPath($row['id']),
            ],
            JobList::Failed => [
                'id' => $row['id'],
                'name' => $row['name'],
                'queue' => $row['queue'],
                'attempts' => $row['attempts'],
                'failed_at' => self::milliseconds($row['failed_at']),
                // Null when the job failed before a worker reserved it.
                'runtime' => self::runtime($row['reserved_at'], $row['failed_at']),
                'exception' => ExceptionSummary::summarize($row['exception'], $mode),
                'horizon_path' => $list->horizonPath($row['id']),
            ],
            JobList::Delayed => throw new LogicException('Delayed rows come from the DelayedJobStore.'),
        };
    }

    /**
     * A delayed row ({@see DelayedJobStore}); `queued_at` is null for a job pushed without Horizon (no `pushedAt`).
     *
     * @param  DelayedRow  $row
     * @return array<string, string|int|float|null>
     */
    public static function delayed(array $row): array
    {
        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'queue' => $row['queue'],
            'attempts' => $row['attempts'],
            'queued_at' => self::milliseconds($row['pushed_at']),
            'available_at' => self::milliseconds($row['available_at']),
        ];
    }

    /**
     * Unix seconds as Unix milliseconds, as Grafana expects them; null without a time or when the milliseconds lie
     * outside the integer range (about 9.2e15 s).
     *
     * Casting such a float would wrap it to a wrong time on PHP 8.2-8.4 and warn on PHP 8.5, which Laravel's error
     * handler turns into an exception, so one corrupt hash would fail the whole list until it expires. (float)
     * PHP_INT_MAX is 2^63, the first value past the range; NAN fails both comparisons.
     */
    public static function milliseconds(?float $seconds): ?int
    {
        if ($seconds === null) {
            return null;
        }

        $milliseconds = round($seconds * 1000);

        return $milliseconds >= (float) PHP_INT_MIN && $milliseconds < (float) PHP_INT_MAX ? (int) $milliseconds : null;
    }

    /**
     * Seconds from start to end rounded to 3 decimals, as Horizon shows a runtime; null without a start or an end.
     */
    public static function runtime(?float $start, ?float $end): ?float
    {
        return $start === null || $end === null ? null : round($end - $start, 3);
    }
}
