<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\Live\LiveMode;
use DF\HorizonObservability\Grafana\Promql;
use DF\HorizonObservability\Tests\Grafana\Support\ProfileSeries;
use DF\HorizonObservability\Tests\Grafana\Support\Promtool;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * Job's "All" is `.+`, and the Batch filter reaches the panels as the batch scope
 * `(<selector> unless on (job_class) <classes outside the selected kinds>)` of every job-filtered selector. The legacy
 * mechanism put the classes of the selected kinds into Job's "All" (`job_class=~"(A|B|...)"`), which Grafana inlined
 * into every one of those selectors (2 MB of PromQL per refresh on the reference profile).
 *
 * Equality with the legacy output: every panel expression of the committed render is evaluated twice by promtool on
 * the medium perf profile (one application, 20 minutes at 1 minute), with Batch "All" and with Batch = one kind:
 * - as Grafana sends it now: Job "All" is `.+`, the scope active;
 * - as the legacy render worked: the same expression with the scope's sets switched off (empty) and Job "All" the
 *   regex of the classes the job_class variable lists (asserted too).
 * Both must return the same series with the same values. The variables that the scope replaced
 * (`batch_filtered()` of Queue and Job) are checked against the classes and queues of the kind. The panels are those of
 * the --live=off render, whose Prometheus targets include every one of the live renders (LiveContractTest).
 */

pest()->group('grafana');

beforeEach(function (): void {
    if (Promtool::command() === null) {
        $this->markTestSkipped('promtool is not available; set PROMTOOL or put promtool on the PATH.');
    }
});

/**
 * The Batch switch of the scope sets: with it at `!~".*"` (what Grafana sends for Batch "All") both sets of every scope
 * are empty, whatever the selected kind.
 */
const BATCH_SWITCH = '__batch_scope__!~"$batch_kind"';

/**
 * An expression with its batch scope switched off: both sets of every scope empty, as with Batch "All".
 */
function withoutBatchScope(string $expr): string
{
    return str_replace(BATCH_SWITCH, '__batch_scope__!~".*"', $expr);
}

it('puts every job-filtered selector of the committed renders in the batch scope', function (LiveMode $mode): void {
    $promql = new Promql('ho_');
    // Every scope ends with the classes of the selected kinds in the period
    $end = ' unless on (job_class) '.$promql->batchKindClasses().'))';
    $unscoped = [];
    $scoped = 0;

    foreach (Rendered::expressions(alerting: [], mode: $mode) as $where => $expr) {
        if (str_starts_with($where, 'panel ')) {
            $count = substr_count($expr, $end);
            $scoped += $count;

            if (substr_count($expr, 'job_class=~"$job_class"') !== $count || substr_count($expr, BATCH_SWITCH) !== 2 * $count) {
                $unscoped[] = $where;
            }
        }
    }

    $variables = array_column(Rendered::dashboard($mode)['templating']['list'], null, 'name');

    expect($unscoped)->toBe([])
        ->and($scoped)->toBeGreaterThan(150)
        ->and($variables['job_class']['allValue'])->toBe('.+')
        ->and($variables['batch_kind']['allValue'])->toBe('.*')
        ->and($variables['job_class']['query'])->toContain(' unless on (job_class) '.$promql->batchExcluded().')')
        ->and($variables['queue']['query'])->toContain(' unless on (queue) '.$promql->batchExcluded('queue').')');
})->with(Rendered::modes());

it('reads the classes of a scoped selector from its own family, window and time', function (): void {
    $promql = new Promql('ho_');
    $kinds = $promql->batchKindClasses();
    $unit = 'max by (job, connection, queue, job_class, status) (min_over_time(ho_queue_jobs_total{job=~"$prometheus_job", status=~"failed"}[$__interval] offset -$__interval @ ${__to}e-3))';

    expect($promql->filtered($unit, true))->toBe(
        '(max by (job, connection, queue, job_class, status) (min_over_time(ho_queue_jobs_total{job=~"$prometheus_job", queue=~"$queue", job_class=~"$job_class", status=~"failed"}[$__interval] offset -$__interval @ ${__to}e-3))'
        .' unless on (job_class) (group by (job_class) (last_over_time({__name__=~"ho_queue_jobs_total", job=~"$prometheus_job", status=~"failed", '.BATCH_SWITCH.'}[$__interval] offset -$__interval @ ${__to}e-3))'
        .' unless on (job_class) '.$kinds.'))',
    )
        ->and($kinds)->toBe('group by (job_class) (last_over_time({__name__=~"ho_queue_batch_job_classes", job=~"$prometheus_job", batch=~"$batch_kind", '.BATCH_SWITCH.'}[$__range] @ ${__to}e-3))');
});

it('lists the classes and queues of the selected kind in the Job and Queue filters', function (?string $kind): void {
    $data = ProfileSeries::data();
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
                'expr' => ProfileSeries::interpolate($variables['job_class'], $kind, '.+'),
                'eval_time' => '19m',
                'exp_samples' => array_map(static fn (string $class): array => [
                    'labels' => '{display='.Promql::quote($short($class)).', job_class='.Promql::quote($class).'}',
                    'value' => 1,
                ], $classes),
            ],
            [
                'expr' => 'group by (queue) ('.ProfileSeries::interpolate($variables['queue'], $kind, '.+').')',
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
    'Batch = one kind' => [ProfileSeries::KIND],
]);

it('gives every panel the legacy result with Batch All and with Batch = one kind', function (?string $kind): void {
    $data = ProfileSeries::data();
    $classes = $kind === null ? $data['classes'] : array_values(array_intersect($data['classes'], $data['kinds'][$kind]['classes']));
    $legacyAll = ProfileSeries::regex($classes);
    $tests = [];

    foreach (Rendered::expressions(alerting: [], mode: LiveMode::Off) as $where => $expr) {
        if (! str_starts_with($where, 'panel ')) {
            continue;
        }

        $now = ProfileSeries::interpolate($expr, $kind, '.+');
        $legacy = ProfileSeries::interpolate(withoutBatchScope($expr), $kind, $legacyAll);

        $tests[] = ['expr' => '# differs '.$where.'
'.ProfileSeries::difference($now, $legacy), 'eval_time' => '19m', 'exp_samples' => []];
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

    expect(count($tests))->toBeGreaterThan(90)
        ->and($differing[1])->toBe([])
        ->and(count($withData[1]))->toBeGreaterThan(40, substr($output, 0, 2000))
        // Every failure is a panel with data, none an evaluation error
        ->and(substr_count($output, 'expr: "# has data '))->toBe(substr_count($output, 'expr: "#'))
        ->and($output)->not->toContain('err:');
})->with([
    'Batch All' => [null],
    'Batch = one kind' => [ProfileSeries::KIND],
]);
