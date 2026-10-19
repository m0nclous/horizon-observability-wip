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

it('sends a script without its comments and the blanks around its lines, keeping every line', function (string $source, string $expected): void {
    expect(Lua::compact($source))->toBe($expected)
        ->and(substr_count(Lua::compact($source), "\n"))->toBe(substr_count($source, "\n"))
        ->and(Lua::compact(Lua::compact($source)))->toBe(Lua::compact($source));
})->with([
    'line comments' => ["-- head\nlocal a = 1 -- tail\n\n    return a\n", "\nlocal a = 1\n\nreturn a\n"],
    'a comment at the end without a line break' => ['return 1 -- end', 'return 1'],
    'blanks between tokens become one space' => ["local  a\t=\t1\r\nreturn   a\r\n", "local a = 1\nreturn a\n"],
    'block comments keep their line breaks' => ["--[[ a\nb ]] local x = 1 --[==[ ]] still\n comment ]==]\nreturn x", "\nlocal x = 1\n\nreturn x"],
    'a block comment separates tokens' => ['return a--[[c]]b', 'return a b'],
    '--[ and --[= start line comments' => ["--[ x\n--[= y ]]\nreturn 1", "\n\nreturn 1"],
    'a minus before a negative number' => ['return 1 - -1', 'return 1 - -1'],
    'an index of a long string' => ['return t[ [[x]] ]', 'return t[ [[x]] ]'],
    'dashes in quoted strings' => ["return '--a', \"b -- c\", '--[[ d ]]'", "return '--a', \"b -- c\", '--[[ d ]]'"],
    'escaped quotes and backslashes' => ["return 'a\\'--b', \"c\\\"--d\", '\\\\' -- e", "return 'a\\'--b', \"c\\\"--d\", '\\\\'"],
    'an escaped line break in a quoted string' => ["return 'a\\\n  -- b' -- c", "return 'a\\\n  -- b'"],
    'long strings as they are' => ["return [[\n  -- not a comment\n]], [==[ ]] -- ]==] -- c", "return [[\n  -- not a comment\n]], [==[ ]] -- ]==]"],
    'patterns with %-' => ["return string.match(s, '^%-?%d+') -- c", "return string.match(s, '^%-?%d+')"],
]);

it('returns a source with an unfinished string or comment as it is, so Redis reports its error', function (string $source): void {
    expect(Lua::compact($source))->toBe($source);
})->with([
    'quoted string' => ["-- c\nreturn 'abc"],
    'quoted string over a line break' => ["-- c\nreturn 'a\nb'"],
    'backslash at the end' => ["-- c\nreturn 'a\\"],
    'long string' => ["-- c\nreturn [==[ x ]]"],
    'block comment' => ["return 1 --[[ open\n"],
]);

it('keeps the lines of every package script and sends each one shorter', function (string $path): void {
    $source = (string) file_get_contents($path);
    $compact = Lua::compact($source);

    expect(substr_count($compact, "\n"))->toBe(substr_count($source, "\n"))
        ->and(strlen($compact))->toBeLessThan(strlen($source))
        ->and((new Lua(dirname($path)))->source(basename($path, '.lua')))->toBe($compact);
})->with(function (): array {
    $paths = glob(dirname(__DIR__, 3).'/resources/lua/*.lua') ?: [];

    return array_combine(array_map(basename(...), $paths), array_map(static fn (string $path): array => [$path], $paths));
});
