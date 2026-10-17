<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\Glyph;
use DF\HorizonObservability\Grafana\Render;

/*
 * Arch Q5.7 item 7: the rendered assets are ASCII except the dashboard's glyphs (the middle dot and the dash of
 * titles and captions, and the job list icons); src/ writes them as \u{...} escapes (SourceTest).
 */

pest()->group('grafana');

it('renders ASCII except the dashboard glyphs', function (string $file): void {
    $contents = (string) file_get_contents(dirname(__DIR__, 2).'/resources/grafana/'.$file);
    $glyphs = [Glyph::DOT, Glyph::DASH, Glyph::CHECK, Glyph::WARNING, Glyph::CLOCK, Glyph::HOURGLASS];

    preg_match_all('/[^\x00-\x7F]/u', str_replace($glyphs, '', $contents), $others);

    expect($others[0])->toBe([])
        ->and(mb_check_encoding($contents, 'UTF-8'))->toBeTrue();
})->with([Render::DASHBOARD, Render::ALERTING, 'js/alert-sound.js']);
