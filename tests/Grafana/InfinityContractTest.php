<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\Live\LiveMode;
use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Http\JobList;
use DF\HorizonObservability\Http\JobListQuery;
use DF\HorizonObservability\Http\JobRows;
use DF\HorizonObservability\Http\RoutePath;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;
use Illuminate\Http\Request;

/*
 * The Infinity contract, in every committed render (off, poll, stream): every Infinity URL is ${api_path}/jobs/<list>
 * and resolves to the horizon-observability.jobs route, or ${api_path}/live and resolves to the
 * horizon-observability.live route; its query parameters are the job list filters (JobListQuery::VARIABLES), and the
 * columns a job list target reads are fields of that list (JobRows::fields()). The path is the hidden api_path
 * variable, exported as horizon_info{api_path}. LiveContractTest holds the live columns to the snapshot schema.
 */

pest()->group('grafana');

/**
 * The Infinity targets of a committed render, by panel id.
 *
 * @return list<array{0: int, 1: array<string, mixed>}> [panel id, target]
 */
function infinityTargetsOf(LiveMode $mode): array
{
    $targets = [];

    foreach (PanelRegistry::panels(Rendered::dashboard($mode)) as $panel) {
        foreach ($panel['targets'] ?? [] as $target) {
            if (($target['datasource']['type'] ?? null) === 'yesoreyeram-infinity-datasource') {
                $targets[] = [$panel['id'], $target];
            }
        }
    }

    return $targets;
}

/**
 * The job list targets of a committed render, by panel id.
 *
 * @return array<int, array<string, mixed>>
 */
function infinityTargets(LiveMode $mode = LiveMode::Poll): array
{
    $targets = [];

    foreach (infinityTargetsOf($mode) as [$id, $target]) {
        if (str_starts_with($target['url'], '${api_path}/jobs/')) {
            $targets[$id] = $target;
        }
    }

    return $targets;
}

function jobListFilters(): string
{
    return implode('&', array_map(static fn (string $name): string => '${'.$name.':queryparam}', JobListQuery::VARIABLES));
}

it('reads every Infinity target from a job list or the live snapshot, with the job list filters', function (LiveMode $mode): void {
    $live = [];

    foreach (infinityTargetsOf($mode) as [$id, $target]) {
        expect($target['url'])->toMatch('#^\$\{api_path\}/(jobs/(pending|completed|failed|delayed)|live)\?#')
            ->and($target['datasource'])->toBe(['type' => 'yesoreyeram-infinity-datasource', 'uid' => '${app_api}']);

        if (str_starts_with($target['url'], '${api_path}/live?')) {
            expect($target['url'])->toBe('${api_path}/live?'.jobListFilters());
            $live[] = $id.' '.$target['refId'];
        }
    }

    expect($live)->toBe(match ($mode) {
        LiveMode::Off => [],
        LiveMode::Poll => ['24 W', '1 L', '3 S', '2 L', '2 C', '8 C', '9 L'],
        LiveMode::Stream => ['24 W', '1 L', '8 C'],
    });
})->with(Rendered::modes());

it('reads the four job lists from ${api_path}/jobs/<list> with the job list filters', function (LiveMode $mode): void {
    $lists = [];
    $filters = jobListFilters();

    foreach (infinityTargets($mode) as $id => $target) {
        expect($target['url'])->toMatch('#^\$\{api_path\}/jobs/(pending|completed|failed|delayed)\?#');
        [$path, $query] = explode('?', $target['url'], 2);
        $lists[$id] = substr($path, strlen('${api_path}/jobs/'));

        expect($query)->toBe('limit='.JobListQuery::DEFAULT_LIMIT.'&from=${__from}&to=${__to}&'.$filters)
            ->and($target['datasource'])->toBe(['type' => 'yesoreyeram-infinity-datasource', 'uid' => '${app_api}']);
    }

    ksort($lists);

    expect($lists)->toBe([
        PanelRegistry::COMPLETED_JOBS => 'completed',
        PanelRegistry::FAILED_JOBS => 'failed',
        PanelRegistry::PENDING_JOBS => 'pending',
        PanelRegistry::DELAYED_JOBS => 'delayed',
    ]);
})->with(Rendered::modes());

it('resolves every Infinity URL to the job list or live route at the exported api_path', function (LiveMode $mode): void {
    // horizon_info{api_path} is RoutePath::apiPath() (Horizon\HorizonUrl)
    $apiPath = RoutePath::apiPath(config());

    foreach (infinityTargetsOf($mode) as [, $target]) {
        $url = str_replace('${api_path}', $apiPath, explode('?', $target['url'], 2)[0]);
        $route = app('router')->getRoutes()->match(Request::create($url));

        expect($route->getName())->toBe(str_ends_with($url, '/live') ? 'horizon-observability.live' : 'horizon-observability.jobs');
    }
})->with(Rendered::modes());

it('reads only fields of the job lists', function (LiveMode $mode): void {
    foreach (infinityTargets($mode) as $target) {
        $list = JobList::from(substr(explode('?', $target['url'], 2)[0], strlen('${api_path}/jobs/')));
        $fields = JobRows::fields($list);
        // The root selector adds `job` (the linked short class name) to each row
        $columns = array_diff(array_column($target['columns'], 'selector'), ['job']);

        expect(array_values(array_diff($columns, $fields)))->toBe([], $list->value);

        preg_match_all('/(?<![\w"$.])(horizon_path|name|data)(?![\w"])/', $target['root_selector'], $read);

        expect(array_values(array_diff(array_unique($read[1]), [...$fields, 'data'])))->toBe([], $list->value);
    }
})->with(Rendered::modes());

it('takes the job list path from horizon_info{api_path} of the selected job', function (): void {
    $variables = array_column(Rendered::dashboard()['templating']['list'], null, 'name');

    expect($variables['api_path'])->toMatchArray([
        // The current value, else the series seen last within 7 days (LinkVariablesTest evaluates both)
        'query' => 'query_result(group by (api_path) (max by (job, url, api_path, tts_url) (ho_horizon_info{job=~"$prometheus_job"}))'
            .' or on () group by (api_path) (topk by (job) (1, max_over_time(timestamp(max by (job, url, api_path, tts_url) (ho_horizon_info{job=~"$prometheus_job"}))[7d:5m]))))',
        'regex' => '/api_path="([^"]+)"/',
        'hide' => 2,
        'refresh' => 1,
        'skipUrlSync' => true,
    ]);
});
