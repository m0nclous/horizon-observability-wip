<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana;

use DF\HorizonObservability\Metrics\Metric;

/**
 * The optional Prometheus recording rules (ADDENDUM R2.4 O9, `horizon-observability:grafana --recording-rules`): the
 * attempt counter and the duration histogram summed per queue, without `job_class`, the replicas deduplicated as on
 * the dashboard (Arch Q5.6 step 10) and scoped to the rendered job like the alert rules.
 *
 * The rendered dashboard never reads them, so the default render does not depend on extra Prometheus config (the
 * Arch Q5 rejected alternative): the recorded series have no class, so they could serve the panels only while Job and
 * Batch are "All", and the 6-hour refresh is within its budget without them (the WP8C measurement). They are for
 * queries over long ranges outside the dashboard, and for panels of one's own.
 *
 * @internal
 */
final readonly class RecordingRulesBuilder
{
    /**
     * The rule group name, as the alert rule group.
     */
    public const GROUP = 'horizon-observability';

    private const BY_QUEUE = ['job', 'connection', 'queue'];

    public function __construct(private DashboardOptions $options) {}

    /**
     * The rules in file order: recorded name => expression.
     *
     * @return array<string, string>
     */
    public function rules(): array
    {
        $promql = $this->options->rulePromql();
        $counter = (string) preg_replace('/_total$/', '', Metric::JobsTotal->value);
        $rules = [
            $this->record($counter, 'sum_by_queue_status') => Promql::sum($promql->selector(Metric::JobsTotal), ...self::BY_QUEUE, ...['status']),
        ];

        foreach (['bucket' => ['le'], 'sum' => [], 'count' => []] as $series => $labels) {
            $rules[$this->record(Metric::JobDuration->value.'_'.$series, 'sum_by_queue')] = Promql::sum(
                $promql->series(Metric::JobDuration, $series),
                ...self::BY_QUEUE,
                ...$labels,
            );
        }

        return $rules;
    }

    /**
     * The rule file for Prometheus `rule_files`: one group evaluated every scrape interval S, the expressions in
     * single quotes.
     */
    public function yaml(): string
    {
        $lines = [
            '# Optional recording rules of sushi-market/horizon-observability, rendered by',
            '# `php artisan horizon-observability:grafana --recording-rules`; the dashboard does not read them.',
            '# Load it with rule_files in prometheus.yml. The sums of counters are counters: read them with increase()',
            '# or rate(), as the class series.',
            'groups:',
            '  - name: '.self::GROUP,
            '    interval: '.$this->options->scrapeIntervalText(),
            '    rules:',
        ];

        foreach ($this->rules() as $record => $expr) {
            $lines[] = '      - record: '.$record;
            $lines[] = "        expr: '".str_replace("'", "''", $expr)."'";
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * A recorded name `level:metric:operations` (the Prometheus naming), the level being the metric namespace;
     * without a namespace the name is `metric:operations`.
     */
    private function record(string $metric, string $operations): string
    {
        $namespace = rtrim($this->options->metricPrefix, '_');

        return ($namespace === '' ? '' : $namespace.':').$metric.':'.$operations;
    }
}
