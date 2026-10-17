<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Grafana\Support;

use stdClass;

/**
 * The first difference between two decoded JSON documents (objects as stdClass), key order included, as a
 * readable message: a byte comparison of 240 KB says nothing about where a port went wrong.
 */
final class JsonDiff
{
    public static function first(mixed $actual, mixed $expected, string $path = ''): ?string
    {
        if ($actual instanceof stdClass && $expected instanceof stdClass) {
            $actual = get_object_vars($actual);
            $expected = get_object_vars($expected);

            if (array_keys($actual) !== array_keys($expected)) {
                return sprintf(
                    "%s: keys differ\n  actual:   %s\n  expected: %s",
                    self::label($path),
                    implode(', ', array_keys($actual)),
                    implode(', ', array_keys($expected)),
                );
            }

            return self::children($actual, $expected, $path);
        }

        if (is_array($actual) && is_array($expected)) {
            if (count($actual) !== count($expected)) {
                return sprintf('%s: %d items instead of %d', self::label($path), count($actual), count($expected));
            }

            return self::children($actual, $expected, $path);
        }

        if ($actual === $expected) {
            return null;
        }

        return sprintf(
            "%s: values differ\n  actual:   %s\n  expected: %s",
            self::label($path),
            var_export($actual, true),
            var_export($expected, true),
        );
    }

    /**
     * @param  array<array-key, mixed>  $actual
     * @param  array<array-key, mixed>  $expected
     */
    private static function children(array $actual, array $expected, string $path): ?string
    {
        foreach ($actual as $key => $value) {
            $difference = self::first($value, $expected[$key], $path.'/'.$key);

            if ($difference !== null) {
                return $difference;
            }
        }

        return null;
    }

    private static function label(string $path): string
    {
        return $path === '' ? '/' : $path;
    }
}
