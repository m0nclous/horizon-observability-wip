<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana;

use InvalidArgumentException;
use stdClass;

/**
 * Deterministic JSON printer for the rendered Grafana assets.
 *
 * The output is byte-compatible with Python's `json.dumps(value, ensure_ascii=False, indent=2) + "\n"`,
 * which produced the legacy dashboard: two-space indentation, a bare `,` at the end of each item line, `": "` after
 * keys, raw (unescaped) Unicode and slashes, `[]` and `{}` for empty containers and Python's `repr()` for floats
 * (`0.9`, `1.0`, `1e-05`, `1e+16`). PHP lists print as JSON arrays, every other array and every
 * `stdClass` prints as a JSON object, so an empty object is written as `new stdClass()`, and so is an object
 * whose only keys are `"0"`, `"1"`, ... (PHP turns those keys into a list).
 *
 * The output depends on no ini setting (`precision`, `serialize_precision`) and no locale.
 *
 * @internal
 */
final class Json
{
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR;

    private const INDENT = '  ';

    /**
     * Python switches `repr()` of a float to scientific notation outside 1e-4 <= |x| < 1e16.
     */
    private const MIN_POSITIONAL_EXPONENT = -4;

    private const MAX_POSITIONAL_EXPONENT = 15;

    /**
     * Digits after the point of the 17 significant digits that identify any float.
     */
    private const MAX_PRECISION = 16;

    /**
     * Prints the document followed by a newline.
     *
     * @param  array<array-key, mixed>|stdClass  $document
     */
    public static function encode(array|stdClass $document): string
    {
        return self::value($document, '')."\n";
    }

    /**
     * A number as {@see encode()} prints it, for numbers Grafana takes as text (such as the `fixed` operand of a
     * calculation): `(string)` of a float follows the `precision` ini setting.
     */
    public static function number(int|float $value): string
    {
        return is_int($value) ? (string) $value : self::float($value);
    }

    private static function value(mixed $value, string $indent): string
    {
        if ($value instanceof stdClass) {
            return self::members(get_object_vars($value), $indent);
        }

        if (is_array($value)) {
            return array_is_list($value) ? self::items($value, $indent) : self::members($value, $indent);
        }

        if (is_float($value)) {
            return self::float($value);
        }

        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) {
            return json_encode($value, self::FLAGS);
        }

        throw new InvalidArgumentException('Cannot print a value of type '.get_debug_type($value).' as JSON.');
    }

    /**
     * @param  list<mixed>  $items
     */
    private static function items(array $items, string $indent): string
    {
        if ($items === []) {
            return '[]';
        }

        $inner = $indent.self::INDENT;
        $lines = array_map(static fn (mixed $item): string => $inner.self::value($item, $inner), $items);

        return "[\n".implode(",\n", $lines)."\n".$indent.']';
    }

    /**
     * @param  array<array-key, mixed>  $members
     */
    private static function members(array $members, string $indent): string
    {
        if ($members === []) {
            return '{}';
        }

        $inner = $indent.self::INDENT;
        $lines = [];

        foreach ($members as $key => $member) {
            $lines[] = $inner.json_encode((string) $key, self::FLAGS).': '.self::value($member, $inner);
        }

        return "{\n".implode(",\n", $lines)."\n".$indent.'}';
    }

    /**
     * Python `repr()` of a float: the shortest digits that read back to the same value.
     */
    private static function float(float $value): string
    {
        if (! is_finite($value)) {
            throw new InvalidArgumentException('JSON cannot represent '.$value.'.');
        }

        // sprintf() drops the sign of -0.0, so the sign is taken separately.
        $sign = $value < 0 || fdiv(1, $value) < 0 ? '-' : '';
        [$digits, $exponent] = self::shortest(abs($value));

        if ($digits === '') {
            return $sign.'0.0';
        }

        if ($exponent < self::MIN_POSITIONAL_EXPONENT || $exponent > self::MAX_POSITIONAL_EXPONENT) {
            $mantissa = strlen($digits) > 1 ? $digits[0].'.'.substr($digits, 1) : $digits;

            return sprintf('%s%se%s%02d', $sign, $mantissa, $exponent < 0 ? '-' : '+', abs($exponent));
        }

        if ($exponent < 0) {
            return $sign.'0.'.str_repeat('0', -$exponent - 1).$digits;
        }

        $integer = str_pad(substr($digits, 0, $exponent + 1), $exponent + 1, '0');
        $fraction = substr($digits, $exponent + 1);

        return $sign.$integer.'.'.($fraction === '' ? '0' : $fraction);
    }

    /**
     * The significant digits (without trailing zeros; empty for zero) and the decimal exponent of the shortest
     * decimal that reads back as $magnitude, the nearest one when several of that length do (Python's `repr()`,
     * David Gay's shortest mode).
     *
     * For each length, sprintf() gives the correctly rounded candidate. Where it does not read back, its
     * neighbour on the other side of $magnitude still can: at a power of two the rounding interval of a float
     * is half as wide below as above.
     *
     * @return array{0: string, 1: int}
     */
    private static function shortest(float $magnitude): array
    {
        for ($precision = 0; ; $precision++) {
            $nearest = sprintf('%.'.$precision.'e', $magnitude);
            [$mantissa, $exponent] = explode('e', $nearest);
            $digits = str_replace('.', '', $mantissa);
            $exponent = (int) $exponent;

            // 17 significant digits always read back
            if ((float) $nearest === $magnitude || $precision >= self::MAX_PRECISION) {
                return [rtrim($digits, '0'), $exponent];
            }

            $neighbour = (string) ((int) $digits + ((float) $nearest < $magnitude ? 1 : -1));

            if ((float) ($neighbour.'e'.($exponent - strlen($digits) + 1)) === $magnitude) {
                return [rtrim($neighbour, '0'), $exponent + strlen($neighbour) - strlen($digits)];
            }
        }
    }
}
