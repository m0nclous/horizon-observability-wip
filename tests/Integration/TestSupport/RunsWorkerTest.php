<?php

declare(strict_types=1);

use DF\HorizonObservability\Tests\Support\RunsWorker;

pest()->group('redis');

uses(RunsWorker::class);

it('works a Redis queue in the test process', function (): void {
    dispatch(static function (): void {
        cache()->increment('worked');
    })->onConnection('redis')->onQueue('default');
    dispatch(static function (): void {
        cache()->increment('worked');
    })->onConnection('redis')->onQueue('default');

    expect($this->runWorker('default'))->toBe(0)
        ->and(cache()->get('worked'))->toBe(2)
        ->and(app('redis')->connection('default')->llen('queues:default'))->toBe(0);
});

it('works one job with once', function (): void {
    foreach ([1, 2] as $ignored) {
        dispatch(static function (): void {
            cache()->increment('worked');
        })->onConnection('redis');
    }

    $this->runWorker('default', once: true);

    expect(cache()->get('worked'))->toBe(1);
});
