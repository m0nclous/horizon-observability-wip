<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Support\CheckResult;
use DF\HorizonObservability\Support\Diagnostics;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Redis\Connectors\PhpRedisConnector;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Arr;
use Laravel\Horizon\Horizon;
use Predis\Connection\Cluster\RedisCluster as PredisRedisCluster;
use Predis\Connection\NodeConnectionInterface;
use Predis\Connection\ParametersInterface;

/*
| horizon-observability:check judges the Redis client Laravel really builds: its Timeouts block, its
| Persistent warnings and the Storage prefix come from what each connector hands its client, and every fix line names a
| place that client reads. The ground truth is always the client itself, built by Laravel's RedisManager from the
| config as RedisServiceProvider builds it (the same code on every Laravel leg of CI, 11.23 to 13, predis 2 and 3):
| - phpredis, one server: the connected client's getTimeout() and getReadTimeout();
| - phpredis, cluster: the options Laravel's connector hands new RedisCluster() (no cluster needed);
| - predis: the connection parameters of each node connection predis creates, and for a Redis Cluster the connection it
|   creates for a node it learns from the slot map;
| - persistent: whether a second client gets the first one's socket (CLIENT ID);
| - end to end: how long each client waits on a server that accepts and never answers.
| For every warning the test applies the fix line to the config and checks that the client then has the setting.
*/

pest()->group('redis');

beforeEach(function (): void {
    config()->set('horizon-observability.token', 'redis-client-check-token-0123456789');
    $this->socketTimeout = ini_get('default_socket_timeout');
});

afterEach(function (): void {
    ini_set('default_socket_timeout', (string) $this->socketTimeout);
});

/**
 * The manager Laravel's RedisServiceProvider builds from the config as it is now.
 */
function redisModelManager(): RedisManager
{
    $config = config('database.redis');
    $driver = Arr::pull($config, 'client', 'phpredis');

    return new RedisManager(app(), is_string($driver) ? $driver : 'phpredis', $config);
}

/**
 * The check result of a section and name.
 */
function redisModelResult(string $section = 'Timeouts', string $name = 'Default cache store'): ?CheckResult
{
    app()->forgetInstance(Config::class);
    app()->forgetInstance(RedisClients::class);

    foreach (app(Diagnostics::class)->check() as $result) {
        if ($result->section === $section && $result->name === $name) {
            return $result;
        }
    }

    return null;
}

/**
 * The default cache store on the Redis connection, so that Timeouts judges it.
 */
function redisModelStore(string $connection): void
{
    config()->set('cache.stores.probe', ['driver' => 'redis', 'connection' => $connection]);
    config()->set('cache.default', 'probe');
}

/**
 * The config path of a place a fix line names, e.g. `the options of database.redis.probe`.
 */
function redisModelPath(string $place): string
{
    return match (true) {
        preg_match('/^the parameters in the options of (\S+)$/', $place, $match) === 1 => $match[1].'.options.parameters',
        preg_match('/^the options of (\S+)$/', $place, $match) === 1 => $match[1].'.options',
        preg_match('/^node (\S+) of (\S+)$/', $place, $match) === 1 => $match[2].'.'.$match[1],
        preg_match('/^database\.redis\.\S+$/', $place) === 1 => $place,
        default => throw new RuntimeException('A fix line names a place this test does not know: '.$place),
    };
}

/**
 * Apply a Timeouts fix line ("Add <entries> to <place>[ and <place>][, and <entries> to <place>] in
 * config/database.php...", the entries `'key' => 2.0 [and ...]` or `'parameters' => [...]`) to the config, as a user
 * would.
 */
function redisModelApplyTimeoutFix(?string $fix): void
{
    expect(preg_match('/^Add (.+?) in config\/database\.php/', (string) $fix, $match))->toBe(1, (string) $fix);

    foreach (explode(', and ', $match[1]) as $clause) {
        expect(preg_match('/^(.+?) to (.+)$/', $clause, $parts))->toBe(1, $clause);
        preg_match_all("/'(\\w+)' => 2\\.0/", $parts[1], $keys);
        $parameters = str_starts_with($parts[1], "'parameters' => [");

        foreach (explode(' and ', $parts[2]) as $place) {
            foreach ($keys[1] as $key) {
                config()->set(redisModelPath($place).($parameters ? '.parameters' : '').'.'.$key, 2.0);
            }
        }
    }
}

/**
 * Apply a Persistent fix line ("Set 'persistent' => false in <place>[ (REDIS_PERSISTENT=false)][ and <place>].").
 */
