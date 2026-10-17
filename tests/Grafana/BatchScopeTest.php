<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\Promql;
use DF\HorizonObservability\Tests\Grafana\Support\Promtool;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;
use Workbench\App\Perf\SyntheticModel;

/*
 * ADDENDUM R2.4 O2 (Q9' WP8C acceptance): Job's "All" is `.+`, and the Batch filter reaches the panels as the batch
 * scope `(<selector> unless on (job_class) <classes outside the selected kinds>)` of every job-filtered selector.
 * The legacy mechanism put the classes of the selected kinds into Job's "All" (`job_class=~"(A|B|...)"`), which
 * Grafana inlined into every one of those selectors (2 MB of PromQL per refresh on the reference profile).
 *
 * Equality with the legacy output: every panel expression of the committed render is evaluated twice by promtool on
 * the medium perf profile (one application, 20 minutes at 1 minute), with Batch "All" and with Batch = one kind:
 * - as Grafana sends it now: Job "All" is `.+`, the scope active;
 * - as the legacy render worked: the same expression with the scope's set replaced by an empty one and Job "All"
 *   the regex of the classes the job_class variable lists (asserted too).
 * Both must return the same series with the same values. The variables that the scope replaced
 * (`batch_filtered()` of Queue and Job) are checked against the classes and queues of the kind.
 */

pest()->group('grafana');

const BATCH_SCOPE_KIND = 'import_catalog';

/**
 * The medium perf profile for one application, 20 points at 1 minute, as promtool input series; and the classes,
 * queues and per-kind batch classes it holds.
 *
 * @return array{series: list<array{series: string, values: string}>, classes: list<string>, queues: list<string>, kinds: array<string, array{classes: list<string>, queues: list<string>}>}
 */
function batchScopeData(): array
{
    static $data = null;

    if ($data !== null) {
        return $data;
    }

    $model = new SyntheticModel('medium');
    $values = [];
    $classes = [];
    $queues = [];
    $kinds = [];

    for ($i = 0; $i < 20; $i++) {
        $t = 1_790_000_000 + 60 * $i;

        foreach ($model->series($t) as [$name, $labels, $value]) {
            $selector = 'ho_'.$name.'{job="app",instance="app:8080"'.($labels === '' ? '' : ','.$labels).'}';
            $values[$selector][] = $value;

            if ($i === 19 && in_array($name, ['queue_jobs_total', 'queue_jobs_pending', 'queue_jobs_reserved'], true)
                && preg_match('/job_class="((?:[^"\\\\]|\\\\.)*)"/', $labels, $class) === 1) {
                $classes[stripcslashes($class[1])] = true;
            }

            if ($i === 19 && $name === 'queue_pending_jobs' && preg_match('/queue="([^"]+)"/', $labels, $queue) === 1) {
                $queues[$queue[1]] = true;
            }

            if ($i === 19 && $name === 'queue_batch_job_classes'
                && preg_match('/batch="([^"]+)",queue="([^"]+)",job_class="((?:[^"\\\\]|\\\\.)*)"/', $labels, $link) === 1) {
                $kinds[$link[1]]['classes'][stripcslashes($link[3])] = true;
                $kinds[$link[1]]['queues'][$link[2]] = true;
            }
        }
    }

    $series = [];

    foreach ($values as $selector => $list) {
        $series[] = ['series' => $selector, 'values' => implode(' ', array_map(static fn (int|float $value): string => (string) $value, $list))];
    }

    return $data = [
        'series' => $series,
        'classes' => array_keys($classes),
        'queues' => array_keys($queues),
        'kinds' => array_map(static fn (array $kind): array => array_map(array_keys(...), $kind), $kinds),
    ];
}

/**
 * Grafana's Prometheus regex of a multi-value selection, as it appears inside a PromQL string literal.
 *
 * @param  list<string>  $values
 */
function batchScopeRegex(array $values): string
{
    $regex = '('.implode('|', array_map(static fn (string $value): string => Promql::literalRegex($value), $values)).')';

    return substr(Promql::quote($regex), 1, -1);
}

/**
 * An expression as Grafana sends it at 19m with a 15-minute range and every filter at "All" except Batch ($kind,
 * or "All" for null), Job "All" being $jobClass.
 */
function batchScopeInterpolate(string $expr, ?string $kind, string $jobClass): string
{
    $expr = strtr($expr, [
        '${__from:date:seconds}' => '240',
        '${__to:date:seconds}' => '1140',
        '$__interval_ms' => '60000',
        '$__interval' => '1m',
        '$__range' => '900s',
        '$busy_window' => '2m',
        '$scrape_interval' => '1m',
        '$prometheus_job' => '.+',
        '${batch_kind:text}' => $kind ?? 'All',
        '$batch_kind' => $kind ?? '.*',
        '$job_class' => $jobClass,
        '${horizon_url}' => 'https://app.test/horizon',
    ]);
    $expr = (string) preg_replace('/\$\{[A-Za-z_]\w*:text\}/', 'All', $expr);

    // $1 in label_replace() replacements is not a variable
    return (string) preg_replace('/\$\{[A-Za-z_]\w*\}|\$[A-Za-z_]\w*/', '.*', $expr);
}

/**
 * Non-empty where two instant vectors differ: a series in one only, or another value (NaN equals NaN).
 */
