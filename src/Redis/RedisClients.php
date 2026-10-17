<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Redis;

use DF\HorizonObservability\Config\Config;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;

/**
 * Private Redis clients of the package (Arch Q6.6), held by a container singleton.
 *
 * A private {@see RedisManager} is built lazily on first use from a filtered copy of `database.redis`; the global
 * config is never mutated, so provider order does not matter. Every private connection:
 * - has `timeout`, `read_timeout` and `read_write_timeout` capped at the package timeout (an unset or infinite value
 *   becomes the cap);
 * - is never persistent, so the app's persistent socket is never shared;
 * - has no `serializer` or `compression`, so stored values are raw and deterministic.
 *
 * {@see self::storage()} is the package storage (`redis.connection`, `horizon` by default) with
 * `redis.worker_timeout`; {@see self::reader()} is any app connection (standalone or `clusters.*`) with
 * `redis.scrape_timeout`, for queue state, delayed jobs and Horizon job lists. Private clients bypass the app's
 * `Redis::listen()`; tests call `manager()->enableEvents()`.
 *
 * Nothing connects before a command runs.
 *
 * @internal
 */
final class RedisClients
{
    /**
     * Private connection name of the storage client.
     */
    public const STORAGE = 'horizon-observability:storage';

    /**
     * Private connection name prefix of the reader clients.
     */
    public const READER = 'horizon-observability:reader:';

    private const RESERVED = ['client', 'options', 'clusters'];

    private const TIMEOUTS = ['timeout', 'read_timeout', 'read_write_timeout'];

    private const UNSAFE_OPTIONS = ['serializer', 'compression', 'compression_level', 'persistent_id'];

    private ?RedisManager $manager = null;

    public function __construct(private readonly Application $app) {}

    /**
     * The storage connection with `redis.worker_timeout`, for the worker-side writes; the scrape, job-list and live
     * paths read the package keys through {@see self::reader()} of {@see self::storageConnection()} (Arch Q1.3).
     */
    public function storage(): Connection
    {
        return $this->manager()->connection(self::STORAGE);
    }

    /**
     * A short-timeout reader of an app connection (`database.redis.<name>` or `database.redis.clusters.<name>`).
     */
    public function reader(string $connection): Connection
    {
        return $this->manager()->connection(self::READER.$connection);
    }

    /**
     * The private manager (tests: `enableEvents()`, `listen()`).
     */
    public function manager(): RedisManager
    {
        if (! $this->manager instanceof RedisManager) {
            $source = $this->repository()->get('database.redis');
            $source = is_array($source) ? $source : [];

            $this->manager = new RedisManager(
                $this->app,
                is_string($source['client'] ?? null) ? $source['client'] : 'phpredis',
                $this->privateConfig($source),
            );
        }

        return $this->manager;
    }

    /**
     * Whether the app connection is a Redis Cluster (`database.redis.clusters.<name>` without a standalone entry of
     * the same name, which Laravel would resolve first).
     */
    public function isCluster(string $connection): bool
    {
        $config = $this->repository();

        return ! $this->isStandalone($connection)
            && $connection !== 'options'
            && is_array($config->get('database.redis.clusters.'.$connection));
    }

    /**
     * Name of the app connection the storage client copies (`redis.connection`).
     */
    public function storageConnection(): string
    {
        return $this->config()->redisConnection;
    }

    /**
     * @param  array<array-key, mixed>  $source
     * @return array<array-key, mixed>
     */
    private function privateConfig(array $source): array
    {
        $settings = $this->config();
        $clusters = is_array($source['clusters'] ?? null) ? $source['clusters'] : [];

        $config = [
            'client' => is_string($source['client'] ?? null) ? $source['client'] : 'phpredis',
            'options' => $this->safeOptions(is_array($source['options'] ?? null) ? $source['options'] : []),
        ];
        $privateClusters = [];

        if (is_array($clusters['options'] ?? null)) {
            $privateClusters['options'] = $this->safeOptions($clusters['options']);
        }

        foreach ($source as $name => $connection) {
            if (! in_array($name, self::RESERVED, true) && is_array($connection)) {
                $config[self::READER.$name] = $this->standalone($connection, $settings->scrapeTimeout);
            }
        }

        foreach ($clusters as $name => $nodes) {
            if ($name !== 'options' && is_array($nodes) && ! isset($config[self::READER.$name])) {
                $privateClusters[self::READER.$name] = $this->cluster($nodes, $settings->scrapeTimeout);
            }
        }

        $storage = $settings->redisConnection;

        if ($this->isStandalone($storage) && is_array($source[$storage] ?? null)) {
            $config[self::STORAGE] = $this->standalone($source[$storage], $settings->workerTimeout);
        } elseif (is_array($clusters[$storage] ?? null) && $storage !== 'options') {
            $privateClusters[self::STORAGE] = $this->cluster($clusters[$storage], $settings->workerTimeout);
        }

        $config['clusters'] = $privateClusters;

        return $config;
    }

    private function isStandalone(string $connection): bool
    {
        return ! in_array($connection, self::RESERVED, true) && is_array($this->repository()->get('database.redis.'.$connection));
    }

    private function repository(): Repository
    {
        /** @var Repository */
        return $this->app->make('config');
    }

    private function config(): Config
    {
        return $this->app->make(Config::class);
    }

    /**
     * @param  array<array-key, mixed>  $connection
     * @return array<array-key, mixed>
     */
    private function standalone(array $connection, float $timeout): array
    {
        $connection = $this->safeOptions($connection);
        $options = $this->safeOptions(is_array($connection['options'] ?? null) ? $connection['options'] : []);
        $connection['persistent'] = false;
        $options['persistent'] = false;

        foreach (self::TIMEOUTS as $key) {
            $connection[$key] = $this->cap($options[$key] ?? $connection[$key] ?? null, $timeout);
            // Laravel merges connection options over the connection config, so the cap must win there too.
            $options[$key] = $connection[$key];
        }

        $connection['options'] = $options;

        return $connection;
    }

    /**
     * @param  array<array-key, mixed>  $nodes
     * @return array<array-key, mixed>
     */
    private function cluster(array $nodes, float $timeout): array
    {
        $options = $this->safeOptions(is_array($nodes['options'] ?? null) ? $nodes['options'] : []);
        unset($nodes['options']);
        $options['persistent'] = false;

        foreach (self::TIMEOUTS as $key) {
            $options[$key] = $this->cap($options[$key] ?? null, $timeout);
        }

        foreach ($nodes as $index => $node) {
            if (! is_array($node)) {
                continue;
            }

            $node = $this->safeOptions($node);
            $node['persistent'] = false;

            foreach (self::TIMEOUTS as $key) {
                $node[$key] = $this->cap($node[$key] ?? null, $timeout);
            }

            $nodes[$index] = $node;
        }

        $nodes['options'] = $options;

        return $nodes;
    }

    /**
     * @param  array<array-key, mixed>  $options
     * @return array<array-key, mixed>
     */
    private function safeOptions(array $options): array
    {
        foreach (self::UNSAFE_OPTIONS as $key) {
            unset($options[$key]);
        }

        return $options;
    }

    /**
     * min(existing, cap); an unset, zero or negative (infinite) value becomes the cap.
     */
    private function cap(mixed $existing, float $cap): float
    {
        return is_numeric($existing) && (float) $existing > 0 ? min((float) $existing, $cap) : $cap;
    }
}
