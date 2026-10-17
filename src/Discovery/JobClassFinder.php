<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Discovery;

/**
 * Queueable classes in the given directories: token prefilter plus reflection, never including files (Arch Q6.9).
 *
 * Stub (T02): finds nothing. Owned by T08 (WP6).
 *
 * @internal
 */
final class JobClassFinder
{
    /**
     * @param  list<string>  $paths
     * @return list<string> sorted class names
     */
    public function find(array $paths): array
    {
        return [];
    }
}
