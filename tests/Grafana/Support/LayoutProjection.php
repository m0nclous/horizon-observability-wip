<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Grafana\Support;

use DF\HorizonObservability\Grafana\Json;
use DF\HorizonObservability\Grafana\Live\LiveMode;
use RuntimeException;

/**
 * The layout projection of a dashboard: what must stay equal to the legacy dashboard while the queries change. Per
 * panel in document order (the panels of a collapsed row right after it): id, type, title, gridPos, the row it belongs
 * to, repeat options and the collapsed flag; the full projection adds the options without the alert sound script, the
 * field config and the transformations.
 *
 * The full projection of the legacy dashboard is frozen in `tests/Fixtures/grafana/default/layout-projection.json` (as
 * decoded JSON: an empty object reads as an empty list, on both sides of the comparison); it outlived the legacy
 * goldens, which were deleted once the queries were rewritten. The dashboard optimisations change it only as
 * {@see optimised()} lists.
 */
final class LayoutProjection
{
    private const FROZEN = __DIR__.'/../../Fixtures/grafana/default/layout-projection.json';

    private const LAYOUT_KEYS = ['id', 'type', 'title', 'gridPos', 'repeat', 'repeatDirection', 'maxPerRow', 'collapsed'];

    private const LOOK_KEYS = ['options', 'fieldConfig', 'transformations'];

    private const ALERT_SOUND = 23;

    private const QUEUES = 5;

    private const JOBS = 6;

    private const QUEUE_SOURCE = 24;

    private const OVERVIEW_ROW = 10;

    /**
     * The layout fields only.
     *
     * @param  array<array-key, mixed>  $dashboard
     * @return list<array<string, mixed>>
     */
    public static function layout(array $dashboard): array
    {
        return self::project($dashboard, self::LAYOUT_KEYS);
    }

    /**
     * The layout fields plus the look of every panel.
     *
     * @param  array<array-key, mixed>  $dashboard
     * @return list<array<string, mixed>>
     */
    public static function full(array $dashboard): array
    {
        return self::project($dashboard, [...self::LAYOUT_KEYS, ...self::LOOK_KEYS]);
    }

    /**
     * The frozen full projection of the legacy dashboard.
     *
     * @return list<array<string, mixed>>
     */
    public static function frozen(): array
    {
        $frozen = json_decode((string) file_get_contents(self::FROZEN), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($frozen) || ! array_is_list($frozen)) {
            throw new RuntimeException('The frozen layout projection is not a list.');
        }

        /** @var list<array<string, mixed>> $frozen */
        return $frozen;
    }

    /**
     * The frozen legacy projection with the documented, look-preserving changes of the dashboard optimisations, so that
     * any other difference fails:
     * - Shared queue source: the hidden queue source #24 shares the hidden top grid row with #23 (both half wide); it
     *   is taken from $dashboard after checking that it sits in that row. The queue table #5 first keeps the rows of
     *   its own supervisor and does not show their supervisor column.
     * - One attempts query per table: the attempts columns of #5 and #6 come from one query by status, which their
     *   transformations pivot and combine; they are taken from $dashboard after checking that they show the legacy
     *   columns in the legacy order ({@see columns()}). Field config and options stay the legacy ones.
     *
     * @param  list<array<string, mixed>>  $frozen
     * @param  array<array-key, mixed>  $dashboard  the render under test, decoded
     * @return list<array<string, mixed>>
     */
    public static function optimised(array $frozen, array $dashboard): array
    {
        $source = null;

        foreach (self::full($dashboard) as $panel) {
            if (($panel['id'] ?? null) === self::QUEUE_SOURCE) {
                $source = $panel;
            }
        }

        if ($source === null || $source['gridPos'] !== ['h' => 1, 'w' => 12, 'x' => 12, 'y' => 0] || $source['row'] !== null) {
            throw new RuntimeException('The queue source #24 is missing or not in the hidden top row.');
        }

        $expected = [];

        foreach ($frozen as $panel) {
            $id = $panel['id'] ?? null;

            if ($id === self::ALERT_SOUND) {
                $panel['gridPos']['w'] = 12;
                $expected[] = $panel;
                $expected[] = $source;

                continue;
            }

            if (($id === self::QUEUES || $id === self::JOBS) && is_array($panel['transformations'] ?? null)) {
                $rendered = self::transformationsOf($dashboard, $id);

                if (self::columns($rendered) !== self::columns($panel['transformations'])) {
                    throw new RuntimeException('Table #'.$id.' shows other columns than the legacy one: '.implode(', ', self::columns($rendered)));
                }

                $panel['transformations'] = $rendered;
            }

            $expected[] = $panel;
        }

        return $expected;
    }

