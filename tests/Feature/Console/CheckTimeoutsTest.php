<?php

declare(strict_types=1);

use DF\HorizonObservability\Batches\BatchReader;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Discovery\JobClassCache;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Support\CheckResult;
use DF\HorizonObservability\Support\Diagnostics;
use DF\HorizonObservability\Support\FailureReporter;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Predis\Client;

/*
| The Timeouts block of horizon-observability:check: it warns when the default cache store or the batching database
| connection has no connect/read timeout, since a hung store can cost a scrape its budget. The default cache store
| backs the throttled logs, the batching detection and the job-class runtime cache. Every driver Laravel 13 ships is
| judged:
| - redis and database by their connection's timeouts (PDO::ATTR_TIMEOUT, login_timeout on SQL Server);
| - failover by the stores it tries in order, up to the first local one (an earlier check read it as "no network");
| - storage by its filesystem disk (s3 by its HTTP timeouts, ftp and sftp by their timeout, scoped by its parent);
| - memcached and dynamodb as info, array, file, null, apc, octane and session as no network;
| - any other driver as info, never as "no network".
| Messages and fix lines are asserted exactly.
*/

pest()->group('redis');

/**
 * The Timeouts result named $name, as [status, message, fix].
 *
 * @return array{0: string, 1: string, 2: string|null}
 */
function checkTimeout(string $name = 'Default cache store'): array
{
    app()->forgetInstance(Config::class);
    app()->forgetInstance(RedisClients::class);

    foreach (app(Diagnostics::class)->check() as $result) {
        if ($result->section === 'Timeouts' && $result->name === $name) {
            return [$result->status->value, $result->message, $result->fix];
        }
    }

    throw new RuntimeException('check has no Timeouts / '.$name.' result.');
}

/**
 * @return list<string> the names of the Timeouts results
 */
function checkTimeoutNames(): array
{
    app()->forgetInstance(Config::class);

    return array_values(array_map(
        static fn (CheckResult $result): string => $result->name,
        array_filter(app(Diagnostics::class)->check(), static fn (CheckResult $result): bool => $result->section === 'Timeouts'),
    ));
}

beforeEach(function (): void {
    config()->set('horizon-observability.token', 'check-timeouts-token-0123456789');
    config()->set('database.redis.client', 'phpredis');
    config()->set('database.redis.cache', ['host' => '127.0.0.1', 'port' => 6379, 'database' => 1]);
    config()->set('database.redis.cache_timeouts', ['host' => '127.0.0.1', 'port' => 6379, 'database' => 1, 'timeout' => 1.5, 'read_timeout' => 2]);
    config()->set('cache.stores.redis', ['driver' => 'redis', 'connection' => 'cache']);
    config()->set('cache.stores.redis_timeouts', ['driver' => 'redis', 'connection' => 'cache_timeouts']);
    config()->set('database.connections.mysql_bare', ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'app']);
    config()->set('database.connections.mysql_timeout', ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'app', 'options' => [PDO::ATTR_TIMEOUT => 2]]);
    config()->set('database.connections.sqlsrv_bare', ['driver' => 'sqlsrv', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'app']);
    config()->set('database.connections.sqlsrv_timeout', ['driver' => 'sqlsrv', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'app', 'login_timeout' => 3]);
});

it('judges a database store by the connect timeout of its connection', function (string $connection, string $status, string $message, ?string $fix): void {
    config()->set('cache.stores.database', ['driver' => 'database', 'connection' => $connection, 'table' => 'cache']);
    config()->set('cache.default', 'database');

    expect(checkTimeout())->toBe([$status, $message, $fix]);
})->with([
    'sqlite' => ['testing', 'ok', 'database on connection testing (sqlite, no network)', null],
    'mysql without a timeout' => ['mysql_bare', 'warning', 'database on connection mysql_bare (mysql) has no connect timeout (PDO::ATTR_TIMEOUT): an unreachable server can cost a scrape its budget', 'Add PDO::ATTR_TIMEOUT => 2 to the options of database.connections.mysql_bare in config/database.php.'],
    'mysql with PDO::ATTR_TIMEOUT' => ['mysql_timeout', 'ok', 'database on connection mysql_timeout (mysql) has a connect timeout of 2 s', null],
    'sqlsrv without login_timeout' => ['sqlsrv_bare', 'warning', 'database on connection sqlsrv_bare (sqlsrv) has no login_timeout: an unreachable server can cost a scrape its budget', "Add 'login_timeout' => 2 to database.connections.sqlsrv_bare in config/database.php."],
    'sqlsrv with login_timeout' => ['sqlsrv_timeout', 'ok', 'database on connection sqlsrv_timeout (sqlsrv) has a login timeout of 3 s', null],
    'not configured' => ['missing', 'error', 'database on connection missing is not configured', 'Add database.connections.missing to config/database.php.'],
]);

