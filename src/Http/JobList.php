<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Http;

/**
 * The job lists of `GET {base}/jobs/{list}` (Arch Q2.1); the values are the public path segments.
 *
 * @internal
 */
enum JobList: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';
    case Delayed = 'delayed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $list): string => $list->value, self::cases());
    }

    /**
     * Path of a job in Horizon's UI relative to Horizon's URL (the `horizon_path` field), or null for delayed jobs,
     * which Horizon does not show: `jobs/pending/<id>`, `jobs/completed/<id>`, `failed/<id>`.
     */
    public function horizonPath(string $id): ?string
    {
        return match ($this) {
            self::Pending => 'jobs/pending/'.$id,
            self::Completed => 'jobs/completed/'.$id,
            self::Failed => 'failed/'.$id,
            self::Delayed => null,
        };
    }
}
