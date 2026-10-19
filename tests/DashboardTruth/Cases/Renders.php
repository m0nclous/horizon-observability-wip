<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Cases;

use DF\HorizonObservability\Grafana\DashboardOptions;
use DF\HorizonObservability\Grafana\Live\LiveMode;
use DF\HorizonObservability\Grafana\Render;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Mode;
use RuntimeException;

/**
 * The renders of the coverage universe (design section 11.1): the committed ones (`resources/grafana` poll,
 * `tests/Fixtures/grafana/{off,stream}`, all at S = 10 s) and the S = 5 s and 60 s renders of every mode, made in
 * memory with the real renderer (`--defaults --scrape-interval=5s|60s --live=<mode>`), and every target in them as
 * (render, panel id, refId, datasource kind, sha1 of the canonical target JSON).
 *
 * Only the gates and bin/run use the renderer; the expectation engine (Expect/**) never does (IndependenceTest).
 */
final class Renders
{
    /**
     * The scrape intervals of the universe: the committed renders' default and the datasets' 5 s and 60 s.
     */
    public const INTERVALS = [10, 5, 60];

    private const ROOT = __DIR__.'/../../..';

    /**
     * @var array<string, array<string, mixed>>
     */
    private static array $cache = [];

    /**
     * The committed dashboard file of a mode.
     */
    public static function committed(Mode $mode): string
    {
        $path = $mode === Mode::Poll
            ? self::ROOT.'/resources/grafana/'.Render::DASHBOARD
            : self::ROOT.'/tests/Fixtures/grafana/'.$mode->value.'/'.Render::DASHBOARD;
        $real = realpath($path);

        if ($real === false) {
            throw new RuntimeException('No committed render at '.$path.'.');
        }

        return $real;
    }

    /**
     * Every render of the universe: name (`off`, `poll`, `stream`, `off@5s`, ...) => the decoded dashboard.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        if (self::$cache !== []) {
            return self::$cache;
        }

        foreach (Mode::cases() as $mode) {
            foreach (self::INTERVALS as $interval) {
                $name = self::name($mode, $interval);
                $json = $interval === DashboardOptions::DEFAULT_SCRAPE_INTERVAL
                    ? (string) file_get_contents(self::committed($mode))
                    : (new Render(new DashboardOptions(scrapeInterval: $interval, live: LiveMode::from($mode->value))))->files()[Render::DASHBOARD];
                $dashboard = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

                if (! is_array($dashboard)) {
                    throw new RuntimeException('The '.$name.' render is not a dashboard.');
                }

                /** @var array<string, mixed> $dashboard */
                self::$cache[$name] = $dashboard;
            }
        }

        return self::$cache;
    }

    public static function name(Mode $mode, int $interval): string
    {
        return $interval === DashboardOptions::DEFAULT_SCRAPE_INTERVAL ? $mode->value : $mode->value.'@'.$interval.'s';
    }

    /**
     * The mode of a render name.
     */
    public static function mode(string $render): Mode
    {
        return Mode::from(explode('@', $render)[0]);
    }

    /**
     * Every target of every render.
     *
     * @return list<array{render: string, mode: Mode, panel: int, refId: string, kind: string, hash: string, panelType: string, title: string, repeat: string|null}>
     */
    public static function targets(): array
    {
        $targets = [];

        foreach (self::all() as $render => $dashboard) {
            foreach (self::panels($dashboard) as $panel) {
                foreach (is_array($panel['targets'] ?? null) ? $panel['targets'] : [] as $target) {
                    if (! is_array($target)) {
                        continue;
                    }

                    $targets[] = [
                        'render' => $render,
                        'mode' => self::mode($render),
                        'panel' => (int) $panel['id'],
                        'refId' => (string) ($target['refId'] ?? 'A'),
                        'kind' => Plan::kind($target['datasource'] ?? $panel['datasource'] ?? null),
                        'hash' => self::hash($target),
                        'panelType' => (string) ($panel['type'] ?? ''),
                        'title' => (string) ($panel['title'] ?? ''),
                        'repeat' => is_string($panel['repeat'] ?? null) ? $panel['repeat'] : null,
                    ];
                }
            }
        }

        return $targets;
    }

    /**
     * The sha1 of a target's canonical JSON (keys sorted recursively).
     *
     * @param  array<array-key, mixed>  $target
     */
    public static function hash(array $target): string
    {
        return sha1((string) json_encode(self::canonical($target), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Every non-row panel, nested ones and the hidden #23/#24 included.
     *
     * @param  array<array-key, mixed>  $dashboard
     * @return list<array<array-key, mixed>>
     */
    public static function panels(array $dashboard): array
    {
        $panels = [];

        foreach (is_array($dashboard['panels'] ?? null) ? $dashboard['panels'] : [] as $panel) {
            if (! is_array($panel)) {
                continue;
            }

            if (($panel['type'] ?? null) === 'row') {
                foreach (is_array($panel['panels'] ?? null) ? $panel['panels'] : [] as $nested) {
                    if (is_array($nested)) {
                        $panels[] = $nested;
                    }
                }

                continue;
            }

            $panels[] = $panel;
        }

        return $panels;
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }
}
