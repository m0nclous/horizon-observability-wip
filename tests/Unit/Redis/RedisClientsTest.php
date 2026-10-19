<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Redis\RedisClients;
use Illuminate\Redis\RedisManager;

/**
 * @return array<array-key, mixed>
 */
function privateRedisConfig(RedisManager $manager): array
{
    return (fn (): array => $this->config)->call($manager);
}

beforeEach(function (): void {
    config()->set('database.redis.client', 'phpredis');
    config()->set('database.redis.options', ['prefix' => 'app:', 'serializer' => 1, 'compression' => 2, 'persistent' => true]);
    config()->set('database.redis.default', ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0, 'timeout' => 5.0, 'read_timeout' => 0.5, 'persistent' => true, 'persistent_id' => 'app']);
    config()->set('database.redis.horizon', ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0, 'options' => ['prefix' => 'app_horizon:', 'serializer' => 1, 'read_timeout' => 60]]);
    config()->set('database.redis.clusters', [
        'options' => ['cluster' => 'redis', 'compression' => 3],
        'metrics' => [['host' => '10.0.0.1', 'port' => 7000, 'read_timeout' => 9], ['host' => '10.0.0.2', 'port' => 7000], 'options' => ['prefix' => '{m}:']],
    ]);
    config()->set('horizon-observability.redis.worker_timeout', 1.0);
    config()->set('horizon-observability.redis.scrape_timeout', 2.0);
    $this->app->forgetInstance(Config::class);
});

it('caps the storage timeouts, forces non-persistent and removes serializer and compression', function (): void {
    $config = privateRedisConfig(app(RedisClients::class)->manager());
    $storage = $config[RedisClients::STORAGE];

    expect($config['client'])->toBe('phpredis')
        ->and($config['options'])->toBe(['prefix' => 'app:', 'persistent' => true])
        ->and($storage['timeout'])->toBe(1.0)
        ->and($storage['read_timeout'])->toBe(1.0)
        ->and($storage['read_write_timeout'])->toBe(1.0)
        ->and($storage['persistent'])->toBeFalse()
        ->and($storage['options'])->toBe(['prefix' => 'app_horizon:', 'read_timeout' => 1.0, 'persistent' => false, 'timeout' => 1.0, 'read_write_timeout' => 1.0])
        ->and(app(RedisClients::class)->storageConnection())->toBe('horizon');
});

it('gives every app connection a reader with the scrape timeout', function (): void {
    $config = privateRedisConfig(app(RedisClients::class)->manager());
    $reader = $config[RedisClients::READER.'default'];

    expect($reader['timeout'])->toBe(2.0)
        ->and($reader['read_timeout'])->toBe(0.5)
        ->and($reader['persistent'])->toBeFalse()
        ->and($reader)->not->toHaveKey('persistent_id')
        ->and($config)->toHaveKey(RedisClients::READER.'horizon')
        ->and($config)->not->toHaveKey(RedisClients::READER.'options')
        ->and($config)->not->toHaveKey(RedisClients::READER.'client');
});

it('copies cluster connections with per-cluster options', function (): void {
    $clusters = privateRedisConfig(app(RedisClients::class)->manager())['clusters'];
    $reader = $clusters[RedisClients::READER.'metrics'];

    expect($clusters['options'])->toBe(['cluster' => 'redis'])
        ->and($reader[0]['read_timeout'])->toBe(2.0)
        ->and($reader[0]['timeout'])->toBe(2.0)
        ->and($reader[0]['persistent'])->toBeFalse()
        ->and($reader[1]['host'])->toBe('10.0.0.2')
        // The predis parameters too, which a node learned from the slot map gets.
        ->and($reader['options'])->toBe(['prefix' => '{m}:', 'persistent' => false, 'timeout' => 2.0, 'read_timeout' => 2.0, 'read_write_timeout' => 2.0, 'parameters' => ['persistent' => false, 'timeout' => 2.0, 'read_timeout' => 2.0, 'read_write_timeout' => 2.0]]);
});

it('builds the storage client from a cluster connection', function (): void {
    config()->set('horizon-observability.redis.connection', 'metrics');
    $this->app->forgetInstance(Config::class);
    $config = privateRedisConfig(app(RedisClients::class)->manager());

    expect($config)->not->toHaveKey(RedisClients::STORAGE)
        ->and($config['clusters'][RedisClients::STORAGE]['options']['timeout'])->toBe(1.0)
        ->and(app(RedisClients::class)->isCluster('metrics'))->toBeTrue()
        ->and(app(RedisClients::class)->isCluster('default'))->toBeFalse()
        ->and(app(RedisClients::class)->isCluster('options'))->toBeFalse()
        ->and(app(RedisClients::class)->isCluster('missing'))->toBeFalse();
});

it('has no storage client for a missing connection', function (): void {
    config()->set('horizon-observability.redis.connection', 'missing');
    $this->app->forgetInstance(Config::class);

    expect(fn () => app(RedisClients::class)->storage())->toThrow(InvalidArgumentException::class);
});

it('never touches the global config and connects lazily', function (): void {
    $before = config('database.redis');
    $clients = app(RedisClients::class);
    $clients->manager();

    expect(config('database.redis'))->toBe($before)
        ->and($clients->manager()->connections())->toBeEmpty();
});
