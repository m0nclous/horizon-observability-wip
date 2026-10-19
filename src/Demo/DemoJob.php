<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Demo;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use RuntimeException;

/**
 * A sample job of `horizon-observability:demo`: it sleeps for its duration, then completes, fails,
 * fails once and succeeds on the retry, or releases itself once. It touches nothing else, so a demo run is safe to
 * repeat. Horizon and the dashboard show it as `Demo\<name>`.
 *
 * @internal
 */
final class DemoJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const OUTCOMES = ['completed', 'failed', 'retried', 'released'];

    /**
     * Attempts: 2 for the outcomes that need a second attempt.
     */
    public int $tries;

    /**
     * Seconds before the retry of a `retried` job.
     */
    public int $backoff = 1;

    public function __construct(public string $name, public string $outcome, public int $milliseconds)
    {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new InvalidArgumentException(sprintf('Unknown demo outcome [%s].', $outcome));
        }

        $this->tries = in_array($outcome, ['retried', 'released'], true) ? 2 : 1;
    }

    public function displayName(): string
    {
        return 'Demo\\'.$this->name;
    }

    public function handle(): void
    {
        Sleep::for(max(0, $this->milliseconds))->milliseconds();
        $first = $this->attempts() <= 1;

        if ($this->outcome === 'failed') {
            throw new RuntimeException('Demo: the sample payment provider did not answer.');
        }

        if ($this->outcome === 'retried' && $first) {
            throw new RuntimeException('Demo: a sample timeout, retried once.');
        }

        if ($this->outcome === 'released' && $first) {
            $this->release(1);
        }
    }
}
