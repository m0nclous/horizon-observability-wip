<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Collectors;

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Horizon\HorizonUrl;
use DF\HorizonObservability\Horizon\Supervisors;
use DF\HorizonObservability\Horizon\SupervisorSnapshot;
use DF\HorizonObservability\Horizon\Topology;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Metrics\MetricFamily;
use DF\HorizonObservability\Metrics\ScrapeContext;
use RuntimeException;
use Throwable;

/**
 * Collector `horizon`: horizon_status, horizon_supervisor_*, horizon_wait_threshold_seconds, horizon_info
 * (Arch Q1.3, Q4, C5, C14, C17).
 *
 * - **Sources.** Horizon's own master and supervisor repositories (container-bound, on the app's `horizon`
 *   connection; `WorkloadRepository` is not used and `horizon_current_processes` is gone), the topology from
 *   Horizon's config, and the links from the config. Each source is read in isolation: a failing one is reported
 *   with {@see ScrapeContext::reportFailure()} (`exporter_collector_success` 0) and the others are still exported,
 *   so a Redis outage leaves `horizon_info` and the wait thresholds (C5). When the status read used up the scrape
 *   budget, the supervisors are not read (source `budget`).
 * - **Supervisors** are keyed by the name after the last `:` (the master prefix stripped). With several masters
 *   running the same supervisor (a deploy, several hosts) the processes are summed per queue, the minimum and maximum
 *   are the largest reported, and the settings come from the first master by name. The `queue` label is Horizon's
 *   pool label, so a balance=false supervisor keeps its comma-joined queue list (C14).
 * - **horizon_supervisor_queues** maps every queue a supervisor works to the pool label of its processes: the queue
 *   itself for balance=auto and simple (a pool per queue), the comma-joined list for balance=false (one pool for all).
 *   PromQL cannot split a label value into several series, so without it a pooled queue had no supervisor, no
 *   processes and no row in the dashboard's queue table (T16, the pilot's #5 bug). The queues come from the
 *   supervisor's `queue` option, the pool labels from its process map (the joined option without one), over every
 *   master: when the masters disagree (a deploy that changes a balance=false queue list, or the balance), a queue has
 *   a series per pool that works it, so the processes per queue count every master's.
 * - **Wait thresholds** follow Horizon's `LongWaitDetected`: the `horizon.waits` entry or 60 s; a queue whose entry
 *   is 0 has no series.
 * - **horizon_info** carries the Horizon URL, the job-list API path and the TTS URL (`''`, i.e. absent, for tone
 *   only).
 *
 * @internal
 */
