<?php

declare(strict_types=1);

use DF\HorizonObservability\Redis\CircuitBreaker;
use DF\HorizonObservability\Support\FailureReporter;
use DF\HorizonObservability\Tests\Support\LogRecorder;

beforeEach(function (): void {
    $this->time = 1000.0;
    $this->log = new LogRecorder;
    $this->app->instance('log', $this->log);
    $clock = fn (): float => $this->time;
    $this->breaker = new CircuitBreaker(new FailureReporter($this->app, $clock), $clock);
});

it('runs writes while closed', function (): void {
    $runs = 0;

    expect($this->breaker->attempt(CircuitBreaker::ATTEMPTS, function () use (&$runs): void {
        $runs++;
    }))->toBeTrue()
        ->and($runs)->toBe(1)
        ->and($this->breaker->isOpen())->toBeFalse()
        ->and($this->breaker->takeDropped())->toBe(0)
        ->and($this->log->records)->toBe([]);
});

it('opens on a failure, warns once and skips writes for the cooldown (C11)', function (): void {
    $runs = 0;
    $fail = function () use (&$runs): never {
        $runs++;

        throw new RedisException('read error on connection');
    };

    expect($this->breaker->attempt(CircuitBreaker::ATTEMPTS, $fail))->toBeFalse()
        ->and($this->breaker->isOpen())->toBeTrue();

    $this->time += 10;
    expect($this->breaker->attempt(CircuitBreaker::ATTEMPTS, $fail))->toBeFalse();

    $this->time += 19.9;
    expect($this->breaker->attempt(CircuitBreaker::ATTEMPTS, $fail))->toBeFalse()
        ->and($runs)->toBe(1)
        ->and($this->breaker->takeDropped())->toBe(3)
        ->and($this->breaker->takeDropped())->toBe(0)
        ->and($this->log->messages('warning'))->toHaveCount(1)
        ->and($this->log->messages('warning')[0])->toBe('horizon-observability: breaker:attempts failed: RedisException: read error on connection');
});

it('probes after the cooldown and closes on success with one info line', function (): void {
    $this->breaker->attempt(CircuitBreaker::ATTEMPTS, fn () => throw new RuntimeException('down'));
    $this->time += 5;
    $this->breaker->attempt(CircuitBreaker::ATTEMPTS, fn (): null => null);

    $this->time += 25;
    expect($this->breaker->isOpen())->toBeFalse()
        ->and($this->breaker->attempt(CircuitBreaker::ATTEMPTS, fn (): null => null))->toBeTrue()
        ->and($this->breaker->isOpen())->toBeFalse()
        ->and($this->log->messages('info'))->toBe(['horizon-observability: attempts recovered after 30s, 2 writes dropped.']);

    expect($this->breaker->attempt(CircuitBreaker::ATTEMPTS, fn (): null => null))->toBeTrue()
        ->and($this->log->messages('info'))->toHaveCount(1);
});

it('re-opens on a failed probe without a second warning', function (): void {
    $this->breaker->attempt(CircuitBreaker::ATTEMPTS, fn () => throw new RuntimeException('down'));
    $this->time += 30;

    expect($this->breaker->attempt(CircuitBreaker::ATTEMPTS, fn () => throw new RuntimeException('still down')))->toBeFalse()
        ->and($this->breaker->isOpen())->toBeTrue();

    $this->time += 29;
    expect($this->breaker->attempt(CircuitBreaker::ATTEMPTS, fn (): null => null))->toBeFalse();

    $this->time += 1;
    expect($this->breaker->attempt(CircuitBreaker::ATTEMPTS, fn (): null => null))->toBeTrue()
        ->and($this->log->messages('warning'))->toHaveCount(1)
        ->and($this->log->messages('info'))->toBe(['horizon-observability: attempts recovered after 60s, 3 writes dropped.']);
});

it('hands the dropped count to the write that persists it', function (): void {
    $this->breaker->attempt(CircuitBreaker::ATTEMPTS, fn () => throw new RuntimeException('down'));
    $this->breaker->attempt(CircuitBreaker::ATTEMPTS, fn (): null => null);
    $this->time += 30;
    $persisted = null;

    $this->breaker->attempt(CircuitBreaker::ATTEMPTS, function () use (&$persisted): void {
        $persisted = $this->breaker->takeDropped();
    });

    expect($persisted)->toBe(2)
        ->and($this->breaker->takeDropped())->toBe(0);
});

it('restores a taken dropped count when the write fails', function (): void {
    $this->breaker->attempt(CircuitBreaker::ATTEMPTS, fn () => throw new RuntimeException('down'));
    $this->time += 30;

    $this->breaker->attempt(CircuitBreaker::ATTEMPTS, function (): never {
        $this->breaker->takeDropped();

        throw new RuntimeException('down again');
    });

    expect($this->breaker->takeDropped())->toBe(2);
});

it('counts only dropped attempts, not other dropped writes', function (): void {
    $this->breaker->attempt('ready_at', fn () => throw new RuntimeException('down'));
    $this->breaker->attempt('batch_links', fn (): null => null);
    $this->breaker->attempt(CircuitBreaker::ATTEMPTS, fn (): null => null);

    expect($this->breaker->isOpen())->toBeTrue()
        ->and($this->breaker->takeDropped())->toBe(1);
});

it('never throws', function (): void {
    expect($this->breaker->attempt(CircuitBreaker::ATTEMPTS, fn () => throw new Error('bug')))->toBeFalse();
});
