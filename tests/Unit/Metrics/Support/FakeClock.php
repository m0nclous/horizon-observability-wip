<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Unit\Metrics\Support;

/**
 * A monotonic clock (seconds) for the exporter and the scrape context that only moves when a test advances it.
 */
final class FakeClock
{
    public function __construct(public float $now = 1000.0) {}

    public function __invoke(): float
    {
        return $this->now;
    }

    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }
}
