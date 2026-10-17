<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Attempts;

use DF\HorizonObservability\Metrics\Labels;

/**
 * The `job_class` label value from a payload `displayName` (Horizon's job `name`), Arch Q4.
 *
 * The same normaliser applies to attempts, queue-state class counts, batch links and the job-list `job_class`
 * filter, so values match everywhere.
 *
 * @internal
 */
final class JobClassLabel
{
    /**
     * Trimmed; `Closure (routes/web.php:42)` becomes `Closure (routes/web.php)` because line numbers churn on every
     * edit; empty becomes `(unknown)`; then {@see Labels::sanitize()}.
     */
    public static function normalize(?string $displayName): string
    {
        $name = trim((string) $displayName);
        $name = (string) preg_replace('/^Closure \((.+):\d+\)$/s', 'Closure ($1)', $name);
        $name = Labels::sanitize($name);

        return $name === '' ? Labels::UNKNOWN : $name;
    }
}
