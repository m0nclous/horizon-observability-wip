<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Redis;

use Closure;
use DF\HorizonObservability\Support\FailureReporter;
use Throwable;

/**
 * Per-process circuit breaker of the worker-side storage writes (Arch Q6.6, C11), shared by `AttemptStore`,
 * `BatchLinks` and `LegacyReadyAt`.
 *
 * - A write that throws (anything: a connection or timeout error, a Lua failure, a missing connection) opens the
 *   breaker for {@see self::COOLDOWN_SECONDS}; one warning is logged per opening, without a cache round trip
 *   ({@see FailureReporter::reportLocally()}).
 * - While open, writes are skipped; {@see self::attempt()} returns false. Only the {@see self::ATTEMPTS} operation's
 *   skipped or failed writes count as dropped (`exporter_attempts_dropped_total`, "job attempts not recorded"); a
 *   dropped ready_at or batch-link write is not an attempt.
 * - After the cooldown the next write probes. Success closes the breaker and logs one info line with the outage
 *   length and the attempts dropped meanwhile; failure re-opens it without a new warning.
 * - {@see self::takeDropped()} hands the dropped count to the write that persists it (`meta.dropped` inside the record
 *   `EVAL`). Called inside a write that then fails, the count is restored, so nothing is lost.
 *
 * Under PHP-FPM the container, and so the breaker, lives for one request, which bounds the cost to one timeout per
 * request. Reads do not use the breaker.
 *
 * @internal
 */
final class CircuitBreaker
{
    public const COOLDOWN_SECONDS = 30.0;

    /**
     * The operation of `AttemptStore::record()`, the only one whose drops are counted.
     */
    public const ATTEMPTS = 'attempts';

    /**
     * @var Closure(): float
     */
    private readonly Closure $clock;

    private ?float $openedAt = null;

    private ?float $retryAt = null;

    private int $dropped = 0;

    private int $droppedWhileOpen = 0;

    private int $taken = 0;

    private bool $writing = false;

    /**
     * @param  (Closure(): float)|null  $clock  monotonic seconds, for tests; default `hrtime()`
     */
    public function __construct(private readonly FailureReporter $reporter, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    /**
     * Run a write unless the breaker is open. Never throws.
     *
     * @param  Closure(): void  $write
     * @return bool true when the write ran and succeeded
     */
    public function attempt(string $operation, Closure $write): bool
    {
        $now = ($this->clock)();
        $counted = $operation === self::ATTEMPTS ? 1 : 0;

        if ($this->retryAt !== null && $now < $this->retryAt) {
            $this->dropped += $counted;
            $this->droppedWhileOpen++;

            return false;
        }

        $this->writing = true;
        $this->taken = 0;

        try {
            $write();
        } catch (Throwable $e) {
            $this->dropped += $this->taken + $counted;
            $this->droppedWhileOpen++;
            $this->retryAt = $now + self::COOLDOWN_SECONDS;

            if ($this->openedAt === null) {
                $this->openedAt = $now;
                // In-process throttle only: the default cache store often shares the unreachable Redis, and its
                // add() would cost another timeout on top of the one this opening bounds (C11).
                $this->reporter->reportLocally('breaker:'.$operation, $e, ['cooldown_seconds' => self::COOLDOWN_SECONDS]);
            }

            return false;
        } finally {
            $this->writing = false;
            $this->taken = 0;
        }

        if ($this->openedAt !== null) {
            $this->reporter->info(sprintf(
                '%s recovered after %ds, %d writes dropped.',
                $operation,
                (int) round($now - $this->openedAt),
                $this->droppedWhileOpen,
            ));

            $this->openedAt = null;
            $this->retryAt = null;
            $this->droppedWhileOpen = 0;
        }

        return true;
    }

    /**
     * Whether writes are being skipped right now (open and cooling down).
     */
    public function isOpen(): bool
    {
        return $this->retryAt !== null && ($this->clock)() < $this->retryAt;
    }

    /**
     * Attempts dropped since the last call; resets the count.
     */
    public function takeDropped(): int
    {
        $dropped = $this->dropped;
        $this->dropped = 0;

        if ($this->writing) {
            $this->taken += $dropped;
        }

        return $dropped;
    }
}
