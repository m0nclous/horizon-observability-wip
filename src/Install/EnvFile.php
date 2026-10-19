<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Install;

use RuntimeException;
use SensitiveParameter;

/**
 * Minimal reader and writer of one `KEY=value` line of a dotenv file, for `horizon-observability:install`.
 *
 * It never rewrites the rest of the file: a missing key is appended, a key without a value gets its value in place,
 * and a key with a value is never changed. "Without a value" is what Laravel's env helper reads as no value
 * ({@see self::resolve()}): `KEY=`, `KEY=""`, and `null`, `(null)`, `empty` or `(empty)` in any case, bare or quoted
 * (the skeleton's `.env.example` writes `KEY=null` for a value not set yet).
 *
 * @internal
 */
final class EnvFile
{
    /**
     * A literal value the env helper reads as null or as an empty string (Illuminate\Support\Env, case-insensitive).
     */
    private const NO_VALUE = '(?i:null|\\(null\\)|empty|\\(empty\\))';

    /**
     * Escapes phpdotenv reads in a double-quoted value.
     */
    private const ESCAPES = ['"' => '"', '\\' => '\\', '$' => '$', 'n' => "\n", 't' => "\t", 'r' => "\r", 'f' => "\f", 'v' => "\v"];

    /**
     * The value of the key: null when the key is absent, `''` when it has no value. Read as phpdotenv reads it: the
     * last definition wins, an unquoted value ends at the first `#` (a comment), a single-quoted one at the next quote,
     * and a double-quoted one at the next unescaped quote, with its escapes (`\"`, `\\`, `\$`, `\n`, ...) read.
     */
    public static function value(string $contents, string $key): ?string
    {
        $value = null;

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?'.preg_quote($key, '/').'\s*=(.*)$/', $line, $match) !== 1) {
                continue;
            }

            $raw = trim($match[1]);

            if ($raw === '' || $raw[0] === '#') {
                $value = '';
            } elseif ($raw[0] === '"') {
                $value = self::doubleQuoted($raw);
            } elseif ($raw[0] === "'") {
                $end = strpos($raw, "'", 1);
                $value = $end === false ? substr($raw, 1) : substr($raw, 1, $end - 1);
            } else {
                $value = trim(explode('#', $raw, 2)[0]);
            }
        }

        return $value;
    }

    /**
     * A double-quoted value without its quotes, up to the first unescaped quote, with its escapes read.
     */
    private static function doubleQuoted(string $raw): string
    {
        $value = '';

        for ($index = 1, $length = strlen($raw); $index < $length; $index++) {
            $char = $raw[$index];

            if ($char === '"') {
                break;
            }

            if ($char === '\\' && $index + 1 < $length) {
                $next = $raw[++$index];
                $value .= self::ESCAPES[$next] ?? '\\'.$next;

                continue;
            }

            $value .= $char;
        }

        return $value;
    }

    /**
     * What Laravel's env helper returns for a value read by {@see self::value()} (Illuminate\Support\Env::get(), all
     * supported versions): `null` and `(null)` are null, `empty` and `(empty)` are `''`, `true`, `(true)`, `false` and
     * `(false)` are booleans, case-insensitively; a value still in matching quotes loses them. Null stays null (no
     * such line).
     */
    public static function resolve(?string $value): string|bool|null
    {
        if ($value === null) {
            return null;
        }

        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'empty', '(empty)' => '',
            'null', '(null)' => null,
            default => preg_match('/\A([\'"])(.*)\1\z/s', $value, $match) === 1 ? $match[2] : $value,
        };
    }

    /**
     * Whether the key is absent or has no value for Laravel ({@see self::resolve()} is null or `''`).
     */
    public static function hasNoValue(string $contents, string $key): bool
    {
        $value = self::resolve(self::value($contents, $key));

        return $value === null || $value === '';
    }

    /**
     * The contents with the key set: appended when absent, filled in when it has no value for Laravel; unchanged when
     * it has a value. phpdotenv reads the last definition, so that one decides: when it can be filled in place (no
     * comment after it), every definition without a value is filled in place; otherwise (`KEY=null # later`,
     * `KEY=null#x`) the key is appended after it.
     */
    public static function with(string $contents, string $key, #[SensitiveParameter] string $value): string
    {
        if (! self::hasNoValue($contents, $key)) {
            return $contents;
        }

        $line = $key.'='.$value;
        $empty = '/^([ \t]*(?:export[ \t]+)?)'.preg_quote($key, '/').'[ \t]*=[ \t]*(?:"'.self::NO_VALUE.'?"|\''.self::NO_VALUE.'?\'|'.self::NO_VALUE.')?[ \t]*(?=\r?$)/m';
        $last = self::lastDefinition($contents, $key);

        if ($last !== null && preg_match($empty, $last) === 1) {
            return (string) preg_replace_callback($empty, static fn (array $match): string => $match[1].$line, $contents);
        }

        if ($contents !== '' && ! str_ends_with($contents, "\n")) {
            $contents .= "\n";
        }

        return $contents.($contents === '' ? '' : "\n").$line."\n";
    }

    /**
     * The last line that defines the key (the one phpdotenv reads), without its line break; null without one.
     */
    private static function lastDefinition(string $contents, string $key): ?string
    {
        $last = null;

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?'.preg_quote($key, '/').'\s*=/', $line) === 1) {
                $last = $line;
            }
        }

        return $last;
    }

    /**
     * Set the key in the file (see {@see self::with()}); whether the file changed.
     *
     * @throws RuntimeException when the file cannot be read or written
     */
    public static function write(string $path, string $key, #[SensitiveParameter] string $value): bool
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Cannot read %s.', $path));
        }

        $updated = self::with($contents, $key, $value);

        if ($updated === $contents) {
            return false;
        }

        if (@file_put_contents($path, $updated) === false) {
            throw new RuntimeException(sprintf('Cannot write %s.', $path));
        }

        return true;
    }
}
