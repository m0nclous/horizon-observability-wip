<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Live;

use Carbon\CarbonImmutable;

/**
 * Stale-while-revalidate snapshot cache on the storage connection, safe against stampedes; freshness by Redis key
 * expiry, never by comparing clocks (Addendum R4.2): at most one build per `cache_ttl` per scope across replicas.
 *
 * Stub (T02): builds on every call. Owned by T12 (WP-L1).
 *
 * @internal
 */
final readonly class LiveSnapshotCache
{
    public function __construct(private LiveSnapshotBuilder $builder) {}

    public function get(LiveScope $scope): LiveSnapshot
    {
        return $this->builder->build($scope, CarbonImmutable::now());
    }
}
