<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Horizon;

use DF\HorizonObservability\Metrics\ScrapeContext;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;

/**
 * Running Horizon supervisors and the Horizon status from Horizon's own (container-bound) repositories, which read
 * through the app's `horizon` connection (Arch Q6.6). Callers memoise the results per scrape via
 * {@see ScrapeContext::remember()}. Repository errors propagate.
 *
 * @internal
 */
final readonly class Supervisors
{
    public function __construct(
        private MasterSupervisorRepository $masters,
        private SupervisorRepository $supervisors,
    ) {}

    /**
     * Running supervisors, sorted by name and master.
     *
     * @return list<SupervisorSnapshot>
     */
    public function all(): array
    {
        $snapshots = [];

        foreach ($this->supervisors->all() as $record) {
            if (is_object($record) && ($snapshot = self::snapshot($record)) instanceof SupervisorSnapshot) {
                $snapshots[] = $snapshot;
            }
        }

        usort($snapshots, static fn (SupervisorSnapshot $a, SupervisorSnapshot $b): int => [$a->name, $a->master] <=> [$b->name, $b->master]);

        return $snapshots;
    }

    /**
     * 1 running, 0 at least one master supervisor paused, -1 no master supervisor (`horizon_status`).
     */
    public function status(): int
    {
        $masters = $this->masters->all();

        if ($masters === []) {
            return -1;
        }

        foreach ($masters as $master) {
            if (is_object($master) && ($master->status ?? null) === 'paused') {
                return 0;
            }
        }

        return 1;
    }

    /**
     * A snapshot from a repository record (`name`, `master`, `processes`, `options`), or null without a name.
     */
    public static function snapshot(object $record): ?SupervisorSnapshot
    {
        $fullName = is_string($record->name ?? null) ? $record->name : '';

        if ($fullName === '') {
            return null;
        }

        $options = is_array($record->options ?? null) ? $record->options : [];
        $connection = is_string($options['connection'] ?? null) ? $options['connection'] : '';
        $processes = [];

        foreach (is_array($record->processes ?? null) ? $record->processes : [] as $key => $count) {
            $key = (string) $key;
            $queue = $connection !== '' && str_starts_with($key, $connection.':')
                ? substr($key, strlen($connection) + 1)
                : (str_contains($key, ':') ? substr($key, strpos($key, ':') + 1) : $key);
            $processes[$queue] = ($processes[$queue] ?? 0) + (is_numeric($count) ? (int) $count : 0);
        }

        $queues = [];

        foreach (explode(',', is_string($options['queue'] ?? null) ? $options['queue'] : '') as $queue) {
            $queue = trim($queue);

            if ($queue !== '' && ! in_array($queue, $queues, true)) {
                $queues[] = $queue;
            }
        }

        $position = strrpos($fullName, ':');

        return new SupervisorSnapshot(
            name: $position === false ? $fullName : substr($fullName, $position + 1),
            master: is_string($record->master ?? null) ? $record->master : ($position === false ? '' : substr($fullName, 0, $position)),
            connection: $connection,
            balance: self::balance($options['balance'] ?? null),
            minProcesses: is_numeric($options['minProcesses'] ?? null) ? (int) $options['minProcesses'] : 1,
            maxProcesses: is_numeric($options['maxProcesses'] ?? null) ? (int) $options['maxProcesses'] : 1,
            processes: $processes,
            queues: $queues,
        );
    }

    /**
     * `auto` and `simple` stay; `false`, `off` and anything unknown become `off` (Arch Q4).
     */
    public static function balance(mixed $balance): string
    {
        return is_string($balance) && in_array($balance, ['auto', 'simple'], true) ? $balance : 'off';
    }
}
