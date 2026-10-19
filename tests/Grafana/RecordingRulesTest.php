<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\DashboardOptions;
use DF\HorizonObservability\Grafana\RecordingRulesBuilder;
use DF\HorizonObservability\Grafana\Render;
use DF\HorizonObservability\Tests\Grafana\Support\Promtool;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;
use Symfony\Component\Yaml\Yaml;

/*
 * The opt-in recording rules (`--recording-rules`) sum the attempt counter and the duration histogram per queue, the
 * replicas deduplicated, scoped to the rendered job, in a rule file of its own. The default render has no rule file,
 * and the dashboard never reads a recorded series.
 */

pest()->group('grafana');

/**
 * @return array{groups: list<array{name: string, interval: string, rules: list<array{record: string, expr: string}>}>}
 */
function recordingRulesFile(DashboardOptions $options = new DashboardOptions(recordingRules: true)): array
{
    $files = (new Render($options))->files();
    /** @var array{groups: list<array{name: string, interval: string, rules: list<array{record: string, expr: string}>}>} $parsed */
    $parsed = Yaml::parse($files[Render::RECORDING_RULES]);

    return $parsed;
}

it('renders no rule file by default', function (): void {
    expect(array_keys((new Render(new DashboardOptions))->files()))->toBe([Render::DASHBOARD, Render::ALERTING]);
});

it('records the attempts and the duration histogram per queue, deduplicated and scoped to the job', function (): void {
    $file = recordingRulesFile();

    expect($file['groups'])->toHaveCount(1)
        ->and($file['groups'][0]['name'])->toBe('horizon-observability')
        ->and($file['groups'][0]['interval'])->toBe('10s')
        ->and(array_column($file['groups'][0]['rules'], 'expr', 'record'))->toBe([
            'ho:queue_jobs:sum_by_queue_status' => 'sum by (job, connection, queue, status) (max by (job, connection, queue, job_class, status) (ho_queue_jobs_total{job=~".+"}))',
            'ho:queue_job_duration_seconds_bucket:sum_by_queue' => 'sum by (job, connection, queue, le) (max by (job, connection, queue, job_class, le) (ho_queue_job_duration_seconds_bucket{job=~".+"}))',
            'ho:queue_job_duration_seconds_sum:sum_by_queue' => 'sum by (job, connection, queue) (max by (job, connection, queue, job_class) (ho_queue_job_duration_seconds_sum{job=~".+"}))',
            'ho:queue_job_duration_seconds_count:sum_by_queue' => 'sum by (job, connection, queue) (max by (job, connection, queue, job_class) (ho_queue_job_duration_seconds_count{job=~".+"}))',
        ])
        ->and(array_column($file['groups'][0]['rules'], 'expr', 'record'))->toBe((new RecordingRulesBuilder(new DashboardOptions))->rules());
});

it('names the rules after the namespace, evaluates them every S and scopes them to --job', function (): void {
    $options = new DashboardOptions(metricPrefix: 'office_horizon_', scrapeInterval: 30, job: "it's \"app\"", recordingRules: true);
    $rules = array_column(recordingRulesFile($options)['groups'][0]['rules'], 'expr', 'record');

    expect(recordingRulesFile($options)['groups'][0]['interval'])->toBe('30s')
        ->and(array_keys($rules))->toBe([
            'office_horizon:queue_jobs:sum_by_queue_status',
            'office_horizon:queue_job_duration_seconds_bucket:sum_by_queue',
            'office_horizon:queue_job_duration_seconds_sum:sum_by_queue',
            'office_horizon:queue_job_duration_seconds_count:sum_by_queue',
        ])
        ->and($rules['office_horizon:queue_jobs:sum_by_queue_status'])
        ->toBe('sum by (job, connection, queue, status) (max by (job, connection, queue, job_class, status) (office_horizon_queue_jobs_total{job=~"it\'s \\"app\\""}))');

    $bare = array_keys(array_column(recordingRulesFile(new DashboardOptions(metricPrefix: '', recordingRules: true))['groups'][0]['rules'], 'expr', 'record'));

    expect($bare[0])->toBe('queue_jobs:sum_by_queue_status');
});

