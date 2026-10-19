<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana\Perf;

use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Metrics\MetricType;

/**
 * The static performance gates of a rendered dashboard, the deterministic part of the budget in
 * `docker/perf/budget.json`, computed from the JSON with every filter at "All", without Prometheus or Grafana, so CI
 * can run them on every change: requests and variable queries per auto-refresh, interpolated PromQL per refresh and
 * per panel, the variable refresh policy, no day-long lookback on a counter, no `batch_filtered()`-style duplicate
 * branch and an explicit `maxDataPoints` on the hidden queue source. The wall times are measured with the tools of
 * `docker/perf/README.md`.
 *
 * What runs on a refresh: every panel outside the collapsed rows, hidden ones included, one request per datasource
 * it queries (a panel on the `-- Dashboard --` datasource reads another panel and sends none, a Grafana Live channel
 * of the `-- Grafana --` datasource is a subscription, not a request), each copy of a repeated panel, and the query
 * variables that refresh on a time range change. Grafana interpolates the expressions the way {@see interpolate()}
 * does for a one-hour range at the reference scrape interval.
 *
 * The budget of 12 requests is the `--live=off` dashboard's; the live modes add the snapshot calls of their Infinity
 * targets (`${api_path}/live`), at most 5 per refresh, each served from the snapshot cache, which {@see liveRequests()}
 * counts apart.
 *
 * @internal
 */