function batchScopeDifference(string $a, string $b): string
{
    return '(('.$a.') != ('.$b.') unless (('.$a.') != ('.$a.'))) or (('.$a.') unless ('.$b.')) or (('.$b.') unless ('.$a.'))';
}

beforeEach(function (): void {
    if (Promtool::command() === null) {
        $this->markTestSkipped('promtool is not available; set PROMTOOL or put promtool on the PATH.');
    }
});

it('puts every job-filtered selector of the committed render in the batch scope', function (): void {
    $promql = new Promql('ho_');
    $scope = ' unless on (job_class) '.$promql->batchExcluded().')';
    $unscoped = [];
    $scoped = 0;

    foreach (Rendered::expressions(alerting: []) as $where => $expr) {
        if (str_starts_with($where, 'panel ')) {
            $scoped += substr_count($expr, $scope);

            if (substr_count($expr, 'job_class=~"$job_class"') !== substr_count($expr, $scope)) {
                $unscoped[] = $where;
            }
        }
    }

    $variables = array_column(Rendered::dashboard()['templating']['list'], null, 'name');

    expect($unscoped)->toBe([])
        ->and($scoped)->toBeGreaterThan(300)
        ->and($variables['job_class']['allValue'])->toBe('.+')
        ->and($variables['batch_kind']['allValue'])->toBe('.*')
        ->and($variables['job_class']['query'])->toContain($scope)
        ->and($variables['queue']['query'])->toContain(' unless on (queue) '.$promql->batchExcluded('queue').')');
});

it('lists the classes and queues of the selected kind in the Job and Queue filters', function (?string $kind): void {
    $data = batchScopeData();
    $variables = [];

    foreach (Rendered::dashboard()['templating']['list'] as $variable) {
        $variables[$variable['name']] = substr($variable['query'], strlen('query_result('), -1);
    }

    $classes = $kind === null ? $data['classes'] : array_values(array_intersect($data['classes'], $data['kinds'][$kind]['classes']));
    $queues = $kind === null ? $data['queues'] : array_values(array_intersect($data['queues'], $data['kinds'][$kind]['queues']));
    $short = static fn (string $class): string => substr($class, (int) strrpos('\\'.$class, '\\'));

    [$status, $output] = Promtool::testRules([], [[
        'interval' => '1m',
        'input_series' => $data['series'],
        'promql_expr_test' => [
            [
                'expr' => batchScopeInterpolate($variables['job_class'], $kind, '.+'),
                'eval_time' => '19m',
                'exp_samples' => array_map(static fn (string $class): array => [
                    'labels' => '{display='.Promql::quote($short($class)).', job_class='.Promql::quote($class).'}',
                    'value' => 1,
                ], $classes),
            ],
            [
                'expr' => 'group by (queue) ('.batchScopeInterpolate($variables['queue'], $kind, '.+').')',
                'eval_time' => '19m',
                'exp_samples' => array_map(static fn (string $queue): array => ['labels' => '{queue="'.$queue.'"}', 'value' => 1], $queues),
            ],
        ],
    ]]);

    expect($classes)->not->toBe([])
        ->and($kind === null || count($classes) < count($data['classes']))->toBeTrue()
        ->and($status)->toBe(0, substr($output, 0, 4000));
})->with([
    'Batch All' => [null],
    'Batch = one kind' => [BATCH_SCOPE_KIND],
]);

it('gives every panel the legacy result with Batch All and with Batch = one kind', function (?string $kind): void {
    $data = batchScopeData();
    $excluded = (new Promql('ho_'))->batchExcluded();
    $classes = $kind === null ? $data['classes'] : array_values(array_intersect($data['classes'], $data['kinds'][$kind]['classes']));
    $legacyAll = batchScopeRegex($classes);
    $tests = [];

    foreach (Rendered::expressions(alerting: []) as $where => $expr) {
        if (! str_starts_with($where, 'panel ')) {
            continue;
        }

        $now = batchScopeInterpolate($expr, $kind, '.+');
        $legacy = batchScopeInterpolate(str_replace($excluded, '(vector(1) unless on () vector(1))', $expr), $kind, $legacyAll);

        $tests[] = ['expr' => '# differs '.$where.'
'.batchScopeDifference($now, $legacy), 'eval_time' => '19m', 'exp_samples' => []];
        // Fails, on purpose, where the panel has a value: equal results must not be trivially empty
        $tests[] = ['expr' => '# has data '.$where.'
count('.$now.')', 'eval_time' => '19m', 'exp_samples' => []];
    }

    [, $output] = Promtool::testRules([], [[
        'interval' => '1m',
        'input_series' => $data['series'],
        'promql_expr_test' => $tests,
    ]]);

    preg_match_all('/# differs (panel [^\\\\"]+?)\\\\n/', $output, $differing);
    preg_match_all('/# has data (panel [^\\\\"]+?)\\\\n/', $output, $withData);

    expect(count($tests))->toBeGreaterThan(120)
        ->and($differing[1])->toBe([])
        ->and(count($withData[1]))->toBeGreaterThan(45, substr($output, 0, 2000))
        // Every failure is a panel with data, none an evaluation error
        ->and(substr_count($output, 'expr: "# has data '))->toBe(substr_count($output, 'expr: "#'))
        ->and($output)->not->toContain('err:');
})->with([
    'Batch All' => [null],
    'Batch = one kind' => [BATCH_SCOPE_KIND],
]);
