<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Grafana\Support;

use DF\HorizonObservability\Grafana\Json;
use RuntimeException;

/**
 * The layout projection of a dashboard (Arch Q5.6 and Q5.7): what must stay equal to the legacy dashboard while
 * WP8B changes the queries. Per panel in document order (the panels of a collapsed row right after it): id,
 * type, title, gridPos, the row it belongs to, repeat options and the collapsed flag; the full projection adds
 * the options without the alert sound script, the field config and the transformations.
 *
 * The full projection of the legacy dashboard is frozen in `tests/Fixtures/grafana/default/layout-projection.json`
 * (as decoded JSON: an empty object reads as an empty list, on both sides of the comparison); it outlived the
 * legacy goldens, which WP8B deleted.
 */
final class LayoutProjection
{
    private const FROZEN = __DIR__.'/../../Fixtures/grafana/default/layout-projection.json';

    private const LAYOUT_KEYS = ['id', 'type', 'title', 'gridPos', 'repeat', 'repeatDirection', 'maxPerRow', 'collapsed'];

    private const LOOK_KEYS = ['options', 'fieldConfig', 'transformations'];

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
