<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\Promql;
use DF\HorizonObservability\Grafana\Queries\BatchState;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Tests\Grafana\Support\ProfileSeries;
use DF\HorizonObservability\Tests\Grafana\Support\Promtool;

/*
 * The batches of the period (BatchState::inRange(), the Batch filter, the Batches table #17 and the batch tables #16)
 * read two range series instead of the legacy three: a batch with a creation time in the period, unless it closed
 * before the period. The legacy set is "closed within the period, or created and not closed"; both are the same because
 * the collector exports the creation time of every batch it exports. Held to the legacy set on the medium perf profile
 * (a new batch every 10 minutes per kind) and on hand-made batches, with Batch "All" and with one kind.
 */

pest()->group('grafana');

beforeEach(function (): void {
    if (Promtool::command() === null) {
        $this->markTestSkipped('promtool is not available; set PROMTOOL or put promtool on the PATH.');
    }
});

/**
 * The legacy BATCHES_IN_RANGE and BATCH_KINDS_SELECTED.
 *
 * @return array{0: string, 1: string}
 */
function legacyBatchPeriod(): array
{
    $promql = new Promql('ho_');
    $last = static fn (Metric $family, string ...$matchers): string => $promql->over('last_over_time', $family, Promql::RANGE, array_values($matchers));
    $closed = $last(Metric::BatchClosed);
    $inRange = Promql::paren(Promql::either(
        Promql::group($closed.' >= '.Promql::FROM_SECONDS, 'batch', 'name'),
        Promql::paren(Promql::unlessOn(Promql::group($last(Metric::BatchCreated), 'batch', 'name'), $closed, 'batch', 'name')),
    ));
    $selected = Promql::andOn(Promql::group($inRange, 'batch'), Promql::group($last(Metric::BatchCreated, Promql::re('batch', '$batch_kind')), 'batch'), 'batch');

    return [$inRange, $selected];
}

it('gives the legacy batches and kinds of the period', function (?string $kind, array $series): void {
    $batches = new BatchState(new Promql('ho_'));
    [$legacyInRange, $legacySelected] = legacyBatchPeriod();
    $pairs = [
        'batches' => [$batches->inRange(), $legacyInRange],
        'kinds' => [$batches->kinds(), Promql::group($legacyInRange, 'batch')],
        'selected kinds' => [$batches->selectedKinds(), $legacySelected],
    ];
    $tests = [];

    foreach ($pairs as $name => [$now, $legacy]) {
        $now = ProfileSeries::interpolate($now, $kind, '.+');
        $tests[] = ['expr' => '# differs '.$name."\n".ProfileSeries::difference($now, ProfileSeries::interpolate($legacy, $kind, '.+')), 'eval_time' => '19m', 'exp_samples' => []];
        $tests[] = ['expr' => '# has data '.$name."\ncount(".$now.')', 'eval_time' => '19m', 'exp_samples' => []];
    }

    [, $output] = Promtool::testRules([], [[
        'interval' => '1m',
        'input_series' => $series === [] ? ProfileSeries::data()['series'] : $series,
        'promql_expr_test' => $tests,
    ]]);

    preg_match_all('/# differs ([a-z ]+)\\\\n/', $output, $differing);
    preg_match_all('/# has data ([a-z ]+)\\\\n/', $output, $withData);

    expect($differing[1])->toBe([])
        ->and($withData[1])->toBe(array_keys($pairs))
        ->and($output)->not->toContain('err:');
})->with(function (): array {
    $batch = static fn (string $family, string $kind, string $name, string $values): array => [
        'series' => sprintf('ho_queue_batch_%s_timestamp_seconds{job="app", instance="app:8080", batch="%s", name="%s"}', $family, $kind, $name),
        'values' => $values,
    ];
    // The period is 4m to 19m: a batch closed before it (still exported), one closed within it, one still running
    $handMade = [
        $batch('created', 'import', 'import 1', '60x20'),
        $batch('closed', 'import', 'import 1', '120x20'),
        $batch('created', 'import', 'import 2', '_x5 300x15'),
        $batch('closed', 'import', 'import 2', '_x9 600x11'),
        $batch('created', 'export', 'export 1', '_x11 700x9'),
    ];

    return [
        'profile, Batch All' => [null, []],
        'profile, Batch = one kind' => [ProfileSeries::KIND, []],
        'hand-made, Batch All' => [null, $handMade],
        'hand-made, Batch = import' => ['import', $handMade],
    ];
});
