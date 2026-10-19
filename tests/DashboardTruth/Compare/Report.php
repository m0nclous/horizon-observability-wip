<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Compare;

use DF\HorizonObservability\Tests\DashboardTruth\Cases\Plan;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Level;
use DF\HorizonObservability\Tests\DashboardTruth\Expect\Specs;

/**
 * The report of a run (`<run dir>/report.json` and `report.md`, design section 3): every assertion with its case,
 * version, panel, refId, level, grid, expected and actual values, executed query and step, category and a replay
 * command; and the runtime gate (design section 11.2): every assertion the specs promise for an executed case and
 * version happened, with a non-trivial expectation where promised.
 *
 * The assertions are kept in `report.json` as the tests run, so the gate (the last test) and bin/run read them.
 */
final class Report
{
    public const JSON = 'report.json';

    public const MARKDOWN = 'report.md';

    /**
     * @param  list<Assertion>  $assertions
     */
    private function __construct(private readonly string $runDir, private array $assertions) {}

    public static function open(string $runDir, bool $fresh = false): self
    {
        $assertions = [];
        $path = $runDir.'/'.self::JSON;

        if (! $fresh && is_file($path)) {
            $data = json_decode((string) file_get_contents($path), true);

            foreach (is_array($data['assertions'] ?? null) ? $data['assertions'] : [] as $assertion) {
                if (is_array($assertion)) {
                    $assertions[] = Assertion::fromArray($assertion);
                }
            }
        }

        return new self($runDir, $assertions);
    }

    /**
     * Replace the assertions of one case and version.
     *
     * @param  list<Assertion>  $assertions
     */
    public function record(string $case, string $grafana, array $assertions): void
    {
        $this->assertions = [
            ...array_values(array_filter($this->assertions, static fn (Assertion $one): bool => $one->case !== $case || $one->grafana !== $grafana)),
            ...$assertions,
        ];
        $this->write();
    }

    /**
     * @return list<Assertion>
     */
    public function assertions(): array
    {
        return $this->assertions;
    }

    /**
     * @return list<Assertion>
     */
    public function failures(?string $case = null, ?string $grafana = null): array
    {
        return array_values(array_filter($this->assertions, static fn (Assertion $one): bool => ! $one->passed
            && ($case === null || $one->case === $case) && ($grafana === null || $one->grafana === $grafana)));
    }

    /**
     * The runtime gate: the promised assertions that did not happen (or never with a non-trivial expectation), for
     * the executed cases and versions of the plan.
     *
     * @param  list<array{0: string, 1: string}>  $executed  (case, grafana)
     * @return list<string>
     */
    public function gate(Plan $plan, array $executed): array
    {
        $missing = [];

        foreach ($executed as [$caseId, $grafana]) {
            $case = null;

            foreach ($plan->cases() as $planned) {
                if ($planned->id === $caseId) {
                    $case = $planned;
                }
            }

            if ($case === null) {
                $missing[] = sprintf('%s on %s: the case is not in the plan', $caseId, $grafana);

                continue;
            }

            if ($case->expect !== null) {
                continue;
            }

            $made = array_filter($this->assertions, static fn (Assertion $one): bool => $one->case === $caseId && $one->grafana === $grafana);

            foreach (Specs::forMode($case->mode) as $target) {
                foreach ($target->levels as $level) {
                    if ($level === Level::Dom) {
                        continue;
                    }

                    $mine = array_filter($made, static fn (Assertion $one): bool => $one->panel === $target->panel && $one->refId === $target->refId && $one->level === $level->value);

                    if ($mine === []) {
                        $missing[] = sprintf('%s on Grafana %s: #%d %s made no %s assertion', $caseId, $grafana, $target->panel, $target->refId, $level->value);

                        continue;
                    }

                    if ($target->range && count(array_unique(array_map(static fn (Assertion $one): string => $one->source, $mine))) < 2) {
                        $missing[] = sprintf('%s on Grafana %s: #%d %s was not compared at its replay steps', $caseId, $grafana, $target->panel, $target->refId);
                    }

                    if (array_filter($mine, static fn (Assertion $one): bool => $one->nonTrivial) === []) {
                        $missing[] = sprintf('%s on Grafana %s: #%d %s had only trivial expectations (empty, all 0 or a dash)', $caseId, $grafana, $target->panel, $target->refId);
                    }
                }
            }

            foreach (Specs::panels() as $panel) {
                $dom = $panel->dom();

                if ($dom !== null && in_array($case->mode, $dom->modes, true)
                    && array_filter($made, static fn (Assertion $one): bool => $one->panel === $dom->panel && $one->level === Level::Dom->value) === []) {
                    $missing[] = sprintf('%s on Grafana %s: #%d made no DOM assertion', $caseId, $grafana, $dom->panel);
                }
            }
        }

        return $missing;
    }

