<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Grafana\Support;

use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Grafana\Render;
use RuntimeException;

/**
 * The committed render in resources/grafana (the `--defaults` render, Arch Q5.1) as decoded JSON, and the PromQL in it.
 */
final class Rendered
{
    private const DIRECTORY = __DIR__.'/../../../resources/grafana';

    /**
     * @return array<string, mixed>
     */
    public static function dashboard(): array
    {
        return self::decode(Render::DASHBOARD);
    }

    /**
     * @return array<string, mixed>
     */
    public static function alerting(): array
    {
        return self::decode(Render::ALERTING);
    }

    /**
     * Every Prometheus expression of a dashboard and its alert rules, keyed by where it is: panel targets, variable
     * queries and rule queries.
     *
     * @param  array<array-key, mixed>|null  $dashboard  default: the committed dashboard
     * @param  array<array-key, mixed>|null  $alerting  default: the committed alert rules
     * @return array<string, string>
     */
    public static function expressions(?array $dashboard = null, ?array $alerting = null): array
    {
        $dashboard ??= self::dashboard();
        $alerting ??= self::alerting();
        $expressions = [];

        foreach (PanelRegistry::panels($dashboard) as $panel) {
            foreach (is_array($panel['targets'] ?? null) ? $panel['targets'] : [] as $target) {
                if (is_array($target) && is_string($target['expr'] ?? null)) {
                    $expressions['panel '.$panel['id'].' '.$target['refId']] = $target['expr'];
                }
            }
        }

        foreach ($dashboard['templating']['list'] ?? [] as $variable) {
            if (($variable['type'] ?? null) === 'query' && is_string($variable['query'] ?? null)) {
                $expressions['variable '.$variable['name']] = $variable['query'];
            }
        }

        foreach ($alerting['groups'] ?? [] as $group) {
            foreach ($group['rules'] ?? [] as $rule) {
                foreach ($rule['data'] ?? [] as $query) {
                    if (is_string($query['model']['expr'] ?? null)) {
                        $expressions['rule '.$rule['uid'].' '.$query['refId']] = $query['model']['expr'];
                    }
                }
            }
        }

        return $expressions;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $file): array
    {
        $decoded = json_decode((string) file_get_contents(self::DIRECTORY.'/'.$file), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException($file.' is not a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
