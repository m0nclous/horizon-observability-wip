<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Http;

use DF\HorizonObservability\Config\ExceptionMode;

/**
 * The `exception` field of the failed-jobs list (Arch Q2.1, C4), from the exception Horizon stores for a failed job
 * (`(string) $exception`: `Class: message in /path/File.php:12`, then the stack trace) or its first line as
 * `job_details.lua` returns it:
 *
 * - `full`: `ShortClass: message`, the first line with the namespace of the class and the trailing
 *   ` in <file>:<line>` removed, e.g. `QueryException: SQLSTATE[HY000] ...`;
 * - `class`: `ShortClass` only, or null when the line does not start with a class name;
 * - `none`: null (the job list does not even read the text from Redis).
 *
 * The result is valid UTF-8 and at most {@see self::MAX_BYTES} bytes: the line is cut at that many bytes, a
 * multibyte character cut by a byte cap is dropped and other invalid bytes become `?`. An anonymous class keeps
 * `@anonymous` without the NUL byte, file and counter PHP puts into its name. An empty result is null.
 *
 * @internal
 */
final class ExceptionSummary
{
    /**
     * Byte cap of the first line, also passed to `job_details.lua`.
     */
    public const MAX_BYTES = 1000;

    /**
     * A PHP class name with its namespace separator: `Illuminate\Database\` of `Illuminate\Database\QueryException`.
     */
    private const NAMESPACE_PREFIX = '/^(?:[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\\\\)+/';

    /**
     * The NUL byte, file, line and counter in the name of an anonymous class (`Foo@anonymous\0/app/x.php:3$0`), or
     * everything after the NUL byte when the byte cap cut the name.
     */
    private const ANONYMOUS = '/@anonymous\x00(?:.*?\$[0-9a-f]+|.*$)/s';

    /**
     * A short PHP class name, as `class` mode returns it (optionally `@anonymous`).
     */
    private const SHORT_CLASS = '/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:@anonymous)?\z/';

    /**
     * The location PHP appends to the message: ` in ` and an absolute path (Unix, Windows drive, UNC or a stream
     * wrapper such as `phar://`) ending in `:<line>`. The prefix is greedy, so the last such suffix is removed.
     */
    private const LOCATION = '~^(.*) in (?:[/\\\\]|[A-Za-z]:[/\\\\]|[a-z][a-z0-9+.-]*://)\S.*:\d+$~s';

    /**
     * An incomplete UTF-8 sequence at the end of the string (a character cut by a byte cap).
     */
    private const CUT_CHARACTER = '/(?:[\xC2-\xDF]|[\xE0-\xEF][\x80-\xBF]?|[\xF0-\xF4][\x80-\xBF]{0,2})$/';

    /**
     * @param  string|null  $exception  the stored exception or its first line; null = none
     */
    public static function summarize(?string $exception, ExceptionMode $mode): ?string
    {
        if ($exception === null || $mode === ExceptionMode::None) {
            return null;
        }

        $line = self::firstLine($exception);
        $line = str_replace("\0", '', (string) preg_replace(self::ANONYMOUS, '@anonymous', $line));

        if (preg_match(self::LOCATION, $line, $match) === 1) {
            $line = $match[1];
        }

        $line = (string) preg_replace(self::NAMESPACE_PREFIX, '', $line);

        if ($mode === ExceptionMode::ClassOnly) {
            $line = trim(explode(': ', $line, 2)[0]);

            // Only a class name: a first line that does not start with one (an exception with its own
            // __toString(), or text that is not `Class: message`) could carry anything, so it gives nothing.
            return preg_match(self::SHORT_CLASS, $line) === 1 ? $line : null;
        }

        $line = trim($line);

        return $line === '' ? null : $line;
    }

    /**
     * The text before the first line break, cut to {@see self::MAX_BYTES} bytes, as valid UTF-8.
     */
    private static function firstLine(string $exception): string
    {
        $line = substr($exception, 0, min(strcspn($exception, "\r\n"), self::MAX_BYTES));

        if (mb_check_encoding($line, 'UTF-8')) {
            return $line;
        }

        return mb_scrub((string) preg_replace(self::CUT_CHARACTER, '', $line), 'UTF-8');
    }
}
