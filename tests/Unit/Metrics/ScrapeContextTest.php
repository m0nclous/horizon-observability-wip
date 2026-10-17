<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Metrics\ScrapeContext;

it('keeps the request time', function (): void {
    $now = CarbonImmutable::parse('2026-10-17 12:00:00');

    expect((new ScrapeContext($now, 8.0))->now())->toBe($now);
});

it('measures the budget with its clock', function (): void {
    $time = 100.0;
    $context = new ScrapeContext(CarbonImmutable::now(), 8.0, function () use (&$time): float {
        return $time;
    });

    expect($context->remaining())->toBe(8.0)
        ->and($context->deadlineExceeded())->toBeFalse();

    $time = 105.5;
    expect($context->remaining())->toBe(2.5);

    $time = 108.0;
    expect($context->deadlineExceeded())->toBeTrue()
        ->and($context->remaining())->toBe(0.0);

    $time = 120.0;
    expect($context->remaining())->toBe(0.0);
});

it('has passed the deadline with no budget', function (): void {
    expect((new ScrapeContext(CarbonImmutable::now(), 0.0))->deadlineExceeded())->toBeTrue()
        ->and((new ScrapeContext(CarbonImmutable::now(), -1.0))->remaining())->toBe(0.0);
});

it('has a real default clock', function (): void {
    expect((new ScrapeContext(CarbonImmutable::now(), 60.0))->remaining())->toBeGreaterThan(59.0)->toBeLessThanOrEqual(60.0);
});

it('remembers a shared source once per scrape', function (): void {
    $context = new ScrapeContext(CarbonImmutable::now(), 8.0);
    $calls = 0;
    $resolve = function () use (&$calls): array {
        $calls++;

        return ['supervisor-1'];
    };

    expect($context->remember('supervisors', $resolve))->toBe(['supervisor-1'])
        ->and($context->remember('supervisors', $resolve))->toBe(['supervisor-1'])
        ->and($calls)->toBe(1)
        ->and($context->remember('other', fn (): ?string => null))->toBeNull();
});

it('remembers a failing source and rethrows to every caller', function (): void {
    $context = new ScrapeContext(CarbonImmutable::now(), 8.0);
    $calls = 0;
    $resolve = function () use (&$calls): never {
        $calls++;

        throw new RuntimeException('redis down');
    };

    expect(fn (): mixed => $context->remember('masters', $resolve))->toThrow(RuntimeException::class, 'redis down')
        ->and(fn (): mixed => $context->remember('masters', $resolve))->toThrow(RuntimeException::class, 'redis down')
        ->and($calls)->toBe(1);
});

it('collects partial failures and clears them on take', function (): void {
    $context = new ScrapeContext(CarbonImmutable::now(), 8.0);
    $first = new RuntimeException('queue default');
    $second = new LogicException('queue slow');

    $context->reportFailure('queue:default', $first);
    $context->reportFailure('queue:slow', $second);

    expect($context->takeFailures())->toBe([
        ['source' => 'queue:default', 'exception' => $first],
        ['source' => 'queue:slow', 'exception' => $second],
    ])->and($context->takeFailures())->toBe([]);
});
