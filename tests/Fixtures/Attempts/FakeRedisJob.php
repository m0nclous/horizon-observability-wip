<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Fixtures\Attempts;

use DF\HorizonObservability\Tests\Support\QueuePayloads;
use Illuminate\Container\Container;
use Illuminate\Queue\Jobs\RedisJob;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\RedisQueue;

/**
 * A real `RedisJob` (what the worker pops) built from a raw payload, whose `release()` and `delete()` only set the
 * flags instead of moving the job in Redis. For the tracker's event-sequence tests.
 */
final class FakeRedisJob extends RedisJob
{
    /**
     * @param  array<string, mixed>  $payload  e.g. {@see QueuePayloads::job()}
     */
    public static function make(array $payload, string $queue = 'default', string $connectionName = 'redis'): self
    {
        return self::raw(QueuePayloads::encode($payload), $queue, $connectionName);
    }

    /**
     * A job with a raw body as popped, e.g. invalid JSON.
     */
    public static function raw(string $raw, string $queue = 'default', string $connectionName = 'redis'): self
    {
        $container = Container::getInstance();
        /** @var QueueManager $manager */
        $manager = $container->make('queue');
        /** @var RedisQueue $redis */
        $redis = $manager->connection($connectionName);

        return new self($container, $redis, $raw, $raw, $connectionName, $queue);
    }

    public function release($delay = 0): void
    {
        $this->released = true;
    }

    public function delete(): void
    {
        $this->deleted = true;
    }
}
