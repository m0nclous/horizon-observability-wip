<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana\Live;

/**
 * How the Overview row gets its current values (owner decision 9, ADDENDUM R4.1, `--live=off|poll|stream`).
 *
 * - `Off`: every tile reads Prometheus, the pilot's look exactly.
 * - `Poll` (the package render): Supervisor Processes, Pending, Delayed and the Completed caption read the
 *   application's `{base}/live` snapshot through Infinity at every dashboard refresh (`--live-poll-interval`).
 * - `Stream`: Supervisor Processes, Pending and Delayed read the Grafana Live channels `stream/<id>/tiles` and
 *   `stream/<id>/delayed` that `horizon-observability:stream` pushes every second; the Completed caption polls.
 *
 * @internal
 */
enum LiveMode: string
{
    case Off = 'off';
    case Poll = 'poll';
    case Stream = 'stream';

    /**
     * Whether some Overview tiles read the live snapshot instead of Prometheus.
     */
    public function isLive(): bool
    {
        return $this !== self::Off;
    }

    /**
     * The title of the Overview row (#10): "Live Overview" when live (ADDENDUM R4.1, R4.8).
     */
    public function overviewTitle(): string
    {
        return $this->isLive() ? 'Live Overview' : 'Overview';
    }
}
