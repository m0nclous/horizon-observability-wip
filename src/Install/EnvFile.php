<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Install;

use RuntimeException;
use SensitiveParameter;

/**
 * Minimal reader and writer of one `KEY=value` line of a dotenv file, for `horizon-observability:install`.
 *
 * It never rewrites the rest of the file: a missing key is appended, a key without a value (`KEY=` or `KEY=""`) gets
 * its value in place, and a key with a value is never changed.
 *
 * @internal
 */
final class EnvFile
{
    /**
     * The value of the key: null when the key is absent, `''` when it has no value. Surrounding quotes are removed;
     * the last definition wins, as in phpdotenv.
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
            } elseif ($raw[0] === '"' || $raw[0] === "'") {
                $quote = $raw[0];
                $end = strpos($raw, $quote, 1);
                $value = $end === false ? substr($raw, 1) : substr($raw, 1, $end - 1);
            } else {
                $value = trim((string) preg_replace('/\s+#.*$/', '', $raw));
            }
        }

        return $value;
    }

    /**
     * The contents with the key set: appended when absent, filled in when it has no value; unchanged when it has a
     * value.
     */
    public static function with(string $contents, string $key, #[SensitiveParameter] string $value): string
    {
        $current = self::value($contents, $key);

        if ($current !== null && $current !== '') {
            return $contents;
        }

        $line = $key.'='.$value;

        if ($current === '') {
            $filled = (string) preg_replace_callback(
                '/^([ \t]*(?:export[ \t]+)?)'.preg_quote($key, '/').'[ \t]*=[ \t]*(?:""|\'\')?[ \t]*(?=\r?$)/m',
                static fn (array $match): string => $match[1].$line,
                $contents,
            );

            // An empty value with a comment after it: a later line wins, as in phpdotenv.
            if ($filled !== $contents) {
                return $filled;
            }
        }

        if ($contents !== '' && ! str_ends_with($contents, "\n")) {
            $contents .= "\n";
        }

        return $contents.($contents === '' ? '' : "\n").$line."\n";
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
