<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Ingest;

use InvalidArgumentException;

/**
 * The protobuf encoding of the remote-write 1.0 `prometheus.WriteRequest` (prompb/remote.proto, types.proto), the
 * only message the ingest sends:
 *
 * - WriteRequest: 1 repeated TimeSeries timeseries;
 * - TimeSeries: 1 repeated Label labels (sorted by name, `__name__` included), 2 repeated Sample samples;
 * - Label: 1 string name, 2 string value;
 * - Sample: 1 double value (fixed64), 2 int64 timestamp (milliseconds, varint).
 *
 * A null value is a Prometheus staleness marker, written as the exact bit pattern of StaleNaN
 * (`0x7ff0000000000002`, `model/value.StaleNaN`), which a PHP float could not carry reliably.
 */
final class Protobuf
{
    public const STALE_NAN = 0x7FF0000000000002;

    /**
     * @param  iterable<array{labels: array<string, string>, samples: list<array{0: int, 1: float|null}>}>  $series
     */
    public static function writeRequest(iterable $series): string
    {
        $out = '';

        foreach ($series as $one) {
            $out .= self::field(1, self::timeSeries($one['labels'], $one['samples']));
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $labels
     * @param  list<array{0: int, 1: float|null}>  $samples
     */
    public static function timeSeries(array $labels, array $samples): string
    {
        ksort($labels, SORT_STRING);
        $out = '';

        foreach ($labels as $name => $value) {
            $out .= self::field(1, self::field(1, (string) $name).self::field(2, $value));
        }

        foreach ($samples as [$time, $value]) {
            $bits = $value === null ? pack('P', self::STALE_NAN) : pack('e', $value);
            $out .= self::field(2, chr((1 << 3) | 1).$bits.chr((2 << 3) | 0).self::varint($time));
        }

        return $out;
    }

    /**
     * A length-delimited field.
     */
    public static function field(int $number, string $bytes): string
    {
        return self::varint(($number << 3) | 2).self::varint(strlen($bytes)).$bytes;
    }

    public static function varint(int $value): string
    {
        if ($value < 0) {
            throw new InvalidArgumentException('Negative varints are not used by the write request.');
        }

        $out = '';

        while ($value >= 0x80) {
            $out .= chr(($value & 0x7F) | 0x80);
            $value >>= 7;
        }

        return $out.chr($value);
    }

    public static function readVarint(string $bytes, int &$offset): int
    {
        $value = 0;
        $shift = 0;

        while (true) {
            if (! isset($bytes[$offset])) {
                throw new InvalidArgumentException('Truncated varint.');
            }

            $byte = ord($bytes[$offset++]);
            $value |= ($byte & 0x7F) << $shift;

            if ($byte < 0x80) {
                return $value;
            }

            $shift += 7;
        }
    }

    /**
     * Decodes a write request again (for the unit tests): every series with its labels and samples, a StaleNaN as
     * null.
     *
     * @return list<array{labels: array<string, string>, samples: list<array{0: int, 1: float|null}>}>
     */
    public static function decodeWriteRequest(string $bytes): array
    {
        $series = [];

        foreach (self::fields($bytes) as [$number, $payload]) {
            if ($number !== 1 || ! is_string($payload)) {
                continue;
            }

            $labels = [];
            $samples = [];

            foreach (self::fields($payload) as [$field, $value]) {
                if ($field === 1 && is_string($value)) {
                    $label = ['', ''];

                    foreach (self::fields($value) as [$part, $text]) {
                        $label[$part - 1] = (string) $text;
                    }

                    $labels[$label[0]] = $label[1];
                } elseif ($field === 2 && is_string($value)) {
                    $sample = [0, 0.0];

                    foreach (self::fields($value) as [$part, $raw]) {
                        if ($part === 1 && is_string($raw)) {
                            $sample[1] = unpack('P', $raw)[1] === self::STALE_NAN ? null : (float) unpack('e', $raw)[1];
                        } elseif ($part === 2 && is_int($raw)) {
                            $sample[0] = $raw;
                        }
                    }

                    $samples[] = $sample;
                }
            }

            $series[] = ['labels' => $labels, 'samples' => $samples];
        }

        return $series;
    }

    /**
     * @return list<array{0: int, 1: int|string}>
     */
    private static function fields(string $bytes): array
    {
        $fields = [];
        $offset = 0;

        while ($offset < strlen($bytes)) {
            $key = self::readVarint($bytes, $offset);
            $number = $key >> 3;
            $fields[] = match ($key & 7) {
                0 => [$number, self::readVarint($bytes, $offset)],
                1 => [$number, substr($bytes, ($offset += 8) - 8, 8)],
                2 => (static function () use ($bytes, &$offset, $number): array {
                    $length = self::readVarint($bytes, $offset);
                    $offset += $length;

                    return [$number, substr($bytes, $offset - $length, $length)];
                })(),
                default => throw new InvalidArgumentException('Unexpected wire type.'),
            };
        }

        return $fields;
    }
}
