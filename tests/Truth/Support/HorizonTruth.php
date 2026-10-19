<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Truth\Support;

use Illuminate\Contracts\Config\Repository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;

/**
 * The horizon families as Horizon's own repositories and the Horizon config define them, restated from the
 * documented rules without the package's collector: the status of the masters, per supervisor (the name after the
 * last colon) the processes summed per pool label over every master, the largest minimum and maximum, the settings
 * of the first master, every queue of every master's `queue` option with the pool that works it, and the wait
 * thresholds of the configured queues (`horizon.waits`, default 60 s, 0 omitted).
 */
final readonly class HorizonTruth
{
    public function __construct(
        private MasterSupervisorRepository $masters,
        private SupervisorRepository $supervisors,
        private Repository $config,
    ) {}

    /**
     * Family (without the namespace) => labels key => value.
     *
     * @return array<string, array<string, float>>
     */
    public function expected(): array
    {
        $masters = $this->masters->all();
        $status = $masters === [] ? -1 : 1;

        foreach ($masters as $master) {
            if (($master->status ?? null) === 'paused') {
                $status = 0;
            }
        }

        $families = ['horizon_status' => ['' => (float) $status]];
        $records = array_values(array_filter($this->supervisors->all(), is_object(...)));
        usort($records, static fn (object $a, object $b): int => [self::name($a), (string) ($a->master ?? '')] <=> [self::name($b), (string) ($b->master ?? '')]);
        $settings = [];

        foreach ($records as $record) {
            $name = self::name($record);
            $options = (array) ($record->options ?? []);
            $connection = (string) ($options['connection'] ?? '');

            foreach ((array) ($record->processes ?? []) as $key => $count) {
                $label = str_starts_with((string) $key, $connection.':') ? substr((string) $key, strlen($connection) + 1) : (string) $key;
                $series = Model::key(['supervisor' => $name, 'queue' => $label]);
                $families['horizon_supervisor_processes'][$series] = ($families['horizon_supervisor_processes'][$series] ?? 0.0) + (float) $count;
            }

            $minimum = (float) ($options['minProcesses'] ?? 1);
            $maximum = (float) ($options['maxProcesses'] ?? 1);
            $families['horizon_supervisor_min_processes'][Model::key(['supervisor' => $name])] = max($families['horizon_supervisor_min_processes'][Model::key(['supervisor' => $name])] ?? $minimum, $minimum);
            $families['horizon_supervisor_max_processes'][Model::key(['supervisor' => $name])] = max($families['horizon_supervisor_max_processes'][Model::key(['supervisor' => $name])] ?? $maximum, $maximum);

            $balance = in_array($options['balance'] ?? null, ['auto', 'simple'], true) ? (string) $options['balance'] : 'off';

            if (! isset($settings[$name])) {
                $settings[$name] = true;
                $families['horizon_supervisor_info'][Model::key(['supervisor' => $name, 'balance' => $balance, 'connection' => $connection])] = 1.0;
            }

            // Every master's queues and pools: during a deploy that changes the options, both masters' pools work.
            // Horizon's pools (Supervisor::createProcessPools()): one for the whole option when the supervisor does
            // not balance, else one per piece of the option split at commas, as it is ('mail, sms' gives ' sms', the
            // label of its processes); a queue is the trimmed piece, worked by the first pool of its name.
            $queueOption = (string) ($options['queue'] ?? '');
            $queues = [];

            foreach (explode(',', $queueOption) as $piece) {
                $queue = trim($piece);

                if ($queue === '' || in_array($queue, $queues, true)) {
                    continue;
                }

                $queues[] = $queue;
                $pool = $balance === 'off' ? $queueOption : $piece;
                $families['horizon_supervisor_queues'][Model::key(['supervisor' => $name, 'queue' => $queue, 'pool' => $pool])] = 1.0;
            }
        }

        $waits = (array) $this->config->get('horizon.waits', []);

        foreach ($this->configuredQueues() as [$connection, $queue]) {
            $seconds = (int) ($waits[$connection.':'.$queue] ?? 60);

            if ($seconds > 0) {
                $families['horizon_wait_threshold_seconds'][Model::key(['connection' => $connection, 'queue' => $queue])] = (float) $seconds;
            }
        }

        foreach ($families as &$series) {
            ksort($series);
        }

        return $families;
    }

    /**
     * The queues of the effective environment's supervisors, `[connection, queue]`, without duplicates.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function configuredQueues(): array
    {
        $environment = (string) ($this->config->get('horizon.env') ?? $this->config->get('app.env'));
        $supervisors = (array) ($this->config->get('horizon.environments.'.$environment) ?? []);
        $defaults = (array) $this->config->get('horizon.defaults', []);
        $queues = [];

        foreach ($supervisors as $name => $supervisor) {
            $supervisor = [...(array) ($defaults[$name] ?? []), ...(array) $supervisor];
            $connection = (string) ($supervisor['connection'] ?? 'redis');

            foreach ((array) ($supervisor['queue'] ?? []) as $queue) {
                $queues[$connection.':'.$queue] = [$connection, (string) $queue];
            }
        }

        return array_values($queues);
    }

    private static function name(object $record): string
    {
        $name = (string) ($record->name ?? '');
        $position = strrpos($name, ':');

        return $position === false ? $name : substr($name, $position + 1);
    }
}
