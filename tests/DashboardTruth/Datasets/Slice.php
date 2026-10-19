<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Datasets;

use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Mode;
use DF\HorizonObservability\Tests\Truth\Support\Scenario;
use DF\HorizonObservability\Tests\Truth\Support\TruthJob;
use Illuminate\Support\Facades\Bus;

/**
 * The dataset of the thin end-to-end slice (WP1, design section 6.4): E_0 = 2025-03-03T00:00Z, 1 h 40 min, one target
 * `app` / `app:8080` scraped every 10 s at a phase of 3.2 s, one supervisor (`supervisor-default`: queue default,
 * balance auto, 1 to 3 processes, 3 running).
 *
 * | at (s after E_0)       | jobs on `default`                                                                  |
 * |------------------------|------------------------------------------------------------------------------------|
 * | 60 + 30k, k = 0..197   | (k mod 3) + 1 x Truth\Invoice complete; k mod 4 = 3: one fails; k mod 5 = 2: one    |
 * |                        | throws once and completes (retried, then completed)                                |
 * | 1205                   | batch `import`: 2 x Truth\Invoice complete                                          |
 * | 3605                   | the held birth: 2 x Truth\Born complete, 5 s after the 2-minute boundary 3600 (held |
 * |                        | by the exporter for 90 s, visible from the scrape at 3703.2)                        |
 * | 3665 + 60m, m = 0..38  | 1 x Truth\Born complete                                                             |
 *
 * Events are on whole seconds, scrapes at .2, so they never coincide. The retried attempts make the #8 A mutant
 * `status=~"completed|retried"` visible.
 */
final class Slice implements Dataset
{
    public const EPOCH = 1740960000;

    public const SPAN = 6000;

    public const INTERVAL = 10;

    public const PHASE = 3.2;

    public const BIRTH = 3605;

    public function name(): string
    {
        return 'slice';
    }

    public function epoch(): int
    {
        return self::EPOCH;
    }

    public function span(): int
    {
        return self::SPAN;
    }

    public function scrapeInterval(): int
    {
        return self::INTERVAL;
    }

    public function targets(): array
    {
        return [[
            'name' => 'app',
            'job' => 'app',
            'instance' => 'app:8080',
            'interval' => self::INTERVAL,
            'phase' => self::PHASE,
            'from' => self::EPOCH,
            'to' => self::EPOCH + self::SPAN,
            'failed' => [],
        ]];
    }

    public function topology(): array
    {
        return [[
            'from' => self::EPOCH,
            'to' => self::EPOCH + self::SPAN + 1,
            'masters' => [[
                'name' => 'host-1',
                'status' => 'running',
                'supervisors' => [[
                    'name' => 'supervisor-default',
                    'queue' => 'default',
                    'balance' => 'auto',
                    'min' => 1,
                    'max' => 3,
                    'processes' => ['redis:default' => 3],
                ]],
            ]],
        ]];
    }

    public function events(): array
    {
        $events = [];

        for ($k = 0; 60 + 30 * $k <= self::SPAN - 30; $k++) {
            $completed = $k % 3 + 1;
            $failed = $k % 4 === 3 ? 1 : 0;
            $retried = $k % 5 === 2 ? 1 : 0;
            $events[] = [
                'at' => (float) (60 + 30 * $k),
                'kind' => 'invoice',
                'detail' => sprintf('%d completed, %d failed, %d retried then completed', $completed, $failed, $retried),
                'run' => static function (Scenario $scenario) use ($completed, $failed, $retried): void {
                    for ($i = 0; $i < $completed; $i++) {
                        $scenario->dispatch(new TruthJob('Truth\\Invoice'));
                    }

                    if ($failed > 0) {
                        $scenario->dispatch(new TruthJob('Truth\\Invoice', ['fail']));
                    }

                    if ($retried > 0) {
                        $scenario->dispatch(new TruthJob('Truth\\Invoice', ['throw', 'complete'], tries: 2));
                    }

                    $scenario->work();
                },
            ];
        }

        $events[] = [
            'at' => 1205.0,
            'kind' => 'batch',
            'detail' => 'batch import: 2 x Truth\\Invoice completed',
            'run' => static function (Scenario $scenario): void {
                Bus::batch([new TruthJob('Truth\\Invoice'), new TruthJob('Truth\\Invoice')])
                    ->name('import')
                    ->onConnection('redis')
                    ->onQueue('default')
                    ->dispatch();
                $scenario->work();
            },
        ];

        $events[] = [
            'at' => (float) self::BIRTH,
            'kind' => 'birth',
            'detail' => 'held birth: 2 x Truth\\Born completed, 5 s after a 2-minute boundary',
            'run' => static function (Scenario $scenario): void {
                $scenario->dispatch(new TruthJob('Truth\\Born'))->dispatch(new TruthJob('Truth\\Born'))->work();
            },
        ];

        for ($m = 0; 3665 + 60 * $m <= self::SPAN - 60; $m++) {
            $events[] = [
                'at' => (float) (3665 + 60 * $m),
                'kind' => 'born',
                'detail' => '1 x Truth\\Born completed',
                'run' => static function (Scenario $scenario): void {
                    $scenario->dispatch(new TruthJob('Truth\\Born'))->work();
                },
            ];
        }

        usort($events, static fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return $events;
    }

    public function renders(): array
    {
        return [Mode::Off];
    }
}
