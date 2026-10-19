<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Grafana\Support;

use DF\HorizonObservability\Grafana\Live\LiveMode;
use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Grafana\Render;
use RuntimeException;

/**
 * The committed renders as decoded JSON, and the PromQL in them: the package render in resources/grafana (the
 * `--defaults` render, `--live=poll`) and the `--defaults --live=off` and `--defaults --live=stream` renders in
 * tests/Fixtures/grafana/{off,stream}.
 */
final class Rendered
{
    private const PACKAGE = __DIR__.'/../../../resources/grafana';

    private const FIXTURES = __DIR__.'/../../Fixtures/grafana';

    /**
     * The directory of the committed render of a live mode.
     */
    public static function directory(LiveMode $mode = LiveMode::Poll): string
    {
        return $mode === LiveMode::Poll ? self::PACKAGE : self::FIXTURES.'/'.$mode->value;
    }

    /**
     * The three committed renders, for datasets: `->with(Rendered::modes())`.
     *
     * @return array<string, array{0: LiveMode}>
     */
    public static function modes(): array
    {
        return [
            'poll (package render)' => [LiveMode::Poll],
            'off' => [LiveMode::Off],
            'stream' => [LiveMode::Stream],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function dashboard(LiveMode $mode = LiveMode::Poll): array
    {
        return self::decode($mode, Render::DASHBOARD);
    }

    /**
     * @return array<string, mixed>
     */
    public static function alerting(LiveMode $mode = LiveMode::Poll): array
    {
        return self::decode($mode, Render::ALERTING);
    }

    /**
     * Every Prometheus expression of a dashboard and its alert rules, keyed by where it is: panel targets, variable
     * queries and rule queries.
     *
     * @param  array<array-key, mixed>|null  $dashboard  default: the committed dashboard of $mode
     * @param  array<array-key, mixed>|null  $alerting  default: the committed alert rules of $mode
     * @return array<string, string>
     */
    public static function expressions(?array $dashboard = null, ?array $alerting = null, LiveMode $mode = LiveMode::Poll): array
    {
        $dashboard ??= self::dashboard($mode);
        $alerting ??= self::alerting($mode);
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
    private static function decode(LiveMode $mode, string $file): array
    {
        $decoded = json_decode((string) file_get_contents(self::directory($mode).'/'.$file), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException($file.' is not a JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
