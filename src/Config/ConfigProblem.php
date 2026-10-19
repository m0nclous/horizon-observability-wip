<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Config;

/**
 * One invalid configuration value that {@see Config} replaced (normalise and report).
 *
 * @internal
 */
final readonly class ConfigProblem
{
    /**
     * @param  string  $path  key below `horizon-observability`, e.g. `redis.worker_timeout`
     * @param  string  $message  English, never contains a secret
     */
    public function __construct(public string $path, public string $message) {}
}
