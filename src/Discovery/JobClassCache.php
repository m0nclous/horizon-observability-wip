<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Discovery;

/**
 * Job classes from `bootstrap/cache/horizon-observability.php`, then the runtime cache (1 h), then live discovery
 * (Arch Q6.9, C22).
 *
 * Stub (T02): no classes, no cache file. Owned by T08 (WP6).
 *
 * @internal
 */
final class JobClassCache
{
    /**
     * @return list<string>
     */
    public function classes(): array
    {
        return [];
    }

    /**
     * Path of the deploy-time cache file.
     */
    public function path(): string
    {
        return app()->bootstrapPath('cache/horizon-observability.php');
    }

    /**
     * Discover and write the cache file; the number of classes written.
     */
    public function write(): int
    {
        return 0;
    }

    /**
     * Delete the cache file and the runtime cache; whether a file was deleted.
     */
    public function clear(): bool
    {
        return false;
    }
}
