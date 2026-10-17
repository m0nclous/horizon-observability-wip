<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Http\JobList;
use DF\HorizonObservability\Http\JobListController;
use DF\HorizonObservability\Http\LiveController;
use DF\HorizonObservability\Http\MetricsController;
use DF\HorizonObservability\Http\Middleware\Authorize;
use DF\HorizonObservability\Http\Middleware\ForceJson;
use Illuminate\Support\Facades\Route;

/*
| Loaded by HorizonObservabilityServiceProvider inside a group with the base path prefix and the optional domain
| (Arch Q2.1, Q2.2). Outside the web and horizon groups: no session, no cookies, no CSRF. The configured middleware
| runs per route after Authorize, so nothing (not even a throttle's cache write) happens before access is decided.
*/

$extra = array_values(array_filter((array) config('horizon-observability.middleware', []), is_string(...)));

Route::get('metrics', MetricsController::class)
    ->middleware([Authorize::class.':metrics', ...$extra])
    ->name('horizon-observability.metrics');

Route::get('jobs/{list}', JobListController::class)
    ->whereIn('list', JobList::values())
    ->middleware([ForceJson::class, Authorize::class.':api', ...$extra])
    ->name('horizon-observability.jobs');

if (Config::flag(config('horizon-observability.live.enabled', true), true)) {
    Route::get('live', LiveController::class)
        ->middleware([ForceJson::class, Authorize::class.':api', ...$extra])
        ->name('horizon-observability.live');
}
