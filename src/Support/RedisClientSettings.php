<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Arr;
use Illuminate\Support\ConfigurationUrlParser;
use Throwable;

/**
 * What the client Laravel builds for an app Redis connection reads, for `horizon-observability:check`: its timeouts,
 * its persistent flag and its key prefix, each with the place in `database.redis` it comes from. check judges the
 * client the app really gets, and every fix line names a place that client reads.
 *
 * Illuminate\Redis\RedisManager and its connectors (the same in Laravel 11.23 to 13), and the clients:
 * - **phpredis, one server** (`PhpRedisConnector::connect()`): `array_merge($connection, database.redis.options,
 *   $connection['options'])`, the later array wins; `timeout` and `read_timeout` go to `connect()`, and a truthy
 *   `persistent` makes it `pconnect()`.
 * - **phpredis, cluster** (`connectToCluster()`, `createRedisClusterInstance()`): `array_merge(database.redis.options,
 *   database.redis.clusters.options, $cluster['options'])`; `timeout`, `read_timeout` and a truthy `persistent` go to
 *   `new RedisCluster()`. The nodes give their host and port only: a timeout on a node is never read.
 * - **predis, one server** (`PredisConnector::connect()`): the connection's keys are the predis connection
 *   parameters. Every `options` array becomes predis client options, of which predis reads only `parameters`: the
 *   default parameters for a key the connection lacks (`Factory::createParameters()`), taken from the connection's
 *   own `options.parameters`, else `database.redis.options.parameters.<name>`, else `database.redis.options.parameters`
 *   (`RedisManager::resolve()`). predis reads `timeout` (connect, 5 s when unset), `read_write_timeout` (unset: PHP's
 *   `default_socket_timeout`) and `persistent` (unless it reads as false) from the parameters; a null or empty value
 *   is not set (`Parameters::filter()`).
 * - **predis, cluster** (`connectToCluster()`): each node's keys, then the `parameters` of `array_merge(
 *   database.redis.options, database.redis.clusters.options, $cluster['options'])`. With the `cluster` option `redis`
 *   (the skeleton's), predis connects to the nodes of the cluster's slot map, and a node it does not find among the
 *   listed ones gets those `parameters` only.
 *
 * A connection's `url` is parsed first, as RedisManager does: the options in its query win over the connection's keys
 * (`ConfigurationUrlParser`), so a key in the query is read from "the url query of" the connection, and a fix line for
 * it names the query. Another client (`Redis::extend()`) is not modelled ({@see self::known()}).
 *
 * @internal
 */