function redisModelApplyPersistentFix(?string $fix): void
{
    expect(preg_match("/^Set 'persistent' => false in (.+)\\.$/", (string) $fix, $match))->toBe(1, (string) $fix);

    foreach (explode(' and ', str_replace(' (REDIS_PERSISTENT=false)', '', $match[1])) as $place) {
        config()->set(redisModelPath($place).'.persistent', false);
    }
}

/**
 * The connect and read timeouts of the phpredis client Laravel builds for a single server (connected).
 *
 * @return array{0: float, 1: float}
 */
function redisModelPhpredisTimeouts(string $name): array
{
    $client = redisModelManager()->connection($name)->client();
    assert($client instanceof Redis);

    return [(float) $client->getTimeout(), $client->getReadTimeout()];
}

/**
 * The options Laravel's phpredis connector hands new RedisCluster() for the cluster: its timeout, read timeout and
 * persistent flag are `$options['timeout'] ?? 0`, `$options['read_timeout'] ?? 0` and a set, truthy `persistent`.
 *
 * @return array<array-key, mixed>
 */
function redisModelPhpredisClusterOptions(string $name): array
{
    $manager = redisModelManager();
    $captured = new ArrayObject;

    // Not static: RedisManager::extend() binds the closure (Laravel 11).
    $manager->extend('phpredis', fn (): PhpRedisConnector => new class($captured) extends PhpRedisConnector
    {
        public function __construct(private readonly ArrayObject $captured) {}

        protected function createRedisClusterInstance(array $servers, array $options): never
        {
            $this->captured->exchangeArray($options);

            throw new RuntimeException('captured');
        }
    });

    try {
        $manager->connection($name);
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toBe('captured');
    }

    return $captured->getArrayCopy();
}

/**
 * The connection parameters of every node connection predis creates for the connection: the server, or each listed
 * node of a cluster (by host:port) and, for a Redis Cluster, a node it learns from the slot map.
 *
 * @param  list<string>  $nodes  host:port of the listed nodes of a cluster
 * @return list<ParametersInterface>
 */
function redisModelPredisParameters(string $name, array $nodes = []): array
{
    $connection = redisModelManager()->connection($name)->client()->getConnection();

    if ($connection instanceof NodeConnectionInterface) {
        return [$connection->getParameters()];
    }

    $parameters = [];

    foreach ($nodes as $id) {
        $node = $connection->getConnectionById($id);
        assert($node instanceof NodeConnectionInterface);
        $parameters[] = $node->getParameters();
    }

    if ($connection instanceof PredisRedisCluster) {
        // How predis connects to a node of the slot map it was not given (RedisCluster::createConnection()).
        $learned = (new ReflectionMethod($connection, 'createConnection'))->invoke($connection, '203.0.113.9:7001');
        assert($learned instanceof NodeConnectionInterface);
        $parameters[] = $learned->getParameters();
    }

    return $parameters;
}

/**
 * Whether the client Laravel builds for the connection is persistent. phpredis: the connection's `persistent_id` (its
 * own pool), which Laravel hands only pconnect(); predis: a second client gets the first one's socket (for a predis
 * cluster, the socket of its node `$node`).
 */
function redisModelPersistent(string $name, ?string $node = null): bool
{
    $client = redisModelManager()->connection($name)->client();

    if ($client instanceof Redis) {
        $id = config('database.redis.'.$name.'.persistent_id');
        expect($id)->toBeString();

        return $client->getPersistentID() === $id;
    }

    $id = static function () use ($name, $node): int {
        $client = redisModelManager()->connection($name)->client();
        $connection = $client->getConnection();
        $connection = $connection instanceof NodeConnectionInterface ? $connection : $connection->getConnectionById((string) $node);
        assert($connection instanceof NodeConnectionInterface);

        return (int) $connection->executeCommand($client->createCommand('CLIENT', ['ID']));
    };

    unset($client);
    $first = $id();
    gc_collect_cycles();

    return $first === $id();
}

/**
 * Seconds a command takes on a server that accepts the connection and never answers (PHP's default_socket_timeout is
 * 1 s): about the client's read timeout when it has one, a multiple of 1 s when not.
 *
 * @param  Closure(int): void  $point  points the connection at the server's port
 */
function redisModelHang(Closure $point, string $name): float
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create(['socket' => ['backlog' => 64]]));
    assert($server !== false);
    $point((int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1));
    ini_set('default_socket_timeout', '1');
    $started = microtime(true);

    try {
        redisModelManager()->connection($name)->command('get', ['horizon-observability:probe']);
    } catch (Throwable) {
        // the timeout
    } finally {
        $elapsed = microtime(true) - $started;
        fclose($server);
    }

    return $elapsed;
}

function redisModelHost(): string
{
    return (string) (getenv('REDIS_HOST') ?: '127.0.0.1');
}

