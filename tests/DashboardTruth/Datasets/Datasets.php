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
