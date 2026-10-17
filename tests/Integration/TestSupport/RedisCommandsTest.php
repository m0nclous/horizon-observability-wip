<?php

declare(strict_types=1);

use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Support\RedisCommands;

pest()->group('redis');

it('counts commands on the app and the private managers', function (): void {
    app('redis')->connection('default')->ping();
    $commands = RedisCommands::start($this->app);

    app('redis')->connection('default')->set('a', '1');
    app(RedisClients::class)->storage()->get('b');
    app(RedisClients::class)->reader('default')->exists('c');

    expect($commands->count())->toBe(3)
        ->and($commands->count('default'))->toBe(1)
        ->and($commands->names(RedisClients::STORAGE))->toBe(['GET'])
        ->and($commands->names())->toBe(['SET', 'GET', 'EXISTS']);

    $commands->stop();
    app('redis')->connection('default')->get('a');

    expect($commands->count())->toBe(3)
        ->and($commands->reset()->count())->toBe(0);
});
