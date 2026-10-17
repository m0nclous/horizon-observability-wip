<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Support\FailureReporter;
use DF\HorizonObservability\Tests\Support\LogRecorder;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;

beforeEach(function (): void {
    $this->time = 0.0;
    $this->log = new LogRecorder;
    $this->app->instance('log', $this->log);
    $this->reporter = new FailureReporter($this->app, fn (): float => $this->time);
});

it('logs a failure with the exception object and the package prefix', function (): void {
    $e = new RuntimeException("connection refused\n#0 trace");
    $this->reporter->report('collector:queues', $e, ['queue' => 'default']);

    expect($this->log->records)->toHaveCount(1)
        ->and($this->log->records[0]['level'])->toBe('warning')
        ->and($this->log->records[0]['message'])->toBe('horizon-observability: collector:queues failed: RuntimeException: connection refused')
        ->and($this->log->records[0]['context'])->toBe(['exception' => $e, 'queue' => 'default']);
});

it('throttles a key for 300 seconds in the process', function (): void {
    $e = new RuntimeException('down');
    $this->reporter->report('collector:queues', $e);
    $this->time = 299.0;
    $this->reporter->report('collector:queues', $e);
    $this->reporter->report('collector:horizon', $e);

    expect($this->log->records)->toHaveCount(2);

    $this->time = 300.0;
    cache()->flush();
    $this->reporter->report('collector:queues', $e);

    expect($this->log->records)->toHaveCount(3);
});

it('throttles across processes through the cache', function (): void {
    $other = new FailureReporter($this->app, fn (): float => $this->time);

    $this->reporter->warnOnce('budget:batches', 'scrape budget exhausted before batches');
    $other->warnOnce('budget:batches', 'scrape budget exhausted before batches');

    expect($this->log->messages())->toBe(['horizon-observability: scrape budget exhausted before batches']);
});

it('still logs when the cache fails', function (): void {
    $this->app->instance('cache', new class
    {
        public function store(): never
        {
            throw new RuntimeException('cache down');
        }
    });

    $this->reporter->warnOnce('x', 'warning');
    $this->time = 1.0;
    $this->reporter->warnOnce('x', 'warning');

    expect($this->log->messages())->toBe(['horizon-observability: warning']);
});

it('does not double the prefix', function (): void {
    $this->reporter->warnOnce('routes.late', 'horizon-observability: provider registered after boot.');

    expect($this->log->messages())->toBe(['horizon-observability: provider registered after boot.']);
});

it('logs info lines without throttling', function (): void {
    $this->reporter->info('record recovered after 30s, 2 writes dropped.');
    $this->reporter->info('record recovered after 30s, 2 writes dropped.');

    expect($this->log->messages('info'))->toHaveCount(2);
});

it('describes exceptions briefly', function (): void {
    expect(FailureReporter::describe(new LogicException('first line'.PHP_EOL.'second')))->toBe('LogicException: first line')
        ->and(FailureReporter::describe(new RuntimeException('')))->toBe('RuntimeException')
        ->and(FailureReporter::describe(new class('anonymous') extends RuntimeException {}))->toBe('class@anonymous: anonymous')
        ->and(strlen(FailureReporter::describe(new RuntimeException(str_repeat('x', 900)))))->toBe(500);
});

it('writes to the configured log channel', function (): void {
    $this->app->forgetInstance('log');
    Log::clearResolvedInstances();
    config()->set('logging.channels.observability', ['driver' => 'monolog', 'handler' => TestHandler::class]);
    config()->set('horizon-observability.log_channel', 'observability');
    $this->app->forgetInstance(Config::class);

    $this->reporter->warnOnce('x', 'to the channel');

    /** @var TestHandler $handler */
    $handler = Log::channel('observability')->getLogger()->getHandlers()[0];

    expect($handler->hasWarningThatContains('horizon-observability: to the channel'))->toBeTrue();
});

it('never throws, even without a logger', function (): void {
    $this->app->instance('log', null);

    $this->reporter->report('x', new RuntimeException('down'));
    $this->reporter->info('y');

    expect(true)->toBeTrue();
});
