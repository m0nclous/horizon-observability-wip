<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Truth\Support;

use Illuminate\Redis\Connections\Connection;

/**
 * Independent reads of a Laravel Redis queue, the ground truth of the queue gauges: plain list and sorted-set
 * commands and `json_decode()` of every payload, without the package's Lua script or readers.
 */
final readonly class RedisState
{
    public function __construct(private Connection $redis, private string $queue) {}

    public function ready(): int
    {
        return (int) $this->redis->llen('queues:'.$this->queue);
    }

    public function delayed(): int
    {
        return (int) $this->redis->zcard('queues:'.$this->queue.':delayed');
    }

    public function due(float $now): int
    {
        return (int) $this->redis->zcount('queues:'.$this->queue.':delayed', '-inf', (string) $now);
    }

    public function reserved(): int
    {
        return (int) $this->redis->zcard('queues:'.$this->queue.':reserved');
    }

    /**
     * Ready jobs per `displayName`.
     *
     * @return array<string, int>
     */
    public function readyByClass(): array
    {
        return $this->classes($this->payloads($this->redis->lrange('queues:'.$this->queue, 0, -1)));
    }

    /**
     * Reserved jobs per `displayName`.
     *
     * @return array<string, int>
     */
    public function reservedByClass(): array
    {
        return $this->classes($this->payloads($this->redis->zrange('queues:'.$this->queue.':reserved', 0, -1)));
    }

    /**
     * Seconds the oldest ready first attempt has waited (C8, restated): the readiness `max(pushedAt, createdAt +
     * delay)` of the first payload with `attempts` 0, or the earliest due delayed score, whichever is older.
     */
    public function oldest(float $now): ?float
    {
        if ($this->ready() + $this->due($now) === 0) {
            return 0.0;
        }

        $times = [];

        foreach ($this->payloads($this->redis->lrange('queues:'.$this->queue, 0, -1)) as $payload) {
            if ((int) ($payload['attempts'] ?? 0) === 0) {
                $pushed = (float) $payload['pushedAt'];
                $times[] = is_int($payload['createdAt'] ?? null) && is_int($payload['delay'] ?? null)
                    ? max($pushed, (float) ($payload['createdAt'] + $payload['delay']))
                    : $pushed;

                break;
            }
        }

        $due = $this->redis->zrangebyscore('queues:'.$this->queue.':delayed', '-inf', (string) $now, ['withscores' => true]);

        foreach (is_array($due) ? $due : [] as $score) {
            $times[] = (float) $score;
        }

        return $times === [] ? null : max(0.0, $now - min($times));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function payloads(mixed $raw): array
    {
        $payloads = [];

        foreach (is_array($raw) ? $raw : [] as $json) {
            $payload = json_decode((string) $json, true);

            if (is_array($payload)) {
                $payloads[] = $payload;
            }
        }

        return $payloads;
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     * @return array<string, int>
     */
    private function classes(array $payloads): array
    {
        $classes = [];

        foreach ($payloads as $payload) {
            $name = (string) ($payload['displayName'] ?? '');
            $classes[$name] = ($classes[$name] ?? 0) + 1;
        }

        ksort($classes);

        return $classes;
    }
}
