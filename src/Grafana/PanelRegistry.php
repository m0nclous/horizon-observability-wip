<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana;

use LogicException;

/**
 * Panel ids of the dashboard and lookups in a rendered dashboard.
 *
 * The ids are the legacy ones: Grafana links, alert annotations and bookmarks refer to them. The alert rules
 * find their `__panelId__` by panel title in the rendered dashboard ({@see idOf()}), so they cannot point at a
 * panel that does not exist.
 *
 * @internal
 */
final class PanelRegistry
{
    public const HORIZON = 1;

    public const PENDING = 2;

    public const SUPERVISOR_PROCESSES = 3;

    public const FAILED = 4;

    public const QUEUES = 5;

    public const JOBS = 6;

    public const PENDING_BY_JOB = 7;

    public const COMPLETED = 8;

    public const DELAYED = 9;

    public const OVERVIEW_ROW = 10;

    public const WORKLOAD_ROW = 11;

    public const JOBS_ROW = 12;

    public const BATCHES_ROW = 13;

    public const COMPLETED_BY_JOB = 14;

    public const BATCH_LIST = 16;

    public const BATCH_WORKLOAD = 17;

    public const COMPLETED_JOBS = 18;

    public const FAILED_JOBS = 19;

    public const PENDING_JOBS = 20;

    public const ALERTS = 21;

    public const DELAYED_JOBS = 22;

    public const ALERT_SOUND = 23;

    /**
     * Every panel of a rendered dashboard in document order, the panels of collapsed rows right after their row.
     *
     * @param  array<array-key, mixed>  $dashboard
     * @return list<array<array-key, mixed>>
     */
    public static function panels(array $dashboard): array
    {
        $panels = [];

        foreach (self::list($dashboard['panels'] ?? []) as $panel) {
            $panels[] = $panel;

            foreach (self::list($panel['panels'] ?? []) as $child) {
                $panels[] = $child;
            }
        }

        return $panels;
    }

    /**
     * The id of the only panel with this title.
     *
     * @param  array<array-key, mixed>  $dashboard
     */
    public static function idOf(array $dashboard, string $title): int
    {
        $ids = [];

        foreach (self::panels($dashboard) as $panel) {
            if (($panel['title'] ?? null) === $title && is_int($panel['id'] ?? null)) {
                $ids[] = $panel['id'];
            }
        }

        if (count($ids) !== 1) {
            throw new LogicException(sprintf('Expected one panel titled "%s", found %d.', $title, count($ids)));
        }

        return $ids[0];
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function list(mixed $panels): array
    {
        return is_array($panels) ? array_values(array_filter($panels, is_array(...))) : [];
    }
}
