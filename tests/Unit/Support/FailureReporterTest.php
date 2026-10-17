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

it('logs with the null cache store, throttled in the process only', function (): void {
    // The null store keeps nothing and its add() always fails: without the in-process fallback nothing was logged.
    config()->set('cache.stores.none', ['driver' => 'null']);
    config()->set('cache.default', 'none');
    $e = new RuntimeException('down');

    $this->reporter->report('discovery.scan', $e);
    $this->reporter->report('discovery.scan', $e);
    (new FailureReporter($this->app, fn (): float => $this->time))->report('discovery.scan', $e);

    expect($this->log->messages('warning'))->toBe([
        'horizon-observability: discovery.scan failed: RuntimeException: down',
        'horizon-observability: discovery.scan failed: RuntimeException: down',
    ]);
});

it('reports locally without touching the cache, throttled in the process', function (): void {
    // The cache may sit on the Redis that failed: reportLocally() must not wait for it (the breaker, C11).
    $this->app->instance('cache', new class
    {
        public int $calls = 0;

        public function store(): never
        {
            $this->calls++;

            throw new LogicException('the cache must not be used');
        }
    });
    $e = new RuntimeException('storage down');
    $other = new FailureReporter($this->app, fn (): float => $this->time);

    $this->reporter->reportLocally('breaker:attempts', $e);
    $this->reporter->reportLocally('breaker:attempts', $e);
    $other->reportLocally('breaker:attempts', $e);
    $this->time = 300.0;
    $this->reporter->reportLocally('breaker:attempts', $e);

    expect($this->log->messages('warning'))->toBe(array_fill(0, 3, 'horizon-observability: breaker:attempts failed: RuntimeException: storage down'))
        ->and($this->log->records[0]['context'])->toBe(['exception' => $e])
        ->and(app('cache')->calls)->toBe(0);
});

it('logs the config problems once, and nothing without problems', function (): void {
    $repository = clone $this->app['config'];
    $repository->set('horizon-observability.namespace', '1bad');
    $config = Config::fromRepository($repository);
    $clean = Config::fromRepository($this->app['config']);

    $this->reporter->configProblems($clean);
    $this->reporter->configProblems($config);
    $this->reporter->configProblems($config);

    expect($this->log->messages('warning'))->toBe(['horizon-observability: 1 invalid configuration values were replaced by defaults; run php artisan horizon-observability:check.'])
        ->and($this->log->records[0]['context']['problems'][0] ?? null)->toStartWith('namespace: ');
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
