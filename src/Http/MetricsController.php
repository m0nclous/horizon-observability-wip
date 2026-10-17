<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Http;

use DF\HorizonObservability\Metrics\Exporter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET|HEAD {base}/metrics`: Prometheus text format 0.0.4, always 200 once authorised (Arch Q1, Q2.1).
 *
 * Stub (T02): the exporter's (empty) output. Owned by T03 (WP1).
 *
 * @internal
 */
final class MetricsController
{
    public const CONTENT_TYPE = 'text/plain; version=0.0.4; charset=utf-8';

    public function __invoke(Request $request, Exporter $exporter): Response
    {
        return new Response($exporter->scrape($request), 200, [
            'Content-Type' => self::CONTENT_TYPE,
            'Cache-Control' => 'no-store',
        ]);
    }
}
