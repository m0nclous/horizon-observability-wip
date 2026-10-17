<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Http\JobList;
use DF\HorizonObservability\Http\JobListQuery;
use DF\HorizonObservability\Http\JobRows;
use DF\HorizonObservability\Http\RoutePath;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;
use Illuminate\Http\Request;

/*
 * Arch Q5.7 item 3: every Infinity URL of the committed dashboard is ${api_path}/jobs/<list> and resolves to the
 * horizon-observability.jobs route, its query parameters are the job list filters (JobListQuery::VARIABLES), and
 * the columns it reads are fields of that list (JobRows::fields()). The job list path is the hidden api_path
 * variable, exported as horizon_info{api_path} (Arch Q5.2, Q5.6 step 5).
 */

pest()->group('grafana');

/**
 * @return array<int, array<string, mixed>> panel id => Infinity target
 */
function infinityTargets(): array
{
    $targets = [];

    foreach (PanelRegistry::panels(Rendered::dashboard()) as $panel) {
        foreach ($panel['targets'] ?? [] as $target) {
            if (($target['datasource']['type'] ?? null) === 'yesoreyeram-infinity-datasource') {
                $targets[$panel['id']] = $target;
            }
        }
    }

    return $targets;
}

it('reads the four job lists from ${api_path}/jobs/<list> with the job list filters', function (): void {
    $lists = [];
    $filters = implode('&', array_map(static fn (string $name): string => '${'.$name.':queryparam}', JobListQuery::VARIABLES));

    foreach (infinityTargets() as $id => $target) {
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
});

it('resolves every job list URL to the job list route at the exported api_path', function (): void {
    // horizon_info{api_path} is RoutePath::apiPath() (Horizon\HorizonUrl)
    $apiPath = RoutePath::apiPath(config());

    foreach (infinityTargets() as $target) {
        $url = str_replace('${api_path}', $apiPath, explode('?', $target['url'], 2)[0]);
        $route = app('router')->getRoutes()->match(Request::create($url));

        expect($route->getName())->toBe('horizon-observability.jobs');
    }
});

it('reads only fields of the job lists', function (): void {
    foreach (infinityTargets() as $target) {
        $list = JobList::from(substr(explode('?', $target['url'], 2)[0], strlen('${api_path}/jobs/')));
        $fields = JobRows::fields($list);
        // The root selector adds `job` (the linked short class name) to each row
        $columns = array_diff(array_column($target['columns'], 'selector'), ['job']);

        expect(array_values(array_diff($columns, $fields)))->toBe([], $list->value);

        preg_match_all('/(?<![\w"$.])(horizon_path|name|data)(?![\w"])/', $target['root_selector'], $read);

        expect(array_values(array_diff(array_unique($read[1]), [...$fields, 'data'])))->toBe([], $list->value);
    }
});

it('takes the job list path from horizon_info{api_path} of the selected job', function (): void {
    $variables = array_column(Rendered::dashboard()['templating']['list'], null, 'name');

    expect($variables['api_path'])->toMatchArray([
        'query' => 'query_result(group by (api_path) (max by (job, url, api_path, tts_url) (last_over_time(ho_horizon_info{job=~"$prometheus_job"}[7d]))))',
        'regex' => '/api_path="([^"]+)"/',
        'hide' => 2,
        'refresh' => 1,
        'skipUrlSync' => true,
    ]);
});
