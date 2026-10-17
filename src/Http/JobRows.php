<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Http;

/**
 * Row formatting of the job lists: epoch ms, runtime in seconds rounded to 3 decimals, `horizon_path` (Arch Q2.1).
 *
 * {@see self::fields()} is the public field contract, which the Grafana contract tests read. The formatting is
 * owned by T06 (WP4).
 *
 * @internal
 */
final class JobRows
{
    /**
     * The fields of every row of a list, in output order (Arch Q2.1; unchanged from the pilot).
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
}
