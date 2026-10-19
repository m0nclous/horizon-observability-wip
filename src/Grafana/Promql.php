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
 * Every package selector carries the job scope first: `job=~"$prometheus_job"` in the dashboard, the rendered job
 * regex in the alert rules. With the replica dedupe every package selector, or the range function around it, sits
 * in `max by (job, <the family's catalog labels>[, le]) (...)`, so two replicas scraped under one job count once.
 *
 * @internal
 */
final readonly class Promql
{
    public const RANGE = '$__range';

    public const INTERVAL = '$__interval';

    public const INTERVAL_MS = '$__interval_ms';

    /**
     * The period edges rounded to whole seconds (Grafana formats them as Math.round(ms / 1000)).
     */
    public const FROM_SECONDS = '${__from:date:seconds}';

    public const TO_SECONDS = '${__to:date:seconds}';

    /**
     * The exact period edges in seconds: Grafana's milliseconds as a number literal (`1760000000123e-3`). The
     * instant queries run at the exact `to` in milliseconds, so a period count that must agree with them (the tiles'
     * edge steps, the per-step graphs, the batch scope) takes these, not the rounded {@see FROM_SECONDS} and
     * {@see TO_SECONDS}: a scrape between an edge and its rounded second counted in only one of the two forms, and
     * a rounded end that reached the next graph point left the last step short.
     */
    public const FROM = '${__from}e-3';

    public const TO = '${__to}e-3';

    /**
     * Families with a `queue` label: the Queue filter applies to them (legacy `QUEUE_METRICS`).
     */
    private const QUEUE_FAMILIES = [
        'queue_pending_jobs',
        'queue_oldest_pending_job_age_seconds',
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
     * The sentinel label of the Batch scope: no stored series can carry it.
     */
    public const BATCH_SCOPE = '__batch_scope__';

    private const BATCH_VARIABLE = '$batch_kind';

    /**
     * @var array<string, true>
     */
    private array $queueMetrics;

    /**
     * @var array<string, true>
     */
    private array $jobMetrics;

    /**
     * The dashboard's job scope: the hidden `prometheus_job` variable.
     */
    public const JOB_VARIABLE = '$prometheus_job';

    /**
     * @param  string  $jobRegex  the `job` regex every package selector is scoped to: {@see JOB_VARIABLE} in the
     *                            dashboard, the rendered regex in the alert rules
     * @param  bool  $dedupe  wrap every package selector in `max by (job, <labels>)`
     */
    public function __construct(
        private string $prefix,
        private string $jobRegex = self::JOB_VARIABLE,
        private bool $dedupe = true,
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
     * in the replica dedupe `max by (job, connection, queue) (...)`. Two replicas of the application export the same
     * storage under different `instance` labels; the dedupe keeps one of them.
     */
    public function selector(Metric $metric, string ...$matchers): string
    {
        return $this->package($metric, '', array_values($matchers));
    }

    /**
     * One series of a histogram family (`bucket`, `sum` or `count`) as an instant vector, deduplicated as
     * {@see selector()} (`bucket` adds `le` to the dedupe labels), for the opt-in recording rules.
     */
    public function series(Metric $metric, string $series, string ...$matchers): string
    {
        return $this->package($metric, $series, array_values($matchers));
    }

    /**
     * A package selector without the replica dedupe, for the opt-in health rules, which look at each replica: the
     * duplicates rule counts the replicas, the collector rule finds a collector that fails on one of them
     * (self-metrics are per replica, unlike the business series read from shared storage).
     */
    public function replicas(Metric $metric, string ...$matchers): string
    {
        return self::vector($this->name($metric), ...$this->scoped(array_values($matchers)));
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
        return [self::re('job', $this->jobRegex), ...array_values($matchers)];
    }

    /**
     * A PromQL duration of whole seconds: `20s`, `2m`, `1h`.
     */
    public static function duration(int $seconds): string
    {
        return match (true) {
            $seconds > 0 && $seconds % 3600 === 0 => intdiv($seconds, 3600).'h',
            $seconds > 0 && $seconds % 60 === 0 => intdiv($seconds, 60).'m',
            default => $seconds.'s',
        };
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
     * (so a selector can opt out of the Queue filter), and `job_class=~"$job_class"` when $jobFilter is set,
     * the family has a job_class label and the selector no job_class matcher of its own. The filters come first,
     * then the selector's own matchers. Names inside PromQL strings are not selectors.
     *
     * A job-filtered selector also gets the Batch scope: Job's "All" is `.+`, so the classes of the selected batch
     * kinds are no longer in `$job_class`; the selector, with its range function and the replica dedupe around it,
     * becomes `(<selector> unless on (job_class) <classes outside the kinds>)`, see {@see batchScoped()}. The classes
     * outside the kinds are those of the selector's own series (the same family, window, offset and `@`, read again
     * under the Batch switch) without the classes of the selected kinds in the period: a class that stopped inside
     * the period is still in the first set, so it no longer slips past the scope (#38), and the reread
     * costs only while kinds are selected.
     */
    public function filtered(string $expr, bool $jobFilter): string
    {
        $labels = '\{(?:[^}"]|"(?:[^"\\\\]|\\\\.)*")*\}';
        $time = '(?: offset -?[$\w{}:]+)?(?: @ (?:start\(\)|end\(\)|[$\w{}:]+(?:e-3)?))?';
        // A string, or a package selector with the range function and the dedupe Promql::package() writes around it
        $pattern = '/"(?:[^"\\\\]|\\\\.)*"|\b(?<dedupe>max by \([^()]*\) \()?(?<fn>[a-z_]+\()?'
            .'(?<name>'.preg_quote($this->prefix, '/').'[a-z_]+)(?<labels>'.$labels.')?'
            .'(?<window>(?(<fn>)\[[^\]]+\]))(?<time>'.$time.')(?(<fn>)\))(?(<dedupe>)\))/';

        return (string) preg_replace_callback($pattern, function (array $match) use ($jobFilter): string {
            if (! isset($match['name']) || $match['name'] === '') {
                return $match[0];
            }

            $name = $match['name'];
            $labels = $match['labels'] ?? '';
            $matchers = [];

            if (isset($this->queueMetrics[$name]) && preg_match('/\bqueue\s*=/', $labels) !== 1) {
                $matchers[] = self::QUEUE_FILTER;
            }

            $scoped = $jobFilter && isset($this->jobMetrics[$name]) && preg_match('/\bjob_class\s*[=!]/', $labels) !== 1;

            if ($scoped) {
                $matchers[] = self::JOB_FILTER;
            }

            if ($matchers === []) {
                return $match[0];
            }

            $own = $labels === '' ? '' : substr($labels, 1, -1);
            $scope = self::re('job', $this->jobRegex);

            // The job scope stays the first matcher
            if (str_starts_with($own, $scope)) {
                $matchers = [$scope, ...$matchers];
                $own = ltrim(substr($own, strlen($scope)), ', ');
            }

            $selector = $name.'{'.implode(', ', $own === '' ? $matchers : [...$matchers, $own]).'}';
            $start = strlen($match['dedupe']) + strlen($match['fn']);
            $unit = substr($match[0], 0, $start).$selector.substr($match[0], $start + strlen($name) + strlen($labels));

            if (! $scoped) {
                return $unit;
            }

            // The classes of the unit's own series: the same family, window and time (offset, `@`) with the selector's
            // own matchers (the job scope first) and the Batch switch, so a vector at a fixed time stays step-invariant.
            // The filters stay out (a superset of the unit's classes, a shorter query); by `__name__` like anyOf(), as
            // a group aggregation needs no replica dedupe
            $classes = self::vector('', self::re('__name__', $name), ...[...($labels === '' ? [] : [substr($labels, 1, -1)]), self::nre(self::BATCH_SCOPE, self::BATCH_VARIABLE)]);
            $classes = $match['fn'] === '' ? $classes.$match['time'] : 'last_over_time('.$classes.$match['window'].$match['time'].')';

            return $this->batchScoped($unit, classes: self::group($classes, 'job_class'));
        }, $expr);
    }

    /**
     * The Batch scope of a per-series vector: `(<vector> unless on (<label>) <excluded>)`, where <excluded> is every
     * class (or queue) of the package series outside the selected batch kinds. While Batch is "All" (`$batch_kind` is
     * `.*`) the set is empty and almost free; with kinds selected it holds what the legacy "All" list of the Job (or
     * Queue) filter left out. $vector must be one operand: a selector with its range function and dedupe, or a
     * parenthesised expression. $classes, when given, are the classes of $vector's own series with the Batch switch
     * ({@see filtered()}), else those of the period ({@see batchExcluded()}).
     *
     * @param  'job_class'|'queue'  $label
     */
    public function batchScoped(string $vector, string $label = 'job_class', ?string $classes = null): string
    {
        return self::paren(self::unlessOn($vector, $this->batchExcluded($label, $classes), $label));
    }

    /**
     * The classes (or queues) of the package series outside the selected batch kinds: $classes (the classes of the
     * series to scope, under the Batch switch), by default every class of the period
     * `group by (job_class) (last_over_time({__name__=~"<jobs_total|jobs_pending|jobs_reserved>",
     * __batch_scope__!~"$batch_kind"}[$__range] @ ${__to}e-3))`, unless the classes of the selected kinds in the period
     * `group by (job_class) (last_over_time({__name__=~"<batch_job_classes>", batch=~"$batch_kind",
     * __batch_scope__!~"$batch_kind"}[$__range] @ ${__to}e-3))`.
     *
     * No stored series has the label `__batch_scope__` (Prometheus drops `__`-prefixed labels after relabelling), and
     * an absent label matches as "": with Batch "All" the matcher `!~".*"` selects nothing, so both operands are empty;
     * with kinds selected it selects every series of the family. Group aggregations do not depend on the number of
     * replicas, hence no dedupe.
     *
     * Both sets are taken over the period, not at the instant: a class (or queue) that is no longer exported at the
     * end of the period (its application stopped inside it, or the last scrape failed) still has attempts in the
     * period; an instant set left it in neither operand, so it slipped past the scope and counted under the selected
     * kind. Pinned to the period end (`@`), each set is evaluated once per range query.
     *
     * @param  'job_class'|'queue'  $label
     */
    public function batchExcluded(string $label = 'job_class', ?string $classes = null): string
    {
        $families = $label === 'job_class'
            ? [Metric::JobsTotal, Metric::QueueJobsPending, Metric::QueueJobsReserved]
            : [Metric::QueuePendingJobs];

        return self::paren(self::unlessOn(
            $classes ?? self::group($this->inPeriod($this->anyOf($families, self::nre(self::BATCH_SCOPE, self::BATCH_VARIABLE))), $label),
            $this->batchKindClasses($label),
            $label,
        ));
    }

    /**
     * The classes (or queues) of the selected batch kinds in the period, under the Batch switch (empty while Batch
     * is "All"): the second operand of {@see batchExcluded()}.
     *
     * @param  'job_class'|'queue'  $label
     */
    public function batchKindClasses(string $label = 'job_class'): string
    {
        $kinds = $this->anyOf([Metric::BatchJobClasses], self::re('batch', self::BATCH_VARIABLE), self::nre(self::BATCH_SCOPE, self::BATCH_VARIABLE));

        return self::group($this->inPeriod($kinds), $label);
    }

    /**
     * The last value of every series of a selector in the period, pinned to the period end.
     */
    private function inPeriod(string $selector): string
    {
        return self::range('last_over_time', $selector, self::RANGE, at: self::TO);
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

    public static function nre(string $label, string $value): string
    {
        return $label.'!~'.self::quote($value);
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
