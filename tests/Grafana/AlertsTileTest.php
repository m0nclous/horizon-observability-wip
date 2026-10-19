<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\Live\LiveMode;
use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Tests\Grafana\Support\Promtool;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * The Alerts tile (#21) and the alert sound (#23) read Grafana's alert state history, GRAFANA_ALERTS: on every
 * evaluation Grafana writes a point for each rule instance that is pending or firing, stamped with the evaluation time,
 * and a stale marker when the instance leaves that state (seen on Grafana 12.4.13 and 13.2.4 with the dev stack). Each
 * active (pending or firing) rule adds 1 to the number; red while a rule fires, yellow while a rule is pending and none
 * fires and during the 15 minutes after the last firing point ("Stable in Nm", 0), green otherwise. The sound rings
 * for firing rules only.
 *
 * The rendered queries are pinned below, then evaluated by promtool on GRAFANA_ALERTS samples as Grafana writes them
 * (points 30 s into the minute, every minute): the caption B, the colour C and the sound at the end of the period
 * `${__to}` (instant queries), the number A at the last point of the range query (the last multiple of the 1-minute
 * step at or before `${__to}`, where Grafana puts it) and at the points before it. The earlier form counted a rule with
 * a point in the last 90 s (`last_over_time(...[90s])`) and the number at the last step: the number lagged the state
 * by up to one step (20 s at 1 hour, 10 minutes at 24 hours), a point more than 30 s late at the query time (a late
 * evaluation or write, or a browser clock ahead of Grafana's) dropped an active rule to a green "Stable" 0, and a
 * resolved rule stayed counted for 30 s after its stale marker.
 */

pest()->group('grafana');

const ALERTS_TILE_RULES = 'horizon-observability-(no-metrics|down|long-wait|failures)';

/**
 * The rendered targets of the Alerts tile and the alert sound, by "<panel> <refId>".
 *
 * @return array<string, array<string, mixed>>
 */
function alertsTileTargets(LiveMode $mode = LiveMode::Poll): array
{
    $targets = [];

    foreach (PanelRegistry::panels(Rendered::dashboard($mode)) as $panel) {
        if (in_array($panel['id'], [PanelRegistry::ALERTS, PanelRegistry::ALERT_SOUND], true)) {
            foreach ($panel['targets'] as $target) {
                $targets[$panel['id'].' '.$target['refId']] = $target;
            }
        }
    }

    return $targets;
}

it('renders the Alerts tile and the alert sound on the active states of GRAFANA_ALERTS', function (LiveMode $mode): void {
    $rules = $mode === LiveMode::Stream ? 'horizon-observability-(no-metrics|down|long-wait|failures|live)' : ALERTS_TILE_RULES;
    $selector = static fn (string $state): string => 'GRAFANA_ALERTS{grafana_rule_uid=~"'.$rules.'", alertstate'.$state.'}';
    $active = $selector('=~"pending|firing"');
    $firing = $selector('="firing"');
    $pending = $selector('="pending"');
    $lastStep = '(vector(time()) + ($__interval_ms / 1000) > ${__to}e-3)';
    $level = '2 * group('.$firing.') or group('.$pending.') or group(last_over_time('.$firing.'[15m])) or vector(0)';
    $targets = alertsTileTargets($mode);

    expect(array_keys($targets))->toBe(['23 A', '21 A', '21 C', '21 B'])
        // The number: the active rules at each point of the sparkline, at ${__to} for the last point
        ->and($targets['21 A']['expr'])->toBe(
            'count(count by (grafana_rule_uid) ('.$active.' @ ${__to}e-3)) and on () '.$lastStep
            .' or count(count by (grafana_rule_uid) ('.$active.')) unless on () '.$lastStep
            .' or vector(0)',
        )
        ->and($targets['21 A'])->toMatchArray(['instant' => false, 'range' => true])
        // The colour: 2 firing (red), 1 pending or fired within 15 minutes (yellow), 0 (green)
        ->and($targets['21 C']['expr'])->toBe('1000000000 * (('.$level.') < bool 1) * 2 + 1000000000 * (('.$level.') < bool 2)')
        ->and($targets['21 C'])->toMatchArray(['instant' => true, 'legendFormat' => 'level'])
        ->and($targets['21 B']['expr'])->toBe(
            'label_replace(group('.$firing.'), "display", "Firing", "", "")'
            .' or on () label_replace(group('.$pending.'), "display", "Pending", "", "")'
            .' or on () label_replace(count_values("v", ceil((900 - (time() - max(max_over_time(timestamp('.$firing.')[15m:])))) / 60) > 0), "display", "Stable in ${1}m", "v", "(.*)")'
            .' or on () label_replace(vector(1), "display", "Stable", "", "")',
        )
        ->and($targets['21 B'])->toMatchArray(['instant' => true, 'legendFormat' => 'caption'])
        // The sound: the firing instances, as the tile counts them
        ->and($targets['23 A']['expr'])->toBe($firing)
        ->and($targets['23 A'])->toMatchArray(['instant' => true, 'format' => 'table']);

    // No window decides whether a rule is active any more; only the stabilization looks back (15 minutes)
    foreach ($targets as $where => $target) {
        preg_match_all('/\[([^\]]+)\]/', $target['expr'], $ranges);

        expect(array_diff($ranges[1], ['15m', '15m:']))->toBe([], $where);
    }
})->with(Rendered::modes());

it('keeps the colours and the caption of the Alerts tile: green, yellow from the level, red', function (): void {
    $panel = array_column(PanelRegistry::panels(Rendered::dashboard()), null, 'id')[PanelRegistry::ALERTS];
    $transformations = $panel['transformations'];

    expect($panel['fieldConfig']['defaults']['thresholds']['steps'])->toBe([['color' => 'green', 'value' => null]])
        ->and($panel['options']['colorMode'])->toBe('background')
        ->and($panel['options']['reduceOptions']['calcs'])->toBe(['lastNotNull'])
        ->and(array_column($transformations, 'id'))->toBe(['labelsToFields', 'configFromData', 'calculateField', 'calculateField', 'configFromData'])
        ->and($transformations[1]['options']['configRefId'])->toBe('B')
        ->and($transformations[4]['options']['configRefId'])->toBe('C')
        ->and(array_map(static fn (array $mapping): ?string => $mapping['handlerArguments']['threshold']['color'] ?? null, $transformations[4]['options']['mappings']))
        ->toBe([null, 'yellow', 'red']);
});

/*
 * The truth over GRAFANA_ALERTS samples. Times are seconds after the start of the input series; Grafana evaluates at
 * 30 s into every minute.
 */

/**
 * A GRAFANA_ALERTS series as Grafana writes it: alertname, alertstate, grafana_alertstate, grafana_folder and
 * grafana_rule_uid plus the instance labels of the rule; $rule is a core rule's uid suffix or another rule's uid, and
 * $points maps a time to 1 or "stale". Samples every 10 s.
 *
 * @param  array<int, int|string>  $points
 * @return array{series: string, values: string}
 */
function alertsTileSeries(string $rule, string $state, string $instance, array $points, int $until = 1800): array
{
    $titles = ['no-metrics' => 'No queue metrics', 'down' => 'Horizon is down', 'long-wait' => 'Queue wait over 2x limit', 'failures' => 'High failure rate'];
    $values = [];

    for ($t = 0; $t <= $until; $t += 10) {
        $values[] = isset($points[$t]) ? (string) $points[$t] : '_';
    }

    // A core rule by its uid suffix, any other rule by its whole uid
    $uid = isset($titles[$rule]) ? 'horizon-observability-'.$rule : $rule;
    $labels = 'alertname="'.($titles[$rule] ?? $rule).'", alertstate="'.$state.'", grafana_alertstate="'.($state === 'firing' ? 'alerting' : $state).'"'
        .', grafana_folder="Horizon", grafana_rule_uid="'.$uid.'"'.($instance === '' ? '' : ', '.$instance);

    return ['series' => 'GRAFANA_ALERTS{'.$labels.'}', 'values' => implode(' ', $values)];
}

/**
 * Points every minute from $from to $to (inclusive), optionally followed by a stale marker one minute after $to.
 *
 * @return array<int, int|string>
 */
function alertsTilePoints(int $from, int $to, bool $stale = true, int ...$missed): array
{
    $points = [];

    for ($t = $from; $t <= $to; $t += 60) {
        if (! in_array($t, $missed, true)) {
            $points[$t] = 1;
        }
    }

    if ($stale) {
        $points[$to + 60] = 'stale';
    }

    return $points;
}

/**
 * What the tile and the sound show at the end of the period $to: the number (the last point of A), the colour, the
 * caption and the firing rules of the sound (rule uid suffix => instances).
 *
 * @param  array<string, int>  $sound
 * @return list<array{expr: string, eval_time: string, exp_samples: list<array{labels: string, value: float|int}>}>
 */
function alertsTileShows(int $to, int $number, string $color, string $caption, array $sound = []): array
{
    $expressions = Rendered::expressions();
    $expr = static fn (string $where): string => Promtool::interpolate($expressions[$where], '.+', $to, 3600);
    $level = ['green' => 3000000000, 'yellow' => 1000000000, 'red' => 0][$color];
    $captionLabels = preg_match('/^Stable in (\d+)m$/', $caption, $minutes) === 1 ? '{display="'.$caption.'", v="'.$minutes[1].'"}' : '{display="'.$caption.'"}';
    $sounds = [];

    foreach ($sound as $rule => $instances) {
        $sounds[] = ['labels' => '{grafana_rule_uid="horizon-observability-'.$rule.'"}', 'value' => $instances];
    }

    return [
        // The last point of the range query: the last multiple of the step at or before $to
        ['expr' => $expr('panel 21 A'), 'eval_time' => (intdiv($to, 60) * 60).'s', 'exp_samples' => [['labels' => '{}', 'value' => $number]]],
        ['expr' => $expr('panel 21 C'), 'eval_time' => $to.'s', 'exp_samples' => [['labels' => '{}', 'value' => $level]]],
        ['expr' => $expr('panel 21 B'), 'eval_time' => $to.'s', 'exp_samples' => [['labels' => $captionLabels, 'value' => 1]]],
        ['expr' => 'count by (grafana_rule_uid) ('.$expr('panel 23 A').')', 'eval_time' => $to.'s', 'exp_samples' => $sounds],
    ];
}

/**
 * The number at a point of the sparkline before the last one ($at, a multiple of the step, more than a step before
 * $to): the rules active at that point.
 *
 * @return array{expr: string, eval_time: string, exp_samples: list<array{labels: string, value: int}>}
 */
function alertsTileSparkline(int $to, int $at, int $number): array
{
    return [
        'expr' => Promtool::interpolate(Rendered::expressions()['panel 21 A'], '.+', $to, 3600),
        'eval_time' => $at.'s',
        'exp_samples' => [['labels' => '{}', 'value' => $number]],
    ];
}

/**
 * @param  list<array{series: string, values: string}>  $input
 * @param  list<array<string, mixed>>  $checks
 */
function alertsTileRun(array $input, array $checks): void
{
    // promtool loads the input samples only up to the last whole minute of the latest evaluation time
    $checks[] = ['expr' => 'vector(1)', 'eval_time' => '1860s', 'exp_samples' => [['labels' => '{}', 'value' => 1]]];

    [$status, $output] = Promtool::testRules([], [['interval' => '10s', 'input_series' => $input, 'promql_expr_test' => $checks]]);

    expect($status)->toBe(0, implode("\n", array_map(
        static fn (string $line): string => preg_match('/^\s*expr: /', $line) === 1 ? substr($line, 0, 120).'...' : $line,
        explode("\n", $output),
    )));
}

describe('the truth over GRAFANA_ALERTS', function (): void {
    beforeEach(function (): void {
        if (Promtool::command() === null) {
            $this->markTestSkipped('promtool is not available; set PROMTOOL or put promtool on the PATH.');
        }
    });

    it('counts a pending rule from its first point to its stale marker, yellow, "Pending"', function (): void {
        // Horizon is down pending for five evaluations (2m30s to 6m30s), then normal again: the stale marker at 7m30s
        alertsTileRun([alertsTileSeries('down', 'pending', 'job="app"', alertsTilePoints(150, 390))], [
            ...alertsTileShows(149, 0, 'green', 'Stable'),
            // The evaluation that writes the first point: the number, the colour and the caption at once
            ...alertsTileShows(150, 1, 'yellow', 'Pending'),
            // The last point of the sparkline (2m) is before the first point (2m30s); it counts at ${__to}
            ...alertsTileShows(170, 1, 'yellow', 'Pending'),
            alertsTileSparkline(170, 60, 0),
            ...alertsTileShows(300, 1, 'yellow', 'Pending'),
            // 59 s after the last point, just before the next evaluation
            ...alertsTileShows(449, 1, 'yellow', 'Pending'),
            // Resolved without firing: green at once, no stabilization
            ...alertsTileShows(450, 0, 'green', 'Stable'),
            ...alertsTileShows(500, 0, 'green', 'Stable'),
            alertsTileSparkline(500, 420, 1),
        ]);
    });

    it('turns red while a rule fires and rings for it, with one count per rule', function (): void {
        // High failure rate on default: pending from 2m30s, firing from 7m30s (the pending series ends with a stale
        // marker at the same evaluation), resolved at 14m30s
        alertsTileRun([
            alertsTileSeries('failures', 'pending', 'job="app", queue="default"', alertsTilePoints(150, 390)),
            alertsTileSeries('failures', 'firing', 'job="app", queue="default"', alertsTilePoints(450, 810)),
        ], [
            ...alertsTileShows(420, 1, 'yellow', 'Pending'),
            ...alertsTileShows(450, 1, 'red', 'Firing', ['failures' => 1]),
            ...alertsTileShows(869, 1, 'red', 'Firing', ['failures' => 1]),
        ]);
    });

    it('counts a pending rule and a firing rule, and a rule with a pending and a firing queue once', function (): void {
        alertsTileRun([
            // Queue wait over 2x limit: slow fires, default is pending
            alertsTileSeries('long-wait', 'firing', 'job="app", queue="slow"', alertsTilePoints(150, 1590, false)),
            alertsTileSeries('long-wait', 'pending', 'job="app", queue="default"', alertsTilePoints(150, 1590, false)),
            // Horizon is down: pending from 4m30s
            alertsTileSeries('down', 'pending', 'job="app"', alertsTilePoints(270, 1590, false)),
            // Not watched: a health rule and another dashboard's rule
            alertsTileSeries('horizon-observability-collector', 'firing', 'job="app", collector="queues"', alertsTilePoints(150, 1590, false)),
            alertsTileSeries('queues-down', 'firing', 'job="app"', alertsTilePoints(150, 1590, false)),
        ], [
            ...alertsTileShows(200, 1, 'red', 'Firing', ['long-wait' => 1]),
            ...alertsTileShows(300, 2, 'red', 'Firing', ['long-wait' => 1]),
            alertsTileSparkline(300, 180, 1),
        ]);
    });

    it('shows a pending state of a single evaluation for its whole minute, and only for it', function (): void {
        // No queue metrics pending for one evaluation (an application restart), normal at the next one
        alertsTileRun([alertsTileSeries('no-metrics', 'pending', '', alertsTilePoints(150, 150))], [
            ...alertsTileShows(150, 1, 'yellow', 'Pending'),
            ...alertsTileShows(200, 1, 'yellow', 'Pending'),
            alertsTileSparkline(200, 120, 0),
            ...alertsTileShows(209, 1, 'yellow', 'Pending'),
            ...alertsTileShows(210, 0, 'green', 'Stable'),
            // The former 90-second window still counted it here
            ...alertsTileShows(239, 0, 'green', 'Stable'),
        ]);
    });

    it('keeps counting an active rule when its next point is late or missing', function (): void {
        // Horizon is down pending; the evaluation of 4m30s wrote no point, the next one did (a late write or a missed
        // evaluation, or, at the query time, a browser clock 60 s ahead of Grafana's)
        alertsTileRun([alertsTileSeries('down', 'pending', 'job="app"', alertsTilePoints(150, 390, true, 270))], [
            // 90 s and 119 s after the last point: the former 90-second window showed a green "Stable" 0 here
            ...alertsTileShows(300, 1, 'yellow', 'Pending'),
            ...alertsTileShows(329, 1, 'yellow', 'Pending'),
            ...alertsTileShows(330, 1, 'yellow', 'Pending'),
        ]);
    });

    it('stays yellow for the stabilization after a firing rule resolved, at 0, "Stable in Nm"', function (): void {
        // Horizon is down fired from 7m30s to 13m30s and resolved at 14m30s (stale marker); the last firing point
        // is at 13m30s, so the stabilization ends at 28m30s
        alertsTileRun([
            alertsTileSeries('down', 'pending', 'job="app"', alertsTilePoints(150, 390)),
            alertsTileSeries('down', 'firing', 'job="app"', alertsTilePoints(450, 810)),
        ], [
            ...alertsTileShows(869, 1, 'red', 'Firing', ['down' => 1]),
            // The resolution: 0 and no sound at once, yellow for 15 minutes after the last firing point
            ...alertsTileShows(870, 0, 'yellow', 'Stable in 14m'),
            // The last point of the sparkline (14m) still had the rule firing; it counts at ${__to}
            ...alertsTileShows(880, 0, 'yellow', 'Stable in 14m'),
            alertsTileSparkline(880, 780, 1),
            ...alertsTileShows(1200, 0, 'yellow', 'Stable in 9m'),
            ...alertsTileShows(1709, 0, 'yellow', 'Stable in 1m'),
            ...alertsTileShows(1710, 0, 'green', 'Stable'),
        ]);
    });

    it('counts a rule pending again during the stabilization, yellow, "Pending"', function (): void {
        alertsTileRun([
            alertsTileSeries('down', 'firing', 'job="app"', alertsTilePoints(150, 390)),
            alertsTileSeries('failures', 'pending', 'job="app", queue="mail"', alertsTilePoints(570, 750)),
        ], [
            ...alertsTileShows(500, 0, 'yellow', 'Stable in 14m'),
            ...alertsTileShows(600, 1, 'yellow', 'Pending'),
            ...alertsTileShows(810, 0, 'yellow', 'Stable in 8m'),
        ]);
    });
});
