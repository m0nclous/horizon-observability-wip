<?php

declare(strict_types=1);

use DF\HorizonObservability\Batches\BatchKind;
use DF\HorizonObservability\HorizonObservability;
use DF\HorizonObservability\Metrics\Labels;

it('normalises a batch name to its kind', function (?string $name, string $kind): void {
    expect(BatchKind::normalize($name))->toBe($kind);
})->with([
    'date and counter' => ['campaign_launch_15_2026-10-15', 'campaign_launch'],
    'plain name' => ['nightly-import', 'nightly-import'],
    'single separators stay' => ['report.daily', 'report.daily'],
    'digit runs in words' => ['report2024', 'report'],
    'uuid' => ['export 9b2f6a3e-1c4d-4e5f-8a9b-0c1d2e3f4a5b', 'export'],
    'uuid upper case' => ['EXPORT-9B2F6A3E-1C4D-4E5F-8A9B-0C1D2E3F4A5B', 'EXPORT'],
    'uuid inside a name' => ['sync_9b2f6a3e-1c4d-4e5f-8a9b-0c1d2e3f4a5b_orders', 'sync_orders'],
    'hex token' => ['export-ab3f9e', 'export'],
    'long hex token' => ['build 1f3e5d7c9b0a2e4d6f8a0c2e4b6d8f0a1c3e5d7f', 'build'],
    'hex token in the middle' => ['release-1a2b3c4d-final', 'release_final'],
    'upper case hex token' => ['export-AB3F9E', 'export'],
    'hex word without a digit stays' => ['deadbeef-cafe', 'deadbeef-cafe'],
    'short hex token is a digit run' => ['export-a1b2', 'export-ab'],
    'hex part of a longer word stays' => ['xab3f9e1', 'xabfe'],
    'separator runs collapse' => ['order  15  shipped', 'order_shipped'],
    'mixed separator run' => ['a._:-b', 'a_b'],
    'trimmed' => [' -_report_- ', 'report'],
    'cyrillic preserved' => ['Рассылка_15_2026-10-15', 'Рассылка'],
    'cyrillic next to a hex token' => ['Отчёт-ab3f9e', 'Отчёт'],
    'emoji preserved' => ['launch 🚀 7', 'launch 🚀'],
    'control characters' => ["import\x01\x02today", 'import today'],
    'null' => [null, Labels::UNNAMED],
    'empty' => ['', Labels::UNNAMED],
    'digits only' => ['123', Labels::UNNAMED],
    'separators only' => ['--__..', Labels::UNNAMED],
    'uuid only' => ['9b2f6a3e-1c4d-4e5f-8a9b-0c1d2e3f4a5b', Labels::UNNAMED],
]);

it('keeps the pilot kinds for names without hex tokens', function (string $name): void {
    // The pilot's rule (drop UUIDs and digit runs, collapse separator runs, trim) without the hex step, verbatim.
    $legacy = trim((string) preg_replace(
        ['/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '/\d+/', '/[\s_.:-]{2,}/'],
        ['', '', '_'],
        $name,
    ), ' _.:-') ?: 'unnamed';

    expect(BatchKind::normalize($name))->toBe($legacy);
})->with([
    'campaign_launch_15_2026-10-15',
    'Import orders 2026-10-17',
    'sync_9b2f6a3e-1c4d-4e5f-8a9b-0c1d2e3f4a5b',
    'daily.report',
    '42',
    'v2 rollout',
]);

it('sanitises invalid UTF-8 and caps the length', function (): void {
    expect(BatchKind::normalize("bad\xC3(name"))->toBe('bad?(name')
        ->and(strlen(BatchKind::normalize(str_repeat('x', 500))))->toBe(Labels::MAX_BYTES)
        ->and(BatchKind::normalize(str_repeat('x', 500)))->toEndWith(Labels::ELLIPSIS.hash('crc32b', str_repeat('x', 500)));
});

it('resolves with the default normaliser', function (): void {
    $kind = new BatchKind;

    expect($kind->resolve('campaign_launch_15_2026-10-15', 'id-1'))->toBe('campaign_launch')
        ->and($kind->resolve(null, 'id-1'))->toBe(Labels::UNNAMED)
        ->and($kind->resolve('', 'id-1'))->toBe(Labels::UNNAMED);
});

it('resolves with the hook, which replaces the default normaliser', function (): void {
    $calls = [];
    HorizonObservability::resolveBatchKindUsing(static function (?string $name, string $id) use (&$calls): string {
        $calls[] = [$name, $id];

        return $name === null ? 'anonymous' : 'kind-'.strtoupper($name);
    });
    $kind = new BatchKind;

    expect($kind->resolve('export-ab3f9e', 'id-1'))->toBe('kind-EXPORT-AB3F9E')
        ->and($kind->resolve('', 'id-2'))->toBe('anonymous')
        ->and($calls)->toBe([['export-ab3f9e', 'id-1'], [null, 'id-2']]);
});

it('sanitises the hook result and never returns an empty kind', function (mixed $result, string $kind): void {
    HorizonObservability::resolveBatchKindUsing(static fn (?string $name, string $id): mixed => $result);

    expect((new BatchKind)->resolve('name', 'id'))->toBe($kind);
})->with([
    'control characters' => ["a\x1fb\n", 'ab'],
    'surrounding whitespace' => ['  kind  ', 'kind'],
    'empty string' => ['', Labels::UNNAMED],
    'only control characters' => ["\x01", Labels::UNNAMED],
    'integer' => [42, '42'],
    'null' => [null, Labels::UNNAMED],
    'array' => [['x'], Labels::UNNAMED],
]);

it('uses the default normaliser again after the hook is removed', function (): void {
    HorizonObservability::resolveBatchKindUsing(static fn (?string $name, string $id): string => 'hooked');
    HorizonObservability::resolveBatchKindUsing(null);

    expect((new BatchKind)->resolve('export-ab3f9e', 'id'))->toBe('export');
});
