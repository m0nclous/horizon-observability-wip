<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Datasets;

use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Mode;
use DF\HorizonObservability\Tests\DashboardTruth\Record\HorizonWorld;
use DF\HorizonObservability\Tests\Truth\Support\Scenario;

/**
 * A dataset of the dashboard truth suite (design section 6.4): one epoch, one span on the virtual clock, the scrape
 * targets, the Horizon topology and the events, as offsets from the epoch. The recorder visits the union of the
 * event times and the scrape slots of every target in time order.
 */
interface Dataset
{
    public function name(): string;

    /**
     * E_k, epoch seconds: 1740960000 + k x 691200 (2025-03-03T00:00Z + k x 8 days).
     */
    public function epoch(): int;

    /**
     * Seconds from E_k to V_k.
     */
    public function span(): int;

    /**
     * S in seconds: the scrape interval of the datasource and of the renders' `--scrape-interval`.
     */
    public function scrapeInterval(): int;

    /**
     * The scrape targets: `name`, `job`, `instance`, `interval` (s), `phase` (s after E_k), `from`/`to` (the active
     * interval, epoch seconds) and the `failed` slot times (epoch milliseconds).
     *
     * @return list<array{name: string, job: string, instance: string, interval: int, phase: float, from: int, to: int, failed: list<int>}>
     */
    public function targets(): array;

    /**
     * The Horizon topology over time ({@see HorizonWorld}).
     *
     * @return list<array{from: int, to: int, masters: list<array<string, mixed>>}>
     */
    public function topology(): array;

    /**
     * The events: `at` (seconds after E_k, never on a scrape slot), a `kind` and `detail` for the manifest, and the
     * action on the scenario.
     *
     * @return list<array{at: float, kind: string, detail: string, run: callable(Scenario): void}>
     */
    public function events(): array;

    /**
     * The renders whose cases use the dataset.
     *
     * @return list<Mode>
     */
    public function renders(): array;
}
