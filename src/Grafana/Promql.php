<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana;

/**
 * PromQL text builders.
 *
 * The static methods are pure combinators that print PromQL exactly as the legacy generator wrote it
 * (`sum by (queue) (x)`, `x and on (queue) y`, `label_replace(x, "dst", "replacement", "src", "regex")`, ...),
 * so the dashboard can be composed from small named parts instead of long literal strings. Expressions are
 * passed as PromQL text; label values, replacements and regexes as plain values, which {@see quote()} escapes.
 *
 * An instance knows the metric prefix and applies the dashboard filters to finished expressions
 * ({@see filtered()}). Every vector selector of a package family is written by {@see selector()}; {@see metric()}
 * gives the bare name only where a name is needed as text (a `__name__` regex, an alert summary).
 *
 * Seams for WP8B: the job scope matcher and the replica dedupe wrappers (Arch Q5.6 steps 4 and 10) belong in
 * {@see selector()}, the one place every package selector comes from, and in {@see filtered()}; the legacy
 * profile has neither.
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

    public function __construct(private string $prefix)
    {
        $this->queueMetrics = $this->names(self::QUEUE_FAMILIES);
        $this->jobMetrics = $this->names(self::JOB_FAMILIES);
    }

    /**
     * The full metric name of a family, e.g. `queue_jobs_total` -> `office_queue_jobs_total`. Use
     * {@see selector()} for a vector selector.
     */
    public function metric(string $family): string
    {
        return $this->prefix.$family;
    }

    /**
     * A vector selector of a package family: `office_queue_jobs_total{status=~"failed"}`, or the bare name
     * without matchers.
     */
    public function selector(string $family, string ...$matchers): string
    {
        return self::vector($this->metric($family), ...$matchers);
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
        return array_fill_keys(array_map($this->metric(...), $families), true);
    }
}
