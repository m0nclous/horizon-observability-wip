<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Horizon;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Str;
use Laravel\Horizon\ProvisioningPlan;
use Laravel\Horizon\SupervisorOptions;

/**
 * The queues `php artisan horizon` runs in the effective environment, and their wait thresholds (Arch Q3, Q6.3).
 *
 * Read from Horizon's config, not from a running Horizon, so queue metrics are collected while Horizon is down and
 * the queues grow. The rules are Horizon's own `ProvisioningPlan`: the environment is `horizon.env ?? app.env`, the
 * first `horizon.environments` key matching it with `Str::is()` wins, `horizon.defaults` are merged in, supervisors
 * with `maxProcesses <= 0` are not started, and a supervisor without `queue` runs its connection's default queue.
 * An invalid plan throws like Horizon does (e.g. `minProcesses < 1`).
 *
 * @internal
 */
final readonly class Topology
{
    /**
     * Horizon's long-wait threshold for queues without a `horizon.waits` entry (`LongWaitDetected`).
     */
    public const DEFAULT_WAIT_SECONDS = 60;

    public function __construct(private Repository $config) {}

    public function environment(): string
    {
        $environment = $this->config->get('horizon.env') ?? $this->config->get('app.env');

        return is_string($environment) ? $environment : 'production';
    }

    /**
     * Queue connection => queues, in config order without duplicates.
     *
     * @return array<string, list<string>>
     */
    public function queuesByConnection(): array
    {
        $result = [];

        foreach ($this->supervisorOptions() as $options) {
            if ((int) $options->maxProcesses <= 0 || ! is_string($options->connection) || $options->connection === '') {
                continue;
            }

            foreach (explode(',', (string) $options->queue) as $queue) {
                $queue = trim($queue);

                if ($queue !== '' && ! in_array($queue, $result[$options->connection] ?? [], true)) {
                    $result[$options->connection][] = $queue;
                }
            }
        }

        return $result;
    }

    /**
     * The same queues as a flat list.
     *
     * @return list<array{connection: string, queue: string}>
     */
    public function queues(): array
    {
        $queues = [];

        foreach ($this->queuesByConnection() as $connection => $names) {
            foreach ($names as $queue) {
                $queues[] = ['connection' => $connection, 'queue' => $queue];
            }
        }

        return $queues;
    }

    /**
     * `horizon.waits` as configured: `connection:queue` => seconds.
     *
     * @return array<string, int>
     */
    public function waits(): array
    {
        $waits = $this->config->get('horizon.waits');
        $result = [];

        foreach (is_array($waits) ? $waits : [] as $key => $seconds) {
            if (is_numeric($seconds)) {
                $result[(string) $key] = (int) $seconds;
            }
        }

        return $result;
    }

    /**
     * The effective long-wait threshold of a queue in seconds, as Horizon's `LongWaitDetected` applies it: the
     * `horizon.waits` entry, or {@see self::DEFAULT_WAIT_SECONDS} without one; null when the entry is 0 or negative
     * (the check is disabled).
     */
    public function waitThreshold(string $connection, string $queue): ?int
    {
        $seconds = $this->waits()[$connection.':'.$queue] ?? self::DEFAULT_WAIT_SECONDS;

        return $seconds > 0 ? $seconds : null;
    }

    /**
     * @return list<SupervisorOptions>
     */
    private function supervisorOptions(): array
    {
        $environments = $this->config->get('horizon.environments');
        $defaults = $this->config->get('horizon.defaults');
        $environment = $this->environment();

        foreach (is_array($environments) ? $environments : [] as $name => $supervisors) {
            if (! Str::is((string) $name, $environment)) {
                continue;
            }

            if (! is_array($supervisors)) {
                return [];
            }

            $plan = new ProvisioningPlan('horizon-observability', [(string) $name => $supervisors], is_array($defaults) ? $defaults : []);
            $parsed = $plan->parsed[(string) $name] ?? [];
            $options = [];

            foreach ($parsed as $supervisor) {
                if ($supervisor instanceof SupervisorOptions) {
                    $options[] = $supervisor;
                }
            }

            return $options;
        }

        return [];
    }
}
