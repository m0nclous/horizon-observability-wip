<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\Json;

/*
 * Grafana\Json prints like Python's json.dumps(value, ensure_ascii=False, indent=2) plus a newline; the expected
 * strings below are Python's output.
 */

it('prints nested containers with two-space indentation and a trailing newline', function (): void {
    expect(Json::encode(['a' => [1, true, null], 'b' => ['c' => 'd']]))
        ->toBe("{\n  \"a\": [\n    1,\n    true,\n    null\n  ],\n  \"b\": {\n    \"c\": \"d\"\n  }\n}\n");
});

it('keeps empty lists and empty objects apart', function (): void {
    expect(Json::encode(['list' => [], 'object' => new stdClass]))->toBe("{\n  \"list\": [],\n  \"object\": {}\n}\n");
});

it('prints numeric keys as object keys', function (): void {
    expect(Json::encode(['-1' => 'x', 'b' => 'y']))->toBe("{\n  \"-1\": \"x\",\n  \"b\": \"y\"\n}\n");
});

it('keeps unicode and slashes raw and escapes control characters', function (): void {
    expect(Json::encode(['s' => "\u{2014} \u{26A0}\u{FE0E} \u{2028} a/b \"q\" \\ \t\n\x01\x7f"]))
        ->toBe("{\n  \"s\": \"\u{2014} \u{26A0}\u{FE0E} \u{2028} a/b \\\"q\\\" \\\\ \\t\\n\\u0001\x7f\"\n}\n");
});

it('prints floats as Python repr does', function (float $value, string $python): void {
    expect(Json::encode([$value]))->toBe("[\n  {$python}\n]\n");
})->with([
    [0.9, '0.9'],
    [0.99, '0.99'],
    [1.0, '1.0'],
    [-0.0, '-0.0'],
    [0.1 + 0.2, '0.30000000000000004'],
    [123456789.125, '123456789.125'],
    [0.0001, '0.0001'],
    [1e-5, '1e-05'],
    [1.5e-7, '1.5e-07'],
    [1e15, '1000000000000000.0'],
    [1e16, '1e+16'],
    [1.5e16, '1.5e+16'],
    [5e-324, '5e-324'],
    [1.7976931348623157e308, '1.7976931348623157e+308'],
    // Powers of two: the shortest digits that read back are not the nearest 16-digit decimal
    [2 ** -140, '7.174648137343064e-43'],
    [2 ** -1017, '7.120236347223045e-307'],
]);

it('prints numbers that Grafana reads from strings as Python does, whatever the precision ini setting', function (): void {
    $precision = ini_get('precision');
    ini_set('precision', '17');

    try {
        expect(Json::number(0.05))->toBe('0.05')
            ->and(Json::number(0.01))->toBe('0.01')
            ->and(Json::number(1.0))->toBe('1.0')
            ->and(Json::number(1000000000))->toBe('1000000000')
            ->and((string) 0.05)->not->toBe('0.05');
    } finally {
        ini_set('precision', (string) $precision);
    }
});

it('refuses values JSON cannot represent', function (mixed $value): void {
    expect(fn (): string => Json::encode([$value]))->toThrow(InvalidArgumentException::class);
})->with([
    'NaN' => [NAN],
    'infinity' => [INF],
    'resource' => [fn () => STDIN],
]);