final readonly class StaticBudget
{
    public const MAX_REQUESTS = 12;

    /**
     * Poll mode's extra cost per refresh: calls of the live snapshot endpoint: the five live tile
     * queries and the queue source's columns of now. The endpoint serves them from its 1-second cache.
     */
    public const MAX_LIVE_REQUESTS = 6;

    public const MAX_VARIABLE_REQUESTS = 2;

    public const MAX_INTERPOLATED_BYTES = 100 * 1024;

    public const MAX_PANEL_BYTES = 25 * 1024;

    /**
     * The only variables that refresh on a time range change, because they read `$__range`; every other query variable
     * refreshes on dashboard load.
     */
    public const RANGE_VARIABLES = ['batch_kind', 'batch_title'];

    /**
     * Grafana's refresh values: on dashboard load and on time range change.
     */
    public const ON_LOAD = 1;

    public const ON_TIME_RANGE_CHANGE = 2;

    /**
     * The hidden shared queue source (`PanelRegistry::QUEUE_SOURCE`).
     */
    public const QUEUE_SOURCE = 24;

    /**
     * Copies of a repeated panel: the supervisors of the reference profile (`medium`, docker/perf/README.md).
     */
    public const REFERENCE_REPEATS = 4;

    /**
     * What a copy of a repeated panel gets for its repeat variable: a supervisor name of the reference profile.
     */
    private const REPEAT_VALUE = 'supervisor-notifications';

    /**
     * The interpolation of a one-hour range at S = 10 s, as Grafana's backend substitutes the built-in variables.
     */
    private const BUILT_INS = [
        '__interval' => '10s',
        '__interval_ms' => '10000',
        '__rate_interval' => '40s',
        '__range' => '3600s',
        '__range_s' => '3600',
        '__range_ms' => '3600000',
        '__from' => '1792252800000',
        '__to' => '1792256400000',
    ];

    private const DATASOURCE_DASHBOARD = '-- Dashboard --';

    /**
     * The `-- Grafana --` datasource of the stream tiles: a Grafana Live subscription, no request per refresh.
     */
    private const DATASOURCE_GRAFANA = 'grafana';

    /**
     * Infinity targets of the live snapshot endpoint (`LivePanels::URL`).
     */
    private const LIVE_URL = '${api_path}/live';

    /**
     * @param  array<array-key, mixed>  $dashboard  the decoded dashboard JSON
     * @param  array<string, list<string>>  $options  the options of the multi-value variables without an allValue,
     *                                                by name: Grafana's "All" is the regex of all of them
     * @param  int  $repeats  copies of each repeated panel
     */
    public function __construct(
        private array $dashboard,
        private array $options = [],
        private int $repeats = self::REFERENCE_REPEATS,
    ) {}

    /**
     * Every violated gate, as readable lines; empty when the dashboard is within the budget.
     *
     * @return list<string>
     */
    public function violations(): array
    {
        $violations = [];

        if ($this->requests() > self::MAX_REQUESTS) {
            $violations[] = sprintf('%d requests per refresh, more than %d', $this->requests(), self::MAX_REQUESTS);
        }

        if ($this->liveRequests() > self::MAX_LIVE_REQUESTS) {
            $violations[] = sprintf('%d live snapshot calls per refresh, more than %d', $this->liveRequests(), self::MAX_LIVE_REQUESTS);
        }

        if ($this->variableRequests() > self::MAX_VARIABLE_REQUESTS) {
            $violations[] = sprintf('%d variable queries per refresh, more than %d', $this->variableRequests(), self::MAX_VARIABLE_REQUESTS);
        }

        if ($this->interpolatedBytes() > self::MAX_INTERPOLATED_BYTES) {
            $violations[] = sprintf('%.1f KB of interpolated PromQL per refresh, more than %d KB', $this->interpolatedBytes() / 1024, self::MAX_INTERPOLATED_BYTES / 1024);
        }

        foreach ($this->panelBytes() as $panel => $bytes) {
            if ($bytes > self::MAX_PANEL_BYTES) {
                $violations[] = sprintf('panel %d: %.1f KB of interpolated PromQL, more than %d KB', $panel, $bytes / 1024, self::MAX_PANEL_BYTES / 1024);
            }
        }

        return [
            ...$violations,
            ...$this->variablePolicy(),
            ...$this->counterLookbacks(),
            ...$this->duplicateBranches(),
            ...$this->queueSource(),
        ];
    }

    /**
     * Requests per auto-refresh: one per datasource of each panel that runs, each repeat copy counted, plus the
     * variable queries; the live snapshot calls are {@see liveRequests()}.
     */
    public function requests(): int
    {
        $requests = $this->variableRequests();

        foreach ($this->runningPanels() as $panel) {
            $requests += count($this->datasources($panel)) * $this->copies($panel);
        }

        return $requests;
    }

    /**
     * Calls of the live snapshot endpoint per auto-refresh: one per Infinity target of `${api_path}/live` (Grafana
     * runs the targets of one datasource in one request, but Infinity fetches each URL), each repeat copy counted.
     */
    public function liveRequests(): int
    {
        $calls = 0;

        foreach ($this->runningPanels() as $panel) {
            foreach ($this->list($panel['targets'] ?? []) as $target) {
                $calls += $this->isLive($target) ? $this->copies($panel) : 0;
            }
        }

        return $calls;
    }

    /**
     * Query variables that run on every auto-refresh (a relative range changes with each refresh).
     */
    public function variableRequests(): int
    {
        return count($this->rangeVariables());
    }

    /**
     * Interpolated PromQL bytes per auto-refresh: the panels that run and the variable queries.
     */
    public function interpolatedBytes(): int
    {
        $bytes = array_sum($this->panelBytes());

        foreach ($this->rangeVariables() as $variable) {
            $bytes += strlen($this->interpolate($this->string($variable['query'] ?? '')));
        }

        return $bytes;
    }

    /**
     * Interpolated PromQL bytes of each panel that runs, all its repeat copies together, by panel id.
     *
     * @return array<int, int>
     */
    public function panelBytes(): array
    {
        $bytes = [];

        foreach ($this->runningPanels() as $panel) {
            $repeat = $this->string($panel['repeat'] ?? '');
            $copy = 0;

            foreach ($this->list($panel['targets'] ?? []) as $target) {
                $expr = $this->string($target['expr'] ?? '');

                if ($expr !== '') {
                    $copy += strlen($this->interpolate($expr, $repeat));
                }
            }

            $id = $panel['id'] ?? 0;
            $bytes[is_int($id) ? $id : 0] = $copy * $this->copies($panel);
        }

        return $bytes;
    }

    /**
     * The variable refresh policy: the query variables in {@see RANGE_VARIABLES} refresh on a time range change, every
     * other query or datasource variable on dashboard load.
     *
     * @return list<string>
     */
    public function variablePolicy(): array
    {
        $violations = [];

        foreach ($this->variables() as $variable) {
            $type = $variable['type'] ?? null;

            if ($type !== 'query' && $type !== 'datasource') {
                continue;
            }

            $name = $this->string($variable['name'] ?? '');
            $expected = $type === 'query' && in_array($name, self::RANGE_VARIABLES, true) ? self::ON_TIME_RANGE_CHANGE : self::ON_LOAD;

            if (($variable['refresh'] ?? null) !== $expected) {
                $violations[] = sprintf('variable %s: refresh %s instead of %d', $name, json_encode($variable['refresh'] ?? null), $expected);
            }
        }

        return $violations;
    }

    /**
     * Counter and histogram selectors with a literal range of an hour or more (the start of a period looks back
     * max(10m, 10 x S), not a day), in every expression of the dashboard.
     *
     * @return list<string>
     */
    public function counterLookbacks(): array
    {
        $counters = [];

        foreach (Metric::cases() as $metric) {
            if ($metric->type() === MetricType::Counter) {
                $counters[] = preg_quote($metric->value, '/');
            } elseif ($metric->type() === MetricType::Histogram) {
                $counters[] = preg_quote($metric->value, '/').'_(?:bucket|sum|count)';
            }
        }

        $pattern = '/\b\w*?(?:'.implode('|', $counters).')(?:\{(?:[^}"]|"(?:[^"\\\\]|\\\\.)*")*\})?\[(\d+)([hdwy])\]/';
        $violations = [];

        foreach ($this->expressions() as $where => $expr) {
            if (preg_match_all($pattern, $expr, $matches, PREG_SET_ORDER) > 0) {
                foreach ($matches as $match) {
                    $violations[] = $where.': a counter looks back '.$match[1].$match[2];
                }
            }
        }

        return $violations;
    }

    /**
     * The legacy `batch_filtered()` pattern: an operand switched on by the Batch-is-All test (`(X) and on ()
     * (label_replace(vector(1), "filter", `${batch_kind:text}`, ...))`) that the same expression evaluates in another
     * branch too. Prometheus evaluates both branches of an `or`, so X costs twice; the batch scope belongs in the
     * selectors (`unless on (job_class) <excluded classes>`, which is empty while Batch is All).
     *
     * @return list<string>
     */
    public function duplicateBranches(): array
    {
        $test = ' and on () ';
        $switch = '(label_replace(vector(1), "filter", `${';
        $violations = [];

        foreach ($this->expressions() as $where => $expr) {
            $offset = 0;

            while (($at = strpos($expr, $test.$switch.'batch_kind:text}`', $offset)) !== false) {
                $offset = $at + 1;
                $operand = $this->operandBefore($expr, $at);

                // The other filter tests chained to this one are no branch
                if ($operand !== null && ! str_starts_with($operand, $switch) && substr_count($expr, $operand) > 1) {
                    $violations[] = $where.': an operand switched by a filter is evaluated twice ('.substr($operand, 0, 60).'...)';
                }
            }
        }

        return $violations;
    }

    /**
     * The hidden queue source must set `maxDataPoints`: without it a source hidden before its first render never
     * runs and every queue table stays empty.
     *
     * @return list<string>
     */
    public function queueSource(): array
    {
        foreach ($this->runningPanels() as $panel) {
            if (($panel['id'] ?? null) === self::QUEUE_SOURCE && ! is_int($panel['maxDataPoints'] ?? null)) {
                return ['panel '.self::QUEUE_SOURCE.': the hidden queue source has no maxDataPoints'];
            }
        }

        return [];
    }

    /**
     * An expression as Grafana sends it with every filter at "All": `${var:text}` is "All", a multi-value variable its
     * allValue or the regex of its options, a textbox its value, and $repeat (the repeat variable of a panel copy) one
     * option.
     */
    public function interpolate(string $expr, string $repeat = ''): string
    {
        $variables = [];

        foreach ($this->variables() as $variable) {
            $variables[$this->string($variable['name'] ?? '')] = $variable;
        }

        return (string) preg_replace_callback(
            '/\$\{(\w+)(?::([\w:]+))?\}|\$(\w+)|\[\[(\w+)\]\]/',
            function (array $match) use ($variables, $repeat): string {
                $name = $match[1] !== '' ? $match[1] : (($match[3] ?? '') !== '' ? $match[3] : ($match[4] ?? ''));
                $format = $match[2] ?? '';

                if ($name === '__from' || $name === '__to') {
                    return $format === 'date:seconds' ? substr(self::BUILT_INS[$name], 0, -3) : self::BUILT_INS[$name];
                }

                if (isset(self::BUILT_INS[$name])) {
                    return self::BUILT_INS[$name];
                }

                if (! isset($variables[$name])) {
                    return $match[0];
                }

                if ($name === $repeat) {
                    return self::REPEAT_VALUE;
                }

                return $format === 'text' ? 'All' : $this->allValue($variables[$name]);
            },
            $expr,
        );
    }

    /**
     * @param  array<array-key, mixed>  $variable
     */
    private function allValue(array $variable): string
    {
        if (($variable['type'] ?? null) === 'textbox' || ($variable['type'] ?? null) === 'constant') {
            return $this->string($variable['query'] ?? '');
        }

        if (is_string($variable['allValue'] ?? null)) {
            return $variable['allValue'];
        }

        $options = $this->options[$this->string($variable['name'] ?? '')] ?? [];

        // Grafana's Prometheus regex of a multi-value selection
        return '('.implode('|', array_map(static fn (string $option): string => (string) preg_replace('/[\\\\^$*+?.()|{}\[\]]/', '\\\\\\\\$0', $option), $options)).')';
    }

    /**
     * The panels a refresh runs: every panel outside the collapsed rows, except the rows.
     *
     * @return list<array<array-key, mixed>>
     */
    private function runningPanels(): array
    {
        return array_values(array_filter(
            $this->list($this->dashboard['panels'] ?? []),
            static fn (array $panel): bool => ($panel['type'] ?? null) !== 'row',
        ));
    }

    /**
     * The datasources a panel queries (by uid) for other than the live snapshot, without the `-- Dashboard --` and
     * `-- Grafana --` datasources.
     *
     * @param  array<array-key, mixed>  $panel
     * @return list<string>
     */
    private function datasources(array $panel): array
    {
        $uids = [];
        $default = is_array($panel['datasource'] ?? null) ? $this->string($panel['datasource']['uid'] ?? '') : '';

        foreach ($this->list($panel['targets'] ?? []) as $target) {
            if ($this->isLive($target)) {
                continue;
            }

            $uid = is_array($target['datasource'] ?? null) ? $this->string($target['datasource']['uid'] ?? '') : $default;
            $uids[$uid === '' ? $default : $uid] = true;
        }

        unset($uids[self::DATASOURCE_DASHBOARD], $uids[self::DATASOURCE_GRAFANA]);

        return array_map(strval(...), array_keys($uids));
    }

    /**
     * Whether a target reads the live snapshot endpoint.
     *
     * @param  array<array-key, mixed>  $target
     */
    private function isLive(array $target): bool
    {
        $url = $this->string($target['url'] ?? '');

        return $url === self::LIVE_URL || str_starts_with($url, self::LIVE_URL.'?');
    }

    /**
     * @param  array<array-key, mixed>  $panel
     */
    private function copies(array $panel): int
    {
        return $this->string($panel['repeat'] ?? '') === '' ? 1 : $this->repeats;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function rangeVariables(): array
    {
        return array_values(array_filter(
            $this->variables(),
            static fn (array $variable): bool => ($variable['type'] ?? null) === 'query' && ($variable['refresh'] ?? null) === self::ON_TIME_RANGE_CHANGE,
        ));
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function variables(): array
    {
        $templating = $this->dashboard['templating'] ?? [];

        return $this->list(is_array($templating) ? ($templating['list'] ?? []) : []);
    }

    /**
     * Every Prometheus expression of the dashboard, collapsed rows and variables included, keyed by where it is.
     *
     * @return array<string, string>
     */
    private function expressions(): array
    {
        $expressions = [];

        foreach ($this->list($this->dashboard['panels'] ?? []) as $panel) {
            foreach ([$panel, ...$this->list($panel['panels'] ?? [])] as $item) {
                foreach ($this->list($item['targets'] ?? []) as $target) {
                    $expr = $this->string($target['expr'] ?? '');

                    if ($expr !== '') {
                        $expressions['panel '.json_encode($item['id'] ?? null).' '.$this->string($target['refId'] ?? '')] = $expr;
                    }
                }
            }
        }

        foreach ($this->variables() as $variable) {
            if (($variable['type'] ?? null) === 'query') {
                $expressions['variable '.$this->string($variable['name'] ?? '')] = $this->string($variable['query'] ?? '');
            }
        }

        return $expressions;
    }

    /**
     * The balanced parenthesised operand that ends right before $offset, or null.
     */
    private function operandBefore(string $expr, int $offset): ?string
    {
        if ($offset === 0 || $expr[$offset - 1] !== ')') {
            return null;
        }

        $depth = 0;
        $inString = false;

        for ($i = $offset - 1; $i >= 0; $i--) {
            $char = $expr[$i];

            if ($char === '"' && ($i === 0 || $expr[$i - 1] !== '\\')) {
                $inString = ! $inString;
            }

            if ($inString) {
                continue;
            }

            if ($char === ')') {
                $depth++;
            } elseif ($char === '(' && --$depth === 0) {
                return substr($expr, $i, $offset - $i);
            }
        }

        return null;
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function list(mixed $items): array
    {
        return is_array($items) ? array_values(array_filter($items, is_array(...))) : [];
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
