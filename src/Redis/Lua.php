<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Redis;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use InvalidArgumentException;
use Predis\Response\ErrorInterface;
use Predis\Response\ServerException;
use Redis;
use RedisCluster;

/**
 * Runs the package's Lua scripts (`resources/lua/<name>.lua`, Arch Q6 and Appendix D) with one `EVAL` through
 * Laravel's `Connection::eval()`, which orders the arguments for phpredis and predis alike. `EVALSHA` is not used:
 * Laravel's phpredis connection runs `SCRIPT LOAD` on every `evalsha()` call.
 *
 * - Sources are read once per process (a static cache survives container rebuilds under PHP-FPM).
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
     * The source of a script, read once per process.
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

            self::$sources[$path] = $source;
        }

        return self::$sources[$path];
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
}
