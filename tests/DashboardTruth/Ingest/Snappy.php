<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Ingest;

use InvalidArgumentException;

/**
 * The snappy block format of the Prometheus remote-write protocol, written as literals only (design section 6.3): a
 * valid snappy stream without compression, so no extension is needed. Prometheus decodes any valid block, and the
 * requests stay small enough (one batch of 60 timestamps) for that not to matter.
 *
 * Block format: the uncompressed length as a varint, then elements; a literal element is a tag byte
 * `(length - 1) << 2` (lengths up to 60) or `60..63 << 2` followed by `length - 1` in 1 to 4 little-endian bytes. The
 * decoder also reads copy elements, so the unit tests can check it on any valid block.
 */
final class Snappy
{
    private const CHUNK = 65536;

    public static function compress(string $data): string
    {
        $out = Protobuf::varint(strlen($data));
        $offset = 0;
        $length = strlen($data);

        while ($offset < $length) {
            $chunk = substr($data, $offset, self::CHUNK);
            $n = strlen($chunk) - 1;
            $out .= match (true) {
                $n < 60 => chr($n << 2),
                $n < 0x100 => chr(60 << 2).chr($n),
                default => chr(61 << 2).pack('v', $n),
            };
            $out .= $chunk;
            $offset += strlen($chunk);
        }

        return $out;
    }

    public static function decompress(string $block): string
    {
        $offset = 0;
        $expected = Protobuf::readVarint($block, $offset);
        $out = '';
        $length = strlen($block);

        while ($offset < $length) {
            $tag = ord($block[$offset++]);

            switch ($tag & 3) {
                case 0:
                    $n = $tag >> 2;

                    if ($n >= 60) {
                        $bytes = $n - 59;
                        $n = 0;

                        for ($i = 0; $i < $bytes; $i++) {
                            $n |= ord($block[$offset++]) << (8 * $i);
                        }
                    }

                    $out .= substr($block, $offset, $n + 1);
                    $offset += $n + 1;

                    break;

                case 1:
                    $n = (($tag >> 2) & 7) + 4;
                    $distance = (($tag >> 5) << 8) | ord($block[$offset++]);
                    $out = self::copy($out, $distance, $n);

                    break;

                case 2:
                    $n = ($tag >> 2) + 1;
                    $distance = unpack('v', substr($block, $offset, 2))[1];
                    $offset += 2;
                    $out = self::copy($out, (int) $distance, $n);

                    break;

                default:
                    $n = ($tag >> 2) + 1;
                    $distance = unpack('V', substr($block, $offset, 4))[1];
                    $offset += 4;
                    $out = self::copy($out, (int) $distance, $n);
            }
        }

        if (strlen($out) !== $expected) {
            throw new InvalidArgumentException(sprintf('The snappy block holds %d bytes, its header says %d.', strlen($out), $expected));
        }

        return $out;
    }

    private static function copy(string $out, int $distance, int $length): string
    {
        if ($distance <= 0 || $distance > strlen($out)) {
            throw new InvalidArgumentException('Bad snappy copy offset.');
        }

        $start = strlen($out) - $distance;

        for ($i = 0; $i < $length; $i++) {
            $out .= $out[$start + $i];
        }

        return $out;
    }
}
