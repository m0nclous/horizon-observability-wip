<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests;

use DF\HorizonObservability\HorizonObservability;
use DF\HorizonObservability\HorizonObservabilityServiceProvider;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use Illuminate\Foundation\Application;
use Laravel\Horizon\HorizonServiceProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base test case (Arch 8.4).
 *
 * - Redis host, port and client come from the environment (`REDIS_HOST`, `REDIS_PORT`, `REDIS_CLIENT`; phpunit.xml
 *   defaults to phpredis on 127.0.0.1:6379), so `REDIS_CLIENT=predis vendor/bin/pest --group=redis` runs every
 *   Redis test on predis.
 * - Every key of a test process lives under {@see self::redisPrefix()} (`hot_<pid>:`): the queue connection prefix
 *   and Horizon's prefix (hence the package storage on the `horizon` connection) both start with it.
 * - Tests in the `redis` or `cluster` group start and end with no key under that prefix. Only those keys are
 *   deleted (`SCAN` + `DEL`), never `FLUSHDB`, so a shared Redis server is safe.
 * - The package's static state is reset after every test.
 */
abstract class TestCase extends Orchestra
{
    use WithWorkbench;

    /**
     * The per-process key prefix of every Redis key the tests create.
     */
    public static function redisPrefix(): string
    {
        return 'hot_'.getmypid().':';
    }

    protected function setUp(): void
    {
        $this->setEnvironmentVariable('REDIS_PREFIX', self::redisPrefix());
        $this->setEnvironmentVariable('HORIZON_PREFIX', self::redisPrefix().'horizon:');

        parent::setUp();

        if ($this->usesRedis()) {
            RedisKeys::delete(self::redisPrefix());
        }
    }

    protected function tearDown(): void
    {
        if ($this->usesRedis()) {
            RedisKeys::delete(self::redisPrefix());
        }

        HorizonObservability::flushState();

        parent::tearDown();
    }

    /**
     * Horizon and the package, after the workbench providers. With package discovery (the default) Horizon and the
     * package are discovered too; registering a provider twice is a no-op.
     *
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), HorizonServiceProvider::class, HorizonObservabilityServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
    }

    protected function usesRedis(): bool
    {
        return array_intersect(['redis', 'cluster'], $this->groups()) !== [];
    }

    private function setEnvironmentVariable(string $name, string $value): void
    {
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv($name.'='.$value);
    }
}