function redisModelPort(): int
{
    return (int) (getenv('REDIS_PORT') ?: 6379);
}

it('judges a phpredis server by the timeouts its client gets, and its fix line gives them to it', function (Closure $configure, bool $applied): void {
    config()->set('database.redis.client', 'phpredis');
    config()->set('database.redis.probe', ['host' => redisModelHost(), 'port' => redisModelPort(), 'database' => 0]);
    $configure();
    redisModelStore('probe');

    $truth = redisModelPhpredisTimeouts('probe');
    $result = redisModelResult();

    expect($truth === [1.5, 2.5])->toBe($applied, 'the dataset describes the client Laravel builds')
        ->and($result?->status->value)->toBe($applied ? 'ok' : 'warning', (string) $result?->message);

    if ($applied) {
        expect($result?->message)->toBe('probe (redis probe) has timeout 1.5 s, read_timeout 2.5 s');

        return;
    }

    redisModelApplyTimeoutFix($result?->fix);

    expect(min(redisModelPhpredisTimeouts('probe')))->toBeGreaterThan(0.0)
        ->and(redisModelResult()?->status->value)->toBe('ok');
})->with([
    'on the connection' => [static fn () => config()->set('database.redis.probe', [...config('database.redis.probe'), 'timeout' => 1.5, 'read_timeout' => 2.5]), true],
    'in database.redis.options' => [static fn () => config()->set('database.redis.options', [...config('database.redis.options'), 'timeout' => 1.5, 'read_timeout' => 2.5]), true],
    "in the connection's options" => [static fn () => config()->set('database.redis.probe.options', ['timeout' => 1.5, 'read_timeout' => 2.5]), true],
    'in the url' => [static fn () => config()->set('database.redis.probe.url', sprintf('redis://%s:%d?timeout=1.5&read_timeout=2.5', redisModelHost(), redisModelPort())), true],
    // The skeleton style 'read_timeout' => env(...) in database.redis.options: null wins over the connection.
    'on the connection, null in database.redis.options' => [static function (): void {
        config()->set('database.redis.probe', [...config('database.redis.probe'), 'timeout' => 1.5, 'read_timeout' => 2.5]);
        config()->set('database.redis.options', [...config('database.redis.options'), 'timeout' => null, 'read_timeout' => null]);
    }, false],
    "on the connection, 0 in the connection's options" => [static function (): void {
        config()->set('database.redis.probe', [...config('database.redis.probe'), 'timeout' => 1.5, 'read_timeout' => 2.5, 'options' => ['read_timeout' => 0]]);
    }, false],
    'nowhere' => [static fn (): null => null, false],
]);

it('judges a phpredis cluster by the timeouts new RedisCluster() gets, and its fix line gives them to it', function (Closure $configure, bool $applied): void {
    config()->set('database.redis.client', 'phpredis');
    config()->set('database.redis.clusters.probe', [['host' => '127.0.0.1', 'port' => 1]]);
    $configure();
    redisModelStore('probe');

    $options = redisModelPhpredisClusterOptions('probe');
    $truth = [(float) ($options['timeout'] ?? 0), (float) ($options['read_timeout'] ?? 0)];
    $result = redisModelResult();

    expect($truth === [1.5, 2.5])->toBe($applied, 'the dataset describes the client Laravel builds')
        ->and($result?->status->value)->toBe($applied ? 'ok' : 'warning', (string) $result?->message);

    if ($applied) {
        expect($result?->message)->toBe('probe (redis probe) has timeout 1.5 s, read_timeout 2.5 s');

        return;
    }

    expect($result?->fix)->toStartWith('Add ')->toEndWith(' to the options of database.redis.clusters.probe in config/database.php.');
    redisModelApplyTimeoutFix($result?->fix);
    $options = redisModelPhpredisClusterOptions('probe');

    expect(min((float) ($options['timeout'] ?? 0), (float) ($options['read_timeout'] ?? 0)))->toBeGreaterThan(0.0)
        ->and(redisModelResult()?->status->value)->toBe('ok');
})->with([
    "in the cluster's options" => [static fn () => config()->set('database.redis.clusters.probe.options', ['timeout' => 1.5, 'read_timeout' => 2.5]), true],
    'in database.redis.clusters.options' => [static fn () => config()->set('database.redis.clusters.options', ['timeout' => 1.5, 'read_timeout' => 2.5]), true],
    'in database.redis.options' => [static fn () => config()->set('database.redis.options', [...config('database.redis.options'), 'timeout' => 1.5, 'read_timeout' => 2.5]), true],
    // As on a single server: the node keys are never read.
    'on its node' => [static fn () => config()->set('database.redis.clusters.probe.0', ['host' => '127.0.0.1', 'port' => 1, 'timeout' => 1.5, 'read_timeout' => 2.5]), false],
    "in database.redis.clusters.options, null in the cluster's options" => [static function (): void {
        config()->set('database.redis.clusters.options', ['timeout' => 1.5, 'read_timeout' => 2.5]);
        config()->set('database.redis.clusters.probe.options', ['read_timeout' => null]);
    }, false],
    'nowhere' => [static fn (): null => null, false],
]);

