<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Grafana\Support;

use DF\HorizonObservability\Grafana\DashboardOptions;
use DF\HorizonObservability\Grafana\LegacyProfile;
use RuntimeException;

/**
 * The frozen legacy goldens (Arch Q5.6 M0) and the profile that reproduces them.
 */
final class LegacyFixtures
{
    private const DIRECTORY = __DIR__.'/../../Fixtures/grafana/legacy';

    public static function path(string $file): string
    {
        return self::DIRECTORY.'/'.$file;
    }

    public static function read(string $file): string
    {
        $contents = file_get_contents(self::path($file));

        if ($contents === false) {
            throw new RuntimeException('Cannot read the fixture '.$file.'.');
        }

        return $contents;
    }

    /**
     * The legacy profile, with some values replaced to probe the seams.
     *
     * @param  array<string, mixed>  $overrides  DashboardOptions constructor arguments
     */
    public static function options(array $overrides = []): DashboardOptions
    {
        $legacy = LegacyProfile::options(self::read('alert-sound.js'));

        if ($overrides === []) {
            return $legacy;
        }

        return new DashboardOptions(...[...get_object_vars($legacy), ...$overrides]);
    }
}
