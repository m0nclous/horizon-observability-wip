<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana\Panels;

use DF\HorizonObservability\Grafana\DashboardOptions;
use DF\HorizonObservability\Grafana\Glyph;
use DF\HorizonObservability\Grafana\PanelRegistry;

/**
 * Completed, failed, pending and delayed jobs row by row, like Horizon's job pages, read by the Infinity
 * datasource from the application's job list endpoints.
 *
 * The lists hold Horizon's data for the dashboard period, but no longer than Horizon keeps it (horizon.trim).
 * Pending are the jobs queued in the period and not completed yet, including reserved ones. Newest first.
 * The filters are passed as query parameters (`${var:queryparam}`: the selection, or `$__all` for no filter);
 * the application resolves Supervisor and Batch into queues and classes. A click on a job opens it in
 * Horizon: the application returns the page path (`horizon_path`, it differs between lists) and the root
 * selector (JSONata) builds the link, which the Job column renders as HTML, so the stub row has no link.
 *
 * @internal
 */
final readonly class JobLists
{
    public const HEIGHT = 15;

    private const FILTERS = ['supervisor', 'batch_kind', 'queue', 'job_class'];

    /**
     * The JSONata short class name of a row.
     */
    private const SHORT_NAME = '$split(name, "\\\\")[-1]';

    public function __construct(private DashboardOptions $options) {}

    /**
     * Failed jobs across the full width at $y, below them completed jobs on the left at full height, pending
     * and delayed jobs stacked on the right.
     *
     * @return list<array<string, mixed>>
     */
    public function all(int $y): array
    {
        $below = $y + self::HEIGHT;

        return [
            $this->failed(Common::gridPos(self::HEIGHT, 24, 0, $y)),
            $this->completed(Common::gridPos(2 * self::HEIGHT, 14, 0, $below)),
            $this->pending(Common::gridPos(self::HEIGHT, 10, 14, $below)),
            $this->delayed(Common::gridPos(self::HEIGHT, 10, 14, $below + self::HEIGHT)),
        ];
    }

    /**
     * Exception is the class and message the application returns ("QueryException: SQLSTATE..."). Job is as
     * wide as the name, Exception takes the rest; the full text is in the cell inspector.
     *
     * @param  array{h: int, w: int, x: int, y: int}  $gridPos
     * @return array<string, mixed>
     */
    private function failed(array $gridPos): array
    {
        return $this->table(PanelRegistry::FAILED_JOBS, Glyph::WARNING.' Failed Jobs', $gridPos, 'failed', 'Failed', [
            $this->field('exception', 'Exception', 'string'),
            $this->field('attempts', 'Attempts', 'number'),
            $this->field('runtime', 'Runtime', 'number'),
            $this->field('failed_at', 'Failed', 'timestamp_epoch'),
        ], [
            Common::column('Job', 220),
            Common::column('Exception', extra: [
                Common::property('custom.minWidth', 200),
                Common::property('custom.inspect', true),
            ]),
            Common::column('Attempts', 75),
            Common::column('Failed', 160, $this->timeFormat()),
        ]);
    }

    /**
     * @param  array{h: int, w: int, x: int, y: int}  $gridPos
     * @return array<string, mixed>
     */
    private function completed(array $gridPos): array
    {
        return $this->table(PanelRegistry::COMPLETED_JOBS, Glyph::CHECK.' Completed Jobs', $gridPos, 'completed', 'Completed', [
            $this->field('queued_at', 'Queued', 'timestamp_epoch'),
            $this->field('completed_at', 'Completed', 'timestamp_epoch'),
            $this->field('runtime', 'Runtime', 'number'),
        ], [
            Common::column('Queued', 160, $this->timeFormat()),
            Common::column('Completed', 160, $this->timeFormat()),
        ]);
    }

    /**
     * @param  array{h: int, w: int, x: int, y: int}  $gridPos
     * @return array<string, mixed>
     */
    private function pending(array $gridPos): array
    {
        return $this->table(PanelRegistry::PENDING_JOBS, Glyph::CLOCK.' Pending Jobs', $gridPos, 'pending', 'Queued', [
            $this->field('queued_at', 'Queued', 'timestamp_epoch'),
        ], [
            Common::column('Queued', 160, $this->timeFormat()),
        ]);
    }

    /**
     * Delayed jobs are not a Horizon list but the current state of the queues: not due yet, so the dashboard
     * period does not apply. Soonest first. Attempts above zero is a retry waiting for its backoff. No link:
     * Horizon shows a delayed job only for horizon.trim.pending minutes after it was queued.
     *
     * @param  array{h: int, w: int, x: int, y: int}  $gridPos
     * @return array<string, mixed>
     */
    private function delayed(array $gridPos): array
    {
        return $this->table(PanelRegistry::DELAYED_JOBS, Glyph::HOURGLASS.' Delayed Jobs', $gridPos, 'delayed', 'Available', [
            $this->field('attempts', 'Attempts', 'number'),
            $this->field('queued_at', 'Queued', 'timestamp_epoch'),
            $this->field('available_at', 'Available', 'timestamp_epoch'),
        ], [
            Common::column('Attempts', 75),
            Common::column('Queued', 160, $this->timeFormat()),
            Common::column('Available', 160, $this->timeFormat()),
        ], descending: false, jobColumn: self::SHORT_NAME);
    }

    /**
     * One job list table (legacy `horizon_jobs_table`). The columns are Horizon's; Infinity returns fields in
     * alphabetical order, so the organize transformation sets their order.
     *
     * @param  array{h: int, w: int, x: int, y: int}  $gridPos
     * @param  list<array{selector: string, text: string, type: string}>  $columns
     * @param  list<array<string, mixed>>  $overrides
     * @param  string|null  $jobColumn  JSONata of the Job column; null links the short class name to Horizon
     * @return array<string, mixed>
     */
    private function table(
        int $id,
        string $title,
        array $gridPos,
        string $list,
        string $sortBy,
        array $columns,
        array $overrides,
        bool $descending = true,
        ?string $jobColumn = null,
    ): array {
        $names = ['Job', 'Queue', ...array_column($columns, 'text')];
        $filters = implode('&', array_map(static fn (string $name): string => '${'.$name.':queryparam}', self::FILTERS));
        $link = '"<a href=\"${horizon_url}/" & horizon_path & "\" target=\"_blank\">" & '.self::SHORT_NAME.' & "</a>"';
        // An empty list is a stub row of dashes: otherwise only headers remain, without the Job column, and the
        // table does not stretch
        $rows = '$count(data) > 0 ? data.($merge([$, {"job": '.($jobColumn ?? $link).'}]))'
            .' : [{"job": "'.Glyph::DASH.'", "queue": "'.Glyph::DASH.'"}]';

        return [
            'datasource' => Common::infinity(),
            'fieldConfig' => [
                'defaults' => Common::tableDefaults(numeric: false),
                'overrides' => [
                    Common::column('Job', extra: [
                        Common::property('custom.minWidth', 140),
                        Common::property('custom.cellOptions', ['type' => 'markdown']),
                    ]),
                    Common::column('Queue', 90),
                    // As in Horizon: "0.14s", not converted to milliseconds
                    Common::column('Runtime', 80, [Common::property('unit', 'suffix:s'), Common::property('decimals', 2)]),
                    ...$overrides,
                ],
            ],
            'gridPos' => $gridPos,
            'id' => $id,
            'options' => Common::tableOptions([$sortBy => $descending]),
            'targets' => [[
                'refId' => 'A',
                'datasource' => Common::infinity(),
                'type' => 'json',
                'source' => 'url',
                'format' => 'table',
                'parser' => 'backend',
                'url' => $this->options->jobListUrl($list).'?limit=500&from=${__from}&to=${__to}&'.$filters,
                'url_options' => ['method' => 'GET', 'data' => ''],
                'root_selector' => $rows,
                'columns' => [
                    $this->field('job', 'Job', 'string'),
                    $this->field('queue', 'Queue', 'string'),
                    ...$columns,
                ],
            ]],
            'title' => $title,
            'transformations' => [['id' => 'organize', 'options' => ['indexByName' => array_flip($names)]]],
            'type' => 'table',
        ];
    }

    /**
     * @return array{selector: string, text: string, type: string}
     */
    private function field(string $selector, string $text, string $type): array
    {
        return ['selector' => $selector, 'text' => $text, 'type' => $type];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function timeFormat(): array
    {
        return [Common::property('unit', 'time:YYYY-MM-DD HH:mm:ss')];
    }
}