it('names the cluster node timeouts phpredis does not read', function (): void {
    config()->set('database.redis.client', 'phpredis');
    config()->set('database.redis.clusters.probe', [['host' => '127.0.0.1', 'port' => 1, 'timeout' => 1.5, 'read_timeout' => 2.5]]);
    redisModelStore('probe');

    expect(redisModelResult()?->message)->toBe('probe (redis probe) has no timeout or read_timeout (phpredis does not read timeout or read_timeout from node 0 of database.redis.clusters.probe): a hung store can cost a scrape its budget');

    config()->set('database.redis.clusters.probe.1', ['host' => '127.0.0.2', 'port' => 1, 'read_timeout' => 2.5]);

    expect(redisModelResult()?->message)->toContain('(phpredis does not read timeout or read_timeout from the nodes of database.redis.clusters.probe)');
});

it('judges a predis server by its connection parameters, and its fix line gives them to it', function (Closure $configure, bool $applied): void {
    config()->set('database.redis.client', 'predis');
    config()->set('database.redis.probe', ['host' => redisModelHost(), 'port' => redisModelPort(), 'database' => 0]);
    $configure();
    redisModelStore('probe');

    $truth = (float) (redisModelPredisParameters('probe')[0]->read_write_timeout ?? 0);
    $result = redisModelResult();

    expect($truth === 2.5)->toBe($applied, 'the dataset describes the client Laravel builds')
        ->and($result?->status->value)->toBe($applied ? 'ok' : 'warning', (string) $result?->message);

    if ($applied) {
        expect($result?->message)->toBe('probe (redis probe) has read_write_timeout 2.5 s');

        return;
    }

    expect($result?->fix)->toBe("Add 'read_write_timeout' => 2.0 to database.redis.probe in config/database.php.");
    redisModelApplyTimeoutFix($result?->fix);

    expect((float) redisModelPredisParameters('probe')[0]->read_write_timeout)->toBe(2.0)
        ->and(redisModelResult()?->status->value)->toBe('ok');
})->with([
    'on the connection' => [static fn () => config()->set('database.redis.probe.read_write_timeout', 2.5), true],
    'in the url' => [static fn () => config()->set('database.redis.probe.url', sprintf('tcp://%s:%d?read_write_timeout=2.5', redisModelHost(), redisModelPort())), true],
    'in database.redis.options.parameters' => [static fn () => config()->set('database.redis.options.parameters', ['read_write_timeout' => 2.5]), true],
    'in database.redis.options.parameters.<name>' => [static fn () => config()->set('database.redis.options.parameters', ['probe' => ['read_write_timeout' => 2.5]]), true],
    "in the parameters of the connection's options" => [static fn () => config()->set('database.redis.probe.options', ['parameters' => ['read_write_timeout' => 2.5]]), true],
    // Every options array is predis client options, not connection parameters.
    'in database.redis.options' => [static fn () => config()->set('database.redis.options.read_write_timeout', 2.5), false],
    "in the connection's options" => [static fn () => config()->set('database.redis.probe.options', ['read_write_timeout' => 2.5]), false],
    // A key the connection has, even null, is never taken from the parameters.
    'in the parameters, null on the connection' => [static function (): void {
        config()->set('database.redis.options.parameters', ['read_write_timeout' => 2.5]);
        config()->set('database.redis.probe.read_write_timeout');
    }, false],
    "in the parameters of database.redis.options, other parameters in the connection's options" => [static function (): void {
        config()->set('database.redis.options.parameters', ['read_write_timeout' => 2.5]);
        config()->set('database.redis.probe.options', ['parameters' => ['timeout' => 1]]);
    }, false],
    'nowhere' => [static fn (): null => null, false],
]);

it('names the predis options it does not read as connection parameters', function (): void {
    config()->set('database.redis.client', 'predis');
    config()->set('database.redis.probe', ['host' => redisModelHost(), 'port' => redisModelPort(), 'options' => ['read_write_timeout' => 2.5]]);
    config()->set('database.redis.options.read_write_timeout', 2.5);
    redisModelStore('probe');

    expect(redisModelResult()?->message)->toBe('probe (redis probe) has no read_write_timeout (predis does not read read_write_timeout from database.redis.options or the options of database.redis.probe): a hung store can cost a scrape its budget');
});

