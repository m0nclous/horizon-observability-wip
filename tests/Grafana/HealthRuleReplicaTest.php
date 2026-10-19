<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\AlertRulesBuilder;
use DF\HorizonObservability\Grafana\DashboardOptions;
use DF\HorizonObservability\Tests\Grafana\Support\Promtool;

/*
 * T13 QA round 1 (adversarial): the alert rules evaluated by Prometheus on known series of two scraped replicas of
 * one application (Arch Q1.5). Business series are read from shared storage, so the dedupe must make two replicas
 * count once; the self-metrics are per replica (each replica runs its own collectors), so the opt-in collector rule
 * must see a collector that fails on one replica only: Arch Q5.3 writes it as
 * `min by (job, collector) (<ns>_exporter_collector_success{job=~"J"})`, without the dedupe.
 *
 * Each Grafana rule becomes a Prometheus alerting rule `(<query A>) <op> <threshold>` with the same `for`, evaluated
 * every minute (the Grafana rule group interval).
 */

pest()->group('grafana');

/**
 * The rendered rules as one Prometheus rule group; each alert is named by its rule uid suffix (`collector`, `down`).
 *
 * @return list<array<string, mixed>>
 */
function replicaRuleGroups(DashboardOptions $options): array
{
    $operators = ['lt' => '<', 'gt' => '>', 'gte' => '>='];
    $rules = [];

    foreach ((new AlertRulesBuilder($options))->build()['groups'][0]['rules'] as $rule) {
        $evaluator = $rule['data'][1]['model']['conditions'][0]['evaluator'];
        $rules[] = [
            'alert' => substr($rule['uid'], strlen($options->uid) + 1),
            'expr' => '('.$rule['data'][0]['model']['expr'].') '.$operators[$evaluator['type']].' '.$evaluator['params'][0],
            'for' => $rule['for'],
        ];
    }

    return [['name' => 'rendered', 'interval' => '1m', 'rules' => $rules]];
}

/**
 * Input series of one job scraped twice (instances i1 and i2), with the values each replica exports.
 *
 * @param  array<string, array{0: string, 1: string}>  $shared  series => [values on i1, values on i2]
 * @return list<array{series: string, values: string}>
 */
function replicaSeries(array $shared): array
{
    $series = [];

    foreach ($shared as $selector => [$first, $second]) {
        foreach (['i1' => $first, 'i2' => $second] as $instance => $values) {
            $labels = 'job="app", instance="'.$instance.'"';
            $series[] = [
                'series' => str_contains($selector, '{') ? str_replace('{', '{'.$labels.', ', $selector) : $selector.'{'.$labels.'}',
                'values' => $values,
            ];
        }
    }

    return $series;
}

beforeEach(function (): void {
    if (Promtool::command() === null) {
        $this->markTestSkipped('promtool is not available; set PROMTOOL or put promtool on the PATH.');
    }
});

it('fires the collector rule for a collector that fails on one of two replicas (Arch Q5.3)', function (): void {
    [$status, $output] = Promtool::testRules(replicaRuleGroups(new DashboardOptions(healthRules: true)), [[
        'interval' => '1m',
        'input_series' => replicaSeries([
            'ho_horizon_status' => ['1x30', '1x30'],
            'ho_exporter_collector_success{collector="horizon"}' => ['1x30', '1x30'],
            // The batches collector of replica i2 cannot reach the database
            'ho_exporter_collector_success{collector="batches"}' => ['1x30', '0x30'],
        ]),
        'alert_rule_test' => [
            ['eval_time' => '20m', 'alertname' => 'collector', 'exp_alerts' => [
                ['exp_labels' => ['job' => 'app', 'collector' => 'batches']],
            ]],
            ['eval_time' => '20m', 'alertname' => 'duplicates', 'exp_alerts' => [['exp_labels' => ['job' => 'app']]]],
        ],
    ]]);

    expect($status)->toBe(0, $output);
});

it('counts two replicas once in the core rules', function (): void {
    $completed = 'ho_queue_jobs_total{connection="redis", queue="default", job_class="App\\\\Jobs\\\\A", status="completed"}';
    $failed = 'ho_queue_jobs_total{connection="redis", queue="default", job_class="App\\\\Jobs\\\\A", status="failed"}';

    [$status, $output] = Promtool::testRules(replicaRuleGroups(new DashboardOptions), [[
        'interval' => '1m',
        'input_series' => replicaSeries([
            // Paused on both replicas: one alert per job, not per replica
            'ho_horizon_status' => ['0x30', '0x30'],
            'ho_horizon_wait_threshold_seconds{connection="redis", queue="default"}' => ['60x30', '60x30'],
            'ho_queue_oldest_pending_job_age_seconds{connection="redis", queue="default"}' => ['500x30', '500x30'],
            // 46 completed and 4 failed attempts in 15 minutes: 4 / max(50, 100) = 4 %, below 5 %. Summed over two
            // replicas the floor would be reached and 8 / 100 would fire.
            $completed => ['0x10 0+5x9 46x10', '0x10 0+5x9 46x10'],
            $failed => ['0x15 4x15', '0x15 4x15'],
        ]),
        'alert_rule_test' => [
            ['eval_time' => '20m', 'alertname' => 'down', 'exp_alerts' => [['exp_labels' => ['job' => 'app']]]],
            ['eval_time' => '20m', 'alertname' => 'long-wait', 'exp_alerts' => [['exp_labels' => ['job' => 'app', 'queue' => 'default']]]],
            ['eval_time' => '25m', 'alertname' => 'failures', 'exp_alerts' => []],
            ['eval_time' => '20m', 'alertname' => 'no-metrics', 'exp_alerts' => []],
        ],
    ]]);

    expect($status)->toBe(0, $output);
});
