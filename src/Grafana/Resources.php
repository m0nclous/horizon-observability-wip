<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana;

use RuntimeException;

/**
 * Files of `resources/grafana` the renderer embeds into the dashboard.
 *
 * @internal
 */
final class Resources
{
    private const DIRECTORY = __DIR__.'/../../resources/grafana';

    /**
     * The `afterRender` script of the hidden alert sound panel (Arch Q5.4): `js/alert-sound.js` without its final
     * newline.
     */
    public static function alertSoundScript(): string
    {
        return self::read('js/alert-sound.js');
    }

    private static function read(string $file): string
    {
        $contents = @file_get_contents(self::DIRECTORY.'/'.$file);

        if ($contents === false) {
            throw new RuntimeException('Cannot read resources/grafana/'.$file.'.');
        }

        return str_ends_with($contents, "\n") ? substr($contents, 0, -1) : $contents;
    }
}
