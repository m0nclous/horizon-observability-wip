<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Compare;

use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Category;

/**
 * One assertion of the assert stage (design section 3 ASSERT): a (case, Grafana version, panel, refId, level, grid)
 * with its expectation and what Grafana showed, or a check of its own (case setup, errors, completion). A failed one
 * carries its category; only a `dashboard defect` is ever a finding against T13.
 */
final readonly class Assertion
{
    /**
     * @param  string  $source  the grid (`grid`, `replay 10s`), `dom`, or the check's name
     * @param  list<string>  $differences  the first differences, human-readable
     */
    public function __construct(
        public string $case,
        public string $grafana,
        public ?int $panel,
        public ?string $refId,
        public string $level,
        public string $source,
        public bool $passed,
        public ?Category $category = null,
        public string $expected = '',
        public string $actual = '',
        public array $differences = [],
        public ?string $executed = null,
        public ?string $step = null,
        public bool $nonTrivial = false,
        public ?string $replay = null,
    ) {}

    public function target(): string
    {
        return $this->panel === null ? '-' : '#'.$this->panel.($this->refId === null ? '' : ' '.$this->refId);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'case' => $this->case,
            'grafana' => $this->grafana,
            'panel' => $this->panel,
            'refId' => $this->refId,
            'level' => $this->level,
            'source' => $this->source,
            'passed' => $this->passed,
            'category' => $this->category?->value,
            'expected' => $this->expected,
            'actual' => $this->actual,
            'differences' => $this->differences,
            'executed' => $this->executed,
            'step' => $this->step,
            'nonTrivial' => $this->nonTrivial,
            'replay' => $this->replay,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            case: (string) $data['case'],
            grafana: (string) $data['grafana'],
            panel: is_int($data['panel'] ?? null) ? $data['panel'] : null,
            refId: is_string($data['refId'] ?? null) ? $data['refId'] : null,
            level: (string) $data['level'],
            source: (string) $data['source'],
            passed: (bool) $data['passed'],
            category: is_string($data['category'] ?? null) ? Category::from($data['category']) : null,
            expected: (string) ($data['expected'] ?? ''),
            actual: (string) ($data['actual'] ?? ''),
            differences: array_values(array_map(strval(...), is_array($data['differences'] ?? null) ? $data['differences'] : [])),
            executed: is_string($data['executed'] ?? null) ? $data['executed'] : null,
            step: is_string($data['step'] ?? null) ? $data['step'] : null,
            nonTrivial: (bool) ($data['nonTrivial'] ?? false),
            replay: is_string($data['replay'] ?? null) ? $data['replay'] : null,
        );
    }
}