it('does not read a recorded series on the dashboard or in the alert rules', function (): void {
    $rendered = (new Render(new DashboardOptions(recordingRules: true)))->files();

    expect($rendered[Render::DASHBOARD])->toBe((new Render(new DashboardOptions))->files()[Render::DASHBOARD])
        ->and(implode("\n", Rendered::expressions()))->not->toMatch('/\bho:/');
});

it('sums one replica per queue and keeps the buckets apart (promtool)', function (): void {
    if (Promtool::command() === null) {
        $this->markTestSkipped('promtool is not available; set PROMTOOL or put promtool on the PATH.');
    }

    $series = static fn (string $name, string $instance, string $class, string $extra = ''): string => sprintf(
        '%s{job="app", instance="%s", connection="redis", queue="default", job_class="App\\\\Jobs\\\\%s"%s}',
        $name,
        $instance,
        $class,
        $extra,
    );
    $input = [];

    foreach (['app-1:8080', 'app-2:8080'] as $instance) {
        $input[] = ['series' => $series('ho_queue_jobs_total', $instance, 'A', ', status="completed"'), 'values' => '0+3x10'];
        $input[] = ['series' => $series('ho_queue_jobs_total', $instance, 'B', ', status="completed"'), 'values' => '0+1x10'];
        $input[] = ['series' => $series('ho_queue_jobs_total', $instance, 'B', ', status="failed"'), 'values' => '0+2x10'];
        $input[] = ['series' => $series('ho_queue_job_duration_seconds_bucket', $instance, 'A', ', le="1"'), 'values' => '0+2x10'];
        $input[] = ['series' => $series('ho_queue_job_duration_seconds_bucket', $instance, 'B', ', le="1"'), 'values' => '0+1x10'];
        $input[] = ['series' => $series('ho_queue_job_duration_seconds_bucket', $instance, 'A', ', le="+Inf"'), 'values' => '0+3x10'];
        $input[] = ['series' => $series('ho_queue_job_duration_seconds_bucket', $instance, 'B', ', le="+Inf"'), 'values' => '0+1x10'];
        $input[] = ['series' => $series('ho_queue_job_duration_seconds_count', $instance, 'A'), 'values' => '0+3x10'];
        $input[] = ['series' => $series('ho_queue_job_duration_seconds_count', $instance, 'B'), 'values' => '0+1x10'];
        $input[] = ['series' => $series('ho_queue_job_duration_seconds_sum', $instance, 'A'), 'values' => '0+1.5x10'];
        $input[] = ['series' => $series('ho_queue_job_duration_seconds_sum', $instance, 'B'), 'values' => '0+4x10'];
    }

    $queue = static fn (string $record, string $extra = ''): string => $record.'{job="app", connection="redis", queue="default"'.$extra.'}';

    [$status, $output] = Promtool::testRules(recordingRulesFile()['groups'], [[
        'interval' => '1m',
        'input_series' => $input,
        'promql_expr_test' => [
            ['expr' => 'ho:queue_jobs:sum_by_queue_status', 'eval_time' => '5m', 'exp_samples' => [
                ['labels' => $queue('ho:queue_jobs:sum_by_queue_status', ', status="completed"'), 'value' => 20],
                ['labels' => $queue('ho:queue_jobs:sum_by_queue_status', ', status="failed"'), 'value' => 10],
            ]],
            ['expr' => 'ho:queue_job_duration_seconds_bucket:sum_by_queue', 'eval_time' => '5m', 'exp_samples' => [
                ['labels' => $queue('ho:queue_job_duration_seconds_bucket:sum_by_queue', ', le="1"'), 'value' => 15],
                ['labels' => $queue('ho:queue_job_duration_seconds_bucket:sum_by_queue', ', le="+Inf"'), 'value' => 20],
            ]],
            ['expr' => 'ho:queue_job_duration_seconds_count:sum_by_queue', 'eval_time' => '5m', 'exp_samples' => [
                ['labels' => $queue('ho:queue_job_duration_seconds_count:sum_by_queue'), 'value' => 20],
            ]],
            ['expr' => 'ho:queue_job_duration_seconds_sum:sum_by_queue', 'eval_time' => '5m', 'exp_samples' => [
                ['labels' => $queue('ho:queue_job_duration_seconds_sum:sum_by_queue'), 'value' => 27.5],
            ]],
        ],
    ]]);

    expect($status)->toBe(0, $output);
});
