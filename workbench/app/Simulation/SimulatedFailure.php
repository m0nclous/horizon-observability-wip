<?php

declare(strict_types=1);

namespace Workbench\App\Simulation;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * The exception of a simulated failure. It is not reported, so a load run does not flood the log; the attempt is still
 * counted and the failed job keeps its message.
 */
final class SimulatedFailure extends RuntimeException implements ShouldntReport
{
    public static function for(string $task, Outcome $outcome): self
    {
        return new self(sprintf('Simulated %s attempt of %s (workbench:simulate-load)', $outcome->value, $task));
    }
}
