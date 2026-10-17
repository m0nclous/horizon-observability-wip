<?php

declare(strict_types=1);

use DF\HorizonObservability\Batches\BatchKind;
use DF\HorizonObservability\HorizonObservability;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Http\Request;

it('stores the auth callback', function (): void {
    $callback = static fn (Request $request): bool => $request->header('X-Mtls') === 'ok';
    HorizonObservability::auth($callback);

    expect(HorizonObservability::authCallback())->toBe($callback);

    HorizonObservability::auth(null);
    expect(HorizonObservability::authCallback())->toBeNull();
});

it('stores the batch kind resolver, which BatchKind uses', function (): void {
    HorizonObservability::resolveBatchKindUsing(static fn (?string $name, string $id): string => 'kind-'.$id);

    expect(HorizonObservability::batchKindResolver())->not->toBeNull()
        ->and((new BatchKind)->resolve('export', '42'))->toBe('kind-42');
});

it('resets the hooks and the maintenance exceptions', function (): void {
    HorizonObservability::auth(static fn (): bool => true);
    HorizonObservability::resolveBatchKindUsing(static fn (): string => 'x');
    PreventRequestsDuringMaintenance::except(['horizon/observability/metrics']);

    HorizonObservability::flushState();

    expect(HorizonObservability::authCallback())->toBeNull()
        ->and(HorizonObservability::batchKindResolver())->toBeNull()
        ->and((new PreventRequestsDuringMaintenance(app()))->getExcludedPaths())->toBe([]);
});
