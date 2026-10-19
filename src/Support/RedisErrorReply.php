<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Support;

use DF\HorizonObservability\Redis\RedisScriptFailed;
use Predis\PredisException;
use Predis\Response\ServerException;
use RedisClusterException;
use RedisException;
use Throwable;

/**
 * An error reply of a Redis server that answered: not a connectivity problem, so `check` gives the
 * fix for the reply instead of "check that Redis is reachable", which its Storage and Queues lines contradict.
 *
 * The reply is read from the failure and its causes: a phpredis `RedisException` or `RedisClusterException` (phpredis
 * throws every error reply but `ERR`, `NOSCRIPT`, `WRONGTYPE` and a few cluster ones), a predis `ServerException` (or
 * the `ConnectionException` of a refused handshake), or {@see RedisScriptFailed} (a package Lua script; a reply of
 * `redis.call()` inside it, such as a refused `TIME`, follows the script's own `ERR`). Replies with a fix:
 * - `READONLY`, `MASTERDOWN`: a replica (a `REDIS_HOST` on a replica, or a primary a failover demoted): point the
 *   connection at the writable primary;
 * - `NOPERM`, and `ERR ACL failure in script` (Redis 7+ and Valkey inside a script): an ACL refusal; the refused
 *   command (Redis 6.2 to 8 and Valkey word it alike) gets its `ACL SETUSER` rule, a refused key the key pattern of
 *   the connection's prefix;
 * - `NOREPLICAS` (a primary with `min-replicas-to-write` and too few replicas), `NOAUTH`, `WRONGPASS` (and
 *   `ERR AUTH <password> called without any password configured`), `OOM`, `MISCONF`, `LOADING`, `BUSY`, `WRONGTYPE`,
 *   `CROSSSLOT`, `CLUSTERDOWN`, `TRYAGAIN`, `ERR max number of clients reached`, and `unknown command`, also inside a
 *   script (Redis 7+ and Valkey 7.2 "Unknown Redis command called from script", Redis 6.2 "... from Lua script",
 *   Valkey 8 "Unknown command called from script"), where the refused command is taken from the script line or the
 *   script's own `ERR <COMMAND> failed:`;
 * - any other error reply (the reply of a package script or of predis, or a message that starts with an upper-case
 *   error code such as `ERR`): a generic fix that never sends the user to the connection settings.
 * A connection error (refused, timed out, reset) has no reply and gets the caller's connectivity fix.
 *
 * @internal
 */
