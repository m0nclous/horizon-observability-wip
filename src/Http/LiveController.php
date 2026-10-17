<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Http;

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Batches\BatchLinks;
use DF\HorizonObservability\Horizon\Supervisors;
use DF\HorizonObservability\Horizon\Topology;
use DF\HorizonObservability\Live\LiveScope;
use DF\HorizonObservability\Live\LiveSnapshot;
use DF\HorizonObservability\Live\LiveSnapshotBuilder;
use DF\HorizonObservability\Live\LiveSnapshotCache;
use DF\HorizonObservability\Support\FailureReporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * `GET|HEAD {base}/live`: the live snapshot JSON (schema v1) of the request's `var-*` scope, registered when
 * `live.enabled` (Addendum R4.3, R4.4).
 *
 * - The filters are the job lists' (`var-supervisor`, `var-batch_kind`, `var-queue`, `var-job_class` in Grafana's
 *   `${var:queryparam}` form, parsed by {@see JobListQuery::filtersFromRequest()}); `$__all` or absence means no
 *   filter, unknown parameters are ignored and invalid input is a 422.
 * - **200 whenever authorised**, partial failures included (`errors[]`, null values, caption `Live unavailable`).
 *   A scope that cannot be resolved (the running supervisors of a Supervisor filter, the links of a Batch filter)
 *   answers {@see LiveSnapshot::unavailable()} with the error.
 * - The snapshot comes from {@see LiveSnapshotCache} (1 `MGET` on a hit). Resolving a Supervisor filter reads the
 *   running supervisors (2 round trips) and a Batch filter the batch links (1) on every request, before the cache.
 * - A HEAD request gets the status and headers without a snapshot, so an uptime check costs no Redis work.
 * - Responses are never cached (`Cache-Control: no-store`).
 *
 * @internal
 */
final readonly class LiveController
{
    public function __construct(private FailureReporter $reporter) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Request $request, LiveSnapshotCache $cache, Topology $topology, Supervisors $supervisors, BatchLinks $links): JsonResponse
    {
        $filters = JobListQuery::filtersFromRequest($request);

        if ($request->isMethod('HEAD')) {
            return $this->respond([]);
        }

        try {
            $scope = LiveScope::fromFilters($filters, $topology, $supervisors, $links);
        } catch (Throwable $e) {
            $this->reporter->reportLocally('live.scope', $e);
            $scope = new LiveScope(
                $filters['supervisors'],
                [],
                $filters['jobClasses'] !== null || $filters['batchKinds'] !== null,
                $filters['jobClasses'],
            );

            return $this->respond(LiveSnapshot::unavailable($scope, (int) floor((float) CarbonImmutable::now()->format('U.u') * 1000), $cache->ttlMs(), [
                ['source' => 'scope', 'message' => LiveSnapshotBuilder::errorMessage($e)],
            ])->toArray());
        }

        return $this->respond($cache->get($scope)->toArray());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function respond(array $data): JsonResponse
    {
        return new JsonResponse($data, 200, ['Cache-Control' => 'no-store'], (LiveSnapshot::JSON_FLAGS & ~JSON_THROW_ON_ERROR) | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
