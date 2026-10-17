<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Queues;

/**
 * State of one queue read by `queue_state.lua` (Arch Q6.3, Appendix D, C8).
 *
 * @internal
 */
final readonly class QueueSnapshot
{
    /**
     * @param  int  $length  `LLEN queues:<q>` (ready jobs not counting due delayed ones)
     * @param  int  $reserved  reserved jobs (`ZCARD :reserved`)
     * @param  int  $delayed  delayed jobs including due ones (`ZCARD :delayed`)
     * @param  int  $delayedDue  delayed jobs whose time has come
     * @param  float|null  $earliestDueAt  the earliest due delayed score (Unix seconds)
     * @param  float|null  $firstFreshReadyAt  readiness of the first `attempts == 0` payload in the scan window
     * @param  int  $scanned  payloads inspected from the head
     * @param  array<string, int>  $pendingByClass  normalised job class => ready jobs in the scanned head
     * @param  array<string, int>  $reservedByClass  normalised job class => reserved jobs
     */
    public function __construct(
        public string $connection,
        public string $queue,
        public int $length,
        public int $reserved,
        public int $delayed,
        public int $delayedDue,
        public ?float $earliestDueAt,
        public ?float $firstFreshReadyAt,
        public int $scanned,
        public array $pendingByClass = [],
        public array $reservedByClass = [],
    ) {}

    public static function empty(string $connection, string $queue): self
    {
        return new self($connection, $queue, 0, 0, 0, 0, null, null, 0);
    }

    /**
     * Ready jobs, including due delayed ones (`queue_pending_jobs`).
     */
    public function pending(): int
    {
        return $this->length + $this->delayedDue;
    }

    /**
     * Delayed jobs whose time has not come yet.
     */
    public function delayedNotDue(): int
    {
        return max(0, $this->delayed - $this->delayedDue);
    }

    /**
     * C8: seconds the oldest ready first-attempt job has waited, `now - min(first fresh readiness, earliest due)`;
     * 0 when nothing is ready; null (unknown) when jobs are ready but neither time is known within the scan window.
     */
    public function oldestAgeSeconds(float $now): ?float
    {
        if ($this->pending() === 0) {
            return 0.0;
        }

        $times = array_filter([$this->firstFreshReadyAt, $this->earliestDueAt], static fn (?float $time): bool => $time !== null);

        return $times === [] ? null : max(0.0, $now - min($times));
    }
}
