<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Truth\Support;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use InvalidArgumentException;
use RuntimeException;

/**
 * The job of the truth scenarios: its `job_class` label is {@see self::$label} (Laravel's `displayName()`), and it
 * follows a plan with one step per attempt, so the scenario knows every outcome in advance ({@see Model}):
 *
 * - `complete`: returns (status `completed`);
 * - `throw`: throws; `retried` while attempts are left, else `failed`;
 * - `fail`: calls `fail()` (status `failed`);
 * - `release:<seconds>`: releases itself with that delay (status `released`); popped again after its last try,
 *   the worker fails it without running it (`failed` without a duration);
 * - `hang`: sleeps for 30 s, for a timeout in a forked worker ({@see Scenario::timeOutInChild()}).
 *
 * Steps after the plan complete. Every attempt first sleeps {@see self::$sleepMs}, the known duration, and with
 * {@see self::$countRuns} counts itself in the cache ({@see self::RUNS}), which proves that a job ran where the
 * exporter must not see it (sync, deferred, background, database).
 */
final class TruthJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Cache key of the run counter of the jobs that count their runs.
     */
    public const RUNS = 'truth-job-runs';

    /**
     * @param  list<string>  $plan
     */
    public function __construct(
        public string $label,
        public array $plan = ['complete'],
        public int $tries = 1,
        public int $sleepMs = 0,
        public int $backoff = 0,
        public ?int $timeout = null,
        public bool $countRuns = false,
    ) {
        foreach ($plan as $step) {
            if (! in_array($step, ['complete', 'throw', 'fail', 'hang'], true) && preg_match('/^release:\d+$/', $step) !== 1) {
                throw new InvalidArgumentException(sprintf('Unknown step "%s".', $step));
            }
        }
    }

    public function displayName(): string
    {
        return $this->label;
    }

    public function handle(): void
    {
        $step = $this->plan[$this->attempts() - 1] ?? 'complete';

        if ($this->countRuns) {
            cache()->increment(self::RUNS);
        }

        if ($this->sleepMs > 0) {
            usleep($this->sleepMs * 1000);
        }

        if ($step === 'throw') {
            throw new RuntimeException('Truth scenario: planned exception.');
        }

        if ($step === 'fail') {
            $this->fail(new RuntimeException('Truth scenario: planned failure.'));

            return;
        }

        if ($step === 'hang') {
            sleep(30);

            return;
        }

        if (str_starts_with($step, 'release:')) {
            $this->release((int) substr($step, strlen('release:')));
        }
    }
}
