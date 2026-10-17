<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Batches;

use Closure;
use DF\HorizonObservability\HorizonObservability;
use DF\HorizonObservability\Metrics\Labels;

/**
 * The batch kind of a batch (the `batch` label): the `resolveBatchKindUsing()` hook, or the default normaliser that
 * strips UUIDs, digit runs and hex tokens (Arch Q4).
 *
 * Stub (T02): the default normaliser only trims. Owned by T07 (WP5).
 *
 * @internal
 */
final class BatchKind
{
    public function resolve(?string $name, string $id): string
    {
        $resolver = HorizonObservability::batchKindResolver();

        return Labels::sanitize($resolver instanceof Closure ? $resolver($name, $id) : self::normalize($name));
    }

    /**
     * The default kind of a batch name; `unnamed` when nothing is left.
     */
    public static function normalize(?string $name): string
    {
        $kind = trim((string) $name);

        return $kind === '' ? Labels::UNNAMED : $kind;
    }
}