    /**
     * The failures as text, for a test's failure message and the terminal.
     *
     * @param  list<Assertion>  $failures
     */
    public static function describe(array $failures): string
    {
        $text = [];

        foreach ($failures as $failure) {
            $lines = [sprintf('%s on Grafana %s, %s %s %s: %s', $failure->case, $failure->grafana, $failure->target(), $failure->level, $failure->source, $failure->category?->value ?? 'failed')];
            $lines[] = '  expected: '.$failure->expected;
            $lines[] = '  actual:   '.$failure->actual;

            foreach (array_slice($failure->differences, 0, 8) as $difference) {
                $lines[] = '    '.$difference;
            }

            if ($failure->executed !== null) {
                $lines[] = '  executed: '.self::shorten($failure->executed, 400);
            }

            if ($failure->replay !== null) {
                $lines[] = '  replay:   '.$failure->replay;
            }

            $text[] = implode("\n", $lines);
        }

        return implode("\n\n", $text);
    }

    private function write(): void
    {
        $failures = $this->failures();
        $summary = [
            'assertions' => count($this->assertions),
            'failed' => count($failures),
            'categories' => array_count_values(array_map(static fn (Assertion $one): string => $one->category->value ?? 'failed', $failures)),
        ];
        file_put_contents($this->runDir.'/'.self::JSON, json_encode([
            'summary' => $summary,
            'assertions' => array_map(static fn (Assertion $one): array => $one->toArray(), $this->assertions),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

        $markdown = ['# Dashboard truth report', '', sprintf('%d assertions, %d failed.', $summary['assertions'], $summary['failed']), ''];
        $markdown[] = '| Case | Grafana | Target | Level | Grid | Result | Expected | Actual |';
        $markdown[] = '|---|---|---|---|---|---|---|---|';

        foreach ($this->assertions as $one) {
            $markdown[] = sprintf(
                '| %s | %s | %s | %s | %s | %s | %s | %s |',
                $one->case,
                $one->grafana,
                $one->target(),
                $one->level,
                $one->source,
                $one->passed ? 'pass' : '**'.($one->category->value ?? 'failed').'**',
                $this->cell($one->expected),
                $this->cell($one->actual),
            );
        }

        if ($failures !== []) {
            $markdown[] = '';
            $markdown[] = '## Failures';

            foreach ($failures as $failure) {
                $markdown[] = '';
                $markdown[] = sprintf('### %s · Grafana %s · %s · %s · %s: %s', $failure->case, $failure->grafana, $failure->target(), $failure->level, $failure->source, $failure->category->value ?? 'failed');
                $markdown[] = '';
                $markdown[] = '- expected: '.$this->cell($failure->expected);
                $markdown[] = '- actual: '.$this->cell($failure->actual);

                foreach (array_slice($failure->differences, 0, 20) as $difference) {
                    $markdown[] = '  - '.$this->cell($difference);
                }

                if ($failure->step !== null) {
                    $markdown[] = '- step: '.$failure->step;
                }

                if ($failure->executed !== null) {
                    $markdown[] = '- executedQueryString:';
                    $markdown[] = '';
                    $markdown[] = '  ```';
                    $markdown[] = '  '.str_replace("\n", "\n  ", $failure->executed);
                    $markdown[] = '  ```';
                }

                if ($failure->replay !== null) {
                    $markdown[] = '- replay: `'.$failure->replay.'`';
                }
            }
        }

        file_put_contents($this->runDir.'/'.self::MARKDOWN, implode("\n", $markdown)."\n");
    }

    private function cell(string $text): string
    {
        return str_replace(['|', "\n"], ['\|', ' '], self::shorten($text, 300));
    }

    private static function shorten(string $text, int $length): string
    {
        return strlen($text) > $length ? substr($text, 0, $length - 3).'...' : $text;
    }
}
