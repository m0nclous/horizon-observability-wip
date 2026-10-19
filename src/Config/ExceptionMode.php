<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Config;

/**
 * How much of a failed job's exception the failed-jobs list shows (`exceptions` config key).
 *
 * The values are public config values and SemVer-covered.
 */
enum ExceptionMode: string
{
    /**
     * Short exception class and message, first line only.
     */
    case Full = 'full';

    /**
     * Short exception class only.
     */
    case ClassOnly = 'class';

    /**
     * No exception text (`exception` is null).
     */
    case None = 'none';
}
