<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Integration\Live\Stream\Support;

use Carbon\CarbonImmutable;
use Closure;

/**
 * A fake monotonic clock for the stream loop, in whole milliseconds (no float drift), that also moves the wall clock
 * (`CarbonImmutable::setTestNow()`) from a start time; sleeping advances both instantly.
 */
final class StreamClock
{
    private int $elapsedMs = 0;

    public function __construct(private readonly CarbonImmutable $start)
    {
        CarbonImmutable::setTestNow($start);
    }

    /**
     * @return Closure(): float
     */
    public function clock(): Closure
    {
        return fn (): float => 1000.0 + $this->elapsedMs / 1000;
    }

    /**
     * @return Closure(float): void
     */
    public function sleep(): Closure
    {
        return function (float $seconds): void {
            $this->advance($seconds);
        };
    }

    public function advance(float $seconds): void
    {
        $this->elapsedMs += (int) round($seconds * 1000);
        CarbonImmutable::setTestNow($this->start->addMilliseconds($this->elapsedMs));
    }

    /**
     * Seconds since the start.
     */
    public function seconds(): float
    {
        return $this->elapsedMs / 1000;
    }
}
