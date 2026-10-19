<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Batches;

use Closure;
use DF\HorizonObservability\HorizonObservability;
use DF\HorizonObservability\Metrics\Labels;

/**
 * The batch kind of a batch, the value of the `batch` label. A single batch never becomes a label of its own;
 * its kind groups the batches that differ only by ids, dates or counters.
 *
 * The default normaliser ({@see self::normalize()}), in this order:
 * - turns control characters into spaces;
 * - removes UUIDs anywhere in the name;
 * - removes hex tokens: maximal runs of at least {@see self::HEX_TOKEN_MIN_LENGTH} hex digits that contain a decimal
 *   digit and touch no other letter or digit (`export-ab3f9e` becomes `export`, `deadbeef` stays);
 * - removes the remaining runs of decimal digits;
 * - turns every run of two or more separators (whitespace, `_`, `.`, `:`, `-`) into one `_`;
 * - trims ` _.:-` from both ends, then caps the length like every label value ({@see Labels::sanitize()});
 * - gives `unnamed` when nothing is left.
 *
 * `campaign_launch_15_2026-10-15` becomes `campaign_launch`. Letters of any script are kept. The rules equal the
 * pilot's except for the hex tokens, which the pilot turned into letter fragments (`export-abfe`).
 *
 * `HorizonObservability::resolveBatchKindUsing()` replaces the default normaliser; its result is only sanitised.
 *
 * @internal
 */
final class BatchKind
{
    public const HEX_TOKEN_MIN_LENGTH = 6;

    private const CONTROL = '/\p{Cc}+/u';

    private const UUID = '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/iu';

    private const HEX_TOKEN = '/(?<![\p{L}\p{N}])(?=[0-9a-f]*[0-9])[0-9a-f]{'.self::HEX_TOKEN_MIN_LENGTH.',}(?![\p{L}\p{N}])/iu';

    private const DIGITS = '/\d+/u';

    private const SEPARATOR_RUN = '/[\s_.:-]{2,}/u';

    private const TRIMMED = ' _.:-';

    /**
     * The kind of a batch: the hook's result (sanitised and trimmed), or {@see self::normalize()}; always a non-empty
     * label value.
     *
     * @param  string|null  $name  the batch name; null (or '') when the batch has none
     */
    public function resolve(?string $name, string $id): string
    {
        $name = $name === '' ? null : $name;
        $resolver = HorizonObservability::batchKindResolver();

        if (! $resolver instanceof Closure) {
            return self::normalize($name);
        }

        /** @var mixed $kind the hook is typed, but a user closure may still return anything */
        $kind = $resolver($name, $id);
        $kind = is_string($kind) || is_int($kind) || is_float($kind) ? trim(Labels::sanitize((string) $kind)) : '';

        return $kind === '' ? Labels::UNNAMED : $kind;
    }

    /**
     * The default kind of a batch name, sanitised ({@see Labels::sanitize()}); `unnamed` when nothing is left.
     */
    public static function normalize(?string $name): string
    {
        $kind = (string) $name;

        if (! mb_check_encoding($kind, 'UTF-8')) {
            $kind = mb_scrub($kind, 'UTF-8');
        }

        $kind = (string) preg_replace(
            [self::CONTROL, self::UUID, self::HEX_TOKEN, self::DIGITS, self::SEPARATOR_RUN],
            [' ', '', '', '', '_'],
            $kind,
        );
        $kind = Labels::sanitize(trim($kind, self::TRIMMED));

        return $kind === '' ? Labels::UNNAMED : $kind;
    }
}
