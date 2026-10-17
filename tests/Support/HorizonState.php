<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Support;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;

/**
 * Writes Horizon master and supervisor records exactly like `RedisMasterSupervisorRepository::update()` and
 * `RedisSupervisorRepository::update()` (Horizon 5.50.1), on the app's `horizon` connection, so Horizon's own
 * repositories read them back.
 */
final readonly class HorizonState
{
    public function __construct(private Connection $redis) {}

    public static function using(Application $app): self
    {
        /** @var RedisManager $manager */
        $manager = $app->make('redis');

        return new self($manager->connection('horizon'));
    }

    /**
     * A master supervisor (`master:<name>`, zset `masters`, TTL 15 s).
     *
     * @param  list<string>  $supervisors  full supervisor names (`<master>:<supervisor>`)
     */
    public function master(string $name, string $status = 'running', array $supervisors = [], string $environment = 'testing', int $pid = 1000): self
    {
        $this->redis->hmset('master:'.$name, [
            'name' => $name,
            'environment' => $environment,
            'pid' => (string) $pid,
            'status' => $status,
            'supervisors' => (string) json_encode($supervisors),
        ]);
        $this->redis->zadd('masters', CarbonImmutable::now()->getTimestamp(), $name);
        $this->redis->expire('master:'.$name, 15);

        return $this;
    }

    /**
     * A supervisor (`supervisor:<name>`, zset `supervisors`, TTL 30 s).
     *
     * @param  string  $name  full name `<master>:<supervisor>`
     * @param  array<string, mixed>  $options  {@see self::options()}
     * @param  array<string, int>  $processes  `<connection>:<queue label>` => processes
     */
    public function supervisor(string $name, array $options, array $processes, string $status = 'running', int $pid = 1001): self
    {
        $this->redis->hmset('supervisor:'.$name, [
            'name' => $name,
            'master' => implode(':', explode(':', $name, -1)),
            'pid' => (string) $pid,
            'status' => $status,
            'processes' => (string) json_encode((object) $processes),
            'options' => (string) json_encode($options),
        ]);
        $this->redis->zadd('supervisors', CarbonImmutable::now()->getTimestamp(), $name);
        $this->redis->expire('supervisor:'.$name, 30);

        return $this;
    }

    /**
     * Supervisor options as `SupervisorOptions::toJson()` writes them (the keys the package reads, plus defaults).
     *
     * @return array<string, mixed>
     */
    public static function options(string $name, string $connection = 'redis', string $queue = 'default', string|bool $balance = 'auto', int $minProcesses = 1, int $maxProcesses = 3): array
    {
        return [
            'balance' => $balance,
            'connection' => $connection,
            'queue' => $queue,
            'backoff' => 0,
            'force' => false,
            'maxProcesses' => $maxProcesses,
            'minProcesses' => $minProcesses,
            'maxTries' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'nice' => 0,
            'name' => $name,
            'workersName' => 'default',
            'sleep' => 3,
            'timeout' => 60,
            'balanceCooldown' => 3,
            'balanceMaxShift' => 1,
            'parentId' => 1000,
            'rest' => 0,
            'autoScalingStrategy' => 'time',
            'json' => false,
        ];
    }
}
