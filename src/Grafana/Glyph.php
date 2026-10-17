<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana;

/**
 * The non-ASCII characters of the rendered assets, kept as escapes so `src/` stays ASCII (Arch Q5.7 item 7).
 *
 * @internal
 */
final class Glyph
{
    /**
     * Placeholder for "no value" in cells, captions and stub rows (U+2014 EM DASH).
     */
    public const DASH = "\u{2014}";

    /**
     * Separator in titles and captions (U+00B7 MIDDLE DOT).
     */
    public const DOT = "\u{00B7}";

    /**
     * Completed Jobs (U+2713 CHECK MARK).
     */
    public const CHECK = "\u{2713}";

    /**
     * Failed Jobs (U+26A0 WARNING SIGN with U+FE0E, the text presentation instead of a colour emoji).
     */
    public const WARNING = "\u{26A0}\u{FE0E}";

    /**
     * Pending Jobs (U+25F7 WHITE CIRCLE WITH UPPER RIGHT QUADRANT).
     */
    public const CLOCK = "\u{25F7}";

    /**
     * Delayed Jobs (U+29D6 WHITE HOURGLASS).
     */
    public const HOURGLASS = "\u{29D6}";
}
