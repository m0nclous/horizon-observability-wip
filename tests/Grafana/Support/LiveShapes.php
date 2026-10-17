<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Grafana\Support;

use DF\HorizonObservability\Grafana\Live\LiveMode;
use RuntimeException;

/**
 * The live panel shapes of ADDENDUM R4.8, written out by hand from the addendum (verified there on Grafana 12.4.13 and
 * 13.2.4), independent of the renderer: what a poll or stream tile must be, given its `--live=off` panel. Only the
 * properties R4.8 lists change; {@see expected()} applies them to the off panel. JSON is compared without key order
 * ({@see canonical()}).
 */
final class LiveShapes
{
    public const FILTERS = '${supervisor:queryparam}&${batch_kind:queryparam}&${queue:queryparam}&${job_class:queryparam}';

    public const STREAM_ID = 'horizon_observability';

    /**
     * `TILE_ROWS(t)`, verbatim from R4.8.
     */
    private const TILE_ROWS = <<<'JSON'
        [ { "id": "filterByValue", "options": { "type": "include", "match": "any", "filters": [
              { "fieldName": "tile", "config": { "id": "equal", "options": { "value": "<t>" } } } ] } },
          { "id": "groupBy", "options": { "fields": {
              "tile":    { "operation": "groupby" },
              "key":     { "operation": "groupby" },
              "caption": { "operation": "aggregate", "aggregations": ["last"] },
              "value":   { "operation": "aggregate", "aggregations": ["last"] },
              "color":   { "operation": "aggregate", "aggregations": ["last"] } } } },
          { "id": "rowsToFields", "options": { "mappings": [
              { "fieldName": "caption (last)", "handlerKey": "field.name" },
              { "fieldName": "value (last)",   "handlerKey": "field.value" },
              { "fieldName": "color (last)",   "handlerKey": "color" },
              { "fieldName": "tile",           "handlerKey": "__ignore" },
              { "fieldName": "key",            "handlerKey": "__ignore" } ] } } ]
        JSON;

    /**
     * `SENTINEL`, verbatim from R4.8.
     */
    private const SENTINEL = '{"type": "value", "options": {"-1": {"text": "—", "color": "text", "index": 0}}}';

    /**
     * An Infinity query of `${api_path}/live?FILTERS` (R4.8), with its root selector and columns.
     */
    private const SNAPSHOT_TARGET = <<<'JSON'
        { "refId": "<ref>", "datasource": { "type": "yesoreyeram-infinity-datasource", "uid": "${app_api}" },
          "type": "json", "source": "url", "parser": "backend", "format": "table",
          "url": "${api_path}/live?FILTERS", "url_options": { "method": "GET", "data": "" }, "root_selector": "<root>",
          "columns": <columns> }
        JSON;

    /**
     * The changed properties of each live tile, by mode and panel id, verbatim from R4.8 (`<A>` is the off-mode target
     * A, unchanged). `"$unset"` lists the properties the live panel no longer has.
     */
    private const CHANGES = [
        'poll' => [
            // Poll #3 Supervisor Processes
            3 => <<<'JSON'
                { "datasource": { "type": "yesoreyeram-infinity-datasource", "uid": "${app_api}" },
                  "targets": [ { "target": "S", "root": "tiles", "columns": [
                                 { "selector": "tile", "text": "tile", "type": "string" },
                                 { "selector": "key", "text": "key", "type": "string" },
                                 { "selector": "caption", "text": "caption", "type": "string" },
                                 { "selector": "value", "text": "value", "type": "number" },
                                 { "selector": "color", "text": "color", "type": "string" } ] } ],
                  "transformations": "TILE_ROWS(supervisor)",
                  "fieldConfig": { "defaults": { "mappings": "SENTINEL" } },
                  "options": { "graphMode": "none" },
                  "$unset": ["interval"] }
                JSON,
            // Poll #2 Pending
            2 => <<<'JSON'
                { "datasource": { "type": "datasource", "uid": "-- Mixed --" },
                  "interval": "$scrape_interval",
                  "targets": [ "<A>",
                    { "target": "L", "root": "", "columns": [ { "selector": "generated_at", "text": "Time", "type": "timestamp_epoch" },
                                                             { "selector": "pending.value", "text": "Value", "type": "number" } ] },
                    { "target": "C", "root": "", "columns": [ { "selector": "pending.caption", "text": "caption", "type": "string" },
                                                             { "selector": "pending.color", "text": "color", "type": "string" } ] } ],
                  "transformations": [
                    { "id": "merge", "filter": { "id": "byRefId", "options": "A|L" }, "options": {} },
                    { "id": "configFromData", "options": { "configRefId": "C", "mappings": [
                        { "fieldName": "caption", "handlerKey": "displayName" },
                        { "fieldName": "color", "handlerKey": "color" } ] } } ] }
                JSON,
            // Poll #9 Delayed: the same A and L with delayed.value, no C, only merge
            9 => <<<'JSON'
                { "datasource": { "type": "datasource", "uid": "-- Mixed --" },
                  "interval": "$scrape_interval",
                  "targets": [ "<A>",
                    { "target": "L", "root": "", "columns": [ { "selector": "generated_at", "text": "Time", "type": "timestamp_epoch" },
                                                             { "selector": "delayed.value", "text": "Value", "type": "number" } ] } ],
                  "transformations": [ { "id": "merge", "filter": { "id": "byRefId", "options": "A|L" }, "options": {} } ] }
                JSON,
            // Poll and stream #8 Completed
            8 => <<<'JSON'
                { "datasource": { "type": "datasource", "uid": "-- Mixed --" },
                  "interval": "$scrape_interval",
                  "targets": [ "<A>",
                    { "target": "C", "root": "", "columns": [ { "selector": "throughput.caption", "text": "caption", "type": "string" } ] } ],
                  "transformations": [ { "id": "configFromData", "options": { "configRefId": "C", "mappings": [
                      { "fieldName": "caption", "handlerKey": "displayName" } ] } } ] }
                JSON,
        ],
        'stream' => [
            // Stream #3 Supervisor Processes
            3 => <<<'JSON'
                { "datasource": { "type": "datasource", "uid": "grafana" },
                  "targets": [ { "refId": "A", "datasource": { "type": "datasource", "uid": "grafana" },
                                 "queryType": "measurements", "channel": "stream/<id>/tiles", "buffer": 500 } ],
                  "transformations": "TILE_ROWS(supervisor)",
                  "fieldConfig": { "defaults": { "mappings": "SENTINEL" } },
                  "options": { "graphMode": "none" },
                  "$unset": ["interval"] }
                JSON,
            // Stream #2 Pending
            2 => <<<'JSON'
                { "datasource": { "type": "datasource", "uid": "grafana" },
                  "targets": [ { "refId": "A", "datasource": { "type": "datasource", "uid": "grafana" },
                                 "queryType": "measurements", "channel": "stream/<id>/tiles", "buffer": 500 } ],
                  "transformations": "TILE_ROWS(pending)",
                  "fieldConfig": { "defaults": { "mappings": "SENTINEL" } },
                  "options": { "graphMode": "none" },
                  "$unset": ["interval"] }
                JSON,
            // Stream #9 Delayed
            9 => <<<'JSON'
                { "datasource": { "type": "datasource", "uid": "grafana" },
                  "targets": [ { "refId": "A", "datasource": { "type": "datasource", "uid": "grafana" },
                                 "queryType": "measurements", "channel": "stream/<id>/delayed" } ],
                  "maxDataPoints": 180,
                  "timeFrom": "15m",
                  "hideTimeOverride": true,
                  "options": { "graphMode": "area", "textMode": "value" },
                  "$unset": ["interval"] }
                JSON,
            8 => 'poll',
        ],
    ];

