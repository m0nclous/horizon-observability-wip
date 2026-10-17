<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Live;

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Config\Config;

/**
 * Builds the live snapshot of a scope from Horizon's repositories, `queue_state.lua` in live mode and the attempt
 * ring, all on reader clients (Addendum R4.2): Q + 5 round trips per build.
 *
 * Stub (T02): an empty snapshot. Owned by T12 (WP-L1).
 *
 * @internal
 */
final readonly class LiveSnapshotBuilder
{
    public function __construct(private Config $config) {}

    public function build(LiveScope $scope, CarbonImmutable $now): LiveSnapshot
    {
        return LiveSnapshot::empty($scope, (int) $now->getPreciseTimestamp(3), (int) round($this->config->liveCacheTtl * 1000));
    }
}
