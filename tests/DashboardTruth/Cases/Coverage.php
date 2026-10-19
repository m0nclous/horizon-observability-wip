<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Cases;

use DF\HorizonObservability\Tests\DashboardTruth\Contracts\CaseDef;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Mode;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\TargetSpec;
use DF\HorizonObservability\Tests\DashboardTruth\Datasets\Datasets;
use DF\HorizonObservability\Tests\DashboardTruth\Expect\Specs;

/**
 * The rules of the static coverage gate (design section 11.1), on any universe of targets, so CoverageTest runs them
 * on the real renders and proves them on synthetic ones:
 *
 * - a target is covered when a spec of its (panel, refId) covers its render's mode and a PR case runs a render that
 *   contains a target with the same JSON hash (identical targets are covered once);
 * - a target that is neither covered nor pending fails, naming the panel, the refId, the render and what to add;
 * - a pending entry that no render has, or whose every target is covered, fails;
 * - a spec of a (panel, refId) that no render of its modes has fails.
 */
final readonly class Coverage
{
    /**
     * @param  list<array{render: string, mode: Mode, panel: int, refId: string, kind: string, hash: string, panelType: string, title: string, repeat: string|null}>  $targets
     * @param  list<TargetSpec>  $specs
     * @param  list<string>  $pending  "<panel> <refId>"
     * @param  array<string, CaseDef>  $cases  the PR set
     */
    public function __construct(
        private array $targets,
        private array $specs,
        private array $pending,
        private array $cases,
    ) {}

    public static function current(): self
    {
        return new self(Renders::targets(), Specs::targets(), Specs::PENDING, Cases::select('pr'));
    }

    /**
     * Every failure of the gate.
     *
     * @return list<string>
     */
    public function failures(): array
    {
        return [...$this->uncovered(), ...$this->stalePending(), ...$this->staleSpecs()];
    }

    /**
     * @return list<string>
     */
    public function uncovered(): array
    {
        $covered = $this->coveredHashes();
        $pending = array_flip($this->pending);
        $failures = [];

        foreach ($this->targets as $target) {
            $key = $target['panel'].' '.$target['refId'];

            if ($this->isCovered($target, $covered) || isset($pending[$key])) {
                continue;
            }

            $spec = $this->spec($target['panel'], $target['refId'], null) instanceof TargetSpec ? 'no spec for the '.$target['mode']->value.' render or no PR case on this JSON' : 'no spec';
            $failures[] = sprintf(
                "panel %d '%s' %s (%s, %s): %s; add Expect/Panels/%s.php (target and DOM spec), register it in Expect/Specs.php and give it a PR case in Cases/Cases.php, or list '%s' in Specs::PENDING during the fan-out",
                $target['panel'],
                $target['title'],
                $target['refId'],
                $target['render'],
                $target['kind'],
                $spec,
                $this->className($target['title']),
                $key,
            );
        }

        return $failures;
    }

    /**
     * @return list<string>
     */
    public function stalePending(): array
    {
        $covered = $this->coveredHashes();
        $byKey = [];

        foreach ($this->targets as $target) {
            $byKey[$target['panel'].' '.$target['refId']][] = $this->isCovered($target, $covered);
        }

        $failures = [];

        foreach (array_count_values($this->pending) as $key => $count) {
            if ($count > 1) {
                $failures[] = sprintf("'%s' is listed %d times in Specs::PENDING", $key, $count);
            }

            if (! isset($byKey[$key])) {
                $failures[] = sprintf("'%s' is pending but no render has it: remove it from Specs::PENDING", $key);
            } elseif (! in_array(false, $byKey[$key], true)) {
                $failures[] = sprintf("'%s' is covered in every render: remove it from Specs::PENDING", $key);
            }
        }

        return $failures;
    }

    /**
     * @return list<string>
     */
    public function staleSpecs(): array
    {
        $failures = [];

        foreach ($this->specs as $spec) {
            foreach ($spec->modes as $mode) {
                $found = array_filter($this->targets, static fn (array $target): bool => $target['mode'] === $mode && $target['panel'] === $spec->panel && $target['refId'] === $spec->refId);

                if ($found === []) {
                    $failures[] = sprintf('the spec of panel %d %s names the %s render, which has no such target: remove it or fix the panel id or refId', $spec->panel, $spec->refId, $mode->value);
                }
            }
        }

        return $failures;
    }

    /**
     * The hashes of the targets the PR cases run: per case (not a negative control), the render of its mode at its
     * dataset's scrape interval, and there every target a spec covers.
     *
     * @return array<string, true>
     */
    public function coveredHashes(): array
    {
        $covered = [];

        foreach ($this->cases as $case) {
            if ($case->expect !== null) {
                continue;
            }

            $render = Renders::name($case->mode, Datasets::get($case->dataset)->scrapeInterval());

            foreach ($this->targets as $target) {
                if ($target['render'] === $render && $this->spec($target['panel'], $target['refId'], $target['mode']) instanceof TargetSpec) {
                    $covered[$target['hash']] = true;
                }
            }
        }

        return $covered;
    }

    /**
     * @param  array{mode: Mode, panel: int, refId: string, hash: string}  $target
     * @param  array<string, true>  $covered
     */
    private function isCovered(array $target, array $covered): bool
    {
        return isset($covered[$target['hash']]) && $this->spec($target['panel'], $target['refId'], $target['mode']) instanceof TargetSpec;
    }

    private function spec(int $panel, string $refId, ?Mode $mode): ?TargetSpec
    {
        foreach ($this->specs as $spec) {
            if ($spec->panel === $panel && $spec->refId === $refId && (! $mode instanceof Mode || $spec->covers($mode))) {
                return $spec;
            }
        }

        return null;
    }

    private function className(string $title): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', $title, -1, PREG_SPLIT_NO_EMPTY) ?: ['Panel'];

        return implode('', array_map(ucfirst(...), array_map(strtolower(...), $words)));
    }
}
