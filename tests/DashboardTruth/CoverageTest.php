<?php

declare(strict_types=1);

use DF\HorizonObservability\Tests\DashboardTruth\Cases\Cases;
use DF\HorizonObservability\Tests\DashboardTruth\Cases\Coverage;
use DF\HorizonObservability\Tests\DashboardTruth\Cases\Renders;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Mode;
use DF\HorizonObservability\Tests\DashboardTruth\Datasets\Datasets;
use DF\HorizonObservability\Tests\DashboardTruth\Expect\Specs;

/*
| The static coverage gate of the dashboard truth suite (T17, design section 11.1), group grafana: no Docker, well
| under a second, in tests.yml's grafana job on every PR and in a local `pest`.
|
| The universe is every target of the committed off, poll and stream renders and of their S = 5 s and 60 s renders
| made in memory with the real renderer, as (render, panel id, refId, datasource kind, sha1 of the canonical target
| JSON). The rules are Cases/Coverage: a target that is neither covered (a spec of its mode and a PR case on its JSON)
| nor listed in Specs::PENDING fails, as does a stale or fully covered pending entry and a spec of a target no
| render has. A target that T13 adds or changes therefore turns `grafana` red until T17 covers it. PENDING must be
| empty by WP8.
*/

pest()->group('grafana');

it('enumerates the targets of every render', function (): void {
    $targets = Renders::targets();
    $renders = array_count_values(array_column($targets, 'render'));

    // 3 modes x 3 scrape intervals, 19 panels each (the hidden #23 and #24 and the nested ones included)
    expect(array_keys($renders))->toHaveCount(9)
        ->and(min($renders))->toBeGreaterThan(40);

    foreach (Renders::all() as $dashboard) {
        expect(Renders::panels($dashboard))->toHaveCount(19);
    }

    foreach ($targets as $target) {
        expect($target['hash'])->toMatch('/^[0-9a-f]{40}$/')
            ->and($target['refId'])->not->toBe('');
    }
});

it('covers every target of every render or lists it as pending', function (): void {
    expect(Coverage::current()->uncovered())->toBe([]);
});

it('lists no stale, duplicate or covered target as pending', function (): void {
    expect(Coverage::current()->stalePending())->toBe([]);
});

it('has no spec of a target that no render has', function (): void {
    expect(Coverage::current()->staleSpecs())->toBe([]);
});

it('gives every PR case a known dataset and every spec a PR case on one of its modes', function (): void {
    $modes = [];

    foreach (Cases::select('pr') as $case) {
        expect(Datasets::all())->toHaveKey($case->dataset);

        if ($case->expect === null) {
            $modes[$case->mode->value] = true;
        }
    }

    foreach (Specs::targets() as $spec) {
        $run = array_filter($spec->modes, static fn (Mode $mode): bool => isset($modes[$mode->value]));

        expect($run)->not->toBeEmpty(sprintf('panel %d %s is specified for %s but no PR case runs one of those renders', $spec->panel, $spec->refId, implode(', ', array_map(static fn (Mode $mode): string => $mode->value, $spec->modes))));
    }
});

it('fails on a new target, a changed target, a stale pending entry and a stale spec', function (): void {
    $targets = Renders::targets();
    $new = ['render' => 'off', 'mode' => Mode::Off, 'panel' => 25, 'refId' => 'A', 'kind' => 'prometheus', 'hash' => str_repeat('a', 40), 'panelType' => 'stat', 'title' => 'New tile', 'repeat' => null];
    $changed = $targets;

    foreach ($changed as &$target) {
        if ($target['panel'] === 8 && $target['refId'] === 'A' && $target['render'] === 'off') {
            $target['hash'] = str_repeat('b', 40);
        }
    }

    unset($target);
    $withNew = new Coverage([...$targets, $new], Specs::targets(), Specs::PENDING, Cases::select('pr'));
    $withChange = new Coverage($changed, Specs::targets(), Specs::PENDING, Cases::select('pr'));
    $stalePending = new Coverage($targets, Specs::targets(), [...Specs::PENDING, '99 A', '8 A'], Cases::select('pr'));
    $withoutEight = new Coverage(array_values(array_filter($targets, static fn (array $target): bool => $target['panel'] !== 8)), Specs::targets(), array_values(array_diff(Specs::PENDING, ['8 B', '8 C'])), Cases::select('pr'));

    expect($withNew->uncovered())->toBe(["panel 25 'New tile' A (off, prometheus): no spec; add Expect/Panels/NewTile.php (target and DOM spec), register it in Expect/Specs.php and give it a PR case in Cases/Cases.php, or list '25 A' in Specs::PENDING during the fan-out"])
        // The changed #8 A JSON of the off render is no longer the one S01 runs: off and its 5 s and 60 s twins share it
        ->and($withChange->uncovered())->not->toBe([])
        ->and($stalePending->stalePending())->toBe([
            "'99 A' is pending but no render has it: remove it from Specs::PENDING",
            "'8 A' is covered in every render: remove it from Specs::PENDING",
        ])
        ->and($withoutEight->staleSpecs())->toContain('the spec of panel 8 A names the off render, which has no such target: remove it or fix the panel id or refId');
});
