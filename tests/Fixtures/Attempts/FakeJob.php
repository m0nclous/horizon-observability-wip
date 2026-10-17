<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Fixtures\Attempts;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;

/**
 * A queue job of no particular driver (not a `RedisJob`), with a raw payload; `release()` and `fail()` only set the
 * flags. For unit tests of the outcome shim and the driver exclusion.
 */
final class FakeJob extends Job implements JobContract
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(private readonly array $payload = [], string $connectionName = 'redis', string $queue = 'default')
    {
        $this->container = Container::getInstance();
        $this->connectionName = $connectionName;
        $this->queue = $queue;
    }

    /**
     * A job its attempt released (no driver call).
     */
    public static function releasedOne(): self
    {
        $job = new self;
        $job->release();

        return $job;
    }

    /**
     * A job its attempt failed (no driver call).
     */
    public static function failedOne(): self
    {
        $job = new self;
        $job->markAsFailed();

        return $job;
    }

    public function getJobId(): ?string
    {
        $id = $this->payload['id'] ?? null;

        return is_string($id) ? $id : null;
    }

    public function getRawBody(): string
    {
        return (string) json_encode($this->payload);
    }

    public function attempts(): int
    {
        $attempts = $this->payload['attempts'] ?? 0;

        return (is_int($attempts) ? $attempts : 0) + 1;
    }
}
