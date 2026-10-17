<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\Glyph;
use DF\HorizonObservability\Grafana\Live\LiveMode;
use DF\HorizonObservability\Grafana\Render;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * Arch Q5.7 item 7: the rendered assets are ASCII except the dashboard's glyphs (the middle dot and the dash of
 * titles and captions, and the job list icons); src/ writes them as \u{...} escapes (SourceTest). In every committed
 * render: off, poll (resources/grafana) and stream.
 */

pest()->group('grafana');

it('renders ASCII except the dashboard glyphs', function (LiveMode $mode, string $file): void {
    $contents = (string) file_get_contents(Rendered::directory($mode).'/'.$file);
    $glyphs = [Glyph::DOT, Glyph::DASH, Glyph::CHECK, Glyph::WARNING, Glyph::CLOCK, Glyph::HOURGLASS];

    preg_match_all('/[^\x00-\x7F]/u', str_replace($glyphs, '', $contents), $others);

    expect($others[0])->toBe([])
        ->and(mb_check_encoding($contents, 'UTF-8'))->toBeTrue();
})->with([
    'poll dashboard' => [LiveMode::Poll, Render::DASHBOARD],
    'poll alert rules' => [LiveMode::Poll, Render::ALERTING],
    'alert sound script' => [LiveMode::Poll, 'js/alert-sound.js'],
    'off dashboard' => [LiveMode::Off, Render::DASHBOARD],
    'off alert rules' => [LiveMode::Off, Render::ALERTING],
    'stream dashboard' => [LiveMode::Stream, Render::DASHBOARD],
    'stream alert rules' => [LiveMode::Stream, Render::ALERTING],
]);
