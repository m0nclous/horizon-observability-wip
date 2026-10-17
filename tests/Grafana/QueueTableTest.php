<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Grafana\Panels\Common;
use DF\HorizonObservability\Grafana\Panels\Jobs;
use DF\HorizonObservability\Grafana\Promql;
use DF\HorizonObservability\Grafana\Queries\Attempts;
use DF\HorizonObservability\Grafana\Queries\Selection;
use DF\HorizonObservability\Grafana\Queries\Workers;
use DF\HorizonObservability\Grafana\Variables;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Tests\Grafana\Support\ProfileSeries;
use DF\HorizonObservability\Tests\Grafana\Support\Promtool;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * ADDENDUM R2.4 O5 and O6: the queue tables (#5) read the hidden source #24, whose attempts query C returns one series
 * per (queue, supervisor, status); the tables pivot the statuses into Completed, Timeouts and Retries and compute
 * Failed (failed + timed_out) and Success rate (completed / finished) in Grafana. Those columns must equal the legacy
 * per-column queries (attempts_delta per status, 0 for configured queues without attempts, Success rate only with
 * attempts), joined with the supervisors of each queue as #24 joins every query. Evaluated by promtool on the medium
 * perf profile, 15 minutes ending at 19m, with Batch "All" and with Batch = one kind. The Jobs table (#6) does the
 * same per (queue, class) with its query B.
 */

pest()->group('grafana');

/**
 * The legacy query of one attempts column, joined with the supervisors of each queue.
 */
function legacyQueueColumn(string $expr): string
{
    $promql = new Promql('ho_');
    $supervised = Promql::group($promql->over('last_over_time', Metric::SupervisorProcesses, Variables::KNOWN_FOR), 'queue', 'supervisor');

    return Promql::binary($supervised, '*', Promql::paren($promql->filtered($expr, true)), on: ['queue'], groupLeft: []);
}

/**
 * $expr, the legacy orZero(): 0 for configured queues without a value.
 */
function legacyOrZero(string $expr): string
{
    $queues = (new Selection(new Promql('ho_')))->queues();

    return Promql::either(Promql::andOn($expr, $queues, 'queue'), '0 * '.$queues);
}

beforeEach(function (): void {
    if (Promtool::command() === null) {
        $this->markTestSkipped('promtool is not available; set PROMTOOL or put promtool on the PATH.');
    }
});

it('gives the legacy attempts columns from one query by status (O6)', function (?string $kind): void {
    $promql = new Promql('ho_');
    $attempts = new Attempts($promql, '10m');
    $delta = static fn (string $statuses): string => Promql::sum($attempts->delta($statuses, Promql::RANGE), 'queue');
    $source = Rendered::expressions()['panel '.PanelRegistry::QUEUE_SOURCE.' C'];
    // The statuses of query C as Grafana's "Labels to fields" turns them into columns, summed per row
    $column = static fn (string ...$statuses): string => 'sum by (queue, supervisor) (('.$source.') and on (status) ('
        .implode(' or ', array_map(static fn (string $status): string => Promql::withLabel('vector(1)', 'status', $status), $statuses)).'))';

    $pairs = [
        'Completed' => [$column('completed'), legacyQueueColumn(legacyOrZero($delta(Attempts::COMPLETED)))],
        'Failed' => [$column('failed', 'timed_out'), legacyQueueColumn(legacyOrZero($delta(Attempts::FAILED)))],
        'Timeouts' => [$column('timed_out'), legacyQueueColumn(legacyOrZero($delta(Attempts::TIMED_OUT)))],
        'Retries' => [$column('retried'), legacyQueueColumn(legacyOrZero($delta(Attempts::RETRIED)))],
        // Grafana divides 0 by 0 without attempts, a dash like the legacy empty cell
        'Success rate' => [
            $column('completed').' / ('.$column('completed', 'failed', 'timed_out').' > 0)',
            legacyQueueColumn($delta(Attempts::COMPLETED).' / ('.$delta(Attempts::FINISHED).' > 0)'),
        ],
    ];
    $tests = [];

    foreach ($pairs as $name => [$now, $legacy]) {
        $now = ProfileSeries::interpolate($now, $kind, '.+');
        $tests[] = ['expr' => '# differs '.$name."\n".ProfileSeries::difference($now, ProfileSeries::interpolate($legacy, $kind, '.+')), 'eval_time' => '19m', 'exp_samples' => []];
        $tests[] = ['expr' => '# has data '.$name."\ncount(".$now.')', 'eval_time' => '19m', 'exp_samples' => []];
    }

    [, $output] = Promtool::testRules([], [[
        'interval' => '1m',
        'input_series' => ProfileSeries::data()['series'],
        'promql_expr_test' => $tests,
    ]]);

    preg_match_all('/# differs ([A-Z][a-z ]+)\\\\n/', $output, $differing);
    preg_match_all('/# has data ([A-Z][a-z ]+)\\\\n/', $output, $withData);

    expect($differing[1])->toBe([])
        ->and($withData[1])->toBe(array_keys($pairs))
        ->and($output)->not->toContain('err:');
})->with([
    'Batch All' => [null],
    'Batch = one kind' => [ProfileSeries::KIND],
]);

it('gives the legacy attempts columns of the Jobs table from one query by status (O6)', function (?string $kind): void {
    $promql = new Promql('ho_');
    $attempts = new Attempts($promql, '10m');
    $selection = new Selection($promql);
    $jobs = new Jobs(new Common($promql), $promql, $selection, $attempts, new Workers($promql, $selection, '$busy_window'));
    // The rows of the table: the classes that ran, wait or run, and the idle ones (legacy ROWS)
    $rows = Closure::bind(static fn (Jobs $table): string => $table->rows(), null, Jobs::class)($jobs);
    $by = ['queue', 'job_class'];
    $delta = static fn (string $statuses): string => Promql::sum($attempts->delta($statuses, Promql::RANGE), ...$by);
    $count = static fn (string $statuses): string => $promql->filtered(Promql::either(Promql::andOn(Promql::call('round', $delta($statuses)), $rows, ...$by), '0 * '.$rows), true);
    $source = Rendered::expressions()['panel '.PanelRegistry::JOBS.' B'];
    $column = static fn (string ...$statuses): string => 'sum by (queue, job_class) (('.$source.') and on (status) ('
        .implode(' or ', array_map(static fn (string $status): string => Promql::withLabel('vector(1)', 'status', $status), $statuses)).'))';

    $pairs = [
        'Failed' => [$column('failed', 'timed_out'), $count(Attempts::FAILED)],
        'Retries' => [$column('retried'), $count(Attempts::RETRIED)],
        'Timeouts' => [$column('timed_out'), $count(Attempts::TIMED_OUT)],
        'Success rate' => [
            $column('completed').' / ('.$column('completed', 'failed', 'timed_out').' > 0)',
            $promql->filtered($delta(Attempts::COMPLETED).' / ('.$delta(Attempts::FINISHED).' > 0)', true),
        ],
    ];
    $tests = [];

    foreach ($pairs as $name => [$now, $legacy]) {
        $now = ProfileSeries::interpolate($now, $kind, '.+');
        $tests[] = ['expr' => '# differs '.$name."\n".ProfileSeries::difference($now, ProfileSeries::interpolate($legacy, $kind, '.+')), 'eval_time' => '19m', 'exp_samples' => []];
        $tests[] = ['expr' => '# has data '.$name."\ncount(".$now.')', 'eval_time' => '19m', 'exp_samples' => []];
    }

    [, $output] = Promtool::testRules([], [[
        'interval' => '1m',
        'input_series' => ProfileSeries::data()['series'],
        'promql_expr_test' => $tests,
    ]]);

    preg_match_all('/# differs ([A-Z][a-z ]+)\\\\n/', $output, $differing);
    preg_match_all('/# has data ([A-Z][a-z ]+)\\\\n/', $output, $withData);

    expect($differing[1])->toBe([])
        ->and($withData[1])->toBe(array_keys($pairs))
        ->and($output)->not->toContain('err:');
})->with([
    'Batch All' => [null],
    'Batch = one kind' => [ProfileSeries::KIND],
]);