it('uses the default database connection for a database store without one', function (): void {
    config()->set('cache.stores.database', ['driver' => 'database', 'table' => 'cache']);
    config()->set('cache.default', 'database');
    config()->set('database.default', 'sqlsrv_bare');

    expect(checkTimeout()[1])->toStartWith('database on connection sqlsrv_bare (sqlsrv) has no login_timeout');
});

it('judges the batching database by its connect timeout', function (string $connection, string $status, string $message): void {
    config()->set('queue.batching.database', $connection);

    expect(checkTimeoutNames())->toBe(['Horizon connection', 'Default cache store', 'Batching database'])
        ->and(array_slice(checkTimeout('Batching database'), 0, 2))->toBe([$status, $message]);

    config()->set('horizon-observability.collectors.batches', false);

    expect(checkTimeoutNames())->toBe(['Horizon connection', 'Default cache store']);
})->with([
    'sqlite' => ['testing', 'ok', 'connection testing (sqlite, no network)'],
    'sqlsrv without login_timeout' => ['sqlsrv_bare', 'warning', 'connection sqlsrv_bare (sqlsrv) has no login_timeout: an unreachable server can cost a scrape its budget'],
    'sqlsrv with login_timeout' => ['sqlsrv_timeout', 'ok', 'connection sqlsrv_timeout (sqlsrv) has a login timeout of 3 s'],
    'mysql with PDO::ATTR_TIMEOUT' => ['mysql_timeout', 'ok', 'connection mysql_timeout (mysql) has a connect timeout of 2 s'],
]);

it('skips the batching database when the batching driver is not database', function (): void {
    config()->set('queue.batching.driver', 'dynamodb');

    expect(checkTimeoutNames())->toBe(['Horizon connection', 'Default cache store']);
});

it('reports memcached and dynamodb stores as info about their client timeouts', function (string $driver): void {
    config()->set('cache.stores.remote', ['driver' => $driver]);
    config()->set('cache.default', 'remote');

    expect(checkTimeout())->toBe(['info', "remote ({$driver}): its client timeouts apply to the package's throttled logs and detection caches", null]);
})->with(['memcached', 'dynamodb']);

it('reports the local drivers as no network', function (string $driver): void {
    config()->set('cache.stores.local', ['driver' => $driver]);
    config()->set('cache.default', 'local');

    expect(checkTimeout())->toBe(['ok', "local ({$driver}, no network)", null]);
})->with(['array', 'file', 'null', 'apc', 'octane', 'session']);

it('reports a driver it does not know as info, never as no network', function (): void {
    config()->set('cache.stores.custom', ['driver' => 'mongodb']);
    config()->set('cache.default', 'custom');

    expect(checkTimeout())->toBe([
        'info',
        'custom (mongodb): a driver this check does not know, so its timeouts are not checked',
        'Give its client connect and read timeouts of about 2 s: a hung store can cost a scrape its budget.',
    ]);
});

it('warns about a default store that is not configured', function (): void {
    config()->set('cache.default', 'gone');

    expect(checkTimeout())->toBe([
        'warning',
        'the cache store gone is not configured: every call to it fails',
        'Add cache.stores.gone to config/cache.php, or set CACHE_STORE to a configured store.',
    ]);
});

it('judges a redis store by its connection, and reports a missing connection', function (): void {
    config()->set('cache.default', 'redis_timeouts');

    expect(checkTimeout())->toBe(['ok', 'redis_timeouts (redis cache_timeouts) has timeout 1.5 s, read_timeout 2 s', null]);

    config()->set('cache.stores.redis_gone', ['driver' => 'redis', 'connection' => 'gone']);
    config()->set('cache.default', 'redis_gone');

    expect(checkTimeout())->toBe([
        'error',
        'redis_gone uses the Redis connection [gone], which is not configured',
        'Add database.redis.gone to config/database.php or point cache.stores.redis_gone.connection at an existing connection.',
    ]);
});