final readonly class RedisClientSettings
{
    private const RESERVED = ['client', 'options', 'clusters'];

    private const GLOBAL_OPTIONS = 'database.redis.options';

    /**
     * `$connections` holds, per connection the client opens (one; for a predis cluster one per listed node and one for
     * the nodes it learns from the slot map), the arrays it reads a key from with their places: the first array that has
     * the key wins. `$unread` are arrays the client does not read the timeouts or the persistent flag from, with their
     * places; `$nodes` the listed nodes of a predis cluster; `$parameters` the place of a predis cluster's `parameters`
     * and whether the config has one.
     *
     * @param  string  $client  `database.redis.client`
     * @param  string  $base  the place of the connection in config/database.php, e.g. `database.redis.cache`
     * @param  list<list<array{0: string, 1: array<array-key, mixed>}>>  $connections
     * @param  list<array{0: string, 1: array<array-key, mixed>}>  $unread
     * @param  list<array{0: string, 1: array<array-key, mixed>}>  $nodes
     * @param  array{0: string, 1: bool}|null  $parameters
     */
    private function __construct(
        public string $client,
        public bool $cluster,
        public string $base,
        private array $connections,
        private array $unread,
        private array $nodes,
        private ?array $parameters,
        private string $prefix,
    ) {}

    /**
     * The settings of `database.redis.<name>`, else of `database.redis.clusters.<name>` (the order RedisManager
     * resolves them in); null when neither is configured.
     *
     * @param  string|null  $base  the place of the connection in config/database.php, when it is not
     *                             `database.redis.<name>` (Horizon's `horizon` connection is a copy of `horizon.use`)
     */
    public static function of(Repository $config, string $name, ?string $base = null): ?self
    {
        $redis = $config->get('database.redis');

        if (! is_array($redis)) {
            return null;
        }

        $client = is_string($redis['client'] ?? null) && $redis['client'] !== '' ? $redis['client'] : 'phpredis';
        $global = is_array($redis['options'] ?? null) ? $redis['options'] : [];
        $clusters = is_array($redis['clusters'] ?? null) ? $redis['clusters'] : [];

        if (! in_array($name, self::RESERVED, true) && is_array($redis[$name] ?? null)) {
            return self::single($client, $name, self::parse($redis[$name]), $global, $base ?? 'database.redis.'.$name, self::query($redis[$name]));
        }

        if ($name !== 'options' && is_array($clusters[$name] ?? null)) {
            $clusterOptions = is_array($clusters['options'] ?? null) ? $clusters['options'] : [];

            return self::cluster($client, $clusters[$name], $clusterOptions, $global, $base ?? 'database.redis.clusters.'.$name);
        }

        return null;
    }

    /**
     * Whether the client is phpredis or predis, the two this class models.
     */
    public function known(): bool
    {
        return in_array($this->client, ['phpredis', 'predis'], true);
    }

    /**
     * The ACL user the client authenticates as (null: none, the `default` user).
     */
    public function username(): ?string
    {
        $username = $this->value($this->connections[0] ?? [], 'username')[0];

        return is_string($username) && $username !== '' ? $username : null;
    }

    /**
     * The key prefix every command gets (`''` without one).
     */
    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * The timeout keys a hung server waits on: `timeout` and `read_timeout` on phpredis, `read_write_timeout` on predis
     * (its connect timeout is 5 s when unset).
     *
     * @return list<string>
     */
    public function timeoutKeys(): array
    {
        return $this->client === 'predis' ? ['read_write_timeout'] : ['timeout', 'read_timeout'];
    }

    /**
     * The timeout keys without a positive value in the client (for a predis cluster: in any of its connections).
     *
     * @return list<string>
     */
    public function missingTimeouts(): array
    {
        if ($this->connections === []) {
            return $this->timeoutKeys();
        }

        return array_values(array_filter($this->timeoutKeys(), function (string $key): bool {
            foreach ($this->connections as $sources) {
                if (! self::positive($this->value($sources, $key)[0])) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * The positive timeouts the client has, e.g. `timeout 1.5 s, read_timeout 2 s` (predis: `timeout` and
     * `read_write_timeout`; a range when the connections of a predis cluster differ).
     */
    public function timeoutText(): string
    {
        $keys = $this->client === 'predis' ? ['timeout', 'read_write_timeout'] : ['timeout', 'read_timeout'];
        $parts = [];

        foreach ($keys as $key) {
            $values = array_map(fn (array $sources): mixed => $this->value($sources, $key)[0], $this->connections);

            if ($values === [] || array_filter($values, static fn (mixed $value): bool => ! self::positive($value)) !== []) {
                continue;
            }

            /** @var list<int|float|string> $values */
            usort($values, static fn (int|float|string $a, int|float|string $b): int => (float) $a <=> (float) $b);
            $low = (string) $values[0];
            $high = (string) $values[count($values) - 1];
            $parts[] = $key.' '.((float) $low === (float) $high ? $low : $low.' s to '.$high).' s';
        }

        return implode(', ', $parts);
    }

    /**
     * The places where a missing timeout key is set but the client does not read it, e.g. `database.redis.options` on
     * predis or the nodes of a phpredis cluster.
     *
     * @param  list<string>  $missing
     * @return list<string>
     */
    public function unreadTimeouts(array $missing): array
    {
        $places = [];

        foreach ($this->unread as [$place, $values]) {
            foreach ($missing as $key) {
                if (self::positive($values[$key] ?? null) && ! in_array($place, $places, true)) {
                    $places[] = $place;
                }
            }
        }

        $nodes = array_values(array_filter($places, static fn (string $place): bool => str_starts_with($place, 'node ')));

        return count($nodes) > 1 ? [...array_values(array_diff($places, $nodes)), 'the nodes of '.$this->base] : $places;
    }

    /**
     * Where to add the missing timeout keys so that the client reads them, as `Add ... to <place>`:
     * - phpredis, one server: the connection, or its own `options` when an `options` array has the key (it would win);
     * - phpredis, cluster: the cluster's own `options`;
     * - predis, one server: the connection;
     * - predis, cluster: the `parameters` (every node, also those learned from the slot map), and each listed node
     *   that has the key without a positive value (a node's own value wins);
     * - a key in the query of the connection's `url` (one server): the url query, which wins over the connection's keys.
     *
     * @param  list<string>  $missing
     * @param  array<string, float>  $suggested  the value to suggest per key (default 2.0)
     */
    public function timeoutFix(array $missing, array $suggested = []): string
    {
        $value = static fn (string $key): string => self::decimal($suggested[$key] ?? 2.0);
        $entries = implode(' and ', array_map(static fn (string $key): string => "'".$key."' => ".$value($key), $missing));

        if ($this->client === 'predis' && $this->cluster && $this->parameters !== null) {
            [$place, $exists] = $this->parameters;
            $overriding = [];

            foreach ($this->nodes as [$node, $values]) {
                foreach ($missing as $key) {
                    if (array_key_exists($key, $values) && ! self::positive($values[$key]) && ! in_array($node, $overriding, true)) {
                        $overriding[] = $node;
                    }
                }
            }

            $fix = $exists
                ? sprintf('Add %s to %s', $entries, $place)
                : sprintf("Add 'parameters' => [%s] to %s", implode(', ', array_map(static fn (string $key): string => "'".$key."' => ".$value($key), $missing)), $place);

            return $overriding === [] ? $fix : sprintf('%s, and %s to %s', $fix, $entries, implode(' and ', $overriding));
        }

        $query = sprintf('Set %s in the url query of %s', implode(' and ', array_map(static fn (string $key): string => $key.'='.$value($key), $missing)), $this->base);

        if ($this->client === 'predis') {
            return $this->inUrlQuery($missing) ? $query : sprintf('Add %s to %s', $entries, $this->base);
        }

        if ($this->cluster) {
            return sprintf('Add %s to the options of %s', $entries, $this->base);
        }

        $options = [];

        foreach ($this->connections[0] ?? [] as [$place, $settings]) {
            if ($place !== $this->base && $place !== $this->urlPlace()) {
                $options[] = $settings;
            }
        }

        foreach ($options as $settings) {
            foreach ($missing as $key) {
                if (array_key_exists($key, $settings)) {
                    return sprintf('Add %s to the options of %s', $entries, $this->base);
                }
            }
        }

        return $this->inUrlQuery($missing) ? $query : sprintf('Add %s to %s', $entries, $this->base);
    }

    /**
     * The read timeout of the client (phpredis `read_timeout`, predis `read_write_timeout`) and the place it comes
     * from: the smallest positive one of its connections; null when one of them has none.
     *
     * @return array{0: float, 1: string}|null
     */
    public function readTimeout(): ?array
    {
        $key = $this->client === 'predis' ? 'read_write_timeout' : 'read_timeout';
        $lowest = null;

        foreach ($this->connections as $sources) {
            [$value, $place] = $this->value($sources, $key);

            if (! is_numeric($value) || (float) $value <= 0 || $place === null) {
                return null;
            }

            if ($lowest === null || (float) $value < $lowest[0]) {
                $lowest = [(float) $value, $place];
            }
        }

        return $lowest;
    }

    /**
     * Whether one of the keys comes from the query of the connection's `url` (one server).
     *
     * @param  list<string>  $keys
     */
    private function inUrlQuery(array $keys): bool
    {
        foreach ($keys as $key) {
            if ($this->value($this->connections[0] ?? [], $key)[1] === $this->urlPlace()) {
                return true;
            }
        }

        return false;
    }

    private function urlPlace(): string
    {
        return 'the url query of '.$this->base;
    }

    /**
     * The places that make the client persistent (empty when it is not): the array its `persistent` comes from, per
     * connection. phpredis reads a truthy value as persistent; predis any value but null, `''` and what reads as false.
     *
     * @return list<string>
     */
    public function persistentPlaces(): array
    {
        $places = [];

        foreach ($this->connections as $sources) {
            [$value, $place] = $this->value($sources, 'persistent');
            $persistent = $this->client === 'predis'
                ? $value !== null && filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false
                : (bool) $value;

            if ($persistent && $place !== null && ! in_array($place, $places, true)) {
                $places[] = $place;
            }
        }

        return $places;
    }

    /**
     * The value the client reads for the key and the place it comes from: the first array that has the key; predis
     * drops null and `''`. `[null, null]` when no array has it.
     *
     * @param  list<array{0: string, 1: array<array-key, mixed>}>  $sources
     * @return array{0: mixed, 1: string|null}
     */
    private function value(array $sources, string $key): array
    {
        foreach ($sources as [$place, $values]) {
            if (array_key_exists($key, $values)) {
                $value = $values[$key];

                return [$this->client === 'predis' && ($value === null || $value === '') ? null : $value, $place];
            }
        }

        return [null, null];
    }

    /**
     * @param  array<array-key, mixed>  $connection  parsed, with its own `options`
     * @param  array<array-key, mixed>  $global
     * @param  array<array-key, mixed>  $query  the options of the query of its `url`, which won over its keys
     */
    private static function single(string $client, string $name, array $connection, array $global, string $base, array $query = []): self
    {
        $own = is_array($connection['options'] ?? null) ? $connection['options'] : [];
        unset($connection['options'], $query['options']);
        $ownPlace = 'the options of '.$base;
        $url = ['the url query of '.$base, $query];
        // Both connectors put a set connection `prefix` over the options.
        $prefix = $connection['prefix'] ?? self::first([[$ownPlace, $own], [self::GLOBAL_OPTIONS, $global]], 'prefix');

        if ($client !== 'predis') {
            return new self($client, false, $base, [[[$ownPlace, $own], [self::GLOBAL_OPTIONS, $global], $url, [$base, $connection]]], [], [], null, self::text($prefix));
        }

        if (array_key_exists('parameters', $own)) {
            $parameters = ['the parameters in '.$ownPlace, $own['parameters']];
        } elseif (Arr::has($global, 'parameters.'.$name)) {
            $parameters = [self::GLOBAL_OPTIONS.'.parameters.'.$name, Arr::get($global, 'parameters.'.$name)];
        } else {
            $parameters = [self::GLOBAL_OPTIONS.'.parameters', $global['parameters'] ?? []];
        }

        $defaults = is_array($parameters[1]) ? $parameters[1] : [];

        return new self(
            $client,
            false,
            $base,
            [[$url, [$base, $connection], [$parameters[0], $defaults]]],
            [[self::GLOBAL_OPTIONS, $global], [$ownPlace, $own]],
            [],
            null,
            self::text($prefix),
        );
    }

    /**
     * @param  array<array-key, mixed>  $nodes  the cluster's nodes and its own `options`
     * @param  array<array-key, mixed>  $clusterOptions
     * @param  array<array-key, mixed>  $global
     */
    private static function cluster(string $client, array $nodes, array $clusterOptions, array $global, string $base): self
    {
        $own = is_array($nodes['options'] ?? null) ? $nodes['options'] : [];
        unset($nodes['options']);
        $ownPlace = 'the options of '.$base;
        // array_merge(global, clusters.options, own): the first of these that has a key wins.
        $options = [[$ownPlace, $own], ['database.redis.clusters.options', $clusterOptions], [self::GLOBAL_OPTIONS, $global]];
        $prefix = self::text(self::first($options, 'prefix'));
        $listed = [];

        foreach ($nodes as $key => $node) {
            if (is_array($node) || is_string($node)) {
                $listed[] = ['node '.$key.' of '.$base, self::parse($node)];
            }
        }

        if ($client !== 'predis') {
            return new self($client, true, $base, [$options], $listed, [], null, $prefix);
        }

        $parameters = ['the options of '.$base, false];
        $defaults = [];

        foreach ($options as [$place, $values]) {
            if (array_key_exists('parameters', $values)) {
                $parameters = [$place === $ownPlace ? 'the parameters in '.$ownPlace : $place.'.parameters', true];
                $defaults = is_array($values['parameters']) ? $values['parameters'] : [];

                break;
            }
        }

        $connections = array_map(static fn (array $node): array => [$node, [$parameters[0], $defaults]], $listed);

        if (in_array(self::first($options, 'cluster'), ['redis', 'redis-cluster'], true)) {
            // A node of the slot map that is not one of the listed nodes.
            $connections[] = [[$parameters[0], $defaults]];
        }

        return new self($client, true, $base, $connections, $options, $listed, $parameters, $prefix);
    }

    /**
     * The connection as RedisManager::parseConnectionConfiguration() passes it on: its `url` merged in.
     *
     * @param  array<array-key, mixed>|string  $connection
     * @return array<array-key, mixed>
     */
    private static function parse(array|string $connection): array
    {
        try {
            $parsed = (new ConfigurationUrlParser)->parseConfiguration($connection);
        } catch (Throwable) {
            $parsed = is_array($connection) ? $connection : [];
            unset($parsed['url']);
        }

        unset($parsed['driver']);

        return $parsed;
    }

    /**
     * The options in the query of the connection's `url`, as ConfigurationUrlParser merges them over its keys (strings
     * that are JSON become their value).
     *
     * @param  array<array-key, mixed>  $connection
     * @return array<array-key, mixed>
     */
    private static function query(array $connection): array
    {
        $url = $connection['url'] ?? null;
        $query = is_string($url) ? parse_url($url, PHP_URL_QUERY) : null;

        if (! is_string($query) || $query === '') {
            return [];
        }

        parse_str($query, $options);

        return array_map(static function (mixed $value): mixed {
            if (! is_string($value)) {
                return $value;
            }

            $decoded = json_decode($value, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }, $options);
    }

    /**
     * @param  list<array{0: string, 1: array<array-key, mixed>}>  $sources
     */
    private static function first(array $sources, string $key): mixed
    {
        foreach ($sources as [, $values]) {
            if (array_key_exists($key, $values)) {
                return $values[$key];
            }
        }

        return null;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) || is_int($value) ? (string) $value : '';
    }

    /**
     * Seconds as a PHP float literal for a fix line: `2.0`, `7.5`.
     */
    public static function decimal(float $seconds): string
    {
        $text = rtrim(rtrim(number_format($seconds, 3, '.', ''), '0'), '.');

        return str_contains($text, '.') ? $text : $text.'.0';
    }

    private static function positive(mixed $value): bool
    {
        return is_numeric($value) && (float) $value > 0;
    }
}
