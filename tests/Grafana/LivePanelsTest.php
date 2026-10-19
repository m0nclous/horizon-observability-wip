<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\DashboardBuilder;
use DF\HorizonObservability\Grafana\DashboardOptions;
use DF\HorizonObservability\Grafana\Live\LiveMode;
use DF\HorizonObservability\Grafana\Live\LivePanels;
use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Tests\Grafana\Support\LayoutProjection;
use DF\HorizonObservability\Tests\Grafana\Support\LiveShapes;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * ADDENDUM R4.1 and R4.8 (Q9' WP8D): the live tiles of the poll and stream renders are their --live=off panels with
 * exactly the R4.8 changes (tests/Grafana/Support/LiveShapes, written from the addendum, not from the renderer):
 * poll #3 reads the snapshot's tile rows (TILE_ROWS, the null sentinel, no sparkline), poll #2 and #9 merge the live
 * value into the Prometheus sparkline, #2 also takes the live caption and colour, #8 takes the live "RPM: n" caption in
 * both modes, stream #3 and #2 read the Grafana Live tiles channel with a 500 ms buffer and #9 the 15-minute delayed
 * window. Everything else (the other panels, the variables, links, annotations, __requires) is the off render's; the
 * Overview row is "Live Overview" in the live modes; the refresh is the poll interval in poll mode and S otherwise.
 */

pest()->group('grafana');

/**
 * @param  array<array-key, mixed>  $dashboard
 * @return array<int, array<string, mixed>>
 */
function livePanelsById(array $dashboard): array
{
    $panels = [];

    foreach (PanelRegistry::panels($dashboard) as $panel) {
        $panels[$panel['id']] = $panel;
    }

    return $panels;
}

it('renders the live tiles exactly as ADDENDUM R4.8', function (LiveMode $mode): void {
    $off = livePanelsById(Rendered::dashboard(LiveMode::Off));
    $live = livePanelsById(Rendered::dashboard($mode));

    // Supervisor Processes, Pending, Delayed and Completed in both modes; Horizon, Failed and Alerts read Prometheus
    expect(LiveShapes::tiles($mode))->toBe([PanelRegistry::SUPERVISOR_PROCESSES, PanelRegistry::PENDING, PanelRegistry::DELAYED, PanelRegistry::COMPLETED]);

    foreach (LiveShapes::tiles($mode) as $id) {
        expect(LiveShapes::canonical($live[$id]))->toBe(LiveShapes::canonical(LiveShapes::expected($mode, $off[$id])), 'panel '.$id);
    }
})->with([LiveMode::Poll, LiveMode::Stream]);

it('renders the R4.8 tiles for any identity, prefix, job, scrape interval and stream id', function (LiveMode $mode, array $options, string $streamId): void {
    $off = livePanelsById(LayoutProjection::decoded((new DashboardBuilder(new DashboardOptions(...$options, live: LiveMode::Off)))->build()));
    $live = livePanelsById(LayoutProjection::decoded((new DashboardBuilder(new DashboardOptions(...$options, live: $mode, liveStreamId: $streamId)))->build()));

    foreach (LiveShapes::tiles($mode) as $id) {
        expect(LiveShapes::canonical($live[$id]))->toBe(LiveShapes::canonical(LiveShapes::expected($mode, $off[$id], $streamId)), 'panel '.$id);
    }
})->with([
    'poll, another identity and job' => [LiveMode::Poll, ['metricPrefix' => 'acme_', 'uid' => 'queues', 'title' => 'Prod', 'job' => 'app'], 'horizon_observability'],
    'poll at S = 60 s with health rules' => [LiveMode::Poll, ['scrapeInterval' => 60, 'healthRules' => true, 'livePollInterval' => 2], 'horizon_observability'],
    'stream, no prefix' => [LiveMode::Stream, ['metricPrefix' => ''], 'horizon_observability'],
    'stream, another stream id and job' => [LiveMode::Stream, ['uid' => 'queues', 'job' => 'app'], 'queues-prod_2'],
]);

it('keeps every other panel and the dashboard settings of the off render', function (LiveMode $mode): void {
    $off = Rendered::dashboard(LiveMode::Off);
    $live = Rendered::dashboard($mode);
    $livePanels = livePanelsById($live);
    $panels = count(PanelRegistry::panels($off));
    $compared = 0;

    foreach (PanelRegistry::panels($off) as $panel) {
        if (in_array($panel['id'], LiveShapes::tiles($mode), true)) {
            continue;
        }

        if ($panel['id'] === PanelRegistry::OVERVIEW_ROW) {
            $panel['title'] = 'Live Overview';
        }

        // The Alerts tile and the alert sound also watch the stall rule in stream mode (ADDENDUM R4.11)
        if ($mode === LiveMode::Stream && in_array($panel['id'], [PanelRegistry::ALERTS, PanelRegistry::ALERT_SOUND], true)) {
            $json = json_encode($panel, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $panel = json_decode(str_replace('-(no-metrics|down|long-wait|failures)', '-(no-metrics|down|long-wait|failures|live)', $json), true, flags: JSON_THROW_ON_ERROR);
        }

        expect($livePanels[$panel['id']])->toBe($panel, 'panel '.$panel['id']);
        $compared++;
    }

    unset($off['panels'], $off['refresh'], $live['panels'], $live['refresh']);

    // Variables, links, annotations, __requires (Infinity kept, nothing added for stream), time picker, identity
    expect($compared)->toBe($panels - 4)
        ->and(count($livePanels))->toBe($panels)
        ->and($live)->toBe($off);
})->with([LiveMode::Poll, LiveMode::Stream]);

it('titles the Overview row "Live Overview" when live', function (LiveMode $mode, string $title): void {
    expect(livePanelsById(Rendered::dashboard($mode))[PanelRegistry::OVERVIEW_ROW]['title'])->toBe($title)
        ->and($mode->overviewTitle())->toBe($title)
        ->and($mode->isLive())->toBe($mode !== LiveMode::Off);
})->with([
    'poll' => [LiveMode::Poll, 'Live Overview'],
    'off' => [LiveMode::Off, 'Overview'],
    'stream' => [LiveMode::Stream, 'Live Overview'],
]);

it('reads Prometheus only, as the pilot, with the live overview off', function (): void {
    foreach (PanelRegistry::panels(Rendered::dashboard(LiveMode::Off)) as $panel) {
        $json = json_encode($panel, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        expect($json)->not->toContain('${api_path}/live')
            ->not->toContain('"channel"')
            ->not->toContain('-- Mixed --');
    }

    expect((new LivePanels(LiveMode::Off, 'x'))->tiles([['id' => PanelRegistry::PENDING, 'title' => 'Pending']]))
        ->toBe([['id' => PanelRegistry::PENDING, 'title' => 'Pending']]);
});

it('refreshes at the poll interval in poll mode and at S in off and stream modes', function (LiveMode $mode, int $poll, int $scrape, string $refresh): void {
    $dashboard = (new DashboardBuilder(new DashboardOptions(scrapeInterval: $scrape, live: $mode, livePollInterval: $poll)))->build();

    expect($dashboard['refresh'])->toBe($refresh)
        ->and($dashboard['timepicker']['refresh_intervals'])->toContain($refresh);
})->with([
    'poll default' => [LiveMode::Poll, 5, 10, '5s'],
    'poll at 1 s (GF_DASHBOARDS_MIN_REFRESH_INTERVAL=1s)' => [LiveMode::Poll, 1, 10, '1s'],
    'poll at 90 s' => [LiveMode::Poll, 90, 10, '90s'],
    'off' => [LiveMode::Off, 1, 30, '30s'],
    'stream: the tiles update by themselves' => [LiveMode::Stream, 1, 30, '30s'],
]);

it('refuses a poll interval or a stream id that Grafana would not take', function (array $options, string $message): void {
    expect(fn (): DashboardOptions => new DashboardOptions(...$options))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'poll interval 0' => [['livePollInterval' => 0], 'live poll interval'],
    'poll interval over 5 minutes' => [['livePollInterval' => 301], 'live poll interval'],
    'empty stream id' => [['liveStreamId' => ''], 'live stream id'],
    'stream id with a slash' => [['liveStreamId' => 'a/b'], 'live stream id'],
    'stream id of 65 characters' => [['liveStreamId' => str_repeat('a', 65)], 'live stream id'],
]);

it('names the channels after the stream id', function (): void {
    $panels = new LivePanels(LiveMode::Stream, 'queues-prod_2');

    expect($panels->channel('tiles'))->toBe('stream/queues-prod_2/tiles')
        ->and($panels->channel('delayed'))->toBe('stream/queues-prod_2/delayed');
});
