<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Support;

/**
 * Outcome of one `horizon-observability:check` item. The values appear in `check --json`.
 *
 * @internal
 */
enum CheckStatus: string
{
    case Ok = 'ok';
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';

    /**
     * Severity for sorting and the overall status: ok and info 0, warning 1, error 2.
     */
    public function severity(): int
    {
        return match ($this) {
            self::Ok, self::Info => 0,
            self::Warning => 1,
            self::Error => 2,
        };
    }
}