it('judges a predis cluster by the parameters of every node it connects to, and its fix line gives them to all', function (string $cluster, Closure $configure, bool $applied): void {
    config()->set('database.redis.client', 'predis');
    config()->set('database.redis.options.cluster', $cluster);
    config()->set('database.redis.clusters.probe', [['host' => '127.0.0.1', 'port' => 1], ['host' => '127.0.0.2', 'port' => 1]]);
    $configure();
    redisModelStore('probe');

    $truth = static fn (): array => array_map(static fn (ParametersInterface $parameters): float => (float) ($parameters->read_write_timeout ?? 0), redisModelPredisParameters('probe', ['127.0.0.1:1', '127.0.0.2:1']));
    $result = redisModelResult();

    expect($truth() === array_fill(0, $cluster === 'redis' ? 3 : 2, 2.5))->toBe($applied, 'the dataset describes the client Laravel builds')
        ->and($result?->status->value)->toBe($applied ? 'ok' : 'warning', (string) $result?->message);

    if ($applied) {
        expect($result?->message)->toBe('probe (redis probe) has read_write_timeout 2.5 s');

        return;
    }

    redisModelApplyTimeoutFix($result?->fix);

    expect(array_filter($truth(), static fn (float $timeout): bool => $timeout <= 0))->toBe([])
        ->and(redisModelResult()?->status->value)->toBe('ok');
})->with([
    'on every node (predis)' => ['predis', static function (): void {
        config()->set('database.redis.clusters.probe.0.read_write_timeout', 2.5);
        config()->set('database.redis.clusters.probe.1.read_write_timeout', 2.5);
    }, true],
    // A node of the slot map that is not listed gets the parameters only.
    'on every node (redis)' => ['redis', static function (): void {
        config()->set('database.redis.clusters.probe.0.read_write_timeout', 2.5);
        config()->set('database.redis.clusters.probe.1.read_write_timeout', 2.5);
    }, false],
    "in the parameters of the cluster's options" => ['redis', static fn () => config()->set('database.redis.clusters.probe.options', ['parameters' => ['read_write_timeout' => 2.5]]), true],
    'in database.redis.clusters.options.parameters' => ['redis', static fn () => config()->set('database.redis.clusters.options', ['parameters' => ['read_write_timeout' => 2.5]]), true],
    'in database.redis.options.parameters' => ['redis', static fn () => config()->set('database.redis.options.parameters', ['read_write_timeout' => 2.5]), true],
    "in the cluster's options" => ['redis', static fn () => config()->set('database.redis.clusters.probe.options', ['read_write_timeout' => 2.5]), false],
    'in database.redis.options' => ['predis', static fn () => config()->set('database.redis.options.read_write_timeout', 2.5), false],
    'in the parameters, 0 on a node' => ['redis', static function (): void {
        config()->set('database.redis.clusters.options', ['parameters' => ['read_write_timeout' => 2.5]]);
        config()->set('database.redis.clusters.probe.0.read_write_timeout', 0);
    }, false],
    'nowhere' => ['redis', static fn (): null => null, false],
]);

it('names where to add the predis cluster parameters', function (): void {
    config()->set('database.redis.client', 'predis');
    config()->set('database.redis.clusters.probe', [['host' => '127.0.0.1', 'port' => 1, 'read_write_timeout' => 0], 'options' => ['cluster' => 'redis']]);
    redisModelStore('probe');

    expect(redisModelResult()?->fix)->toBe("Add 'parameters' => ['read_write_timeout' => 2.0] to the options of database.redis.clusters.probe, and 'read_write_timeout' => 2.0 to node 0 of database.redis.clusters.probe in config/database.php.");

    config()->set('database.redis.clusters.options', ['parameters' => ['password' => null]]);

    expect(redisModelResult()?->fix)->toBe("Add 'read_write_timeout' => 2.0 to database.redis.clusters.options.parameters, and 'read_write_timeout' => 2.0 to node 0 of database.redis.clusters.probe in config/database.php.");
});