it('reads the predis timeout keys of a redis store', function (): void {
    config()->set('database.redis.client', 'predis');
    config()->set('cache.default', 'redis');

    expect(checkTimeout())->toBe([
        'warning',
        'redis (redis cache) has no read_write_timeout: a hung store can cost a scrape its budget',
        "Add 'read_write_timeout' => 2.0 to database.redis.cache in config/database.php.",
    ]);

    config()->set('database.redis.cache.timeout', 1);
    config()->set('database.redis.cache.read_write_timeout', 2);

    expect(checkTimeout())->toBe(['ok', 'redis (redis cache) has timeout 1 s, read_write_timeout 2 s', null]);
})->skip(fn (): bool => ! class_exists(Client::class), 'predis is not installed');

it('judges a failover store by the stores it tries before the first local one', function (array $stores, string $status, string $message, ?string $fix): void {
    config()->set('cache.stores.database', ['driver' => 'database', 'connection' => 'mysql_bare', 'table' => 'cache']);
    config()->set('cache.stores.sqlite', ['driver' => 'database', 'connection' => 'testing', 'table' => 'cache']);
    config()->set('cache.stores.array', ['driver' => 'array']);
    config()->set('cache.stores.failover', ['driver' => 'failover', 'stores' => $stores]);
    config()->set('cache.default', 'failover');

    expect(checkTimeout())->toBe([$status, $message, $fix]);
})->with([
    // Laravel 13's skeleton config/cache.php, on a MySQL connection without a timeout.
    'database, then array' => [['database', 'array'], 'warning', 'failover (failover: database, then array): database on connection mysql_bare (mysql) has no connect timeout (PDO::ATTR_TIMEOUT): an unreachable server can cost a scrape its budget', 'Add PDO::ATTR_TIMEOUT => 2 to the options of database.connections.mysql_bare in config/database.php.'],
    'redis without timeouts, then array' => [['redis', 'array'], 'warning', 'failover (failover: redis, then array): redis (redis cache) has no timeout or read_timeout: a hung store can cost a scrape its budget', "Add 'timeout' => 2.0 and 'read_timeout' => 2.0 to database.redis.cache in config/database.php."],
    // Each store in front of a local one holds the call: the one with timeouts fails over to the one without.
    'redis with timeouts, then database' => [['redis_timeouts', 'database'], 'warning', 'failover (failover: redis_timeouts, then database): database on connection mysql_bare (mysql) has no connect timeout (PDO::ATTR_TIMEOUT): an unreachable server can cost a scrape its budget', 'Add PDO::ATTR_TIMEOUT => 2 to the options of database.connections.mysql_bare in config/database.php.'],
    'redis with timeouts, then sqlite' => [['redis_timeouts', 'sqlite'], 'ok', 'failover (failover: redis_timeouts, then sqlite): redis_timeouts (redis cache_timeouts) has timeout 1.5 s, read_timeout 2 s; sqlite on connection testing (sqlite, no network)', null],
    // A local store answers every call: the stores after it are never reached.
    'array, then redis' => [['array', 'redis'], 'ok', 'failover (failover: array): array (array, no network)', null],
    'a store it does not know' => [['custom', 'array'], 'warning', 'failover (failover: custom, then array): the cache store custom is not configured: every call to it fails', 'Add cache.stores.custom to config/cache.php, or remove it from cache.stores.failover.stores.'],
    'no stores' => [[], 'warning', 'failover (failover) lists no stores: every call to it fails', "List the stores to try in cache.stores.failover.stores, e.g. ['redis', 'array']."],
]);

it('keeps the info of a failover store over a store it cannot judge', function (): void {
    config()->set('cache.stores.memcached', ['driver' => 'memcached']);
    config()->set('cache.stores.failover', ['driver' => 'failover', 'stores' => ['memcached', 'file']]);
    config()->set('cache.stores.file', ['driver' => 'file', 'path' => '/tmp']);
    config()->set('cache.default', 'failover');

    expect(checkTimeout())->toBe(['info', "failover (failover: memcached, then file): memcached (memcached): its client timeouts apply to the package's throttled logs and detection caches; file (file, no network)", null]);
});

