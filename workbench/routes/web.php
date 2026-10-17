<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Workbench\App\Simulation\LoadGenerator;
use Workbench\App\Simulation\TaskProfile;

/*
| Workbench routes (Arch 8.2). GET /demo/dispatch?count=&queue= dispatches a manual burst of simulated jobs with the
| continuous outcome mix: count 1-1000 (default 10), queue one of the simulated queues (default "default").
*/

Route::get('/demo/dispatch', static function (Request $request, Application $app, Repository $config): JsonResponse {
    abort_if($app->isProduction(), 403, 'The load simulation never runs in production.');

    $request->validate([
        'count' => ['sometimes', 'integer', 'between:1,1000'],
        'queue' => ['sometimes', 'string', Rule::in(TaskProfile::queues())],
    ]);

    $connection = $config->get('queue.default');
    $connection = is_string($connection) ? $connection : '';

    abort_if($config->get("queue.connections.{$connection}.driver") !== 'redis', 422, 'The default queue connection must use the redis driver.');

    $queue = $request->string('queue', 'default')->toString();
    $count = $request->integer('count', 10);
    $generator = new LoadGenerator($connection);

    for ($i = 0; $i < $count; $i++) {
        $generator->stream($generator->weightedTask($queue), 1);
    }

    return new JsonResponse(['connection' => $connection, 'queue' => $queue, 'dispatched' => $count]);
})->name('workbench.demo.dispatch');
