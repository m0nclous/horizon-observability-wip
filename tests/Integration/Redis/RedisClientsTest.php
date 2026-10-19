<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Event;
use Predis\Client;
use Predis\Connection\Cluster\RedisCluster;

pest()->group('redis');

it('writes the storage keys under the horizon connection prefix', function (): void {
    app(RedisClients::class)->storage()->set('storage-probe', '1');
    app(RedisClients::class)->reader('default')->set('reader-probe', '1');

    expect(RedisKeys::all(TestCase::redisPrefix()))->toBe([
        TestCase::redisPrefix().'horizon:storage-probe',
        TestCase::redisPrefix().'reader-probe',
    ]);
});

it('stores raw values whatever the app serializer', function (): void {
    if (config('database.redis.client') !== 'phpredis') {
        $this->markTestSkipped('The serializer option exists on phpredis only.');
    }

    config()->set('database.redis.horizon.options.serializer', Redis::SERIALIZER_PHP);
    $this->app->forgetInstance(RedisClients::class);

    app(RedisClients::class)->storage()->set('raw', 'value');

    expect(RedisKeys::client()->get(TestCase::redisPrefix().'horizon:raw'))->toBe('value');
});

it('applies the private timeouts and never a persistent socket', function (): void {
    config()->set('database.redis.horizon.persistent', true);
    $this->app->forgetInstance(RedisClients::class);
    $storage = app(RedisClients::class)->storage();
    $storage->ping();
    $client = $storage->client();

    if ($client instanceof Redis) {
        expect($client->getTimeout())->toBe(1.0)
            ->and($client->getReadTimeout())->toBe(1.0)
            ->and($client->getPersistentID())->toBeIn([null, false, '']);
    } else {
        $parameters = $client->getConnection()->getParameters();

        expect((float) $parameters->timeout)->toBe(1.0)
            ->and((float) $parameters->read_write_timeout)->toBe(1.0)
            ->and((bool) $parameters->persistent)->toBeFalse();
    }
});

it('caps the timeouts of every node a predis cluster reader connects to, also those it learns from the slot map', function (string $cluster): void {
    // predis reads connection parameters only: each listed node's, and for a node of a Redis Cluster's slot map that
    // is not listed (e.g. seeds by host name, a cluster announcing IPs) the `parameters` option (T14 QA round 3).
    config()->set('database.redis.client', 'predis');
    config()->set('database.redis.options.cluster', $cluster);
    config()->set('database.redis.options.parameters', ['password' => 'from-the-options', 'read_write_timeout' => 30]);
    config()->set('database.redis.clusters.probe', [['host' => '127.0.0.1', 'port' => 1, 'persistent' => true], ['host' => '127.0.0.2', 'port' => 1]]);
    $this->app->forgetInstance(RedisClients::class);

    $connection = app(RedisClients::class)->reader('probe')->client()->getConnection();
    $parameters = [$connection->getConnectionById('127.0.0.1:1')->getParameters(), $connection->getConnectionById('127.0.0.2:1')->getParameters()];

    if ($connection instanceof RedisCluster) {
        $parameters[] = (new ReflectionMethod($connection, 'createConnection'))->invoke($connection, '203.0.113.9:7001')->getParameters();
    }

    expect($parameters)->toHaveCount($cluster === 'redis' ? 3 : 2);

    foreach ($parameters as $node) {
        expect((float) $node->timeout)->toBe(2.0)
            ->and((float) $node->read_write_timeout)->toBe(2.0)
            ->and(filter_var($node->persistent, FILTER_VALIDATE_BOOLEAN))->toBeFalse()
            // The app's own parameters stay.
            ->and($node->password)->toBe('from-the-options');
    }
})->with(['redis', 'predis'])->skip(fn (): bool => ! class_exists(Client::class), 'predis is not installed');

it('bypasses the app Redis events unless the private manager enables them', function (): void {
    $seen = [];
    app('redis')->enableEvents();
    Event::listen(CommandExecuted::class, function (CommandExecuted $event) use (&$seen): void {
        $seen[] = $event->connectionName;
    });

    app(RedisClients::class)->storage()->ping();
    expect($seen)->toBe([]);

    $this->app->forgetInstance(RedisClients::class);
    app(RedisClients::class)->manager()->enableEvents();
    app(RedisClients::class)->storage()->ping();

    expect($seen)->toBe([RedisClients::STORAGE]);
});

it('fails fast on unreachable storage', function (): void {
    config()->set('database.redis.horizon', ['host' => '127.0.0.1', 'port' => 1, 'database' => 0]);
    config()->set('horizon-observability.redis.worker_timeout', 0.2);
    $this->app->forgetInstance(Config::class);
    $this->app->forgetInstance(RedisClients::class);
    $started = hrtime(true);

    try {
        app(RedisClients::class)->storage()->ping();
        $this->fail('No connection error.');
    } catch (Throwable) {
        expect((hrtime(true) - $started) / 1e9)->toBeLessThan(2.0);
    }
});
