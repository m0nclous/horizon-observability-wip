<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Cases;

use DF\HorizonObservability\Tests\DashboardTruth\Contracts\CaseDef;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Manifest;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Mode;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Schema;
use RuntimeException;

/**
 * The plan of a run (`<run dir>/plan.json`, schema `schema/plan.schema.json`): what the capture stage loads and the
 * assert stage checks. Per case: its definition, the dashboard uid and URL path, the fake clock (the dataset's end V),
 * the viewport, the collapsed rows to open and the render's panels with their refIds, for the completion criterion.
 * Per Grafana version: its URL and version. Written by bin/run, read by browser/observe.mjs and DashboardTruthTest.
 */
final readonly class Plan
{
    public const SCHEMA = 1;

    public const HEIGHT = 5000;

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function __construct(public array $data) {}

    /**
     * @param  array<string, CaseDef>  $cases
     * @param  array<string, array{url: string, version: string}>  $grafanas  `12`/`13` => URL and version
     * @param  array<string, string>  $renders  mode => the dashboard JSON file imported for it
     * @param  array<string, Manifest>  $manifests  dataset => manifest
     */
    public static function build(string $run, string $runDir, array $cases, array $grafanas, array $renders, array $manifests, ?string $mutant = null): self
    {
        $planned = [];

        foreach ($cases as $case) {
            $manifest = $manifests[$case->dataset] ?? throw new RuntimeException(sprintf('Case %s needs the dataset %s, which was not recorded.', $case->id, $case->dataset));
            $render = $renders[$case->mode->value] ?? throw new RuntimeException(sprintf('Case %s needs the %s render.', $case->id, $case->mode->value));
            $dashboard = json_decode((string) file_get_contents($render), true, flags: JSON_THROW_ON_ERROR);
            $uid = self::uid($case->mode, $mutant);

            $planned[] = [
                'case' => $case->toArray(),
                'id' => $case->id,
                'uid' => $uid,
                'path' => '/d/'.$uid.'/?'.$case->query(),
                'fakeNow' => $manifest->endMs(),
                'timezone' => $case->timezone,
                'width' => $case->width,
                'height' => self::HEIGHT,
                'rows' => self::collapsedRows(is_array($dashboard) ? $dashboard : []),
                'panels' => self::panels(is_array($dashboard) ? $dashboard : []),
            ];
        }

        $data = [
            'schema' => self::SCHEMA,
            'run' => $run,
            'runDir' => $runDir,
            'mutant' => $mutant,
            'routerLog' => $runDir.'/router.log',
            'grafanas' => $grafanas,
            'renders' => $renders,
            'cases' => $planned,
        ];
        Schema::assert('plan', $data, 'the plan');

        return new self($data);
    }

    public static function read(string $path): self
    {
        $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new RuntimeException($path.' is not a plan.');
        }

        Schema::assert('plan', $data, $path);

        return new self($data);
    }

    public function write(string $path): void
    {
        file_put_contents($path, json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    }

    public static function uid(Mode $mode, ?string $mutant = null): string
    {
        return $mutant === null ? $mode->uid() : 't17m-'.$mode->value.'-'.$mutant;
    }

    /**
     * @return list<CaseDef>
     */
    public function cases(): array
    {
        return array_map(static fn (array $case): CaseDef => CaseDef::fromArray($case['case']), $this->data['cases']);
    }

    /**
     * @return array<string, mixed>
     */
    public function planned(string $id): array
    {
        foreach ($this->data['cases'] as $case) {
            if ($case['id'] === $id) {
                return $case;
            }
        }

        throw new RuntimeException(sprintf('The plan has no case %s.', $id));
    }

    /**
     * @return list<string> the Grafana versions of the plan (`12`, `13`)
     */
    public function versions(): array
    {
        return array_map(strval(...), array_keys($this->data['grafanas']));
    }

    public function grafanaUrl(string $version): string
    {
        return (string) $this->data['grafanas'][$version]['url'];
    }

    /**
     * The titles of the render's collapsed rows (opened by the observer).
     *
     * @param  array<array-key, mixed>  $dashboard
     * @return list<string>
     */
    public static function collapsedRows(array $dashboard): array
    {
        $rows = [];

        foreach (is_array($dashboard['panels'] ?? null) ? $dashboard['panels'] : [] as $panel) {
            if (is_array($panel) && ($panel['type'] ?? null) === 'row' && ($panel['collapsed'] ?? false) === true) {
                $rows[] = (string) ($panel['title'] ?? '');
            }
        }

        return $rows;
    }

    /**
     * Every non-row panel of a dashboard (nested ones included) with its refIds, its repeat variable and whether it
     * sends `/api/ds/query` requests (a `-- Dashboard --` or Grafana Live panel does not).
     *
     * @param  array<array-key, mixed>  $dashboard
     * @return list<array{id: int, type: string, title: string, refIds: list<string>, repeat: string|null, queried: bool, datasources: list<string>}>
     */
    public static function panels(array $dashboard): array
    {
        $panels = [];
        $walk = static function (array $list) use (&$walk, &$panels): void {
            foreach ($list as $panel) {
                if (! is_array($panel)) {
                    continue;
                }

                if (($panel['type'] ?? null) === 'row') {
                    $walk(is_array($panel['panels'] ?? null) ? $panel['panels'] : []);

                    continue;
                }

                $datasources = [];
                $refIds = [];

                foreach (is_array($panel['targets'] ?? null) ? $panel['targets'] : [] as $target) {
                    if (! is_array($target)) {
                        continue;
                    }

                    $refIds[] = (string) ($target['refId'] ?? 'A');
                    $datasources[] = self::kind($target['datasource'] ?? $panel['datasource'] ?? null);
                }

                $datasources = array_values(array_unique($datasources));
                $panels[] = [
                    'id' => (int) ($panel['id'] ?? 0),
                    'type' => (string) ($panel['type'] ?? ''),
                    'title' => (string) ($panel['title'] ?? ''),
                    'refIds' => $refIds,
                    'repeat' => is_string($panel['repeat'] ?? null) ? $panel['repeat'] : null,
                    'queried' => array_intersect($datasources, ['prometheus', 'yesoreyeram-infinity-datasource']) !== [],
                    'datasources' => $datasources,
                ];
            }
        };
        $walk(is_array($dashboard['panels'] ?? null) ? $dashboard['panels'] : []);
        usort($panels, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return $panels;
    }

    /**
     * The datasource kind of a target: its type, or the uid for the built-in ones (`-- Dashboard --`, `grafana`).
     */
    public static function kind(mixed $datasource): string
    {
        if (! is_array($datasource)) {
            return 'default';
        }

        $type = (string) ($datasource['type'] ?? '');

        return $type === 'datasource' || $type === '' ? (string) ($datasource['uid'] ?? 'default') : $type;
    }
}
