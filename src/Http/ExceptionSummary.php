<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Http;

use DF\HorizonObservability\Config\ExceptionMode;

/**
 * The `exception` field of failed jobs: `ShortClass: message` (first line, without ` in file:line`, at most 1000
 * bytes, scrubbed UTF-8), the short class only, or nothing, by {@see ExceptionMode} (Arch Q2.1, C4).
 *
 * Stub (T02): the first line as stored (no class shortening, no cap) or null for `none`. Owned by T06 (WP4).
 *
 * @internal
 */
final class ExceptionSummary
{
    /**
     * @param  string|null  $exception  the first exception line (from `job_details.lua`) or Horizon's stored text
     */
    public static function summarize(?string $exception, ExceptionMode $mode): ?string
    {
        if ($exception === null || $mode === ExceptionMode::None) {
            return null;
        }

        $line = strtok($exception, "\r\n");

        return $line === false ? null : $line;
    }
}
