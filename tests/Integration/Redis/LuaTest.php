<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\Lua;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Redis\RedisScriptFailed;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;

pest()->group('redis');

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/ho-lua-'.getmypid().'-'.uniqid();
    mkdir($this->directory);
    file_put_contents($this->directory.'/echo_args.lua', "redis.call('SET', KEYS[1], ARGV[1])\nreturn {KEYS[1], ARGV[1], ARGV[2], #KEYS}");
    file_put_contents($this->directory.'/nil_reply.lua', 'return nil');
    file_put_contents($this->directory.'/error_reply.lua', "return redis.error_reply('ERR boom')");
    file_put_contents($this->directory.'/runtime_error.lua', "error('crash')");
    $this->lua = new Lua($this->directory);
    $this->storage = app(RedisClients::class)->storage();
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->directory.'/*.lua') ?: []);
    rmdir($this->directory);
});

it('passes keys with the connection prefix and arguments in their exact form (C2)', function (): void {
    $key = (new Keys)->meta();
    $result = $this->lua->eval($this->storage, 'echo_args', [$key], [1700000000.123456, 42]);
    $prefixed = TestCase::redisPrefix().'horizon:'.$key;

    expect($result)->toBe([$prefixed, '1700000000.123456', '42', 1])
        ->and(RedisKeys::all(TestCase::redisPrefix()))->toBe([$prefixed]);
});

it('returns null for a nil reply', function (): void {
    expect($this->lua->eval($this->storage, 'nil_reply', [], []))->toBeNull();
});

it('turns an error reply into RedisScriptFailed', function (): void {
    $this->lua->eval($this->storage, 'error_reply', [], []);
})->throws(RedisScriptFailed::class, 'Lua script error_reply failed: ERR boom');

it('turns a runtime error into RedisScriptFailed', function (): void {
    $this->lua->eval($this->storage, 'runtime_error', [], []);
})->throws(RedisScriptFailed::class, 'crash');

it('recovers after a script error on the same connection', function (): void {
    try {
        $this->lua->eval($this->storage, 'error_reply', [], []);
    } catch (RedisScriptFailed) {
    }

    expect($this->lua->eval($this->storage, 'nil_reply', [], []))->toBeNull()
        ->and($this->lua->eval($this->storage, 'echo_args', [(new Keys)->meta()], ['a', 'b'])[1])->toBe('a');
});

it('rethrows connection errors unchanged', function (): void {
    config()->set('database.redis.unreachable', ['host' => '127.0.0.1', 'port' => 1, 'database' => 0]);
    config()->set('horizon-observability.redis.scrape_timeout', 0.2);
    $this->app->forgetInstance(Config::class);
    $this->app->forgetInstance(RedisClients::class);

    try {
        $this->lua->eval(app(RedisClients::class)->reader('unreachable'), 'nil_reply', [], []);
        $this->fail('No connection error.');
    } catch (Throwable $e) {
        expect($e)->not->toBeInstanceOf(RedisScriptFailed::class);
    }
});