final readonly class RedisErrorReply
{
    /**
     * The codes this class has a fix for, as the first word of an error reply.
     */
    private const CODES = 'READONLY|MASTERDOWN|NOREPLICAS|NOPERM|NOAUTH|WRONGPASS|OOM|MISCONF|LOADING|BUSY|WRONGTYPE|CROSSSLOT|CLUSTERDOWN|TRYAGAIN';

    /**
     * @param  string|null  $refused  the refused command when the reply does not name it (Redis 6.2 inside a script)
     */
    private function __construct(
        public string $code,
        public string $message,
        private ?string $refused = null,
    ) {}

    /**
     * The error reply in the failure or its causes, when it comes from Redis; null otherwise (a connection error, a
     * timeout, anything else). A reply with a fix of its own wins over a generic one of another cause.
     */
    public static function of(Throwable $e): ?self
    {
        $other = null;

        for ($cause = $e; $cause instanceof Throwable; $cause = $cause->getPrevious()) {
            if (! self::fromRedis($cause)) {
                continue;
            }

            $message = $cause->getMessage();

            if (preg_match('/unknown command [`\'][^`\']+[`\']/i', $message, $match) === 1) {
                return new self('UNKNOWN', $match[0]);
            }

            if (preg_match('/Unknown (?:Redis )?command called from (?:Lua )?script/i', $message, $match) === 1) {
                return new self('UNKNOWN', $match[0], $cause instanceof RedisScriptFailed ? self::unknownInScript($cause) : null);
            }

            if (preg_match('/max(?:imum)? number of clients reached/i', $message, $match) === 1) {
                return new self('MAXCLIENTS', 'ERR '.$match[0]);
            }

            // An ACL refusal inside a script is "ERR ACL failure in script: User ... has no permissions ..." on Redis 7+
            // and Valkey, NOPERM elsewhere.
            if (preg_match('/(?:\bUser \S+ |this user )?has no permissions to .*$|No permissions to access .*$/s', $message, $match) === 1) {
                return new self('NOPERM', trim($match[0]));
            }

            // Redis 6.2 inside a script names the line, not the command: the command on that line of the script.
            if (preg_match("/@user_script:\\s*(\\d+): The user executing the script can't (run this command|access)/", $message, $match) === 1) {
                $line = (int) $match[1];

                return new self('NOPERM', trim(substr($message, (int) strpos($message, 'The user executing'))), $match[2] === 'access' || ! $cause instanceof RedisScriptFailed ? null : self::scriptCommand($cause->script, $line));
            }

            // A code at the start of the reply, or after the script's "...: " (a "-" before it on Redis 6.2).
            if (preg_match('/(?:^|[\s:(-])('.self::CODES.')\b.*$/s', $message, $match) === 1) {
                return new self($match[1], trim(substr($match[0], (int) strpos($match[0], $match[1]))));
            }

            // A password for a user without one (predis 3 falls back to AUTH <password> after a refused HELLO), or the
            // reply of Redis before 6.
            if (preg_match('/ERR (?:AUTH <password> called without any password configured|invalid password).*$/s', $message, $match) === 1) {
                return new self('WRONGPASS', trim($match[0]));
            }

            $other ??= self::other($cause);
        }

        return $other;
    }

    /**
     * One actionable fix line for the reply on the Redis connection at `$place` in config/database.php (e.g.
     * `database.redis.default`), whose client authenticates as `$user` (null: the `default` user) and prefixes its keys
     * with `$prefix`.
     */
    public function fix(string $place, ?string $user, string $prefix): string
    {
        $user = $user === null || $user === '' ? 'default' : $user;

        return match ($this->code) {
            'READONLY', 'MASTERDOWN' => sprintf('Point %s at the writable primary: it answers as a read-only replica (or a primary a failover demoted), which refuses the package\'s writes.', $place),
            'NOREPLICAS' => sprintf('Point %s at a primary with enough connected replicas, or lower its min-replicas-to-write: it refuses every write while fewer replicas acknowledge them (NOREPLICAS).', $place),
            'NOPERM' => $this->permission($place, $this->deniedUser() ?? $user, $prefix),
            'NOAUTH', 'WRONGPASS' => sprintf('Set the password (and username) Redis expects in %s (REDIS_PASSWORD, REDIS_USERNAME).', $place),
            'OOM' => sprintf('Free memory on the Redis of %s or raise its maxmemory: at maxmemory it refuses the package\'s writes (OOM).', $place),
            'MISCONF' => sprintf('Fix the failing RDB snapshots of the Redis of %s (disk space or permissions of its dir, see its log): until then it refuses writes (MISCONF).', $place),
            'LOADING' => sprintf('Wait until the Redis of %s has loaded its dataset after its restart, then run the check again.', $place),
            'BUSY' => sprintf('A script runs too long on the Redis of %s (BUSY): wait for it or stop it with SCRIPT KILL, then run the check again.', $place),
            'WRONGTYPE' => sprintf('A key it reads on %s holds another type, so another app writes the same key names: give each app its own prefix in config/database.php.', $place),
            'CROSSSLOT' => sprintf('Give the prefix of %s a hash tag with content, e.g. {app}: the keys of one command are in different slots of the cluster (see Storage, Prefix).', $place),
            'CLUSTERDOWN', 'TRYAGAIN' => sprintf('Wait until the Redis Cluster of %s is healthy again (%s: %s), then run the check again; CLUSTER INFO shows its state.', $place, $this->code, $this->code === 'TRYAGAIN' ? 'a slot of the keys is being moved' : 'the cluster is down or a slot has no node'),
            'MAXCLIENTS' => sprintf('Raise maxclients on the Redis of %s, or close idle clients (CLIENT LIST shows them): at its limit it refuses every new connection.', $place),
            'ERROR' => sprintf('The Redis of %s answers with the error reply above, so the connection works: fix what the reply names on that server (see its log), then run the check again.', $place),
            default => sprintf('The Redis of %s does not offer %s (a proxy or a managed Redis that disables it): point the connection at a Redis that runs EVAL, TIME and INFO, which the package needs.', $place, $this->command() ?? 'that command'),
        };
    }

    /**
     * The refused command of a NOPERM or unknown command reply, lower case (`time`, `client|id`); null for a refused
     * key or channel.
     */
    public function command(): ?string
    {
        if ($this->refused !== null) {
            return $this->refused;
        }

        return preg_match('/(?:run the|command) [`\']([^`\']+)[`\']/i', $this->message, $match) === 1 ? strtolower($match[1]) : null;
    }

    /**
     * The user a NOPERM reply names (Redis 7+ and Valkey: "User <name> has no permissions").
     */
    private function deniedUser(): ?string
    {
        return preg_match('/\bUser (\S+) has no permissions/', $this->message, $match) === 1 ? $match[1] : null;
    }

    private function permission(string $place, string $user, string $prefix): string
    {
        $command = $this->command();

        if ($command !== null) {
            return sprintf(
                'Allow %s for the Redis user %s of %s: ACL SETUSER %s +%s (the package runs EVAL, TIME and INFO besides the hash, list, sorted set and string commands on its keys).',
                strtoupper($command),
                $user,
                $place,
                $user,
                $command,
            );
        }

        if (preg_match('/\bkeys?\b/i', $this->message) === 1) {
            return sprintf("Allow the keys of %s for its Redis user %s: ACL SETUSER %s '~%s*'.", $place, $user, $user, $prefix);
        }

        return sprintf('Allow the commands of the package for the Redis user %s of %s: ACL SETUSER %s +@read +@write +@scripting +time +info (Redis refused one without naming it).', $user, $place, $user);
    }

    /**
     * The command a package script calls that the server does not offer: the command on the script line the reply
     * names (Redis 6.2 always, Redis 7+ for a `redis.call()`), else the one in the script's own
     * `ERR <COMMAND> failed:` (the `redis.pcall()` of `TIME` in new_series.lua); lower case.
     */
    private static function unknownInScript(RedisScriptFailed $e): ?string
    {
        $message = $e->getMessage();

        if (preg_match('/@user_script:\s*(\d+)/', $message, $match) === 1 && ($command = self::scriptCommand($e->script, (int) $match[1])) !== null) {
            return $command;
        }

        return preg_match('/\bERR ([A-Z]+) failed: /', $message, $match) === 1 ? strtolower($match[1]) : null;
    }

    /**
     * An error reply without a fix of its own: the reason of a package script or a predis server error, or a message
     * that starts with an upper-case error code (`ERR ...`, a code this class does not know); null for a connection
     * error, whose message never does.
     */
    private static function other(Throwable $e): ?self
    {
        $message = trim($e instanceof RedisScriptFailed ? (string) preg_replace('/^Lua script \S+ failed: /', '', $e->getMessage()) : $e->getMessage());

        if (preg_match('/^-?[A-Z][A-Z0-9_]+(?: |$)/', $message) === 1 || ($e instanceof ServerException && $message !== '')) {
            return new self('ERROR', ltrim($message, '-'));
        }

        return null;
    }

    /**
     * The command `redis.call()` or `redis.pcall()` runs on a line of a package script (`resources/lua/<script>.lua`,
     * whose lines EVAL numbers as they are), lower case.
     */
    private static function scriptCommand(string $script, int $line): ?string
    {
        $path = dirname(__DIR__, 2).'/resources/lua/'.$script.'.lua';

        if (preg_match('/^[a-z_]+$/', $script) !== 1 || ! is_file($path)) {
            return null;
        }

        $lines = file($path);
        $source = is_array($lines) ? ($lines[$line - 1] ?? '') : '';

        return preg_match("/redis\\.p?call\\(\\s*'([A-Za-z]+)'/", $source, $match) === 1 ? strtolower($match[1]) : null;
    }

    private static function fromRedis(Throwable $e): bool
    {
        return $e instanceof RedisException
            || $e instanceof RedisClusterException
            || $e instanceof PredisException
            || $e instanceof RedisScriptFailed;
    }
}
