<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Queues;

use RuntimeException;

/**
 * A queue whose keys cannot be read by one script on its Redis connection (Arch Q6.5): on a Redis Cluster the keys
 * `queues:<q>`, `queues:<q>:reserved` and `queues:<q>:delayed` share a slot only through a hash tag, e.g. the
 * queue `{default}`. Raised before any command is sent, instead of a `CROSSSLOT` error on every scrape.
 *
 * @internal
 */
final class UnsupportedTopology extends RuntimeException
{
    public static function clusterQueue(string $connection, string $queue, string $redisConnection): self
    {
        return new self(sprintf(
            'unsupported topology: the keys of queue "%s" (queue connection "%s") on the Redis Cluster connection "%s" span several hash slots; give the queue a hash tag, e.g. "{%s}", as Laravel requires on a cluster.',
            $queue,
            $connection,
            $redisConnection,
            $queue,
        ));
    }
}
