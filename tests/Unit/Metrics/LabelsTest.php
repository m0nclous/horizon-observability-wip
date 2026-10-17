<?php

declare(strict_types=1);

use DF\HorizonObservability\Metrics\Labels;

it('keeps valid short values', function (): void {
    expect(Labels::sanitize('App\\Jobs\\SendInvoice'))->toBe('App\\Jobs\\SendInvoice')
        ->and(Labels::sanitize(''))->toBe('')
        ->and(Labels::sanitize("App\\Jobs\\\u{041E}\u{0442}\u{0447}\u{0451}\u{0442}"))->toBe("App\\Jobs\\\u{041E}\u{0442}\u{0447}\u{0451}\u{0442}");
});

it('scrubs invalid UTF-8', function (): void {
    expect(Labels::sanitize("bad\xC3\x28byte"))->toBe('bad?(byte')
        ->and(mb_check_encoding(Labels::sanitize("\xFF\xFE"), 'UTF-8'))->toBeTrue();
});

it('strips control characters including the field separator (C9)', function (): void {
    expect(Labels::sanitize("a\x1fb\nc\td\re\x00f\x7fg\u{0085}h"))->toBe('abcdefgh');
});

it('cuts at 200 bytes on a character boundary with an ellipsis', function (): void {
    $ascii = Labels::sanitize(str_repeat('a', 250));
    $multibyte = Labels::sanitize(str_repeat("\u{0436}", 150));

    expect(strlen($ascii))->toBe(200)
        ->and($ascii)->toEndWith('...')
        ->and(strlen($multibyte))->toBeLessThanOrEqual(200)
        ->and($multibyte)->toEndWith('...')
        ->and(mb_check_encoding($multibyte, 'UTF-8'))->toBeTrue()
        ->and(Labels::sanitize(str_repeat('b', 200)))->toBe(str_repeat('b', 200));
});

it('defines the English sentinels', function (): void {
    expect([Labels::UNSCANNED, Labels::UNKNOWN, Labels::OTHER, Labels::UNNAMED])->toBe(['(unscanned)', '(unknown)', '(other)', 'unnamed']);
});

it('keeps the top N and folds the rest into (other)', function (): void {
    expect(Labels::topN(['a' => 1, 'b' => 5, 'c' => 3, 'd' => 2], 2))->toBe(['b' => 5, 'c' => 3, '(other)' => 3]);
});

it('returns small sets unchanged, largest first, without (other)', function (): void {
    expect(Labels::topN(['a' => 1, 'b' => 5], 3))->toBe(['b' => 5, 'a' => 1]);
});

it('breaks ties by key', function (): void {
    expect(Labels::topN(['z' => 2, 'a' => 2, 'm' => 2], 2))->toBe(['a' => 2, 'm' => 2, '(other)' => 2]);
});

it('adds an existing (other) to the folded sum without ranking it', function (): void {
    expect(Labels::topN(['(other)' => 100, 'a' => 1, 'b' => 2], 1))->toBe(['b' => 2, '(other)' => 101])
        ->and(Labels::topN(['(other)' => 4, 'a' => 1], 5))->toBe(['a' => 1, '(other)' => 4]);
});

it('folds everything with N = 0', function (): void {
    expect(Labels::topN(['a' => 1, 'b' => 2], 0))->toBe(['(other)' => 3])
        ->and(Labels::topN(['a' => 1], -3))->toBe(['(other)' => 1])
        ->and(Labels::topN([], 3))->toBe([]);
});
