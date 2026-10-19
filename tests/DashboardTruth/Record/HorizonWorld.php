<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Record;

use DF\HorizonObservability\Tests\Support\HorizonState;
use Illuminate\Contracts\Foundation\Application;

/**
 * The Horizon topology of a dataset over time (design section 6.2): before every scrape it writes the master and
 * supervisor records of the topology at that time through tests/Support/HorizonState, exactly as Horizon's own
 * repositories write them (scores and statuses on the virtual clock), so the exporter's horizon families and Horizon's
 * repositories read the dataset's processes, pauses and stops.
 *
 * A topology entry: `from`/`to` (epoch seconds, [from, to)), and its masters: `name`, `status` (running, paused) and
 * `supervisors` (`name` without the master, `queue`, `balance`, `min`, `max`, `processes` per queue label). An
 * interval without an entry has no master (Horizon is not running).
 */
final readonly class HorizonWorld
{
    /**
     * @param  list<array{from: int, to: int, masters: list<array<string, mixed>>}>  $topology
     */
    public function __construct(private array $topology) {}

    /**
     * Write the records of the topology at `$at` (the scenario's virtual time).
     */
    public function heartbeat(Application $app, float $at): void
    {
        $state = HorizonState::using($app);

        foreach ($this->at($at) as $master) {
            $name = (string) $master['name'];
            $supervisors = is_array($master['supervisors'] ?? null) ? $master['supervisors'] : [];
            $names = [];

            foreach ($supervisors as $supervisor) {
                /** @var array{name: string, queue: string, balance: string|bool, min: int, max: int, processes: array<string, int>} $supervisor */
                $full = $name.':'.$supervisor['name'];
                $names[] = $full;
                $state->supervisor(
                    $full,
                    HorizonState::options($supervisor['name'], 'redis', $supervisor['queue'], $supervisor['balance'], $supervisor['min'], $supervisor['max']),
                    $supervisor['processes'],
                    (string) ($master['status'] ?? 'running') === 'paused' ? 'paused' : 'running',
                );
            }

            $state->master($name, (string) ($master['status'] ?? 'running'), $names);
        }
    }

    /**
     * The masters of the topology entry that holds `$at`.
     *
     * @return list<array<string, mixed>>
     */
    public function at(float $at): array
    {
        foreach ($this->topology as $entry) {
            if ($at >= $entry['from'] && $at < $entry['to']) {
                return $entry['masters'];
            }
        }

        return [];
    }
}
