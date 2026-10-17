<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana;

use DF\HorizonObservability\Metrics\Metric;

/**
 * PromQL text builders.
 *
 * The static methods are pure combinators that print PromQL exactly as the legacy generator wrote it
 * (`sum by (queue) (x)`, `x and on (queue) y`, `label_replace(x, "dst", "replacement", "src", "regex")`, ...),
 * so the dashboard can be composed from small named parts instead of long literal strings. Expressions are
 * passed as PromQL text; label values, replacements and regexes as plain values, which {@see quote()} escapes.
 *
 * An instance knows the metric prefix and applies the dashboard filters to finished expressions
 * ({@see filtered()}). Every selector of a package family is written by {@see selector()}, {@see shifted()} or
 * {@see over()} from a catalog case (Metrics\Metric); {@see name()} gives the bare name only where a name is
 * needed as text (a `label_values()` query, an alert summary).
 *
 * Every package selector carries the job scope first (Arch Q5.6 step 4): `job=~"$prometheus_job"` in the
 * dashboard, the rendered job regex in the alert rules. With the replica dedupe (step 10) every package selector,
 * or the range function around it, sits in `max by (job, <the family's catalog labels>[, le]) (...)`, so two
 * replicas scraped under one job count once.
 *
 * @internal
 */
final readonly class Promql
{
    public const RANGE = '$__range';

    public const INTERVAL = '$__interval';

    public const FROM_SECONDS = '${__from:date:seconds}';

    public const TO_SECONDS = '${__to:date:seconds}';

    /**
     * Families with a `queue` label: the Queue filter applies to them (legacy `QUEUE_METRICS`).
     */
    private const QUEUE_FAMILIES = [
        'queue_pending_jobs',
        'queue_oldest_pending_job_age_seconds',
        // The legacy names, for the LegacyProfile render only
        'queue_oldest_pending_job_age',
        'horizon_current_processes',
        'queue_jobs_total',
        'queue_jobs_pending',
        'queue_jobs_reserved',
        'queue_job_duration_seconds_bucket',
        'queue_job_duration_seconds_sum',
        'queue_job_duration_seconds_count',
        'queue_job_duration_min_seconds',
        'queue_job_duration_max_seconds',
        'queue_delayed_jobs',
        'queue_delayed_due_jobs',
    ];

    /**
     * Families with a `job_class` label: the Job filter applies to them (legacy `JOB_METRICS`).
     */
    private const JOB_FAMILIES = [
        'queue_jobs_total',
        'queue_jobs_pending',
        'queue_jobs_reserved',
        'queue_job_duration_seconds_bucket',
        'queue_job_duration_seconds_sum',
        'queue_job_duration_seconds_count',
        'queue_job_duration_min_seconds',
        'queue_job_duration_max_seconds',
        'queue_job_classes',
    ];

    private const QUEUE_FILTER = 'queue=~"$queue"';

    private const JOB_FILTER = 'job_class=~"$job_class"';

    /**
     * @var array<string, true>
     */
    private array $queueMetrics;

    /**
     * @var array<string, true>
     */
    private array $jobMetrics;

    /**
     * The dashboard's job scope: the hidden `prometheus_job` variable (Arch Q5.2).
     */
    public const JOB_VARIABLE = '$prometheus_job';

    /**
     * @param  bool  $legacy  the pilot's family names (TEMPORARY, {@see DashboardOptions::$legacy})
     * @param  string|null  $jobRegex  the `job` regex every package selector is scoped to: {@see JOB_VARIABLE} in the
     *                                 dashboard, the rendered regex in the alert rules; null scopes nothing (the pilot)
     * @param  bool  $dedupe  wrap every package selector in `max by (job, <labels>)` (Arch Q5.6 step 10)
     */
    public function __construct(
        private string $prefix,
        public bool $legacy = false,
        private ?string $jobRegex = null,
        private bool $dedupe = false,
    ) {
        $this->queueMetrics = $this->names(self::QUEUE_FAMILIES);
        $this->jobMetrics = $this->names(self::JOB_FAMILIES);
    }

    /**
     * The full name of a family, or of one series of a histogram (`bucket`, `sum` or `count`), e.g.
     * `ho_queue_job_duration_seconds_bucket`. Use {@see selector()} and {@see over()} for selectors.
     */
    public function name(Metric $metric, string $series = ''): string
    {
        return $this->prefix.$metric->value.($series === '' ? '' : '_'.$series);
    }

    /**
     * A package selector as an instant vector: `ho_queue_pending_jobs{job=~"$prometheus_job", queue="x"}`, wrapped
     * in the replica dedupe `max by (job, connection, queue) (...)` (Arch Q1.5, Q5.6 step 10). Two replicas of the
     * application export the same storage under different `instance` labels; the dedupe keeps one of them.
     */
    public function selector(Metric $metric, string ...$matchers): string
    {
        return $this->package($metric, '', array_values($matchers));
    }

    /**
     * A package selector shifted in time: `x offset 5m`, `x @ ${__to:date:seconds}`, deduplicated around it.
     *
     * @param  list<string>  $matchers
     */
    public function shifted(Metric $metric, array $matchers, ?string $offset = null, ?string $at = null): string
    {
        return $this->package($metric, '', $matchers, offset: $offset, at: $at);
    }

    /**
     * A range-vector function of a package selector, `max_over_time(x[5m] offset 1h @ t)`, deduplicated around the
     * call; $series picks a histogram series (`bucket` adds `le` to the dedupe labels).
     *
     * @param  list<string>  $matchers
     */
    public function over(
        string $function,
        Metric $metric,
        string $window,
        array $matchers = [],
        ?string $offset = null,
        ?string $at = null,
        string $series = '',
    ): string {
        return $this->package($metric, $series, $matchers, $function, $window, $offset, $at);
    }

    /**
     * The oldest pending job age per queue (Arch Q5.6 step 3: `queue_oldest_pending_job_age_seconds`).
     */
    public function oldestAge(string ...$matchers): string
    {
        return $this->legacy
            ? self::vector($this->prefix.'queue_oldest_pending_job_age', ...$matchers)
            : $this->selector(Metric::QueueOldestPendingJobAge, ...$matchers);
    }

    /**
     * Worker processes per queue: `horizon_supervisor_processes` per (supervisor, queue), which replaces the
     * pilot's `horizon_current_processes` per queue (Arch Q5.2, Q5.6 step 3). Both come from the supervisors'
     * process maps with the same pooled queue label, so their sums and sample counts per queue are equal. With
     * $function and $window, a range function of it.
     *
     * @param  list<string>  $matchers
     */
    public function queueProcesses(array $matchers = [], ?string $function = null, ?string $window = null): string
    {
        if (! $this->legacy) {
            return $this->package(Metric::SupervisorProcesses, '', $matchers, $function, $window);
        }

        $selector = self::vector($this->prefix.'horizon_current_processes', ...$matchers);

        return $function === null || $window === null ? $selector : self::range($function, $selector, $window);
    }

    /**
     * @param  list<string>  $matchers
     */
    private function package(
        Metric $metric,
        string $series,
        array $matchers,
        ?string $function = null,
        ?string $window = null,
        ?string $offset = null,
        ?string $at = null,
    ): string {
        $selector = self::vector($this->name($metric, $series), ...$this->scoped($matchers));

        if ($function !== null && $window !== null) {
            $selector = self::range($function, $selector, $window, $offset, $at);
        } else {
            $selector = $offset === null ? $selector : self::offset($selector, $offset);
            $selector = $at === null ? $selector : self::at($selector, $at);
        }

        if (! $this->dedupe) {
            return $selector;
        }

        return self::max($selector, ...self::dedupeLabels($metric, $series));
    }

    /**
     * The labels the replica dedupe keeps: `job` and the family's catalog labels, plus `le` for histogram buckets.
     *
     * @return list<string>
     */
    public static function dedupeLabels(Metric $metric, string $series = ''): array
    {
        return ['job', ...$metric->labels(), ...($series === 'bucket' ? ['le'] : [])];
    }

    /**
     * A selector of several package families by `__name__`: `{__name__=~"ho_a|ho_b", job=~"$prometheus_job"}`. Only
     * for `group` and `count` aggregations, which do not depend on the number of replicas.
     *
     * @param  list<Metric>  $families
     */
    public function anyOf(array $families, string ...$matchers): string
    {
        $names = array_map(fn (Metric $metric): string => $this->name($metric), $families);

        return self::vector('', self::re('__name__', implode('|', $names)), ...$this->scoped($matchers));
    }

    /**
     * The job scope in front of a selector's own matchers.
     *
     * @param  array<string>  $matchers
     * @return list<string>
     */
    private function scoped(array $matchers): array
    {
        return $this->jobRegex === null ? array_values($matchers) : [self::re('job', $this->jobRegex), ...array_values($matchers)];
    }

    /**
     * A job name as an exact regex (Go's `regexp.QuoteMeta`): the alert rules scope a pinned `--job` with it.
     */
    public static function literalRegex(string $value): string
    {
        return (string) preg_replace('/[\\\\.+*?()|\[\]{}^$]/', '\\\\$0', $value);
    }

    /**
     * Adds the dashboard filters to every package selector of a finished expression (legacy `filtered`):
     * `queue=~"$queue"` where the family has a queue label and the selector has no queue matcher of its own
     * (so a selector can opt out of the Queue filter), and `job_class=~"$job_class"` when $jobFilter is set
     * and the family has a job_class label. The filters come first, then the selector's own matchers.
     */
    public function filtered(string $expr, bool $jobFilter): string
    {
        $pattern = '/\b('.preg_quote($this->prefix, '/').'[a-z_]+)(\{[^}]*\})?/';

        return (string) preg_replace_callback($pattern, function (array $match) use ($jobFilter): string {
            $name = $match[1];
            $labels = $match[2] ?? '';
            $matchers = [];

            if (isset($this->queueMetrics[$name]) && preg_match('/\bqueue\s*=/', $labels) !== 1) {
                $matchers[] = self::QUEUE_FILTER;
            }

            if ($jobFilter && isset($this->jobMetrics[$name])) {
                $matchers[] = self::JOB_FILTER;
            }

            if ($matchers === []) {
                return $match[0];
            }

            $own = $labels === '' ? '' : substr($labels, 1, -1);
            $scope = $this->jobRegex === null ? '' : self::re('job', $this->jobRegex);

            // The job scope stays the first matcher
            if ($scope !== '' && str_starts_with($own, $scope)) {
                $matchers = [$scope, ...$matchers];
                $own = ltrim(substr($own, strlen($scope)), ', ');
            }

            return $name.'{'.implode(', ', $own === '' ? $matchers : [...$matchers, $own]).'}';
        }, $expr);
    }

    /**
     * A PromQL string literal.
     */
    public static function quote(string $value): string
    {
        return '"'.strtr($value, ['\\' => '\\\\', '"' => '\\"', "\t" => '\\t', "\n" => '\\n']).'"';
    }

    public static function eq(string $label, string $value): string
    {
        return $label.'='.self::quote($value);
    }

    public static function ne(string $label, string $value): string
    {
        return $label.'!='.self::quote($value);
    }

    public static function re(string $label, string $value): string
    {
        return $label.'=~'.self::quote($value);
    }

    /**
     * A vector selector: `name{a="b", c=~"d"}`, or `{a="b"}` without a name.
     */
    public static function vector(string $name, string ...$matchers): string
    {
        return $matchers === [] ? $name : $name.'{'.implode(', ', $matchers).'}';
    }

    public static function paren(string $expr): string
    {
        return '('.$expr.')';
    }

    /**
     * A function call: `name(a, b)`.
     */
    public static function call(string $function, string ...$arguments): string
    {
        return $function.'('.implode(', ', $arguments).')';
    }

    /**
     * A range-vector function: `name(vector[range] offset x @ t)`. A subquery range is written as `1h:1m`.
     */
    public static function range(string $function, string $vector, string $range, ?string $offset = null, ?string $at = null): string
    {
        $expr = $vector.'['.$range.']';

        if ($offset !== null) {
            $expr = self::offset($expr, $offset);
        }

        if ($at !== null) {
            $expr = self::at($expr, $at);
        }

        return self::call($function, $expr);
    }

    public static function offset(string $expr, string $offset): string
    {
        return $expr.' offset '.$offset;
    }

    public static function at(string $expr, string $time): string
    {
        return $expr.' @ '.$time;
    }

    public static function sum(string $expr, string ...$by): string
    {
        return self::aggregate('sum', $expr, $by);
    }

    public static function min(string $expr, string ...$by): string
    {
        return self::aggregate('min', $expr, $by);
    }

    public static function max(string $expr, string ...$by): string
    {
        return self::aggregate('max', $expr, $by);
    }

    public static function count(string $expr, string ...$by): string
    {
        return self::aggregate('count', $expr, $by);
    }

    public static function group(string $expr, string ...$by): string
    {
        return self::aggregate('group', $expr, $by);
    }

    public static function countValues(string $label, string $expr, string ...$by): string
    {
        return self::aggregate('count_values', $expr, $by, self::quote($label));
    }

    public static function topk(int $k, string $expr, string ...$by): string
    {
        return self::aggregate('topk', $expr, $by, (string) $k);
    }

    /**
     * `label_replace(expr, "destination", "replacement", "source", "regex")`.
     */
    public static function labelReplace(string $expr, string $destination, string $replacement, string $source, string $regex): string
    {
        return self::call(
            'label_replace',
            $expr,
            self::quote($destination),
            self::quote($replacement),
            self::quote($source),
            self::quote($regex),
        );
    }

    /**
     * Sets a constant label: `label_replace(expr, "label", "value", "", "")`.
     */
    public static function withLabel(string $expr, string $label, string $value): string
    {
        return self::labelReplace($expr, $label, $value, '', '');
    }

    /**
     * `label_join(expr, "destination", "separator", "source", ...)`.
     */
    public static function labelJoin(string $expr, string $destination, string $separator, string ...$sources): string
    {
        return self::call(
            'label_join',
            $expr,
            self::quote($destination),
            self::quote($separator),
            ...array_map(self::quote(...), $sources),
        );
    }

    /**
     * A binary operation with optional vector matching: `left / on (queue) group_left (limit) right`.
     * $on null means no `on` clause; an empty list prints `on ()`. The same holds for $groupLeft.
     *
     * @param  list<string>|null  $on
     * @param  list<string>|null  $groupLeft
     */
    public static function binary(string $left, string $operator, string $right, ?array $on = null, ?array $groupLeft = null): string
    {
        $matching = '';

        if ($on !== null) {
            $matching .= ' on '.self::labels($on);
        }

        if ($groupLeft !== null) {
            $matching .= ' group_left '.self::labels($groupLeft);
        }

        return $left.' '.$operator.$matching.' '.$right;
    }

    /**
     * `left and on (labels) right`.
     */
    public static function andOn(string $left, string $right, string ...$labels): string
    {
        return self::binary($left, 'and', $right, array_values($labels));
    }

    /**
     * `left or on (labels) right`.
     */
    public static function orOn(string $left, string $right, string ...$labels): string
    {
        return self::binary($left, 'or', $right, array_values($labels));
    }

    /**
     * `left unless on (labels) right`.
     */
    public static function unlessOn(string $left, string $right, string ...$labels): string
    {
        return self::binary($left, 'unless', $right, array_values($labels));
    }

    /**
     * `a or b or c` without vector matching.
     */
    public static function either(string ...$operands): string
    {
        return implode(' or ', $operands);
    }

    /**
     * @param  array<string>  $by
     */
    private static function aggregate(string $operator, string $expr, array $by, ?string $parameter = null): string
    {
        $operand = ($parameter === null ? '' : $parameter.', ').$expr;

        return $by === [] ? $operator.'('.$operand.')' : $operator.' by '.self::labels($by).' ('.$operand.')';
    }

    /**
     * @param  array<string>  $labels
     */
    private static function labels(array $labels): string
    {
        return '('.implode(', ', $labels).')';
    }

    /**
     * @param  list<string>  $families
     * @return array<string, true>
     */
    private function names(array $families): array
    {
        return array_fill_keys(array_map(fn (string $family): string => $this->prefix.$family, $families), true);
    }
}
