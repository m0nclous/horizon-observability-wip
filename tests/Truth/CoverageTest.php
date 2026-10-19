<?php

declare(strict_types=1);

use DF\HorizonObservability\Metrics\Metric;

/*
| The coverage gate of the exporter truth tests: every catalog family has a row in the coverage table of
| tests/Truth/README.md, every test file listed there exists and references its family (the Metric case or the family
| name), and the table lists nothing else. A new family without a truth test fails here.
*/

pest()->group('truth');

/**
 * Rows of the README coverage table: family => [case, list of test files].
 *
 * @return array<string, array{case: string, tests: list<string>}>
 */
function truthCoverageTable(): array
{
    $readme = (string) file_get_contents(__DIR__.'/README.md');
    preg_match_all('/^\| `([a-z_]+)` \| (\w+) \| ([^|]+) \|$/m', $readme, $rows, PREG_SET_ORDER);
    $table = [];

    foreach ($rows as [, $family, $case, $tests]) {
        $table[$family] = ['case' => $case, 'tests' => array_map(trim(...), explode(',', $tests))];
    }

    return $table;
}

it('lists every catalog family, and only those, with its case', function (): void {
    $table = truthCoverageTable();
    $catalog = [];

    foreach (Metric::cases() as $metric) {
        $catalog[$metric->value] = $metric->name;
    }

    expect(array_map(static fn (array $row): string => $row['case'], $table))->toBe($catalog);
});

it('names truth tests that exist and reference their family', function (): void {
    foreach (truthCoverageTable() as $family => ['case' => $case, 'tests' => $tests]) {
        expect($tests)->not->toBe([]);

        foreach ($tests as $test) {
            $file = __DIR__.'/'.$test.'.php';

            expect(is_file($file))->toBeTrue($test.' does not exist');

            $code = (string) file_get_contents($file);

            // A whole case name: Metric::JobDurationMin alone does not cover JobDuration.
            expect(preg_match('/Metric::'.$case.'\b/', $code) === 1 || str_contains($code, "'".$family."'"))
                ->toBeTrue(sprintf('%s does not reference %s', $test, $family));
        }
    }
});