    /**
     * The expected projection of a live render (the accepted look deviations of the live modes, README
     * "Live Overview modes"): the `--live=off` projection with row #10 titled "Live Overview" and, on the live tiles
     * ({@see LiveShapes}), the look properties the live shapes change: no sparkline and the null sentinel on the
     * tile-row tiles (#3, and #2 in stream mode), and the live transformations. Everything else must stay the off
     * render's look.
     *
     * @param  list<array<string, mixed>>  $off  the full projection of the off render
     * @param  array<array-key, mixed>  $offDashboard  the off render, decoded
     * @return list<array<string, mixed>>
     */
    public static function live(LiveMode $mode, array $off, array $offDashboard): array
    {
        $offPanels = [];

        foreach (self::panels($offDashboard) as $panel) {
            $offPanels[$panel['id'] ?? null] = $panel;
        }

        $expected = [];

        foreach ($off as $panel) {
            $id = $panel['id'] ?? null;

            if ($id === self::OVERVIEW_ROW) {
                $panel['title'] = $mode->overviewTitle();
            }

            if (in_array($id, LiveShapes::tiles($mode), true) && is_array($offPanels[$id] ?? null)) {
                /** @var array<string, mixed> $offPanel */
                $offPanel = $offPanels[$id];
                $live = LiveShapes::expected($mode, $offPanel);

                foreach (self::LOOK_KEYS as $key) {
                    if (array_key_exists($key, $live)) {
                        $panel[$key] = $live[$key];
                    } else {
                        unset($panel[$key]);
                    }
                }

                unset($panel['options']['afterRender']);
            }

            $expected[] = $panel;
        }

        return $expected;
    }

    /**
     * The columns a table shows, left to right: the fields its organize step orders, renamed, without the excluded
     * ones.
     *
     * @param  array<array-key, mixed>  $transformations
     * @return list<string>
     */
    public static function columns(array $transformations): array
    {
        foreach ($transformations as $transformation) {
            if (! is_array($transformation) || ($transformation['id'] ?? null) !== 'organize' || ! is_array($transformation['options'] ?? null)) {
                continue;
            }

            $options = $transformation['options'];
            $index = is_array($options['indexByName'] ?? null) ? $options['indexByName'] : [];
            $excluded = is_array($options['excludeByName'] ?? null) ? array_keys(array_filter($options['excludeByName'])) : [];
            $rename = is_array($options['renameByName'] ?? null) ? $options['renameByName'] : [];
            asort($index);
            $columns = [];

            foreach (array_keys($index) as $field) {
                if (! in_array($field, $excluded, true)) {
                    $columns[] = (string) ($rename[$field] ?? $field);
                }
            }

            return $columns;
        }

        return [];
    }

    /**
     * @param  array<array-key, mixed>  $dashboard
     * @return array<array-key, mixed>
     */
    private static function transformationsOf(array $dashboard, int $id): array
    {
        foreach (self::full($dashboard) as $panel) {
            if (($panel['id'] ?? null) === $id) {
                return is_array($panel['transformations'] ?? null) ? $panel['transformations'] : [];
            }
        }

        throw new RuntimeException('Panel '.$id.' is missing.');
    }

    /**
     * A dashboard model as decoded JSON (associative arrays), so a render and a golden compare alike.
     *
     * @param  array<array-key, mixed>  $dashboard
     * @return array<array-key, mixed>
     */
    public static function decoded(array $dashboard): array
    {
        $decoded = json_decode(Json::encode($dashboard), true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<array-key, mixed>  $dashboard
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private static function project(array $dashboard, array $keys): array
    {
        $projection = [];
        $row = null;

        foreach (self::panels($dashboard) as $panel) {
            $isRow = ($panel['type'] ?? null) === 'row';
            $projection[] = self::panel($panel, $keys, $isRow ? null : $row);

            if ($isRow) {
                $row = $panel['id'] ?? null;

                foreach (self::panels($panel) as $child) {
                    $projection[] = self::panel($child, $keys, $row);
                }
            }
        }

        return $projection;
    }

    /**
     * @param  array<array-key, mixed>  $panel
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private static function panel(array $panel, array $keys, mixed $row): array
    {
        $projected = ['row' => $row];

        foreach ($keys as $key) {
            if (array_key_exists($key, $panel)) {
                $projected[$key] = $panel[$key];
            }
        }

        if (isset($projected['options']) && is_array($projected['options'])) {
            unset($projected['options']['afterRender']);
        }

        return $projected;
    }

    /**
     * @param  array<array-key, mixed>  $container
     * @return list<array<array-key, mixed>>
     */
    private static function panels(array $container): array
    {
        $panels = $container['panels'] ?? [];

        return is_array($panels) ? array_values(array_filter($panels, is_array(...))) : [];
    }
}
