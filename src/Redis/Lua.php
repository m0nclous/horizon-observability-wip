<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Redis;

use DF\HorizonObservability\Support\RedisErrorReply;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use InvalidArgumentException;
use Predis\Response\ErrorInterface;
use Predis\Response\ServerException;
use Redis;
use RedisCluster;

/**
 * Runs the package's Lua scripts (`resources/lua/<name>.lua`) with one `EVAL` through
 * Laravel's `Connection::eval()`, which orders the arguments for phpredis and predis alike. `EVALSHA` is not used:
 * Laravel's phpredis connection runs `SCRIPT LOAD` on every `evalsha()` call.
 *
 * - Sources are read once per process (a static cache survives container rebuilds under PHP-FPM) and sent without
 *   their comments ({@see self::compact()}): `record_attempt.lua` (every job end) is 3.1 KB instead of 8.5 KB,
 *   `queue_state.lua` (every queue at every scrape) 7 KB instead of 12.6 KB.
 * - Every key is passed in `$keys` and receives the connection prefix; scripts never build key names.
 * - Floats are passed in their shortest round-trip form (`var_export`), never with a `(string)` cast that rounds
 *   to 14 digits.
 * - A script error becomes {@see RedisScriptFailed} on both clients (phpredis returns `false` and keeps the error in
 *   `getLastError()`; predis throws or returns an error response). A nil reply is `null` on both clients.
 * - Connection errors (`RedisException`, predis `ConnectionException`) are rethrown unchanged.
 *
 * @internal
 */
final class Lua
{
    /**
     * @var array<string, string> path => source
     */
    private static array $sources = [];

    private readonly string $directory;

    /**
     * @param  string|null  $directory  directory of the scripts; default the package's `resources/lua`
     */
    public function __construct(?string $directory = null)
    {
        $this->directory = rtrim($directory ?? dirname(__DIR__, 2).'/resources/lua', '/');
    }

    /**
     * Run a script.
     *
     * @param  string  $script  file name without `.lua`, e.g. `record_attempt`
     * @param  list<string>  $keys
     * @param  list<string|int|float>  $args
     *
     * @throws RedisScriptFailed
     */
    public function eval(Connection $connection, string $script, array $keys, array $args): mixed
    {
        $source = $this->source($script);
        $client = $connection->client();
        $phpredis = $client instanceof Redis || $client instanceof RedisCluster;

        if ($phpredis) {
            $client->clearLastError();
        }

        $arguments = [...$keys, ...array_map(self::argument(...), $args)];

        try {
            // What Laravel's Connection::eval() does per client: phpredis takes (script, args, numKeys), predis the
            // Redis order (script, numKeys, ...args).
            $result = $connection instanceof PhpRedisConnection
                ? $connection->eval($source, count($keys), ...$arguments)
                : $connection->command('eval', [$source, count($keys), ...$arguments]);
        } catch (ServerException $e) {
            throw new RedisScriptFailed($script, $e->getMessage(), $e);
        }

        if ($result instanceof ErrorInterface) {
            throw new RedisScriptFailed($script, $result->getMessage());
        }

        if ($phpredis && $result === false) {
            $error = $client->getLastError();

            if (is_string($error) && $error !== '') {
                $client->clearLastError();

                throw new RedisScriptFailed($script, $error);
            }

            return null;
        }

        return $result;
    }

