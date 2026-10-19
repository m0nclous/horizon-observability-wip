<?php

declare(strict_types=1);

use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Tests\Truth\Support\Model;
use DF\HorizonObservability\Tests\Truth\Support\Samples;
use DF\HorizonObservability\Tests\Truth\Support\Scenario;
use DF\HorizonObservability\Tests\Truth\Support\TruthJob;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\BackgroundQueue;
use Illuminate\Queue\DeferredQueue;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/*
| Exporter truth: the attempt families count Redis queue attempts only. The same job class run through the sync,
| deferred and background connections (Laravel 12.45+ fires JobAttempted for those) and through a real worker on a
| database queue is not counted; the attempts of the redis queue are, exactly. Every job counts its runs in the
| cache, so the test also proves the excluded jobs ran.
*/

pest()->group('redis', 'truth');

afterEach(function (): void {
    Scenario::stop();
});

it('counts the attempts of the redis queue and nothing of sync, deferred, background or database jobs', function (): void {
    config()->set('queue.connections.deferred', ['driver' => 'deferred']);
    config()->set('queue.connections.background', ['driver' => 'background']);
    // The background queue defers to the process concurrency driver; run it in this process instead.
    config()->set('concurrency.driver.process', ['driver' => 'sync']);
    config()->set('queue.connections.database', ['driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
    Schema::connection('testing')->create('jobs', static function (Blueprint $table): void {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    $scenario = Scenario::start($this);
    $ran = 0;

    foreach (['sync', 'deferred', 'background', 'database'] as $connection) {
        if ($connection === 'deferred' && ! class_exists(DeferredQueue::class)) {
            continue;
        }

        if ($connection === 'background' && ! class_exists(BackgroundQueue::class)) {
            continue;
        }

        dispatch((new TruthJob('Truth\\Shared', ['complete'], countRuns: true))->onConnection($connection));
        $ran++;
    }

    if (class_exists(DeferredCallbackCollection::class)) {
        app(DeferredCallbackCollection::class)->invoke();
    }

    expect(Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0, '--memory' => 4096]))->toBe(0);

    $scenario->dispatch(new TruthJob('Truth\\Shared', ['complete'], countRuns: true))
        ->dispatch(new TruthJob('Truth\\Shared', ['complete'], countRuns: true))
        ->work()
        ->advance(Model::HOLD_SECONDS);
    $samples = new Samples($scenario->scrape());

    expect(Cache::get(TruthJob::RUNS))->toBe($ran + 2)
        ->and($samples->integers(Metric::JobsTotal->value))->toBe($scenario->model->jobsTotal($scenario->unix()))
        ->and($samples->sumBy(Metric::JobsTotal->value, 'connection'))->toBe(['redis' => 2.0]);
});
