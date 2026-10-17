<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Http;

use DF\HorizonObservability\Batches\BatchLinks;
use DF\HorizonObservability\Horizon\Supervisors;
use DF\HorizonObservability\Horizon\Topology;
use DF\HorizonObservability\Live\LiveScope;
use DF\HorizonObservability\Live\LiveSnapshot;
use DF\HorizonObservability\Live\LiveSnapshotCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET|HEAD {base}/live`: the live snapshot JSON (schema v1) of the request's `var-*` scope, registered when
 * `live.enabled` (Addendum R4.3). 200 whenever authorised; partial failures go to `errors`.
 *
 * Stub (T02): the snapshot of the (stub) cache. Owned by T12 (WP-L1).
 *
 * @internal
 */
final class LiveController
{
    public function __invoke(Request $request, LiveSnapshotCache $cache, Topology $topology, Supervisors $supervisors, BatchLinks $links): JsonResponse
    {
        $scope = LiveScope::fromFilters(JobListQuery::filtersFromRequest($request), $topology, $supervisors, $links);

        return new JsonResponse($cache->get($scope)->toArray(), 200, ['Cache-Control' => 'no-store'], LiveSnapshot::JSON_FLAGS & ~JSON_THROW_ON_ERROR);
    }
}
