<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Metrics;

use Illuminate\Http\Request;

/**
 * Ordered, isolated, budgeted run of the collectors plus the self-metrics and the namespace prefix (Arch Q1.3).
 *
 * Stub (T02): renders nothing. Owned by T03 (WP1).
 *
 * @internal
 */
final class Exporter
{
    /**
     * The text exposition of one scrape.
     */
    public function scrape(Request $request): string
    {
        return '';
    }
}
