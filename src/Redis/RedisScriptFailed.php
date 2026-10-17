<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Redis;

use RuntimeException;
use Throwable;

/**
 * A package Lua script could not run: Redis answered with an error (phpredis `false` plus `getLastError()`, or a
 * predis server error), or the script file is missing.
 *
 * @internal
 */
final class RedisScriptFailed extends RuntimeException
{
    public function __construct(public readonly string $script, string $reason, ?Throwable $previous = null)
    {
        parent::__construct(sprintf('Lua script %s failed: %s', $script, $reason), 0, $previous);
    }
}
