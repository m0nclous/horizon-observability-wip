<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\Lua;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Redis\RedisScriptFailed;
use DF\HorizonObservability\Tests\Support\RedisCommands;
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
    file_put_contents($this->directory.'/commented.lua', "-- A comment.\nreturn {'-- kept', [[\n  -- kept]]} -- gone\n");
    // Compiles both sources in the server's own Lua: string.dump() holds the code, the constants and the debug
    // information (the line of every instruction, the local names), so equal dumps are the same function.
    file_put_contents($this->directory.'/same_function.lua', implode("\n", [
        "local file, compact = loadstring(ARGV[1], '=script'), loadstring(ARGV[2], '=script')",
        "if not file or not compact then return redis.error_reply('ERR does not compile') end",
        'local dump = string.dump(file)',
        'return {dump == string.dump(compact) and 1 or 0, #dump}',
    ]));
    $this->lua = new Lua($this->directory);
    $this->storage = app(RedisClients::class)->storage();
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->directory.'/*.lua') ?: []);
    rmdir($this->directory);
});

it('passes keys with the connection prefix and arguments in their exact form', function (): void {
    $key = (new Keys)->meta();
    $result = $this->lua->eval($this->storage, 'echo_args', [$key], [1700000000.123456, 42]);
    $prefixed = TestCase::redisPrefix().'horizon:'.$key;

    expect($result)->toBe([$prefixed, '1700000000.123456', '42', 1])
        ->and(RedisKeys::all(TestCase::redisPrefix()))->toBe([$prefixed]);
});

it('sends the script without its comments, which Redis caches byte for byte', function (): void {
    $commands = RedisCommands::start($this->app);
    $storage = app(RedisClients::class)->storage();
    $compact = "\nreturn {'-- kept', [[\n  -- kept]]}\n";

    expect($this->lua->eval($storage, 'commented', [], []))->toBe(['-- kept', '  -- kept'])
        ->and($commands->names(RedisClients::STORAGE))->toBe(['EVAL'])
        ->and($commands->commands(RedisClients::STORAGE)[0]['parameters'][0])->toBe($compact)
        ->and(array_map(intval(...), (array) $storage->command('script', ['exists', sha1($compact)])))->toBe([1]);
});

it('compiles every package script without its comments to the function of its file', function (string $path): void {
    $source = (string) file_get_contents($path);
    $reply = $this->lua->eval($this->storage, 'same_function', [], [$source, Lua::compact($source)]);

    expect($reply)->toBeArray()
        ->and($reply[0] ?? null)->toBe(1)
        ->and($reply[1] ?? 0)->toBeGreaterThan(500);
})->with(function (): array {
    $paths = glob(dirname(__DIR__, 3).'/resources/lua/*.lua') ?: [];

    return array_combine(array_map(basename(...), $paths), array_map(static fn (string $path): array => [$path], $paths));
});

it('tells a changed function from the function of its file', function (string $file, string $changed): void {
    expect($this->lua->eval($this->storage, 'same_function', [], [$file, $changed])[0] ?? null)->toBe(0);
})->with([
    'a line moved' => ["local a = 1\nreturn a", 'local a = 1 return a'],
    'a string changed' => ["return '--a'", "return '-a'"],
    'a local renamed' => ['local a = 1 return a', 'local b = 1 return b'],
]);

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
