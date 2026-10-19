<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Live\Stream;

use DF\HorizonObservability\Live\LiveRedis;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\Lua;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Redis\RedisScriptFailed;
use RuntimeException;

/**
 * Redis leader lease of the stream command: `SET live:leader <token> NX PX 5000`, renewed and
 * released with `lock.lua` (compare-and-PEXPIRE, compare-and-DEL), on the private storage client
 * (`redis.worker_timeout`). At most one process holds the lease; one that lost it can neither extend nor delete
 * the next holder's.
 *
 * Redis errors propagate: the streamer treats them as "no lease".
 *
 * @internal
 */
final class LeaderLock
{
    /**
     * Lifetime of the lease; the leader renews it every tick and before every push.
     */
    public const TTL_MS = 5000;

    private ?string $token = null;

    public function __construct(
        private readonly RedisClients $clients,
        private readonly Lua $lua,
        private readonly Keys $keys,
    ) {}

    /**
     * Take the lease if nobody holds it; true also when this process still holds it (then it is renewed).
     *
     * @throws RuntimeException|RedisScriptFailed on a Redis error
     */
    public function acquire(): bool
    {
        $redis = $this->clients->storage();

        return LiveRedis::setIfAbsent($redis, $this->keys->liveLeader(), $this->token(), self::TTL_MS)
            || LiveRedis::renew($this->lua, $redis, $this->keys->liveLeader(), $this->token(), self::TTL_MS);
    }

    /**
     * Extend the lease; false means the lease was lost: stop pushing.
     *
     * @throws RedisScriptFailed on a Redis error
     */
    public function renew(): bool
    {
        return LiveRedis::renew($this->lua, $this->clients->storage(), $this->keys->liveLeader(), $this->token(), self::TTL_MS);
    }

    /**
     * Give the lease up if this process holds it.
     *
     * @throws RedisScriptFailed on a Redis error
     */
    public function release(): void
    {
        LiveRedis::release($this->lua, $this->clients->storage(), $this->keys->liveLeader(), $this->token());
    }

    /**
     * This process's lease token (`host:pid:rand`).
     */
    public function token(): string
    {
        return $this->token ??= gethostname().':'.getmypid().':'.bin2hex(random_bytes(4));
    }
}
