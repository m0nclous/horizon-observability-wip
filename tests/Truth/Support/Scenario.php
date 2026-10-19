<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Truth\Support;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\ServerClock;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Storage;
use DF\HorizonObservability\Tests\Integration\Live\Support\Processes;
use DF\HorizonObservability\Tests\Support\Exposition;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Laravel\Horizon\Events\JobPushed;
use LogicException;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Throwable;

/**
 * The scenario engine of the truth tests (T16, owner decision 13).
 *
 * A scenario runs real jobs ({@see TruthJob}) through Horizon's Redis queue and real `queue:work` workers in this
 * process (or a forked child for timeouts and reserved jobs) on a frozen clock, and feeds the same events into
 * the expected-value {@see Model}: every job Horizon pushes (`JobPushed`, with its stored payload) and every worker
 * run. Tests then scrape the real endpoint and compare the exposition with the model and with independent reads of
 * Redis, the database and Horizon's repositories.
 *
 * The clock (Laravel's and CarbonImmutable's, so also Horizon's and Carbon 2's) stays on whole seconds near the real
 * time: payloads carry the real `pushedAt` (microtime) next to `createdAt`, and the model reads both from the payload.
 * The storage Redis' clock, on which the new-series hold runs ({@see ServerClock}), is pinned to the same time.
 * Durations are real (the attempts sleep their planned time); everything else is exact.
 */
final class Scenario
{
    public const TOKEN = 'truth-scenario-token-0123456789abcdef';

    /**
     * Histogram buckets of the scenarios: wide margins around the planned durations (0 s, 0.4 s, 1.5 s, the 1 s
     * timeout), so the measured overhead never moves an attempt to another bucket ({@see Model::OVERHEAD_SECONDS}).
     */
    public const BUCKETS = [0.25, 1, 2.5, 10, 30, 60, 120];

    public readonly Model $model;

    private CarbonImmutable $now;

    /**
     * The counter samples of the previous scrape ({@see Invariants::counters()}).
     *
     * @var array<string, float>
     */
    private array $counters = [];

    /**
     * `meta.reset_at` at the previous scrape.
     */
    private ?string $resetAt = null;

    private int $scrapes = 0;

    /**
     * @param  list<float|int>  $buckets
     */
    private function __construct(private readonly TestCase $test, array $buckets)
    {
        $app = self::app();
        $app['config']->set('horizon-observability.token', self::TOKEN);
        $app['config']->set('horizon-observability.buckets', $buckets);
        // A final failure goes to the failed-job provider; the test database has no failed_jobs table.
        $app['config']->set('queue.failed.driver', 'null');
        $app->forgetInstance('queue.failer');
        $app->forgetInstance(Config::class);

        $this->model = new Model(array_map(floatval(...), $buckets));
        $this->freeze(CarbonImmutable::createFromTimestamp((int) floor(microtime(true))));

        Event::listen(JobPushed::class, function (JobPushed $event): void {
            $payload = $event->payload->decoded;
            $command = is_array($payload['data'] ?? null) && is_string($payload['data']['command'] ?? null)
                ? unserialize($payload['data']['command'])
                : null;

            if ($command instanceof TruthJob) {
                $delay = is_int($command->delay) ? $command->delay : 0;
                $this->model->pushed((string) $event->payload->id(), $payload, (string) $event->connectionName, (string) $event->queue, $command, $delay, $this->unix());
            }
        });
    }

    /**
     * A scenario on a clock frozen at the current whole second.
     *
     * @param  list<float|int>  $buckets
     */
    public static function start(TestCase $test, array $buckets = self::BUCKETS): self
    {
        return new self($test, $buckets);
    }

    public function now(): CarbonImmutable
    {
        return $this->now;
    }

    public function unix(): float
    {
        return (float) $this->now->format('U.u');
    }

    /**
     * Move the frozen clock forward.
     */
    public function advance(float $seconds): self
    {
        return $this->freeze($this->now->addMicroseconds((int) round($seconds * 1e6)));
    }

    /**
     * Dispatch a job (`$delay` seconds later).
     */
    public function dispatch(TruthJob $job, string $queue = 'default', int $delay = 0, string $connection = 'redis'): self
    {
        $job->onConnection($connection)->onQueue($queue);

        if ($delay > 0) {
            $job->delay($delay);
        }

        dispatch($job);

        return $this;
    }

    /**
     * Run `queue:work --stop-when-empty` on a queue now, in this process.
     */
    public function work(string $queue = 'default', string $connection = 'redis'): self
    {
        $code = Artisan::call('queue:work', self::workerOptions($connection, $queue, once: false));

        if ($code !== 0) {
            throw new RuntimeException(sprintf('queue:work exited with %d.', $code));
        }

        $this->model->work($connection, $queue, $this->unix());

        return $this;
    }