    /**
     * The live tiles of a mode: panel ids.
     *
     * @return list<int>
     */
    public static function tiles(LiveMode $mode): array
    {
        return $mode === LiveMode::Off ? [] : array_keys(self::CHANGES[$mode->value]);
    }

    /**
     * `TILE_ROWS(t)`.
     *
     * @return list<array<string, mixed>>
     */
    public static function tileRows(string $tile): array
    {
        /** @var list<array<string, mixed>> */
        return self::decode(str_replace('<t>', $tile, self::TILE_ROWS));
    }

    /**
     * @return array<string, mixed>
     */
    public static function sentinel(): array
    {
        /** @var array<string, mixed> */
        return self::decode(self::SENTINEL);
    }

    /**
     * The R4.8 panel of a live tile: its decoded `--live=off` panel with the R4.8 changes.
     *
     * @param  array<string, mixed>  $off
     * @return array<string, mixed>
     */
    public static function expected(LiveMode $mode, array $off, string $streamId = self::STREAM_ID): array
    {
        $changes = self::CHANGES[$mode->value][$off['id']] ?? throw new RuntimeException('No R4.8 shape for panel '.$off['id']);
        $changes = $changes === 'poll' ? self::CHANGES['poll'][$off['id']] : $changes;
        /** @var array<string, mixed> $changes */
        $changes = self::decode(str_replace('<id>', $streamId, $changes));
        $panel = $off;

        foreach ($changes['$unset'] ?? [] as $key) {
            unset($panel[$key]);
        }

        unset($changes['$unset']);

        foreach ($changes as $key => $value) {
            $panel[$key] = match ($key) {
                'targets' => array_map(static fn (mixed $target): mixed => self::target($target, $off), $value),
                'transformations' => is_string($value) ? self::tileRows((string) preg_replace('/^TILE_ROWS\((\w+)\)$/', '$1', $value)) : $value,
                'fieldConfig' => self::merged($off['fieldConfig'], $value),
                'options' => [...$off['options'], ...$value],
                default => $value,
            };
        }

        return $panel;
    }

    /**
     * A document with every associative array sorted by key, recursively: JSON compared without key order.
     */
    public static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::canonical(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $off
     */
    private static function target(mixed $target, array $off): mixed
    {
        if ($target === '<A>') {
            foreach ($off['targets'] as $offTarget) {
                if ($offTarget['refId'] === 'A') {
                    return $offTarget;
                }
            }

            throw new RuntimeException('The off panel '.$off['id'].' has no target A.');
        }

        if (! is_array($target) || ! isset($target['target'])) {
            return $target;
        }

        $json = strtr(self::SNAPSHOT_TARGET, [
            '<ref>' => $target['target'],
            '<root>' => $target['root'],
            '<columns>' => json_encode($target['columns'], JSON_THROW_ON_ERROR),
            'FILTERS' => self::FILTERS,
        ]);

        return self::decode($json);
    }

    /**
     * The off field config with the R4.8 mappings: `SENTINEL` merged into the existing mappings.
     *
     * @param  array<string, mixed>  $fieldConfig
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private static function merged(array $fieldConfig, array $changes): array
    {
        if (($changes['defaults']['mappings'] ?? null) === 'SENTINEL') {
            $fieldConfig['defaults']['mappings'] = [...$fieldConfig['defaults']['mappings'], self::sentinel()];
        }

        return $fieldConfig;
    }

    private static function decode(string $json): mixed
    {
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }
}
