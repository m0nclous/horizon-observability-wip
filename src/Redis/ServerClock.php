<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Redis;

use InvalidArgumentException;

/**
 * The clock of the new-series hold: the storage Redis' own `TIME`.
 *
 * `record_attempt.lua` stamps the creation time of a new counter series with `TIME` and `new_series.lua` measures
 * its age with `TIME` again, both inside the script on the node that holds the package keys. The clocks of the
 * workers, of the exporter replicas and of the scrapers therefore never enter the hold, and every replica decides it
 * on the same clock.
 *
 * Tests pin it: a pinned time is passed to both scripts and stands in for `TIME`. The package never pins it. A
 * container singleton (the pin lives as long as the application).
 *
 * @internal
 */
final class ServerClock
{
    private ?float $pinned = null;

    /**
     * Pin the clock to a Unix time, or unpin it with null.
     */
    public function pin(?float $seconds): void
    {
        if ($seconds !== null && ! is_finite($seconds)) {
            throw new InvalidArgumentException('The server clock can only be pinned to a finite Unix time.');
        }

        $this->pinned = $seconds;
    }

    /**
     * The pinned Unix time, or null for the server's `TIME`.
     */
    public function pinned(): ?float
    {
        return $this->pinned;
    }

    /**
     * The scripts' `ARGV` form: `''` (they read `TIME`) or the pinned time.
     */
    public function argument(): string
    {
        return $this->pinned === null ? '' : Lua::argument($this->pinned);
    }
}