    /**
     * Let a forked worker take the head job of a queue and hold it (the job sleeps its planned time) while
     * `$during` runs, e.g. a scrape that sees it reserved; then wait for the worker to finish the attempt.
     *
     * @template T
     *
     * @param  callable(): T  $during
     * @return T
     */
    public function whileReserved(callable $during, string $queue = 'default', string $connection = 'redis'): mixed
    {
        $at = $this->unix();
        $id = $this->model->reserve($connection, $queue, $at);
        $pids = Processes::fork(1, static function () use ($connection, $queue): void {
            // The daemon loop: only it arms the timeout alarm (`--once` runs the job without one).
            Artisan::call('queue:work', self::workerOptions($connection, $queue, once: false));
        });

        try {
            $this->waitFor(fn (): bool => (int) $this->queueRedis($connection)->zcard('queues:'.$queue.':reserved') > 0, 'the forked worker did not reserve the job');
            $result = $during();
        } finally {
            Processes::wait($pids, 60.0);
        }

        $this->model->finish($id, $at);

        return $result;
    }

    /**
     * Let a forked worker run the head job of a queue, which hangs past its timeout: the worker's SIGALRM handler
     * records the attempt as timed out and kills the worker.
     */
    public function timeOutInChild(float $timeout, string $queue = 'default', string $connection = 'redis'): self
    {
        $at = $this->unix();
        $id = $this->model->reserve($connection, $queue, $at);
        $pids = Processes::fork(1, static function () use ($connection, $queue): void {
            Artisan::call('queue:work', self::workerOptions($connection, $queue, once: false));
        });

        Processes::wait($pids, 60.0);
        $this->model->timedOut($id, $at, $timeout);

        return $this;
    }

    /**
     * Scrape the real endpoint now, with the scenario's token, and assert the {@see Invariants} on it: those of the
     * scrape itself and, against the previous scrape of this scenario, counters that never decrease except at a
     * documented reset (`meta.reset_at` changed in between).
     */
    public function scrape(): Exposition
    {
        $response = $this->test->withHeader('Authorization', 'Bearer '.self::TOKEN)->get('/horizon/observability/metrics');

        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException(sprintf('The scrape answered %d: %s', $response->getStatusCode(), (string) $response->getContent()));
        }

        $exposition = Exposition::parse((string) $response->getContent());
        $samples = new Samples($exposition);
        $counters = Invariants::counters($samples);
        $resetAt = $this->resetAt();
        $violations = Invariants::violations($samples);

        if ($resetAt === $this->resetAt) {
            array_push($violations, ...Invariants::decreases($this->counters, $counters));
        }

        Assert::assertSame([], $violations, sprintf("The invariants of the scrape %d of this scenario, at %s:\n%s", ++$this->scrapes, $this->now->format('U.u'), implode("\n", $violations)));
        $this->counters = $counters;
        $this->resetAt = $resetAt;

        return $exposition;
    }

    /**
     * The queue connection's Redis connection (it applies the queue prefix), for independent reads of the queues.
     */
    public function queueRedis(string $connection = 'redis'): Connection
    {
        $app = self::app();
        $name = $app['config']->get('queue.connections.'.$connection.'.connection', 'default');

        return $app->make('redis')->connection(is_string($name) ? $name : 'default');
    }

    /**
     * Unfreeze the clocks, the server clock included.
     */
    public static function stop(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        if (Container::getInstance()->bound(ServerClock::class)) {
            Container::getInstance()->make(ServerClock::class)->pin(null);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function workerOptions(string $connection, string $queue, bool $once): array
    {
        return [
            'connection' => $connection,
            '--queue' => $queue,
            '--stop-when-empty' => true,
            '--once' => $once,
            '--sleep' => 0,
            '--tries' => 1,
            '--timeout' => 0,
            '--memory' => 4096,
        ];
    }

    private function freeze(CarbonImmutable $time): self
    {
        $this->now = $time;
        Carbon::setTestNow($time);
        CarbonImmutable::setTestNow($time);
        self::app()->make(ServerClock::class)->pin((float) $time->format('U.u'));

        return $this;
    }

    /**
     * `meta.reset_at` of the attempt storage now (null when unset or unreadable).
     */
    private function resetAt(): ?string
    {
        try {
            $resetAt = Storage::store()->reader()->hget((new Keys)->meta(), 'reset_at');
        } catch (Throwable) {
            return null;
        }

        return is_string($resetAt) ? $resetAt : null;
    }

    private function waitFor(callable $condition, string $message): void
    {
        $deadline = microtime(true) + 20;

        while (! $condition()) {
            if (microtime(true) > $deadline) {
                throw new LogicException($message.'.');
            }

            usleep(20_000);
        }
    }

    /**
     * The test's application (the container instance while a test runs).
     */
    public static function app(): Application
    {
        $app = Container::getInstance();

        if (! $app instanceof Application) {
            throw new LogicException('No application is running.');
        }

        return $app;
    }
}
