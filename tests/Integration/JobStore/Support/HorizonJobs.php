<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Integration\JobStore\Support;

use DF\HorizonObservability\Tests\Support\QueuePayloads;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\JobPayload;
use RuntimeException;

/**
 * Seeds Horizon's job records through Horizon's own `JobRepository` (pushed, reserved, completed, failed), then
 * pins the times: the hash fields Horizon writes with `microtime(true)` and the scores of its sorted sets, so lists
 * and periods are deterministic. Uses the app's `horizon` connection (Horizon's prefix).
 */
final readonly class HorizonJobs
{
    public function __construct(private JobRepository $jobs, private Connection $redis) {}

    public static function using(Application $app): self
    {
        /** @var RedisManager $manager */
        $manager = $app->make('redis');

        return new self($app->make(JobRepository::class), $manager->connection('horizon'));
    }

    /**
     * A pending job pushed at `$pushedAt`; its id.
     */
    public function pending(string $name = 'App\\Jobs\\SendInvoice', string $queue = 'default', float $pushedAt = 1700000000.0, ?string $id = null, string $connection = 'redis'): string
    {
        $payload = $this->payload($name, $id, 0);
        $this->jobs->pushed($connection, $queue, $payload);
        $this->pin($payload->id(), 'pending_jobs', $pushedAt, ['created_at' => $pushedAt, 'updated_at' => $pushedAt]);

        return $payload->id();
    }

    /**
     * A job pushed, reserved (unless `$reservedAt` is null) and completed; its id.
     */
    public function completed(string $name = 'App\\Jobs\\SendInvoice', string $queue = 'default', float $pushedAt = 1700000000.0, ?float $reservedAt = 1700000001.0, float $completedAt = 1700000002.0, ?string $id = null, string $connection = 'redis'): string
    {
        $payload = $this->payload($name, $id, 1);
        $this->jobs->pushed($connection, $queue, $payload);
        $this->jobs->reserved($connection, $queue, $payload);
        $this->jobs->completed($payload);
        $this->pin($payload->id(), 'completed_jobs', $completedAt, ['created_at' => $pushedAt, 'reserved_at' => $reservedAt, 'completed_at' => $completedAt]);

        return $payload->id();
    }

    /**
     * A job pushed, reserved (unless `$reservedAt` is null) and failed after `$attempts` attempts with the stored
     * exception text `$exception` (Horizon stores `(string) $exception`); its id.
     */
    public function failed(string $name = 'App\\Jobs\\SendInvoice', string $queue = 'default', ?float $reservedAt = 1700000001.0, float $failedAt = 1700000002.0, int $attempts = 1, ?string $exception = null, ?string $id = null, string $connection = 'redis'): string
    {
        $payload = $this->payload($name, $id, $attempts);
        $this->jobs->pushed($connection, $queue, $payload);

        if ($reservedAt !== null) {
            $this->jobs->reserved($connection, $queue, $payload);
        }

        $thrown = new RuntimeException('Simulated failure');
        $this->jobs->failed($thrown, $connection, $queue, $payload);
        $this->pin($payload->id(), 'failed_jobs', $failedAt, [
            'reserved_at' => $reservedAt,
            'failed_at' => $failedAt,
            'exception' => $exception ?? (string) $thrown,
        ]);

        return $payload->id();
    }

    /**
     * Ids `<prefix><index>` in a sorted set without job hashes (expired hashes), scored at the given times.
     *
     * @param  list<float>  $times
     */
    public function orphans(string $set, array $times, string $prefix = 'orphan-'): void
    {
        foreach (array_chunk($times, 1000, true) as $chunk) {
            $arguments = [];

            foreach ($chunk as $index => $time) {
                $arguments[] = -$time;
                $arguments[] = $prefix.$index;
            }

            $this->redis->zadd($set, ...$arguments);
        }
    }

    public function connection(): Connection
    {
        return $this->redis;
    }

    private function payload(string $name, ?string $id, int $attempts): JobPayload
    {
        $overrides = ['attempts' => $attempts];

        if ($id !== null) {
            $overrides['uuid'] = $id;
            $overrides['id'] = $id;
        }

        return new JobPayload(QueuePayloads::encode(QueuePayloads::job($name, $overrides)));
    }

    /**
     * @param  array<string, float|string|null>  $fields  null deletes the field
     */
    private function pin(string $id, string $set, float $score, array $fields): void
    {
        foreach ($fields as $field => $value) {
            if ($value === null) {
                $this->redis->hdel($id, $field);
            } else {
                $this->redis->hset($id, $field, is_float($value) ? var_export($value, true) : $value);
            }
        }

        $this->redis->zadd($set, -$score, $id);
    }
}
