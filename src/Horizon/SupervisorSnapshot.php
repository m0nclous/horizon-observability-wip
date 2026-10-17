<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Horizon;

/**
 * One running Horizon supervisor as Horizon's repository reports it (Arch Q6, C14).
 *
 * @internal
 */
final readonly class SupervisorSnapshot
{
    /**
     * @param  string  $name  the name after the last `:` (master prefix stripped), e.g. `supervisor-default`
     * @param  string  $master  the master supervisor's name
     * @param  string  $connection  the Laravel queue connection (e.g. `redis`)
     * @param  string  $balance  `auto`, `simple` or `off`
     * @param  array<string, int>  $processes  queue label => processes; balance=false pools keep Horizon's `a,b` label
     * @param  list<string>  $queues  the split queue names of `options.queue`
     */
    public function __construct(
        public string $name,
        public string $master,
        public string $connection,
        public string $balance,
        public int $minProcesses,
        public int $maxProcesses,
        public array $processes,
        public array $queues,
    ) {}

    /**
     * Running worker processes over all pools.
     */
    public function totalProcesses(): int
    {
        return array_sum($this->processes);
    }
}
