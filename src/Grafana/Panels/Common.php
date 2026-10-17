<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana\Panels;

use DF\HorizonObservability\Grafana\Glyph;
use DF\HorizonObservability\Grafana\Promql;
use DF\HorizonObservability\Grafana\Thresholds;
use stdClass;

/**
 * Pieces shared by the panels: datasource references, targets, the stat tile, table columns, rows and the
 * transformation building blocks.
 *
 * @internal
 */
final readonly class Common
{
    public const ROW_HEIGHT = 1;

    /**
     * Percent bars: the cell value is OFFSET + percent, so a four-digit string sorts like the number;
     * OFFSET + UNKNOWN means "unknown" and prints a dash.
     */
    public const PERCENT_OFFSET = 1000;

    public const UNKNOWN_PERCENT = 999;

    /**
     * A centred dash for markdown cells.
     */
    public const DASH_CELL = '<div style="text-align: center;">'.Glyph::DASH.'</div>';

    public function __construct(private Promql $promql) {}

    /**
     * The Prometheus datasource of the hidden `datasource` variable.
     *
     * @return array<string, string>
     */
    public static function prometheus(): array
    {
        return ['type' => 'prometheus', 'uid' => '${datasource}'];
    }

    /**
     * Grafana's `-- Dashboard --` datasource: a panel reads the results of another panel.
     *
     * @return array<string, string>
     */
    public static function dashboard(): array
    {
        return ['type' => 'datasource', 'uid' => '-- Dashboard --'];
    }

    /**
     * The Infinity datasource of the hidden `app_api` variable.
     *
     * @return array<string, string>
     */
    public static function infinity(): array
    {
        return ['type' => 'yesoreyeram-infinity-datasource', 'uid' => '${app_api}'];
    }

    /**
     * A Prometheus target with the dashboard filters applied (legacy `target`). $jobFilter false keeps the Job
     * filter out, for values that must not depend on the selected jobs.
     *
     * @return array<string, mixed>
     */
    public function target(
        string $refId,
        string $expr,
        bool $instant = true,
        ?string $format = null,
        bool $jobFilter = true,
        string $legend = '__auto',
    ): array {
        return self::rawTarget($refId, $this->promql->filtered($expr, $jobFilter), $instant, $format, $legend);
    }

    /**
     * A Prometheus target with an expression used as is.
     *
     * @return array<string, mixed>
     */
    public static function rawTarget(
        string $refId,
        string $expr,
        bool $instant = true,
        ?string $format = null,
        string $legend = '__auto',
    ): array {
        $target = [
            'datasource' => self::prometheus(),
            'editorMode' => 'code',
            'expr' => $expr,
            'legendFormat' => $legend,
            'range' => ! $instant,
            'instant' => $instant,
            'refId' => $refId,
        ];

        if ($format !== null) {
            $target['format'] = $format;
        }

        return $target;
    }

    /**
     * A stat tile (legacy `stat`, with the options the legacy script set on the tiles afterwards).
     *
     * @param  array{h: int, w: int, x: int, y: int}  $gridPos
     * @param  list<array<string, mixed>>  $targets
     * @param  array<string, mixed>|null  $thresholds
     * @param  list<string>  $calcs
     * @param  array{0: int, 1: int}|null  $valueRange
     * @param  list<array<string, mixed>>|null  $transformations
     * @return array<string, mixed>
     */
    public static function stat(
        int $id,
        string $title,
        array $gridPos,
        array $targets,
        ?array $thresholds = null,
        string $colorMode = 'none',
        string $graphMode = 'none',
        array $calcs = ['lastNotNull'],
        ?array $valueRange = null,
        ?string $displayName = null,
        bool $withName = false,
        ?array $transformations = null,
    ): array {
        $defaults = [
            'color' => ['mode' => 'thresholds'],
            'decimals' => 0,
            'mappings' => [],
            'noValue' => Glyph::DASH,
            'thresholds' => $thresholds ?? self::thresholds('text'),
            // Digit grouping by the browser locale
            'unit' => 'locale',
        ];

        if ($valueRange !== null) {
            $defaults['min'] = $valueRange[0];
            $defaults['max'] = $valueRange[1];
        }

        if ($displayName !== null) {
            $defaults['displayName'] = $displayName;
        }

        $options = [
            'colorMode' => $colorMode,
            'graphMode' => $graphMode,
            'justifyMode' => 'center',
            'orientation' => 'auto',
            'percentChangeColorMode' => 'standard',
            'reduceOptions' => ['calcs' => $calcs, 'fields' => '', 'values' => false],
            'showPercentChange' => false,
            'textMode' => $withName ? 'value_and_name' : 'value',
            'wideLayout' => true,
        ];

        if ($withName) {
            // The default caption size follows the tile and wrapped onto two lines in narrow tiles
            $options['text'] = ['titleSize' => 14];
        }

        $panel = [
            'datasource' => self::prometheus(),
            'fieldConfig' => ['defaults' => $defaults, 'overrides' => []],
            'gridPos' => $gridPos,
            'id' => $id,
            'options' => $options,
            'targets' => $targets,
            'title' => $title,
            'type' => 'stat',
        ];

        if ($transformations !== null) {
            $panel['transformations'] = $transformations;
        }

        return $panel;
    }

    /**
     * Absolute thresholds: the base colour, then [value, colour] steps.
     *
     * @param  array{0: int|float, 1: string}  ...$steps
     * @return array<string, mixed>
     */
    public static function thresholds(string $base, array ...$steps): array
    {
        $list = [['color' => $base, 'value' => null]];

        foreach ($steps as [$value, $color]) {
            $list[] = ['color' => $color, 'value' => $value];
        }

        return ['mode' => 'absolute', 'steps' => $list];
    }

    /**
     * A field override by column name (legacy `column`).
     *
     * @param  list<array<string, mixed>>  $extra
     * @return array<string, mixed>
     */
    public static function column(string $name, ?int $width = null, array $extra = []): array
    {
        $properties = $width === null ? [] : [self::property('custom.width', $width)];

        return ['matcher' => ['id' => 'byName', 'options' => $name], 'properties' => [...$properties, ...$extra]];
    }

    /**
     * @return array<string, mixed>
     */
    public static function property(string $id, mixed $value): array
    {
        return ['id' => $id, 'value' => $value];
    }

    /**
     * A count column that turns $color from 1 on.
     *
     * @return list<array<string, mixed>>
     */
    public static function colourFromOne(string $color = 'red'): array
    {
        return [
            self::property('custom.cellOptions', ['type' => 'color-text']),
            self::property('thresholds', self::thresholds('text', [1, $color])),
        ];
    }

    /**
     * A duration column in seconds.
     *
     * @return list<array<string, mixed>>
     */
    public static function seconds(int $decimals): array
    {
        return [self::property('unit', 's'), self::property('decimals', $decimals)];
    }

    /**
     * The success rate column of the queue and job tables. Without attempts in the period the cell is a plain
     * dash, not the red of the lowest threshold.
     *
     * @return array<string, mixed>
     */
    public static function successRateColumn(): array
    {
        return self::column('Success rate', 110, [
            self::property('mappings', [['type' => 'special', 'options' => [
                'match' => 'null+nan',
                'result' => ['text' => Glyph::DASH, 'color' => 'text', 'index' => 0],
            ]]]),
            self::property('unit', 'percentunit'),
            self::property('decimals', 1),
            self::property('custom.cellOptions', ['type' => 'color-text']),
            self::property('thresholds', self::thresholds(
                'red',
                [Thresholds::SUCCESS_RATE_CRITICAL, 'yellow'],
                [Thresholds::SUCCESS_RATE_WARNING, 'text'],
            )),
        ]);
    }

    /**
     * Field defaults of the tables; $numeric adds the integer locale format of the Prometheus tables.
     *
     * @return array<string, mixed>
     */
    public static function tableDefaults(bool $numeric = true): array
    {
        $defaults = [
            'custom' => [
                'align' => 'auto',
                'cellOptions' => ['type' => 'auto'],
                'filterable' => false,
                'inspect' => false,
            ],
        ];

        if ($numeric) {
            $defaults['decimals'] = 0;
        }

        $defaults['mappings'] = [];
        $defaults['noValue'] = Glyph::DASH;

        if ($numeric) {
            $defaults['unit'] = 'locale';
        }

        $defaults['thresholds'] = self::thresholds('text');

        return $defaults;
    }

    /**
     * Table options sorted by the given columns, descending unless the value is false.
     *
     * @param  array<string, bool>  $sortBy  column => descending
     * @return array<string, mixed>
     */
    public static function tableOptions(array $sortBy): array
    {
        $sort = [];

        foreach ($sortBy as $column => $descending) {
            $sort[] = ['desc' => $descending, 'displayName' => $column];
        }

        return ['cellHeight' => 'md', 'showHeader' => true, 'sortBy' => $sort];
    }

    /**
     * A dashboard row. A collapsed row keeps its panels inside, not in the dashboard list (legacy `row`).
     *
     * @param  list<array<string, mixed>>|null  $collapsedPanels
     * @return array<string, mixed>
     */
    public static function row(int $id, string $title, int $y, ?array $collapsedPanels = null): array
    {
        return [
            'collapsed' => $collapsedPanels !== null,
            'gridPos' => self::gridPos(self::ROW_HEIGHT, 24, 0, $y),
            'id' => $id,
            'panels' => $collapsedPanels ?? [],
            'title' => $title,
            'type' => 'row',
        ];
    }

    /**
     * @return array{h: int, w: int, x: int, y: int}
     */
    public static function gridPos(int $h, int $w, int $x, int $y): array
    {
        return ['h' => $h, 'w' => $w, 'x' => $x, 'y' => $y];
    }

    /**
     * `merge`: joins the frames of all table queries into one.
     *
     * @return array<string, mixed>
     */
    public static function merge(): array
    {
        return ['id' => 'merge', 'options' => new stdClass];
    }

    /**
     * Markdown cells render string fields only.
     *
     * @return array<string, mixed>
     */
    public static function toString(string $field): array
    {
        return ['id' => 'convertFieldType', 'options' => ['conversions' => [
            ['targetField' => $field, 'destinationType' => 'string'],
        ]]];
    }

    /**
     * `labelsToFields` for one query.
     *
     * @return array<string, mixed>
     */
    public static function labelsToFields(string $refId): array
    {
        return ['id' => 'labelsToFields', 'filter' => self::byRefId($refId), 'options' => new stdClass];
    }

    /**
     * @return array<string, mixed>
     */
    public static function renameByRegex(string $regex, string $pattern): array
    {
        return ['id' => 'renameByRegex', 'options' => ['regex' => $regex, 'renamePattern' => $pattern]];
    }

    /**
     * "Config from query results": fields of query $refId configure the frames of query $applyTo.
     *
     * @param  list<array<string, mixed>>  $mappings
     * @return array<string, mixed>
     */
    public static function configFromData(string $refId, array $mappings, string $applyTo = 'A'): array
    {
        return ['id' => 'configFromData', 'options' => [
            'configRefId' => $refId,
            'mappings' => $mappings,
            'applyTo' => ['id' => 'byFrameRefID', 'options' => $applyTo],
        ]];
    }

    /**
     * A "Config from query results" field mapping.
     *
     * @return array<string, mixed>
     */
    public static function handler(string $field, string $handler): array
    {
        return ['fieldName' => $field, 'handlerKey' => $handler];
    }

    /**
     * A field that becomes a threshold step of $color.
     *
     * @return array<string, mixed>
     */
    public static function thresholdHandler(string $field, string $color): array
    {
        return [
            ...self::handler($field, 'threshold1'),
            'handlerArguments' => ['threshold' => ['color' => $color]],
        ];
    }

    /**
     * A binary "Add field from calculation" on the frames of query $refId: `$field $operator $fixed`.
     *
     * @return array<string, mixed>
     */
    public static function calculate(string $refId, string $alias, string $field, string $operator, string $fixed): array
    {
        return ['id' => 'calculateField', 'filter' => self::byRefId($refId), 'options' => [
            'mode' => 'binary',
            'alias' => $alias,
            'binary' => [
                'left' => ['matcher' => ['id' => 'byName', 'options' => $field]],
                'operator' => $operator,
                'right' => ['fixed' => $fixed],
            ],
        ]];
    }

    /**
     * @return array<string, string>
     */
    public static function byRefId(string $refId): array
    {
        return ['id' => 'byRefId', 'options' => $refId];
    }

    /**
     * A regex value mapping.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public static function regexMapping(string $pattern, array $result): array
    {
        return ['type' => 'regex', 'options' => ['pattern' => $pattern, 'result' => $result]];
    }

    /**
     * Markdown percent bar with the percent inside, filled with $fill (legacy `load_bar`). The two capture
     * groups of the mapping pattern hold the percent without leading zeros. Grafana's HTML sanitiser strips
     * flex and line-height, so the bar height comes from padding.
     */
    public static function percentBar(string $fill, string $text): string
    {
        return '<div style="background: linear-gradient(90deg, '.$fill.' $1$2%, rgba(204, 204, 220, 0.12) $1$2%);'
            .' border-radius: 3px; color: '.$text.'; font-size: 12px; text-align: center; padding: 2px 0;">$1$2%</div>';
    }

    /**
     * The mapping of the unknown percent to a dash.
     *
     * @return array<string, mixed>
     */
    public static function unknownPercentMapping(int $index): array
    {
        return self::regexMapping(
            '/^'.(self::PERCENT_OFFSET + self::UNKNOWN_PERCENT).'$/',
            ['text' => self::DASH_CELL, 'index' => $index],
        );
    }

    /**
     * "undefined" is the empty value of a stub row after the string conversion.
     *
     * @return array<string, mixed>
     */
    public static function undefinedMapping(): array
    {
        return ['type' => 'value', 'options' => ['undefined' => ['text' => self::DASH_CELL, 'index' => 99]]];
    }
}