it('agrees with how long each client waits on a server that never answers', function (string $client, Closure $configure, Closure $point, bool $applied): void {
    config()->set('database.redis.client', $client);
    $configure();
    redisModelStore('probe');

    // The verdict does not depend on the address; the wait is measured on the silent server.
    $result = redisModelResult();
    $elapsed = redisModelHang($point, 'probe');

    expect($result?->status->value)->toBe($applied ? 'ok' : 'warning', (string) $result?->message)
        ->and($elapsed < 0.8)->toBe($applied, sprintf('%s waited %.2f s', $client, $elapsed));
})->with([
    'phpredis server, on the connection' => ['phpredis', static fn () => config()->set('database.redis.probe', ['host' => redisModelHost(), 'port' => redisModelPort(), 'timeout' => 0.2, 'read_timeout' => 0.2]), static fn (int $port) => config()->set('database.redis.probe.port', $port), true],
    "phpredis server, null in the connection's options" => ['phpredis', static fn () => config()->set('database.redis.probe', ['host' => redisModelHost(), 'port' => redisModelPort(), 'timeout' => 0.2, 'read_timeout' => 0.2, 'options' => ['read_timeout' => null]]), static fn (int $port) => config()->set('database.redis.probe.port', $port), false],
    "phpredis cluster, in the cluster's options" => ['phpredis', static fn () => config()->set('database.redis.clusters.probe', [['host' => '127.0.0.1', 'port' => 1], 'options' => ['timeout' => 0.2, 'read_timeout' => 0.2]]), static fn (int $port) => config()->set('database.redis.clusters.probe.0.port', $port), true],
    'phpredis cluster, on its node' => ['phpredis', static fn () => config()->set('database.redis.clusters.probe', [['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.2, 'read_timeout' => 0.2]]), static fn (int $port) => config()->set('database.redis.clusters.probe.0.port', $port), false],
    'predis server, on the connection' => ['predis', static fn () => config()->set('database.redis.probe', ['host' => redisModelHost(), 'port' => redisModelPort(), 'read_write_timeout' => 0.2]), static fn (int $port) => config()->set('database.redis.probe.port', $port), true],
    'predis server, in database.redis.options' => ['predis', static function (): void {
        config()->set('database.redis.probe', ['host' => redisModelHost(), 'port' => redisModelPort()]);
        config()->set('database.redis.options.read_write_timeout', 0.2);
    }, static fn (int $port) => config()->set('database.redis.probe.port', $port), false],
    // Client-side sharding: commands go to the listed node, without the retries of a Redis Cluster.
    'predis cluster, on its node' => ['predis', static fn () => config()->set('database.redis.clusters.probe', [['host' => '127.0.0.1', 'port' => 1, 'read_write_timeout' => 0.2], 'options' => ['cluster' => 'predis']]), static fn (int $port) => config()->set('database.redis.clusters.probe.0.port', $port), true],
    "predis cluster, in the cluster's options" => ['predis', static fn () => config()->set('database.redis.clusters.probe', [['host' => '127.0.0.1', 'port' => 1], 'options' => ['cluster' => 'predis', 'read_write_timeout' => 0.2]]), static fn (int $port) => config()->set('database.redis.clusters.probe.0.port', $port), false],
]);

it('judges the Horizon connection by its client, and names the connection Horizon copies', function (string $client, Closure $configure, bool $applied): void {
    config()->set('database.redis.client', $client);
    config()->set('horizon.use', 'default');
    $configure();
    // What Horizon does at boot: database.redis.horizon is a copy of horizon.use.
    Horizon::use('default');

    $truth = static fn (): bool => $client === 'predis'
        ? (float) (redisModelPredisParameters('horizon')[0]->read_write_timeout ?? 0) > 0
        : min(redisModelPhpredisTimeouts('horizon')) > 0;
    $result = redisModelResult('Timeouts', 'Horizon connection');

    expect($truth())->toBe($applied, 'the dataset describes the client Laravel builds')
        ->and($result?->status->value)->toBe($applied ? 'ok' : 'warning', (string) $result?->message);

    if ($applied) {
        return;
    }

    expect($result?->fix)->toEndWith('; Horizon copies it into its horizon connection.')
        ->and($result?->fix)->not->toContain('database.redis.horizon');
    redisModelApplyTimeoutFix($result?->fix);
    Horizon::use('default');

    expect($truth())->toBeTrue()
        ->and(redisModelResult('Timeouts', 'Horizon connection')?->status->value)->toBe('ok');
})->with([
    'predis, on the connection' => ['predis', static fn () => config()->set('database.redis.default.read_write_timeout', 2.5), true],
    'predis, in database.redis.options' => ['predis', static fn () => config()->set('database.redis.options.read_write_timeout', 2.5), false],
    "predis, in the connection's options" => ['predis', static fn () => config()->set('database.redis.default.options', ['read_write_timeout' => 2.5]), false],
    'phpredis, on the connection' => ['phpredis', static function (): void {
        config()->set('database.redis.default.timeout', 1.5);
        config()->set('database.redis.default.read_timeout', 2.5);
    }, true],
    "phpredis, on the connection, 0 in the connection's options" => ['phpredis', static function (): void {
        config()->set('database.redis.default.timeout', 1.5);
        config()->set('database.redis.default.read_timeout', 2.5);
        config()->set('database.redis.default.options', ['read_timeout' => 0]);
    }, false],
]);

it('names the cluster Horizon copies when horizon.use is a cluster', function (): void {
    config()->set('database.redis.client', 'phpredis');
    config()->set('database.redis.clusters.probe', [['host' => redisModelHost(), 'port' => redisModelPort()]]);
    config()->set('horizon.use', 'probe');
    // Horizon with cluster support (Laravel's Connection::hasHashTag()) copies the cluster into clusters.horizon,
    // older Horizon its first node into database.redis.horizon.
    config()->set('database.redis', Arr::except(config('database.redis'), 'horizon'));
    Horizon::use('probe');
    $standalone = is_array(config('database.redis.horizon'));
    $truth = static function () use ($standalone): bool {
        if ($standalone) {
            return min(redisModelPhpredisTimeouts('horizon')) > 0;
        }

        $options = redisModelPhpredisClusterOptions('horizon');

        return (float) ($options['timeout'] ?? 0) > 0 && (float) ($options['read_timeout'] ?? 0) > 0;
    };
    $result = redisModelResult('Timeouts', 'Horizon connection');

    expect($truth())->toBeFalse()
        ->and($result?->status->value)->toBe('warning')
        ->and($result?->message)->toStartWith('horizon (a copy of probe) has no timeout or read_timeout')
        ->and($result?->fix)->toBe($standalone
            ? "Add 'timeout' => 2.0 and 'read_timeout' => 2.0 to database.redis.clusters.probe.0 in config/database.php; Horizon copies it into its horizon connection."
            : "Add 'timeout' => 2.0 and 'read_timeout' => 2.0 to the options of database.redis.clusters.probe in config/database.php; Horizon copies it into its horizon connection.");

    redisModelApplyTimeoutFix($result?->fix);
    config()->set('database.redis', Arr::except(config('database.redis'), 'horizon'));
    Horizon::use('probe');

    expect($truth())->toBeTrue()
        ->and(redisModelResult('Timeouts', 'Horizon connection')?->status->value)->toBe('ok');
});

it('warns about a persistent client exactly when the client Laravel builds is persistent, and its fix line ends it', function (string $client, Closure $configure, bool $persistent): void {
    config()->set('database.redis.client', $client);
    config()->set('database.redis.probe', ['host' => redisModelHost(), 'port' => redisModelPort(), 'database' => 0, 'persistent_id' => 'ho-probe-'.bin2hex(random_bytes(4))]);
    config()->set('horizon-observability.redis.connection', 'probe');
    $configure();

    $result = redisModelResult('Storage', 'Persistent');

    expect(redisModelPersistent('probe'))->toBe($persistent, 'the dataset describes the client Laravel builds')
        ->and($result?->status->value)->toBe($persistent ? 'warning' : null);

    if (! $persistent) {
        return;
    }

    redisModelApplyPersistentFix($result?->fix);

    expect(redisModelPersistent('probe'))->toBeFalse()
        ->and(redisModelResult('Storage', 'Persistent'))->toBeNull();
})->with([
    'phpredis, database.redis.options (REDIS_PERSISTENT=true)' => ['phpredis', static fn () => config()->set('database.redis.options.persistent', true), true],
    "phpredis, the connection's options" => ['phpredis', static fn () => config()->set('database.redis.probe.options', ['persistent' => true]), true],
    // The skeleton's database.redis.options has 'persistent' => env('REDIS_PERSISTENT', false) since Laravel 11.x (not
    // in the testbench skeleton of 11.23), which wins.
    'phpredis, the connection, false in database.redis.options' => ['phpredis', static function (): void {
        config()->set('database.redis.options.persistent', false);
        config()->set('database.redis.probe.persistent', true);
    }, false],
    'phpredis, the connection' => ['phpredis', static function (): void {
        config()->set('database.redis.options', Arr::except(config('database.redis.options'), 'persistent'));
        config()->set('database.redis.probe.persistent', true);
    }, true],
    // A string is truthy for phpredis's connector.
    "phpredis, the connection's options, the string 'false'" => ['phpredis', static fn () => config()->set('database.redis.probe.options', ['persistent' => 'false']), true],
    "phpredis, database.redis.options, false in the connection's options" => ['phpredis', static function (): void {
        config()->set('database.redis.options.persistent', true);
        config()->set('database.redis.probe.options', ['persistent' => false]);
    }, false],
    // predis: client options are not connection parameters.
    'predis, database.redis.options (REDIS_PERSISTENT=true)' => ['predis', static fn () => config()->set('database.redis.options.persistent', true), false],
    "predis, the connection's options" => ['predis', static fn () => config()->set('database.redis.probe.options', ['persistent' => true]), false],
    'predis, the connection' => ['predis', static fn () => config()->set('database.redis.probe.persistent', true), true],
    'predis, database.redis.options.parameters' => ['predis', static fn () => config()->set('database.redis.options.parameters', ['persistent' => true]), true],
    "predis, the connection, the string 'false'" => ['predis', static fn () => config()->set('database.redis.probe.persistent', 'false'), false],
    'predis, the parameters, false on the connection' => ['predis', static function (): void {
        config()->set('database.redis.options.parameters', ['persistent' => true]);
        config()->set('database.redis.probe.persistent', false);
    }, false],
]);

it('warns about a persistent cluster client exactly when the client Laravel builds is persistent', function (string $client, Closure $configure, bool $persistent): void {
    config()->set('database.redis.client', $client);
    // predis: client-side sharding on the test server, so the node connection is real.
    config()->set('database.redis.clusters.probe', [['host' => redisModelHost(), 'port' => redisModelPort()], 'options' => ['cluster' => 'predis']]);
    config()->set('horizon-observability.redis.connection', 'probe');
    $configure();

    $truth = static function () use ($client): bool {
        if ($client === 'predis') {
            return redisModelPersistent('probe', redisModelHost().':'.redisModelPort());
        }

        $options = redisModelPhpredisClusterOptions('probe');

        return isset($options['persistent']) && $options['persistent'];
    };
    $result = redisModelResult('Storage', 'Persistent');

    expect($truth())->toBe($persistent, 'the dataset describes the client Laravel builds')
        ->and($result?->status->value)->toBe($persistent ? 'warning' : null);

    if (! $persistent) {
        return;
    }

    redisModelApplyPersistentFix($result?->fix);

    expect($truth())->toBeFalse()
        ->and(redisModelResult('Storage', 'Persistent'))->toBeNull();
})->with([
    'phpredis, database.redis.options' => ['phpredis', static fn () => config()->set('database.redis.options.persistent', true), true],
    'phpredis, database.redis.clusters.options' => ['phpredis', static fn () => config()->set('database.redis.clusters.options', ['persistent' => true]), true],
    "phpredis, the cluster's options" => ['phpredis', static fn () => config()->set('database.redis.clusters.probe.options.persistent', true), true],
    'phpredis, its node' => ['phpredis', static fn () => config()->set('database.redis.clusters.probe.0.persistent', true), false],
    'predis, its node' => ['predis', static fn () => config()->set('database.redis.clusters.probe.0.persistent', true), true],
    "predis, the parameters of the cluster's options" => ['predis', static fn () => config()->set('database.redis.clusters.probe.options.parameters', ['persistent' => true]), true],
    "predis, the cluster's options" => ['predis', static fn () => config()->set('database.redis.clusters.probe.options.persistent', true), false],
]);

it('reports the prefix the client Laravel builds uses', function (string $client, Closure $configure, string $suffix): void {
    config()->set('database.redis.client', $client);
    config()->set('database.redis.probe', ['host' => redisModelHost(), 'port' => redisModelPort(), 'database' => 0]);
    config()->set('horizon-observability.redis.connection', 'probe');
    $configure();

    $raw = redisModelManager()->connection('probe')->client();
    $prefix = $raw instanceof Redis ? (string) $raw->getOption(Redis::OPT_PREFIX) : (string) $raw->getOptions()->prefix?->getPrefix();

    expect($prefix)->toBe(TestCase::redisPrefix().$suffix)
        ->and(redisModelResult('Storage', 'Connection')?->message)->toEndWith(sprintf(', prefix "%s")', $prefix));
})->with([
    'database.redis.options' => ['phpredis', static fn () => config()->set('database.redis.options.prefix', TestCase::redisPrefix().'global:'), 'global:'],
    'the connection over database.redis.options (phpredis)' => ['phpredis', static function (): void {
        config()->set('database.redis.options.prefix', TestCase::redisPrefix().'global:');
        config()->set('database.redis.probe.prefix', TestCase::redisPrefix().'connection:');
    }, 'connection:'],
    'the connection over database.redis.options (predis)' => ['predis', static function (): void {
        config()->set('database.redis.options.prefix', TestCase::redisPrefix().'global:');
        config()->set('database.redis.probe.prefix', TestCase::redisPrefix().'connection:');
    }, 'connection:'],
    "the connection's options over database.redis.options" => ['predis', static function (): void {
        config()->set('database.redis.options.prefix', TestCase::redisPrefix().'global:');
        config()->set('database.redis.probe.options', ['prefix' => TestCase::redisPrefix().'own:']);
    }, 'own:'],
]);
