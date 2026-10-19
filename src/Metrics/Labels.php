<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Metrics;

/**
 * Label value rules shared by every collector: the English sentinels, sanitising and top-N folding.
 *
 * @internal
 */
final class Labels
{
    /**
     * Pending jobs beyond the queue scan limit (replaces the pilot's Russian sentinel).
     */
    public const UNSCANNED = '(unscanned)';

    /**
     * Empty or missing payload `displayName`.
     */
    public const UNKNOWN = '(unknown)';

    /**
     * Cardinality overflow: classes or kinds folded together.
     */
    public const OTHER = '(other)';

    /**
     * A batch without a name.
     */
    public const UNNAMED = 'unnamed';

    /**
     * Maximum length of a label value in bytes, including the suffix of a cut value ({@see self::ELLIPSIS} and the
     * hash).
     */
    public const MAX_BYTES = 200;

    public const ELLIPSIS = '...';

    /**
     * Valid UTF-8 (invalid bytes become `?`), without control characters (this includes the `\x1f` field separator
     * of the storage hashes and newlines) and at most {@see self::MAX_BYTES} bytes. A longer value is cut on a
     * character boundary and ends with `...` and the CRC32 of the whole value (8 hex digits), so two long values
     * that share their first bytes stay apart: the queue lists of a balance=false pool before and after a deploy
     * that adds a queue at the end would otherwise be one label set twice, and the exporter drops a family with a
     * duplicate label set.
     */
    public static function sanitize(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_scrub($value, 'UTF-8');
        }

        $value = (string) preg_replace('/\p{Cc}+/u', '', $value);

        if (strlen($value) > self::MAX_BYTES) {
            $suffix = self::ELLIPSIS.hash('crc32b', $value);

            return mb_strcut($value, 0, self::MAX_BYTES - strlen($suffix), 'UTF-8').$suffix;
        }

        return $value;
    }

    /**
     * The $n largest counts (ties by key), largest first, plus {@see self::OTHER} with the sum of the rest. An
     * existing `(other)` entry never competes for a place and is added to that sum. `(other)` is present only when
     * something was folded or the input had it.
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    public static function topN(array $counts, int $n): array
    {
        $other = null;

        if (array_key_exists(self::OTHER, $counts)) {
            $other = $counts[self::OTHER];
            unset($counts[self::OTHER]);
        }

        $entries = [];

        foreach ($counts as $key => $count) {
            $entries[] = [(string) $key, $count];
        }

        usort($entries, static fn (array $a, array $b): int => [$b[1], $a[0]] <=> [$a[1], $b[0]]);

        $kept = [];

        foreach (array_slice($entries, 0, max(0, $n)) as [$key, $count]) {
            $kept[$key] = $count;
        }

        foreach (array_slice($entries, max(0, $n)) as [, $count]) {
            $other = ($other ?? 0) + $count;
        }

        if ($other !== null) {
            $kept[self::OTHER] = $other;
        }

        return $kept;
    }
}
