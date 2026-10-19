<?php

declare(strict_types=1);

use DF\HorizonObservability\Live\Captions;

/*
| Adversarial probe of Captions::oldestExact(): for every exported age (3 decimals, as the exporter
| and the live builder round it) the live Pending caption must read exactly what the off-mode tile computes in
| PromQL from the same value (Tiles::pending(): `round(age < 120)` " s", else `round((age >= 120) / 60)` " min",
| a dash unless `age > 0`). Prometheus' round() is floor(v + 0.5) and count_values prints the shortest decimal.
*/

/**
 * The off-mode caption, computed the way Prometheus evaluates Tiles::pending().
 */
function qa3PromqlOldest(float $age, string $queue): string
{
    if ($age <= 0) {
        return 'Oldest: '.Captions::DASH;
    }

    $value = $age < Captions::SECONDS_TEXT_LIMIT ? floor($age + 0.5) : floor($age / 60 + 0.5);

    return sprintf('Oldest: %s %s %s %s', (string) (int) $value, $age < Captions::SECONDS_TEXT_LIMIT ? 's' : 'min', Captions::DOT, $queue);
}

it('captions every exported age from 0 to 200 s at a 1 ms step like the off-mode tile', function (): void {
    $mismatches = [];

    for ($ms = 0; $ms <= 200000; $ms++) {
        $age = $ms / 1000;
        $live = Captions::oldestExact($age, 'q');

        if ($live !== qa3PromqlOldest($age, 'q')) {
            $mismatches[] = sprintf('%.3F: live "%s", off "%s"', $age, $live, qa3PromqlOldest($age, 'q'));
        }
    }

    expect(array_slice($mismatches, 0, 5))->toBe([]);
});

it('captions the minute boundaries (60 n + 30 s +- 1 ms) and long waits up to a day like the off-mode tile', function (): void {
    $ages = [];

    for ($n = 2; $n <= 1440; $n++) {
        foreach ([-1, 0, 1] as $deltaMs) {
            $ages[] = (60 * $n * 1000 + 30000 + $deltaMs) / 1000;
        }

        $ages[] = (60 * $n * 1000 - 1) / 1000;
        $ages[] = (float) (60 * $n);
    }

    $mismatches = [];

    foreach ($ages as $age) {
        $live = Captions::oldestExact($age, 'q');

        if ($live !== qa3PromqlOldest($age, 'q')) {
            $mismatches[] = sprintf('%.3F: live "%s", off "%s"', $age, $live, qa3PromqlOldest($age, 'q'));
        }
    }

    expect(array_slice($mismatches, 0, 5))->toBe([]);
});

it('keeps the frozen whole-second Captions::oldest() output', function (?int $age, ?string $queue, string $expected): void {
    expect(Captions::oldest($age, $queue))->toBe($expected);
})->with([
    'unknown' => [null, 'default', 'Oldest: '.Captions::DASH],
    'zero' => [0, 'default', 'Oldest: '.Captions::DASH],
    'negative' => [-3, 'default', 'Oldest: '.Captions::DASH],
    '1 s' => [1, 'default', 'Oldest: 1 s '.Captions::DOT.' default'],
    '119 s' => [119, 'default', 'Oldest: 119 s '.Captions::DOT.' default'],
    '120 s' => [120, 'slow', 'Oldest: 2 min '.Captions::DOT.' slow'],
    '149 s' => [149, 'slow', 'Oldest: 2 min '.Captions::DOT.' slow'],
    '150 s' => [150, 'slow', 'Oldest: 3 min '.Captions::DOT.' slow'],
    'a day' => [86400, 'slow', 'Oldest: 1440 min '.Captions::DOT.' slow'],
    'a year' => [31536000, 'slow', 'Oldest: 525600 min '.Captions::DOT.' slow'],
]);
