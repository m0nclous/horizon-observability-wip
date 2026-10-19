<?php

declare(strict_types=1);

/*
| The clock shims of the recorder (design section 4): namespaced functions that PHP's fallback for unqualified calls
| finds before the global ones, so Horizon's and Illuminate\Bus' unqualified microtime() and time() calls read the
| scenario's virtual clock (Record\HorizonClock), and pass through to the real clock without a test now.
|
| Load order matters: PHP caches how it resolves an unqualified call per call site, so a call made before this file
| is loaded keeps the global function for the rest of the process (design X1). RecordTest requires this file at file
| load, and only with DASHBOARD_TRUTH_RECORD=1, so a normal Pest run never defines these functions.
|
| Call sites (Horizon 5.50.1, Laravel 13): JobPayload.php:104 and Stopwatch.php:22/34 (Laravel\Horizon);
| RedisJobRepository.php and RedisTagRepository.php (Laravel\Horizon\Repositories); DatabaseBatchRepository.php
| (Illuminate\Bus). The recorder's guards (pushedAt, job-record stamps and batch timestamps) fail with a pointer to
| this file when an update stops calling them unqualified.
*/

namespace Laravel\Horizon {
    use DF\HorizonObservability\Tests\DashboardTruth\Record\HorizonClock;

    if (! function_exists('Laravel\Horizon\microtime')) {
        function microtime(bool $as_float = false): string|float
        {
            return HorizonClock::frozen($as_float);
        }
    }
}

namespace Laravel\Horizon\Repositories {
    use DF\HorizonObservability\Tests\DashboardTruth\Record\HorizonClock;

    if (! function_exists('Laravel\Horizon\Repositories\microtime')) {
        function microtime(bool $as_float = false): string|float
        {
            return HorizonClock::drifting($as_float);
        }
    }
}

namespace Illuminate\Bus {
    use DF\HorizonObservability\Tests\DashboardTruth\Record\HorizonClock;

    if (! function_exists('Illuminate\Bus\time')) {
        function time(): int
        {
            return HorizonClock::seconds();
        }
    }
}
