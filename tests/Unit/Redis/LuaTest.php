<?php

declare(strict_types=1);

use DF\HorizonObservability\Redis\Lua;
use DF\HorizonObservability\Redis\RedisScriptFailed;

it('formats arguments deterministically', function (string|int|float $value, string $expected): void {
    expect(Lua::argument($value))->toBe($expected);
})->with([
    'string' => ['redis', 'redis'],
    'int' => [42, '42'],
    'integral float' => [3.0, '3.0'],
    'short float' => [0.1, '0.1'],
    'unix seconds with microseconds' => [1700000000.123456, '1700000000.123456'],
    'no 14-digit rounding' => [1.2345678901234568E+17, '1.2345678901234568E+17'],
    'negative' => [-0.5, '-0.5'],
]);

it('rejects non-finite numbers', function (float $value): void {
    Lua::argument($value);
})->with([INF, -INF, NAN])->throws(InvalidArgumentException::class);

it('loads a script once per process', function (): void {
    $directory = sys_get_temp_dir().'/ho-lua-'.getmypid().'-'.uniqid();
    mkdir($directory);
    file_put_contents($directory.'/answer.lua', 'return 42');

    $lua = new Lua($directory);
    $first = $lua->source('answer');
    file_put_contents($directory.'/answer.lua', 'return 43');

    expect($first)->toBe('return 42')
        ->and($lua->source('answer'))->toBe('return 42')
        ->and((new Lua($directory))->source('answer'))->toBe('return 42');

    unlink($directory.'/answer.lua');
    rmdir($directory);
});

it('reports a missing script', function (): void {
    (new Lua(sys_get_temp_dir()))->source('does_not_exist_'.getmypid());
})->throws(RedisScriptFailed::class, 'is missing');

it('rejects script names that are not plain file names', function (string $name): void {
    (new Lua)->source($name);
})->with(['../etc/passwd', 'Record', 'a.lua', ''])->throws(InvalidArgumentException::class);

it('defaults to the package resources/lua directory', function (): void {
    $path = dirname(__DIR__, 3).'/resources/lua/answer_'.getmypid().'.lua';

    expect(fn (): string => (new Lua)->source('answer_'.getmypid()))->toThrow(RedisScriptFailed::class, $path);
});