    /**
     * The source of a script as `EVAL` sends it ({@see self::compact()}), read once per process.
     *
     * @throws RedisScriptFailed when the file does not exist
     */
    public function source(string $script): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $script) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid Lua script name "%s".', $script));
        }

        $path = $this->directory.'/'.$script.'.lua';

        if (! isset(self::$sources[$path])) {
            $source = is_file($path) ? file_get_contents($path) : false;

            if ($source === false) {
                throw new RedisScriptFailed($script, sprintf('the file %s is missing.', $path));
            }

            self::$sources[$path] = self::compact($source);
        }

        return self::$sources[$path];
    }

    /**
     * A script without its comments and without the blanks around its lines; a run of blanks between two tokens
     * becomes one space. Every line break stays, also those inside a block comment, so the line an error reply names
     * is the line of the file ({@see RedisErrorReply}); strings, quoted or in long brackets, are copied byte for byte.
     * Redis compiles the same function from both: tests/Integration/Redis/LuaTest compares their `string.dump()` in
     * the Lua of the server for every package script.
     *
     * The rules of the Lua 5.1 lexer: `--[[ ... ]]` (with any number of `=` between the brackets, the same in the
     * closing ones) is a block comment, any other `--` outside a string runs to the end of the line, and a backslash
     * in a quoted string escapes the next byte. A source with an unfinished string or comment is returned as it is,
     * so Redis reports its error.
     */
    public static function compact(string $source): string
    {
        $length = strlen($source);
        $compact = '';
        $blank = false;

        for ($at = 0; $at < $length;) {
            $byte = $source[$at];

            if ($byte === "\n") {
                $compact .= "\n";
                $blank = false;
                $at++;

                continue;
            }

            if (str_contains(" \t\r\f\v", $byte)) {
                $blank = true;
                $at++;

                continue;
            }

            if ($byte === '-' && ($source[$at + 1] ?? '') === '-') {
                $level = self::longBracket($source, $at + 2);

                if ($level === null) {
                    $end = strpos($source, "\n", $at);
                    $at = $end === false ? $length : $end;

                    continue;
                }

                $end = self::longBracketEnd($source, $at + 2, $level);

                if ($end === null) {
                    return $source;
                }

                // A block comment separates the tokens around it like a blank.
                $compact .= str_repeat("\n", substr_count($source, "\n", $at, $end - $at));
                $blank = true;
                $at = $end;

                continue;
            }

            if ($blank && $compact !== '' && ! str_ends_with($compact, "\n")) {
                $compact .= ' ';
            }

            $blank = false;

            if ($byte === "'" || $byte === '"') {
                $end = $at + 1;

                while ($end < $length && $source[$end] !== $byte) {
                    if ($source[$end] === "\n") {
                        return $source;
                    }

                    $end += $source[$end] === '\\' ? 2 : 1;
                }

                if ($end >= $length) {
                    return $source;
                }

                $compact .= substr($source, $at, $end + 1 - $at);
                $at = $end + 1;

                continue;
            }

            $level = $byte === '[' ? self::longBracket($source, $at) : null;

            if ($level !== null) {
                $end = self::longBracketEnd($source, $at, $level);

                if ($end === null) {
                    return $source;
                }

                $compact .= substr($source, $at, $end - $at);
                $at = $end;

                continue;
            }

            $compact .= $byte;
            $at++;
        }

        return $compact;
    }

    /**
     * The `ARGV` form of a value: strings unchanged, integers in decimal, finite floats in shortest round-trip form.
     */
    public static function argument(string|int|float $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (! is_finite($value)) {
            throw new InvalidArgumentException('Lua arguments must be finite numbers.');
        }

        return var_export($value, true);
    }

    /**
     * The level of the opening long bracket at `$at` (`[[` 0, `[=[` 1, ...), null when there is none.
     */
    private static function longBracket(string $source, int $at): ?int
    {
        if (($source[$at] ?? '') !== '[') {
            return null;
        }

        $level = strspn($source, '=', $at + 1);

        return ($source[$at + 1 + $level] ?? '') === '[' ? $level : null;
    }

    /**
     * The offset after the closing long bracket of the level of the opening one at `$at`; null when it is missing.
     */
    private static function longBracketEnd(string $source, int $at, int $level): ?int
    {
        $close = strpos($source, ']'.str_repeat('=', $level).']', $at + $level + 2);

        return $close === false ? null : $close + $level + 2;
    }
}
