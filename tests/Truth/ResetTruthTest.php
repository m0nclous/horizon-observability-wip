<?php

declare(strict_types=1);

use DF\HorizonObservability\Attempts\AttemptStore;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Storage;
use DF\HorizonObservability\Tests\Support\LogRecorder;
use DF\HorizonObservability\Tests\Truth\Support\Invariants;
use DF\HorizonObservability\Tests\Truth\Support\Model;
use DF\HorizonObservability\Tests\Truth\Support\Samples;
use DF\HorizonObservability\Tests\Truth\Support\Scenario;
use DF\HorizonObservability\Tests\Truth\Support\Scenarios;
use DF\HorizonObservability\Tests\Truth\Support\TruthJob;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/*
| Exporter truth (T16): counters never decrease, except in a documented reset. A Redis restart without persistence
| loses the counters; a restore from an older dataset (an RDB, a lagging replica) would make increase() count old
| totals again, so the reset guard deletes the counters when the Redis run_id changes (Arch Q6.10, C21) and
| documents it: meta.reset_at and one info line. After either, every series starts at 0 again.
*/

pest()->group('redis', 'truth');

afterEach(function (): void {
    Scenario::stop();
});

it('decreases a counter only at the documented reset of a restored dataset', function (): void {
    $this->app->instance('log', $log = new LogRecorder);
    $resetAt = null;
    [$scenario, $scrapes] = Scenarios::timeline($this, 10, static function (Scenario $scenario, int $second) use (&$resetAt): void {
        if ($second === 350) {
            // A dataset restored from an older snapshot carries another server's run id.
            Storage::store()->reader()->hset((new Keys)->meta(), 'run_id', 'a-restored-dataset');
            $resetAt = $scenario->unix();
        }
    });
    $previous = [];

    foreach ($scrapes as $second => ['samples' => $samples]) {
        $values = $samples->integers(Metric::JobsTotal->value);

        foreach ($previous as $series => $value) {
            if (($values[$series] ?? 0) < $value) {
                expect($second)->toBe(350, sprintf('%s decreased at %d s', $series, $second));
            }
        }

        expect(Invariants::violations($samples))->toBe([], 'at '.$second.' s');
        $previous = $values;
    }

    $after = [];

    foreach ($scenario->model->attempts() as $attempt) {
        if ($attempt['at'] > $resetAt) {
            $key = Model::key(['connection' => $attempt['connection'], 'queue' => $attempt['queue'], 'job_class' => $attempt['class'], 'status' => $attempt['status']]);
            $after[$key] = ($after[$key] ?? 0) + 1;
        }
    }

    ksort($after);

    expect($scrapes[340]['samples']->integers(Metric::JobsTotal->value))->not->toBe([])
        ->and($scrapes[350]['samples']->integers(Metric::JobsTotal->value))->toBe([])
        ->and(array_filter(end($scrapes)['samples']->integers(Metric::JobsTotal->value)))->toBe($after)
        ->and((float) Storage::hash((new Keys)->meta())['reset_at'])->toBe($resetAt)
        ->and($log->messages('info'))->toContain('horizon-observability: the Redis run_id changed (restart or restore); the attempt counters were reset.');
});

it('starts the counters at 0 after a real Redis restart, with and without the old dataset', function (): void {
    $server = (new ExecutableFinder)->find('redis-server');

    if ($server === null) {
        $this->markTestSkipped('No redis-server binary to restart a private Redis.');
    }

    $directory = sys_get_temp_dir().'/ho-truth-redis-'.getmypid().'-'.bin2hex(random_bytes(3));
    mkdir($directory);
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $redis = null;
    $start = static function () use ($server, $port, $directory, &$redis): void {
        $redis = new Process([$server, '--port', (string) $port, '--dir', $directory, '--save', '', '--appendonly', 'no']);
        $redis->start();
        $deadline = microtime(true) + 10;

        while (@fsockopen('127.0.0.1', $port) === false) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('The private Redis did not start: '.$redis->getErrorOutput());
            }

            usleep(20_000);
        }
    };
    $stop = static function (string $mode) use ($port, &$redis): void {
        // SAVE keeps the dataset in dump.rdb for the next start, like a restore of an older snapshot.
        $command = $mode === 'save' ? "*1\r\n$4\r\nSAVE\r\n*2\r\n$8\r\nSHUTDOWN\r\n$6\r\nNOSAVE\r\n" : "*2\r\n$8\r\nSHUTDOWN\r\n$6\r\nNOSAVE\r\n";
        $connection = fsockopen('127.0.0.1', $port);
        fwrite($connection, $command);
        $redis->wait();
    };
    $reconnect = static function (): void {
        foreach ([Config::class, RedisClients::class, AttemptStore::class] as $abstract) {
            app()->forgetInstance($abstract);
        }
    };

    $this->app->instance('log', $log = new LogRecorder);
    config()->set('database.redis.truth-private', ['host' => '127.0.0.1', 'port' => $port, 'database' => 0]);
    config()->set('horizon-observability.redis.connection', 'truth-private');
    $reconnect();
    $start();

    try {
        $scenario = Scenario::start($this);
        $completed = static fn (): ?float => (new Samples($scenario->scrape()))->value(Metric::JobsTotal->value, ['connection' => 'redis', 'queue' => 'default', 'job_class' => 'Truth\\Invoice', 'status' => 'completed']);

        foreach (range(1, 3) as $ignored) {
            $scenario->dispatch(new TruthJob('Truth\\Invoice'));
        }

        $scenario->work()->advance(Model::HOLD_SECONDS);

        expect($completed())->toBe(3.0);

        // Restart with the old dataset: the counters come back under a new run id and are reset at once.
        $stop('save');
        $start();
        $reconnect();

        expect($completed())->toBeNull()
            ->and($log->messages('info'))->toContain('horizon-observability: the Redis run_id changed (restart or restore); the attempt counters were reset.');

        $scenario->dispatch(new TruthJob('Truth\\Invoice'))->work();

        expect($completed())->toBe(0.0);

        $scenario->advance(Model::HOLD_SECONDS);

        expect($completed())->toBe(1.0);

        // Restart without the dataset: nothing to reset, the counters are simply gone and start at 0 again.
        $stop('nosave');
        @unlink($directory.'/dump.rdb');
        $start();
        $reconnect();

        expect($completed())->toBeNull();

        $scenario->dispatch(new TruthJob('Truth\\Invoice'))->dispatch(new TruthJob('Truth\\Invoice'))->work();

        expect($completed())->toBe(0.0);

        $scenario->advance(Model::HOLD_SECONDS);

        expect($completed())->toBe(2.0);
    } finally {
        if ($redis instanceof Process && $redis->isRunning()) {
            $redis->stop(0);
        }

        array_map(unlink(...), glob($directory.'/*') ?: []);
        rmdir($directory);
    }
});
