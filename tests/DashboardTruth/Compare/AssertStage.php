<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Compare;

use DF\HorizonObservability\Tests\DashboardTruth\Cases\Plan;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Manifest;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Observation as Captured;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Recording;
use DF\HorizonObservability\Tests\DashboardTruth\Expect\Core\SeriesStore;
use DF\HorizonObservability\Tests\DashboardTruth\Ingest\ScrapeEmulation;
use DF\HorizonObservability\Tests\DashboardTruth\Record\WorldKeeper;
use LogicException;

/**
 * The assert stage of a run, for DashboardTruthTest (design section 3 ASSERT): the plan, each dataset's manifest
 * and stored samples (the recording through the same ScrapeEmulation the ingest used), the replays against the
 * still-running Grafanas, and the report. One instance per Pest process; it remembers which cases and versions ran
 * for the runtime gate.
 */
final class AssertStage
{
    private static ?self $instance = null;

    /**
     * @var array<string, array{0: Manifest, 1: SeriesStore}>
     */
    private array $datasets = [];

    /**
     * @var list<array{0: string, 1: string}>
     */
    private array $executed = [];

    private readonly Report $report;

    /**
     * @param  string  $runDir  where the plan, the observations and the report are (the run directory, or a mutant's
     *                          directory under it); the datasets are in the plan's run directory
     */
    private function __construct(public readonly string $runDir, public readonly Plan $plan)
    {
        $this->report = Report::open($runDir);
    }

    public static function enabled(): bool
    {
        return getenv('DASHBOARD_TRUTH_STACK') === '1';
    }

    public static function get(): self
    {
        if (! self::$instance instanceof AssertStage) {
            $runDir = getenv('DASHBOARD_TRUTH_RUN_DIR');

            if (! is_string($runDir) || $runDir === '') {
                throw new LogicException('DASHBOARD_TRUTH_RUN_DIR is not set (bin/run sets it).');
            }

            self::$instance = new self($runDir, Plan::read($runDir.'/plan.json'));
        }

        return self::$instance;
    }

    /**
     * The (case, version) pairs of the plan, for the test's data set; one placeholder without the stack.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function tests(): array
    {
        if (! self::enabled()) {
            return ['without the stack' => ['-', '-']];
        }

        $stage = self::get();
        $tests = [];

        foreach ($stage->plan->cases() as $case) {
            foreach ($stage->plan->versions() as $version) {
                $tests[$case->id.' on Grafana '.$version] = [$case->id, $version];
            }
        }

        return $tests;
    }

    public function negative(string $caseId): ?string
    {
        foreach ($this->plan->cases() as $one) {
            if ($one->id === $caseId) {
                return $one->expect?->value;
            }
        }

        return null;
    }

    /**
     * Assert one case on one Grafana version; its failures.
     *
     * @return list<Assertion>
     */
    public function check(string $caseId, string $grafana): array
    {
        $planned = $this->plan->planned($caseId);
        $case = null;

        foreach ($this->plan->cases() as $one) {
            if ($one->id === $caseId) {
                $case = $one;
            }
        }

        if ($case === null) {
            throw new LogicException('No case '.$caseId.' in the plan.');
        }

        [$manifest, $store] = $this->dataset($case->dataset);
        $observation = Captured::read($this->runDir.'/observations/'.$grafana.'/'.$caseId.'.json');
        $comparator = new Comparator($this->runDir, new Replays($this->plan->grafanaUrl($grafana)));
        $assertions = $comparator->run($case, $planned, $observation, $manifest, $store);
        $this->report->record($caseId, $grafana, $assertions);
        $this->executed[] = [$caseId, $grafana];

        return array_values(array_filter($assertions, static fn (Assertion $one): bool => ! $one->passed));
    }

    /**
     * The runtime gate over the cases and versions this process asserted.
     *
     * @return list<string>
     */
    public function gate(): array
    {
        return $this->report->gate($this->plan, $this->executed);
    }

    /**
     * @return array{0: Manifest, 1: SeriesStore}
     */
    public function dataset(string $name): array
    {
        if (! isset($this->datasets[$name])) {
            $directory = WorldKeeper::directory((string) $this->plan->data['runDir'], $name);
            $manifest = Manifest::read($directory.'/manifest.json');
            $this->datasets[$name] = [$manifest, ScrapeEmulation::store($manifest, Recording::open($directory.'/recording.ndjson'))];
        }

        return $this->datasets[$name];
    }

    public function report(): Report
    {
        return $this->report;
    }
}
