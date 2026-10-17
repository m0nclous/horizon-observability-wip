<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Metrics;

use Carbon\CarbonImmutable;
use Closure;
use Throwable;

/**
 * State of one scrape (Arch Q1.3): the request time, the deadline of the scrape budget, shared reads and the partial
 * failures collectors report. One instance per request; nothing is static.
 *
 * @internal
 */
final class ScrapeContext
{
    /**
     * @var Closure(): float
     */
    private readonly Closure $clock;

    private readonly float $deadline;

    /**
     * @var array<string, array{0: bool, 1: mixed}>
     */
    private array $memo = [];

    /**
     * @var list<array{source: string, exception: Throwable}>
     */
    private array $failures = [];

    /**
     * @param  float  $budgetSeconds  time collectors may use from now on; <= 0 means the deadline has passed
     * @param  (Closure(): float)|null  $clock  monotonic seconds, for tests; default `hrtime()`
     */
    public function __construct(private readonly CarbonImmutable $now, float $budgetSeconds, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
        $this->deadline = ($this->clock)() + max(0.0, $budgetSeconds);
    }

    /**
     * The request time; every collector uses it instead of reading the clock.
     */
    public function now(): CarbonImmutable
    {
        return $this->now;
    }

    /**
     * Seconds left until the deadline, never negative.
     */
    public function remaining(): float
    {
        return max(0.0, $this->deadline - ($this->clock)());
    }

    public function deadlineExceeded(): bool
    {
        return ($this->clock)() >= $this->deadline;
    }

    /**
     * Resolve a shared source once per scrape. A failure is cached too, so a failing source is read once and its
     * exception is rethrown to every later caller.
     *
     * @template T
     *
     * @param  Closure(): T  $resolve
     * @return T
     */
    public function remember(string $key, Closure $resolve): mixed
    {
        if (! array_key_exists($key, $this->memo)) {
            try {
                $this->memo[$key] = [true, $resolve()];
            } catch (Throwable $e) {
                $this->memo[$key] = [false, $e];
            }
        }

        [$ok, $value] = $this->memo[$key];

        if (! $ok && $value instanceof Throwable) {
            throw $value;
        }

        /** @var T $value */
        return $value;
    }

    /**
     * Keep the data read so far but mark the collector as failed (`exporter_collector_success = 0`).
     */
    public function reportFailure(string $source, Throwable $e): void
    {
        $this->failures[] = ['source' => $source, 'exception' => $e];
    }

    /**
     * The failures reported since the last call, oldest first; clears them.
     *
     * @internal
     *
     * @return list<array{source: string, exception: Throwable}>
     */
    public function takeFailures(): array
    {
        $failures = $this->failures;
        $this->failures = [];

        return $failures;
    }
}
