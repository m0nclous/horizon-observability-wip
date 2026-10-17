<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET|HEAD {base}/jobs/{list}`: `{"data":[...]}` for Grafana's Infinity datasource (Arch Q2.1).
 *
 * Stub (T02): empty lists. Owned by T06 (WP4).
 *
 * @internal
 */
final class JobListController
{
    public function __invoke(Request $request, string $list): JsonResponse
    {
        return new JsonResponse(['data' => []], 200, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