it('judges a failover store inside a failover store', function (): void {
    config()->set('cache.stores.inner', ['driver' => 'failover', 'stores' => ['redis', 'array']]);
    config()->set('cache.stores.outer', ['driver' => 'failover', 'stores' => ['inner', 'array']]);
    config()->set('cache.stores.array', ['driver' => 'array']);
    config()->set('cache.default', 'outer');

    expect(checkTimeout())->toBe([
        'warning',
        'outer (failover: inner, then array): inner (failover: redis, then array): redis (redis cache) has no timeout or read_timeout: a hung store can cost a scrape its budget',
        "Add 'timeout' => 2.0 and 'read_timeout' => 2.0 to database.redis.cache in config/database.php.",
    ]);
});

it('reports a failover store that leads back to itself instead of following it', function (array $stores, string $message, string $fix): void {
    // Laravel itself recurses without end on such a store, so the package's own cache calls go to an array store here.
    app()->instance('cache', new class implements CacheFactory
    {
        public function store($name = null): CacheRepository
        {
            return new CacheRepository(new ArrayStore);
        }
    });
    app()->forgetInstance(BatchReader::class);
    app()->forgetInstance(JobClassCache::class);
    app()->forgetInstance(FailureReporter::class);
    config()->set('cache.stores.array', ['driver' => 'array']);
    config()->set('cache.stores.inner', ['driver' => 'failover', 'stores' => ['outer']]);
    config()->set('cache.stores.outer', ['driver' => 'failover', 'stores' => $stores]);
    config()->set('cache.default', 'outer');

    expect(checkTimeout())->toBe(['warning', $message, $fix]);
})->with([
    'itself' => [['outer', 'array'], 'outer (failover: outer): outer lists the failover store outer, which leads back to itself', 'Remove outer from cache.stores.outer.stores.'],
    'through another failover store' => [['inner', 'array'], 'outer (failover: inner, then array): inner (failover: outer): inner lists the failover store outer, which leads back to itself', 'Remove outer from cache.stores.inner.stores.'],
]);

it('judges a storage store by its disk', function (array $disk, string $status, string $message, ?string $fix): void {
    config()->set('filesystems.disks.cache_disk', $disk);
    config()->set('filesystems.disks.parent_s3', ['driver' => 's3', 'bucket' => 'cache', 'region' => 'eu-west-1']);
    config()->set('cache.stores.storage', ['driver' => 'storage', 'disk' => 'cache_disk', 'path' => 'cache']);
    config()->set('cache.default', 'storage');

    expect(checkTimeout())->toBe([$status, $message, $fix]);
})->with([
    'local' => [['driver' => 'local', 'root' => '/tmp'], 'ok', 'storage (storage on disk cache_disk, local, no network)', null],
    's3 without HTTP timeouts' => [['driver' => 's3', 'bucket' => 'cache', 'region' => 'eu-west-1'], 'warning', 'storage (storage on disk cache_disk, s3) has no HTTP connect_timeout or timeout (the AWS SDK waits without a limit): a hung endpoint can cost a scrape its budget', "Add 'http' => ['connect_timeout' => 2, 'timeout' => 2] to filesystems.disks.cache_disk in config/filesystems.php."],
    's3 with one HTTP timeout' => [['driver' => 's3', 'bucket' => 'cache', 'http' => ['connect_timeout' => 1]], 'warning', 'storage (storage on disk cache_disk, s3) has no HTTP timeout (the AWS SDK waits without a limit): a hung endpoint can cost a scrape its budget', "Add 'http' => ['connect_timeout' => 2, 'timeout' => 2] to filesystems.disks.cache_disk in config/filesystems.php."],
    's3 with HTTP timeouts' => [['driver' => 's3', 'bucket' => 'cache', 'http' => ['connect_timeout' => 1, 'timeout' => 2.5]], 'ok', 'storage (storage on disk cache_disk, s3) has an HTTP connect_timeout of 1 s and a timeout of 2.5 s', null],
    'ftp without a timeout' => [['driver' => 'ftp', 'host' => 'ftp.internal'], 'warning', "storage (storage on disk cache_disk, ftp) has no timeout, so the driver's default of 90 s applies: a hung server can cost a scrape its budget", "Add 'timeout' => 2 to filesystems.disks.cache_disk in config/filesystems.php."],
    'sftp without a timeout' => [['driver' => 'sftp', 'host' => 'sftp.internal'], 'warning', "storage (storage on disk cache_disk, sftp) has no timeout, so the driver's default of 10 s applies: a hung server can cost a scrape its budget", "Add 'timeout' => 2 to filesystems.disks.cache_disk in config/filesystems.php."],
    'sftp with a timeout' => [['driver' => 'sftp', 'host' => 'sftp.internal', 'timeout' => 3], 'ok', 'storage (storage on disk cache_disk, sftp) has a timeout of 3 s', null],
    'scoped on s3' => [['driver' => 'scoped', 'disk' => 'parent_s3', 'prefix' => 'cache'], 'warning', 'storage (storage on disk parent_s3, s3) has no HTTP connect_timeout or timeout (the AWS SDK waits without a limit): a hung endpoint can cost a scrape its budget', "Add 'http' => ['connect_timeout' => 2, 'timeout' => 2] to filesystems.disks.parent_s3 in config/filesystems.php."],
    'a disk driver it does not know' => [['driver' => 'gcs', 'bucket' => 'cache'], 'info', 'storage (storage on disk cache_disk, gcs): a disk driver this check does not know, so its timeouts are not checked', 'Give its client connect and read timeouts of about 2 s: a hung disk can cost a scrape its budget.'],
]);

