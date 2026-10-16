<?php

declare(strict_types=1);

namespace SushiMarket\HorizonPrometheusGrafana\Http\Controllers;

use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use SushiMarket\HorizonPrometheusGrafana\HorizonJobs\HorizonJobsService;
use SushiMarket\HorizonPrometheusGrafana\Http\Requests\GetHorizonJobsRequest;
use SushiMarket\HorizonPrometheusGrafana\Http\Resources\HorizonCompletedJobResource;
use SushiMarket\HorizonPrometheusGrafana\Http\Resources\HorizonDelayedJobResource;
use SushiMarket\HorizonPrometheusGrafana\Http\Resources\HorizonFailedJobResource;
use SushiMarket\HorizonPrometheusGrafana\Http\Resources\HorizonPendingJobResource;

/**
 * Списки ожидающих, выполненных, упавших и отложенных джобов для таблиц Pending Jobs, Completed Jobs, Failed Jobs
 * и Delayed Jobs на дашборде «Queues»:
 * Grafana читает их плагином Infinity, фильтры — переменными дашборда (см. GetHorizonJobsRequest).
 */
class HorizonJobsController extends Controller
{
    public function pending(GetHorizonJobsRequest $request, HorizonJobsService $service): AnonymousResourceCollection
    {
        return HorizonPendingJobResource::collection($service->getPending($request->toData()));
    }

    public function completed(GetHorizonJobsRequest $request, HorizonJobsService $service): AnonymousResourceCollection
    {
        return HorizonCompletedJobResource::collection($service->getCompleted($request->toData()));
    }

    public function failed(GetHorizonJobsRequest $request, HorizonJobsService $service): AnonymousResourceCollection
    {
        return HorizonFailedJobResource::collection($service->getFailed($request->toData()));
    }

    public function delayed(GetHorizonJobsRequest $request, HorizonJobsService $service): AnonymousResourceCollection
    {
        return HorizonDelayedJobResource::collection($service->getDelayed($request->toData()));
    }
}