final readonly class HorizonCollector implements Collector
{
    /**
     * {@see ScrapeContext::remember()} key of the topology's queue list, shared with the queues collector.
     */
    public const TOPOLOGY = 'horizon.topology.queues';

    /**
     * {@see ScrapeContext::remember()} key of the running supervisors.
     */
    public const SUPERVISORS = 'horizon.supervisors';

    public function __construct(
        private Supervisors $supervisors,
        private Topology $topology,
        private HorizonUrl $url,
        private Config $config,
    ) {}

    public function name(): string
    {
        return 'horizon';
    }

    public function collect(ScrapeContext $context): iterable
    {
        $families = [];

        try {
            $status = $this->supervisors->status();
        } catch (Throwable $e) {
            $context->reportFailure('status', $e);
            $status = null;
        }

        if ($status !== null) {
            $families[] = MetricFamily::of(Metric::HorizonStatus)->set([], $status);
        }

        if ($context->deadlineExceeded()) {
            // A slow status read (e.g. an unreachable Redis) has used the budget; the supervisors would wait as long.
            $context->reportFailure('budget', new RuntimeException('the scrape budget ran out before the supervisors were read.'));
        } else {
            try {
                /** @var list<SupervisorSnapshot> $supervisors */
                $supervisors = $context->remember(self::SUPERVISORS, fn (): array => $this->supervisors->all());
                array_push($families, ...$this->supervisorFamilies($supervisors));
            } catch (Throwable $e) {
                $context->reportFailure('supervisors', $e);
            }
        }

        try {
            /** @var list<array{connection: string, queue: string}> $queues */
            $queues = $context->remember(self::TOPOLOGY, fn (): array => $this->topology->queues());
            $families[] = $this->waitThresholds($queues);
        } catch (Throwable $e) {
            $context->reportFailure('topology', $e);
        }

        $families[] = MetricFamily::of(Metric::HorizonInfo)->set([
            'url' => $this->url->url(),
            'api_path' => $this->url->apiPath(),
            'tts_url' => $this->ttsUrl($this->config->ttsUrl),
        ], 1);

        return array_values(array_filter($families, static fn (MetricFamily $family): bool => ! $family->isEmpty()));
    }

    /**
     * @param  list<SupervisorSnapshot>  $snapshots
     * @return list<MetricFamily>
     */
    private function supervisorFamilies(array $snapshots): array
    {
        /** @var array<string, array<string, int>> $processes */
        $processes = [];
        /** @var array<string, array{min: int, max: int, balance: string, connection: string}> $settings */
        $settings = [];

        foreach ($snapshots as $snapshot) {
            foreach ($snapshot->processes as $queue => $count) {
                $processes[$snapshot->name][(string) $queue] = ($processes[$snapshot->name][(string) $queue] ?? 0) + $count;
            }

            $previous = $settings[$snapshot->name] ?? null;
            $settings[$snapshot->name] = [
                'min' => max($previous['min'] ?? $snapshot->minProcesses, $snapshot->minProcesses),
                'max' => max($previous['max'] ?? $snapshot->maxProcesses, $snapshot->maxProcesses),
                'balance' => $previous['balance'] ?? $snapshot->balance,
                'connection' => $previous['connection'] ?? $snapshot->connection,
            ];
        }

        /** @var array<string, array<array-key, array<array-key, true>>> $pools supervisor => queue => pool label => true */
        $pools = [];

        foreach ($snapshots as $snapshot) {
            // Every master's pools: while a deploy changes the queue option of a balance=false supervisor, the old
            // and the new master's pools (e.g. "mail,sms" and "mail,sms,push") both work the common queues, and the
            // processes per queue add up over both. Queue names and pool labels like "1" are integer keys.
            foreach ($this->pools($snapshot) as $queue => $pool) {
                $pools[$snapshot->name][$queue][$pool] = true;
            }
        }

        $processFamily = MetricFamily::of(Metric::SupervisorProcesses);
        $minFamily = MetricFamily::of(Metric::SupervisorMinProcesses);
        $maxFamily = MetricFamily::of(Metric::SupervisorMaxProcesses);
        $infoFamily = MetricFamily::of(Metric::SupervisorInfo);
        $queuesFamily = MetricFamily::of(Metric::SupervisorQueues);

        foreach ($settings as $name => $setting) {
            $name = (string) $name;

            foreach ($processes[$name] ?? [] as $queue => $count) {
                $processFamily->set(['supervisor' => $name, 'queue' => (string) $queue], $count);
            }

            $minFamily->set(['supervisor' => $name], $setting['min']);
            $maxFamily->set(['supervisor' => $name], $setting['max']);
            $infoFamily->set(['supervisor' => $name, 'balance' => $setting['balance'], 'connection' => $setting['connection']], 1);

            foreach ($pools[$name] ?? [] as $queue => $labels) {
                foreach (array_keys($labels) as $pool) {
                    $queuesFamily->set(['supervisor' => $name, 'queue' => (string) $queue, 'pool' => (string) $pool], 1);
                }
            }
        }

        return [$processFamily, $minFamily, $maxFamily, $infoFamily, $queuesFamily];
    }

    /**
     * Queue => the queue label of the process pool that works it (Horizon's `Supervisor::createProcessPools()`): a pool
     * per queue when the supervisor balances (auto, simple), else one pool for the whole `queue` option, labelled with
     * the option as it is (`mail,sms,mail` stays). The label is taken from the process map, where Horizon writes every
     * pool, even one without processes: for balance=false the label whose queue list ({@see self::queues()}) is the
     * supervisor's. Only a record without a matching label gets one rebuilt from the queue list. A supervisor
     * without a `queue` option maps the queues of its process labels. Numeric queue names are integer keys.
     *
     * @return array<array-key, string>
     */
    private function pools(SupervisorSnapshot $snapshot): array
    {
        $labels = array_map(strval(...), array_keys($snapshot->processes));
        $pools = [];

        if ($snapshot->queues === []) {
            foreach ($labels as $label) {
                foreach ($this->queues($label) as $queue) {
                    $pools[$queue] ??= $label;
                }
            }

            return $pools;
        }

        foreach ($snapshot->queues as $queue) {
            $pool = $snapshot->balance === 'off' ? implode(',', $snapshot->queues) : $queue;

            foreach ($labels as $label) {
                if ($snapshot->balance === 'off' ? $this->queues($label) === $snapshot->queues : trim($label) === $queue) {
                    $pool = $label;

                    break;
                }
            }

            $pools[$queue] = $pool;
        }

        return $pools;
    }

    /**
     * The queues of a pool label or `queue` option as {@see Supervisors::snapshot()} splits them: comma-separated,
     * trimmed, without empty names and repeats, in order.
     *
     * @return list<string>
     */
    private function queues(string $label): array
    {
        $queues = [];

        foreach (explode(',', $label) as $queue) {
            $queue = trim($queue);

            if ($queue !== '' && ! in_array($queue, $queues, true)) {
                $queues[] = $queue;
            }
        }

        return $queues;
    }

    /**
     * @param  list<array{connection: string, queue: string}>  $queues
     */
    private function waitThresholds(array $queues): MetricFamily
    {
        $family = MetricFamily::of(Metric::WaitThreshold);

        foreach ($queues as ['connection' => $connection, 'queue' => $queue]) {
            $seconds = $this->topology->waitThreshold($connection, $queue);

            if ($seconds !== null) {
                $family->set(['connection' => $connection, 'queue' => $queue], $seconds);
            }
        }

        return $family;
    }

    /**
     * The `tts_url` label: `''` (absent) for tone only, `browser`, or the speech server URL without a trailing slash.
     */
    private function ttsUrl(?string $url): string
    {
        return $url === null ? '' : rtrim($url, '/');
    }
}
