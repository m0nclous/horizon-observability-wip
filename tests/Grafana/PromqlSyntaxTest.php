<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\AlertRulesBuilder;
use DF\HorizonObservability\Grafana\DashboardOptions;
use DF\HorizonObservability\Grafana\Live\LiveMode;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Yaml\Yaml;

/*
 * Arch Q5.7 item 5: every PromQL expression of the committed dashboards and alert rules (off, poll, stream; and of the
 * opt-in health rules), with Grafana's macros and the dashboard variables substituted, parses. The test writes them as recording
 * rules to build/grafana-rules.yml, which the CI grafana job checks with promtool, and runs promtool itself when
 * PROMTOOL is set (e.g. "docker run --rm -i --entrypoint promtool quay.io/prometheus/prometheus:v3.15.0") or
 * promtool is on the PATH.
 */

pest()->group('grafana');

/**
 * An expression as Prometheus receives it: macros of a 1-hour range at a 1-minute step, the textbox defaults, and
 * `.*` for every other variable.
 */
function withoutMacros(string $expr): string
{
    $expr = strtr($expr, [
        '${__from:date:seconds}' => '0',
        '${__to:date:seconds}' => '0',
        '$__interval_ms' => '60000',
        '$__interval' => '1m',
        '$__range' => '1h',
        '$busy_window' => '20s',
        '$scrape_interval' => '10s',
    ]);

    // $1 in label_replace() replacements is not a variable
    return (string) preg_replace('/\$\{[A-Za-z_]\w*(?::\w+)?\}|\$[A-Za-z_]\w*/', '.*', $expr);
}

/**
 * @return array{groups: list<array{name: string, rules: list<array{record: string, expr: string}>}>}
 */
function syntaxRules(): array
{
    $expressions = [];

    foreach ([LiveMode::Off, LiveMode::Poll, LiveMode::Stream] as $mode) {
        foreach (Rendered::expressions(mode: $mode) as $where => $expr) {
            // The renders share most expressions; each one once
            $expressions[in_array($expr, $expressions, true) ? (string) array_search($expr, $expressions, true) : $mode->value.' '.$where] = $expr;
        }
    }

    foreach ((new AlertRulesBuilder(new DashboardOptions(live: LiveMode::Stream, healthRules: true)))->build()['groups'][0]['rules'] as $rule) {
        $expressions['rule '.$rule['uid'].' A'] = $rule['data'][0]['model']['expr'];
    }

    $rules = [];

    foreach ($expressions as $where => $expr) {
        // label_values() is Grafana's variable syntax, not PromQL
        if (! str_starts_with($expr, 'label_values(')) {
            $query = str_starts_with($expr, 'query_result(') ? substr($expr, strlen('query_result('), -1) : $expr;
            $rules[] = ['record' => 'grafana:'.preg_replace('/[^A-Za-z0-9_]+/', '_', $where), 'expr' => withoutMacros($query)];
        }
    }

    return ['groups' => [['name' => 'horizon-observability-grafana', 'rules' => $rules]]];
}

function promtoolCommand(): ?string
{
    $promtool = getenv('PROMTOOL');

    return is_string($promtool) && $promtool !== '' ? $promtool : (new ExecutableFinder)->find('promtool');
}

it('writes every expression with the macros substituted to build/grafana-rules.yml', function (): void {
    $rules = syntaxRules();
    $directory = dirname(__DIR__, 2).'/build';

    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    file_put_contents($directory.'/grafana-rules.yml', Yaml::dump($rules, 6, 2));
    $text = implode("\n", array_column($rules['groups'][0]['rules'], 'expr'));

    $outsideStrings = (string) preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '""', $text);

    expect(count($rules['groups'][0]['rules']))->toBeGreaterThan(60)
        ->and($outsideStrings)->not->toContain('$')
        ->and(preg_match('/\[[^\]]*\.\*/', $outsideStrings))->toBe(0, 'a variable was substituted where a duration belongs');
});

it('parses with promtool', function (): void {
    $promtool = promtoolCommand();

    if ($promtool === null) {
        $this->markTestSkipped('promtool is not available; set PROMTOOL or put promtool on the PATH.');
    }

    $rules = syntaxRules();
    $result = Process::timeout(300)->input(Yaml::dump($rules, 6, 2))->run($promtool.' check rules');
    $rules['groups'][0]['rules'][0]['expr'] = 'sum(ho_horizon_status';
    $broken = Process::timeout(300)->input(Yaml::dump($rules, 6, 2))->run($promtool.' check rules');

    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput())
        ->and($broken->exitCode())->toBe(1);
});
