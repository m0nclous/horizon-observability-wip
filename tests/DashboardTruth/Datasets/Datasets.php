<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Datasets;

use InvalidArgumentException;

/**
 * The datasets of the suite by name (design section 6.4). WP1 has the slice; WP2 adds core, s5, s60, replicas,
 * multi-job and quiet.
 */
final class Datasets
{
    /**
     * E_0, epoch seconds: 2025-03-03T00:00Z; dataset k starts at E_k = E_0 + k x SPACING (design section 4).
     */
    public const E_0 = 1740960000;

    /**
     * 8 days, longer than the dashboard's longest lookback ([7d:5m]), so datasets never see each other's series.
     */
    public const SPACING = 691200;

    /**
     * @return array<string, Dataset>
     */
    public static function all(): array
    {
        return ['slice' => new Slice];
    }

    public static function get(string $name): Dataset
    {
        return self::all()[$name] ?? throw new InvalidArgumentException(sprintf('Unknown dataset "%s".', $name));
    }

    /**
     * The names in DASHBOARD_TRUTH_DATASETS (comma-separated), or every dataset.
     *
     * @return list<string>
     */
    public static function selected(): array
    {
        $selected = getenv('DASHBOARD_TRUTH_DATASETS');
        $names = array_keys(self::all());

        return is_string($selected) && $selected !== '' ? array_values(array_intersect($names, explode(',', $selected))) : $names;
    }
}
