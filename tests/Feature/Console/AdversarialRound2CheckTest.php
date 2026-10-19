<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\HorizonObservabilityServiceProvider;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Support\CheckResult;
use DF\HorizonObservability\Support\CheckStatus;
use DF\HorizonObservability\Support\Diagnostics;
use DF\HorizonObservability\Tests\Support\Apps;
use Illuminate\Foundation\Application;
use Laravel\Horizon\HorizonServiceProvider;

/*
| T14 QA round 2 (adversarial): the Timeouts block of horizon-observability:check (T14 addition of the phase B review:
| "check warns when the default cache store [...] has no connect/read timeout (a hung store can cost a scrape its
| budget)").
|
| Laravel 13's skeleton config/cache.php ships two store drivers the block does not know (Illuminate\Cache\CacheManager
| ::createFailoverDriver() and ::createStorageDriver(), both in the Laravel 13 the suite resolves):
| - `failover` tries its `stores` in order, so a Redis store without timeouts in front still holds the scrape until
|   the socket default;
| - `storage` keeps the cache on a filesystem disk, which may be S3 (network).
| check reports both as "(failover, no network)" / "(storage, no network)" with status ok: a false all-clear. A store it
| cannot judge must not read as "no network"; a failover store is as safe as its first networked store.
*/

pest()->group('redis');

/**
 * The "Default cache store" result of the Timeouts block.
 */
function adversarialRound2CacheTimeout(): CheckResult
{
    app()->forgetInstance(Config::class);
    app()->forgetInstance(RedisClients::class);

    foreach (app(Diagnostics::class)->check() as $result) {
        if ($result->section === 'Timeouts' && $result->name === 'Default cache store') {
            return $result;
        }
    }

    throw new RuntimeException('check has no Timeouts / Default cache store result.');
}

beforeEach(function (): void {
    config()->set('horizon-observability.token', 'adversarial-round2-token-0123456789');
    config()->set('database.redis.client', 'phpredis');
    config()->set('database.redis.cache', ['host' => '127.0.0.1', 'port' => 6379, 'database' => 1]);
    config()->set('cache.stores.redis', ['driver' => 'redis', 'connection' => 'cache']);
});

it('still warns about a Redis store without timeouts (the known case)', function (): void {
    config()->set('cache.default', 'redis');

    expect(adversarialRound2CacheTimeout()->status)->toBe(CheckStatus::Warning);
});

it('does not call a failover store with a store without timeouts in front "no network"', function (array $stores): void {
    // Laravel 13's config/cache.php: 'failover' => ['driver' => 'failover', 'stores' => ['database', 'array']].
    config()->set('database.connections.mysql_no_timeout', ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'app']);
    config()->set('cache.stores.database', ['driver' => 'database', 'connection' => 'mysql_no_timeout', 'table' => 'cache']);
    config()->set('cache.stores.failover', ['driver' => 'failover', 'stores' => $stores]);
    config()->set('cache.default', 'failover');

    $result = adversarialRound2CacheTimeout();

    expect($result->status)->not->toBe(CheckStatus::Ok, 'check says: '.$result->message)
        ->and($result->message)->not->toContain('no network');
})->with([
    'redis without timeouts, then array' => [['redis', 'array']],
    'the skeleton: database (mysql without PDO::ATTR_TIMEOUT), then array' => [['database', 'array']],
]);

it('does not call a storage store on an S3 disk "no network"', function (): void {
    config()->set('filesystems.disks.s3', ['driver' => 's3', 'bucket' => 'cache', 'region' => 'eu-west-1']);
    config()->set('cache.stores.storage', ['driver' => 'storage', 'disk' => 's3', 'path' => 'cache']);
    config()->set('cache.default', 'storage');

    $result = adversarialRound2CacheTimeout();

    expect($result->message)->not->toContain('no network');
});

/*
| A package domain written with its scheme. EndpointUrl drops the scheme ("as Route::getDomain() drops it") and the
| routes answer on the host, but the Routes check builds 'http://'.$config->domain.$uri, i.e.
| http://https://metrics.internal/horizon/observability/metrics, which matches no route: check reports every endpoint
| as "no route answers it" (error, exit 1, and install exits 1) with the fix "Register the package provider".
*/
it('checks the route precedence on the host of a domain written with its scheme', function (): void {
    [$answers, $routes] = Apps::inspect(
        [HorizonServiceProvider::class, HorizonObservabilityServiceProvider::class],
        ['horizon-observability.domain' => 'https://metrics.internal', 'horizon-observability.token' => 'adversarial-round2-token-0123456789'],
        static fn (Application $app): array => [
            Apps::match($app['router']->getRoutes(), 'https://metrics.internal/horizon/observability/metrics'),
            array_values(array_map(
                static fn (CheckResult $result): string => $result->status->value.' '.$result->name.': '.$result->message,
                array_filter($app->make(Diagnostics::class)->check(), static fn (CheckResult $result): bool => $result->section === 'Routes'),
            )),
        ],
    );

    // The routes answer on the domain's host.
    expect($answers)->toBe('horizon-observability.metrics')
        ->and(implode("\n", $routes))->not->toContain('error ', implode("\n", $routes))
        ->and($routes[0])->toStartWith('ok Precedence');
});