it('uses the default disk for a storage store without one, and reports a disk that is not configured', function (): void {
    config()->set('cache.stores.storage', ['driver' => 'storage', 'path' => 'cache']);
    config()->set('cache.default', 'storage');
    config()->set('filesystems.default', 'local');
    config()->set('filesystems.disks.local', ['driver' => 'local', 'root' => '/tmp']);

    expect(checkTimeout())->toBe(['ok', 'storage (storage on disk local, local, no network)', null]);

    config()->set('cache.stores.storage.disk', 'gone');

    expect(checkTimeout())->toBe([
        'warning',
        'storage (storage on disk gone): the disk is not configured, so every call fails',
        'Add filesystems.disks.gone to config/filesystems.php, or set the disk of cache.stores.storage (CACHE_STORAGE_DISK) to a configured disk.',
    ]);
});

it('keeps the read timeout it suggests for the Horizon connection above the block_for of its queue connections', function (mixed $blockFor, string $fix): void {
    config()->set('horizon.use', 'default');
    config()->set('queue.connections.redis', ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default', 'retry_after' => 90, 'block_for' => $blockFor]);
    // A queue connection on another Redis connection never counts.
    config()->set('queue.connections.elsewhere', ['driver' => 'redis', 'connection' => 'cache', 'queue' => 'default', 'retry_after' => 90, 'block_for' => 30]);

    expect(checkTimeout('Horizon connection'))->toBe(['warning', "horizon (a copy of default) has no timeout or read_timeout: a hung Redis holds every scrape until PHP's default_socket_timeout (".ini_get('default_socket_timeout').' s)', $fix]);
})->with([
    'null, as in a new app' => [null, "Add 'timeout' => 2.0 and 'read_timeout' => 2.0 to database.redis.default in config/database.php; Horizon copies it into its horizon connection."],
    '5 s' => [5, "Add 'timeout' => 2.0 and 'read_timeout' => 7.0 to database.redis.default in config/database.php (the read_timeout stays above the block_for of 5 s of queue.connections.redis); Horizon copies it into its horizon connection."],
    'a string, as env() returns it' => ['2.5', "Add 'timeout' => 2.0 and 'read_timeout' => 4.5 to database.redis.default in config/database.php (the read_timeout stays above the block_for of 2.5 s of queue.connections.redis); Horizon copies it into its horizon connection."],
    '0, without a limit' => [0, "Give queue.connections.redis a block_for of a few seconds, e.g. 5, in config/queue.php (0 blocks without a limit, which any read timeout breaks), then add 'timeout' => 2.0 and 'read_timeout' => 7.0 to database.redis.default in config/database.php; Horizon copies it into its horizon connection."],
]);

it('warns about a read timeout at or below the block_for of a queue connection on the same Redis connection', function (int $blockFor, string $message, string $fix): void {
    config()->set('horizon.use', 'default');
    config()->set('database.redis.horizon.timeout', 1);
    config()->set('database.redis.horizon.read_timeout', 2);
    config()->set('queue.connections.redis', ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default', 'retry_after' => 90, 'block_for' => $blockFor]);

    expect(checkTimeout('Horizon connection'))->toBe(['warning', $message, $fix]);

    // Also in the local environment: the workers fail there too.
    config()->set('app.env', 'local');

    expect(checkTimeout('Horizon connection')[0])->toBe('warning');
})->with([
    'above it' => [5, 'horizon (a copy of default) has a read_timeout of 2 s, not above the block_for of 5 s of the queue connection redis: every blocking pop of an idle worker fails after 2 s', 'Set read_timeout to 7.0 (block_for + 2 s) in database.redis.default in config/database.php; Horizon copies it into its horizon connection.'],
    'equal' => [2, 'horizon (a copy of default) has a read_timeout of 2 s, not above the block_for of 2 s of the queue connection redis: every blocking pop of an idle worker fails after 2 s', 'Set read_timeout to 4.0 (block_for + 2 s) in database.redis.default in config/database.php; Horizon copies it into its horizon connection.'],
    'without a limit' => [0, 'horizon (a copy of default) has a read_timeout of 2 s, but the queue connection redis blocks without a limit (block_for 0): every blocking pop of an idle worker fails after 2 s', 'Give queue.connections.redis a block_for of a few seconds, e.g. 5, in config/queue.php, then set read_timeout to 7.0 in database.redis.default in config/database.php; Horizon copies it into its horizon connection.'],
]);

it('accepts a read timeout above the block_for', function (): void {
    config()->set('horizon.use', 'default');
    config()->set('database.redis.horizon.timeout', 1);
    config()->set('database.redis.horizon.read_timeout', 7);
    config()->set('queue.connections.redis', ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default', 'retry_after' => 90, 'block_for' => 5]);

    expect(checkTimeout('Horizon connection'))->toBe(['ok', 'horizon has timeout 1 s, read_timeout 7 s', null]);
});

it('judges a redis store against the block_for of a queue connection on its Redis connection', function (): void {
    config()->set('cache.default', 'redis');
    config()->set('queue.connections.cache-queue', ['driver' => 'redis', 'connection' => 'cache', 'queue' => 'default', 'retry_after' => 90, 'block_for' => 10]);

    expect(checkTimeout()[2])->toBe("Add 'timeout' => 2.0 and 'read_timeout' => 12.0 to database.redis.cache in config/database.php (the read_timeout stays above the block_for of 10 s of queue.connections.cache-queue).");
});

it('reports missing Redis timeouts as info in the local environment, as on a new app', function (): void {
    config()->set('app.env', 'local');
    config()->set('cache.default', 'redis');

    expect(checkTimeout('Horizon connection')[0])->toBe('info')
        ->and(checkTimeout('Horizon connection')[2])->toBe("Add 'timeout' => 2.0 and 'read_timeout' => 2.0 to database.redis.default in config/database.php; Horizon copies it into its horizon connection.")
        ->and(checkTimeout())->toBe(['info', 'redis (redis cache) has no timeout or read_timeout: a hung store can cost a scrape its budget', "Add 'timeout' => 2.0 and 'read_timeout' => 2.0 to database.redis.cache in config/database.php."]);
});

it('names the url query for a timeout the url sets, which wins over the connection keys', function (string $client, string $url, string $fix): void {
    config()->set('database.redis.client', $client);
    config()->set('database.redis.cache', ['url' => $url, 'host' => '127.0.0.1', 'port' => 6379, 'database' => 1, 'timeout' => 2, 'read_timeout' => 2, 'read_write_timeout' => 2]);
    config()->set('cache.default', 'redis');

    expect(checkTimeout()[2])->toBe($fix);
})->with([
    'phpredis' => ['phpredis', 'redis://127.0.0.1:6379?timeout=0', 'Set timeout=2.0 in the url query of database.redis.cache in config/database.php.'],
    'phpredis, both' => ['phpredis', 'redis://127.0.0.1:6379?timeout=0&read_timeout=0', 'Set timeout=2.0 and read_timeout=2.0 in the url query of database.redis.cache in config/database.php.'],
    'predis' => ['predis', 'tcp://127.0.0.1:6379?read_write_timeout=0', 'Set read_write_timeout=2.0 in the url query of database.redis.cache in config/database.php.'],
]);
