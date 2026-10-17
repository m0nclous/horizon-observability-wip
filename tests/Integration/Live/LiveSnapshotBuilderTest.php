<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Batches\BatchLinks;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Horizon\Supervisors;
use DF\HorizonObservability\Horizon\Topology;
use DF\HorizonObservability\Live\LiveScope;
use DF\HorizonObservability\Live\LiveSnapshot;
use DF\HorizonObservability\Live\LiveSnapshotBuilder;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Integration\Live\Support\LiveState;
use DF\HorizonObservability\Tests\Support\HorizonState;
use DF\HorizonObservability\Tests\Support\QueuePayloads;
use DF\HorizonObservability\Tests\Support\RedisCommands;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Repositories\RedisMasterSupervisorRepository;
use Laravel\Horizon\Repositories\RedisSupervisorRepository;

/*
| The live snapshot builder (Addendum R4.2, R4.4) over a seeded state (Support\LiveState): the JSON golden with every
| R4.4 field and unit, the Supervisor, Queue, Job and Batch scopes, the Q + 5 round trips, failure isolation per
| source and the 50 ms p95 budget for 20 queues. Runs on phpredis and on predis.
*/

pest()->group('redis');

beforeEach(function (): void {
    CarbonImmutable::setTestNow(LiveState::now());
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @param  array{supervisors?: list<string>|null, batchKinds?: list<string>|null, queues?: list<string>|null, jobClasses?: list<string>|null}  $filters
 */
function liveBuild(array $filters = [], ?LiveSnapshotBuilder $builder = null): LiveSnapshot
{
    $scope = LiveScope::fromFilters(
        [...['supervisors' => null, 'batchKinds' => null, 'queues' => null, 'jobClasses' => null], ...$filters],
        app(Topology::class),
        app(Supervisors::class),
        app(BatchLinks::class),
    );

    return ($builder ?? app(LiveSnapshotBuilder::class))->build($scope, LiveState::now());
}

function liveSeed(): void
{
    LiveState::horizon(app());
    LiveState::queues(app());
    LiveState::attempts();
}

/**
 * @return array<string, float|null>
 */
function liveTileValues(LiveSnapshot $snapshot, string $tile): array
{
    $values = [];

    foreach ($snapshot->tiles() as $row) {
        if ($row['tile'] === $tile) {
            $values[$row['key']] = $row['value'];
        }
    }

    return $values;
}

it('matches the golden document of the seeded state', function (): void {
    liveSeed();

    $json = json_encode(liveBuild()->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n";

    expect($json)->toBe(file_get_contents(dirname(__DIR__, 2).'/Fixtures/live/snapshot.json'));
});

it('uses the R4.4 units: Unix ms, the cache TTL in ms, seconds and per-minute rates', function (): void {
    liveSeed();
    config()->set('horizon-observability.live.cache_ttl', 2.5);
    app()->forgetInstance(Config::class);

    $snapshot = liveBuild()->toArray();

    expect($snapshot['generated_at'])->toBe(1792201716123)
        ->and($snapshot['ttl_ms'])->toBe(2500)
        ->and($snapshot['age_ms'])->toBe(0)
        ->and($snapshot['pending']['oldest_seconds'])->toBe(17)
        ->and($snapshot['queues'][0]['wait_threshold_seconds'])->toBe(10)
        ->and($snapshot['throughput']['window_seconds'])->toBe(60)
        ->and($snapshot['throughput']['bucket_seconds'])->toBe(5)
        ->and($snapshot['throughput']['completed_per_minute'])->toBe(42)
        ->and($snapshot['throughput']['failed_per_minute'])->toBe(2.0);
});

it('scopes by a Supervisor filter: its queues, its processes and its throughput', function (): void {
    liveSeed();

    $snapshot = liveBuild(['supervisors' => ['supervisor-pool']]);

    expect($snapshot->scope)->toBe(['supervisors' => ['supervisor-pool'], 'queues' => ['mail', 'sms'], 'job_filter' => false])
        ->and(array_column($snapshot->supervisors, 'name'))->toBe(['supervisor-pool'])
        ->and(liveTileValues($snapshot, 'supervisor'))->toBe(['supervisor-pool' => 2.0])
        ->and($snapshot->pending['value'])->toBe(0)
        ->and($snapshot->pending['caption'])->toBe("Oldest: \u{2014}")
        ->and($snapshot->delayed)->toBe(['value' => 1, 'due' => 0])
        ->and($snapshot->reserved)->toBe(['value' => 2])
        ->and($snapshot->throughput['completed'])->toBe(0)
        ->and($snapshot->throughput['retried'])->toBe(1)
        ->and(array_column($snapshot->queues, 'queue'))->toBe(['mail', 'sms']);
});

it('scopes by a Queue filter: only the pools of the selected queues count', function (): void {
    liveSeed();

    $slow = liveBuild(['queues' => ['slow']]);
    $sms = liveBuild(['queues' => ['sms']]);

    expect(array_column($slow->supervisors, 'name'))->toBe(['supervisor-slow'])
        ->and($slow->supervisors[0]['processes'])->toBe(1)
        ->and($slow->supervisors[0]['queues'])->toBe(['slow'])
        ->and($slow->pending['value'])->toBe(2)
        ->and($slow->pending['caption'])->toBe("Oldest: 12 s \u{00B7} slow")
        ->and($slow->throughput['completed'])->toBe(2)
        ->and($slow->throughput['caption'])->toBe('RPM: 2')
        // balance=false: the pool "mail,sms" serves sms, so all its processes count; busy is sms's reserved job
        ->and(array_column($sms->supervisors, 'name'))->toBe(['supervisor-pool'])
        ->and($sms->supervisors[0]['processes'])->toBe(2)
        ->and($sms->supervisors[0]['busy'])->toBe(1)
        ->and($sms->supervisors[0]['level'])->toBe(0);
});

it('gives null pending and delayed values, no throughput and busy workers of the classes with a Job filter', function (): void {
    liveSeed();
    $commands = RedisCommands::start(app());

    $snapshot = liveBuild(['jobClasses' => ['App\\Jobs\\SendInvoice']]);

    expect($snapshot->scope['job_filter'])->toBeTrue()
        ->and($snapshot->pending)->toBe(['value' => null, 'oldest_seconds' => 0, 'oldest_queue' => null, 'wait_ratio' => null, 'level' => 0, 'color' => 'green', 'caption' => "Oldest: \u{2014}"])
        ->and($snapshot->delayed)->toBe(['value' => null, 'due' => null])
        ->and($snapshot->throughput['completed'])->toBeNull()
        ->and($snapshot->throughput['caption'])->toBe("RPM: \u{2014}")
        ->and(array_column($snapshot->supervisors, 'busy', 'name'))->toBe(['supervisor-default' => 1, 'supervisor-pool' => 1, 'supervisor-slow' => 0])
        // the tiles count the busy workers of the class, as the off-mode tile does
        ->and(liveTileValues($snapshot, 'supervisor'))->toBe(['supervisor-default' => 1.0, 'supervisor-pool' => 1.0, 'supervisor-slow' => 0.0])
        ->and(liveTileValues($snapshot, 'pending'))->toBe(['' => null])
        ->and(liveTileValues($snapshot, 'delayed'))->toBe(['' => null])
        // no ring read: the ring has no classes
        ->and($commands->count(RedisClients::READER.'horizon'))->toBe(0)
        ->and($snapshot->queues[0]['reserved'])->toBe(2);
});

it('narrows a Batch filter to the linked queues and classes', function (): void {
    liveSeed();
    RedisKeys::client()->zadd(TestCase::redisPrefix().'horizon:{horizon-observability}:v1:batch_links', [
        "Import\x1fredis\x1fdefault\x1fApp\\Jobs\\Export" => (int) LiveState::NOW,
    ]);

    $snapshot = liveBuild(['batchKinds' => ['Import']]);

    expect($snapshot->scope)->toBe(['supervisors' => null, 'queues' => ['default'], 'job_filter' => true])
        ->and($snapshot->pending['value'])->toBeNull()
        ->and(array_column($snapshot->supervisors, 'busy', 'name'))->toBe(['supervisor-default' => 1]);
});

it('makes no queue call for an empty scope', function (): void {
    liveSeed();
    $commands = RedisCommands::start(app());

    $snapshot = liveBuild(['supervisors' => ['supervisor-gone']]);

    expect($snapshot->scope['queues'])->toBe([])
        ->and($snapshot->supervisors)->toBe([])
        ->and($snapshot->pending['value'])->toBe(0)
        ->and($snapshot->delayed)->toBe(['value' => 0, 'due' => 0])
        ->and($snapshot->throughput['completed'])->toBe(0)
        ->and($snapshot->errors)->toBe([])
        ->and($commands->count(RedisClients::READER.'default'))->toBe(0)
        ->and(array_filter($commands->names(), static fn (string $name): bool => $name === 'EVAL'))->toHaveCount(1);
});

it('costs Q + 5 round trips: 4 for Horizon, one EVAL per queue and one for the ring', function (): void {
    liveSeed();
    // Laravel fires no command event for a pipeline, so Horizon's repositories get a connection that counts calls.
    $calls = [];
    $factory = new class(app('redis'), $calls) implements RedisFactory
    {
        /** @param  list<string>  $calls */
        public function __construct(private readonly RedisFactory $redis, private array &$calls) {}

        public function connection($name = null): object
        {
            $connection = $this->redis->connection($name);
            $calls = &$this->calls;

            return new class($connection, $calls)
            {
                /** @param  list<string>  $calls */
                public function __construct(private readonly object $connection, private array &$calls) {}

                /** @param  array<int, mixed>  $arguments */
                public function __call(string $method, array $arguments): mixed
                {
                    $this->calls[] = strtoupper($method);

                    return $this->connection->{$method}(...$arguments);
                }
            };
        }
    };
    $builder = app()->make(LiveSnapshotBuilder::class, ['supervisors' => new Supervisors(
        new RedisMasterSupervisorRepository($factory),
        new RedisSupervisorRepository($factory),
    )]);
    $commands = RedisCommands::start(app());

    $snapshot = liveBuild([], $builder);

    expect($snapshot->supervisors)->toHaveCount(3)
        ->and($calls)->toBe(['ZREVRANGEBYSCORE', 'PIPELINE', 'ZREVRANGEBYSCORE', 'PIPELINE'])
        ->and($commands->names(RedisClients::READER.'default'))->toBe(['EVAL', 'EVAL', 'EVAL', 'EVAL', 'EVAL'])
        ->and($commands->names(RedisClients::READER.'horizon'))->toBe(['EVAL'])
        // the horizon connection's own events (ZREVRANGEBYSCORE, and PIPELINE on predis) are among $calls
        ->and(count($calls) + $commands->count() - $commands->count('horizon'))->toBe(5 + 5);
});

it('lists a failed queue in errors and leaves it out of the sums', function (): void {
    liveSeed();
    RedisKeys::client()->del(TestCase::redisPrefix().'queues:slow');
    RedisKeys::client()->set(TestCase::redisPrefix().'queues:slow', 'not a list');

    $snapshot = liveBuild();

    expect($snapshot->errors)->toHaveCount(1)
        ->and($snapshot->errors[0]['source'])->toBe('queue:redis:slow')
        ->and($snapshot->errors[0]['message'])->toStartWith('RedisScriptFailed: ')
        ->and(strlen($snapshot->errors[0]['message']))->toBeLessThanOrEqual(LiveSnapshotBuilder::MAX_ERROR_BYTES)
        ->and(array_column($snapshot->queues, 'queue'))->toBe(['default', 'reports', 'mail', 'sms'])
        ->and($snapshot->pending['value'])->toBe(3)
        ->and($snapshot->isAvailable())->toBeTrue();
});

it('skips the other queues of a Redis connection that failed', function (): void {
    config()->set('database.redis.dead', ['host' => '127.0.0.1', 'port' => 1, 'database' => 0, 'timeout' => 0.2]);
    config()->set('queue.connections.dead', ['driver' => 'redis', 'connection' => 'dead', 'queue' => 'default']);
    config()->set('horizon.environments.testing.supervisor-dead', ['connection' => 'dead', 'queue' => ['a', 'b'], 'maxProcesses' => 1, 'minProcesses' => 1]);
    app()->forgetInstance(RedisClients::class);
    liveSeed();

    $snapshot = liveBuild();

    expect(array_column($snapshot->errors, 'source'))->toBe(['queue:dead:a', 'queue:dead:b'])
        ->and($snapshot->errors[1]['message'])->toBe('skipped: the Redis connection "dead" failed for an earlier queue.')
        ->and($snapshot->pending['value'])->toBe(5)
        ->and(array_column($snapshot->queues, 'queue'))->toBe(['default', 'slow', 'reports', 'mail', 'sms']);
});

it('answers Live unavailable for the queue part when no queue could be read', function (): void {
    config()->set('database.redis.dead', ['host' => '127.0.0.1', 'port' => 1, 'database' => 0, 'timeout' => 0.2]);
    config()->set('queue.connections.redis.connection', 'dead');
    app()->forgetInstance(RedisClients::class);
    liveSeed();

    $snapshot = liveBuild();

    expect($snapshot->pending)->toBe(['value' => null, 'oldest_seconds' => 0, 'oldest_queue' => null, 'wait_ratio' => null, 'level' => 0, 'color' => 'text', 'caption' => 'Live unavailable'])
        ->and($snapshot->delayed)->toBe(['value' => null, 'due' => null])
        ->and($snapshot->reserved)->toBe(['value' => null])
        ->and($snapshot->queues)->toBe([])
        ->and($snapshot->horizon['caption'])->toBe('Running')
        ->and(array_column($snapshot->supervisors, 'color'))->toBe(['text', 'text', 'text'])
        ->and(liveTileValues($snapshot, 'supervisor'))->toBe(['supervisor-default' => 3.0, 'supervisor-pool' => 2.0, 'supervisor-slow' => 2.0])
        ->and($snapshot->throughput['completed'])->toBe(42)
        ->and(array_column($snapshot->errors, 'source'))->toBe(['queue:redis:default', 'queue:redis:slow', 'queue:redis:reports', 'queue:redis:mail', 'queue:redis:sms'])
        ->and($snapshot->isAvailable())->toBeTrue();
});

it('answers LiveSnapshot::unavailable() when nothing could be read', function (): void {
    config()->set('database.redis.dead', ['host' => '127.0.0.1', 'port' => 1, 'database' => 0, 'timeout' => 0.2]);
    config()->set('queue.connections.redis.connection', 'dead');
    config()->set('horizon-observability.redis.connection', 'dead');
    app()->forgetInstance(Config::class);
    app()->forgetInstance(RedisClients::class);
    $masters = Mockery::mock(MasterSupervisorRepository::class);
    $masters->shouldReceive('all')->andThrow(new RuntimeException('Connection refused'));
    $supervisors = Mockery::mock(SupervisorRepository::class)->shouldNotReceive('all')->getMock();
    $builder = app()->make(LiveSnapshotBuilder::class, ['supervisors' => new Supervisors($masters, $supervisors)]);
    $scope = LiveScope::full(app(Topology::class));

    $snapshot = $builder->build($scope, LiveState::now());

    expect($snapshot->isAvailable())->toBeFalse()
        ->and($snapshot->toArray())->toBe(LiveSnapshot::unavailable($scope, 1792201716123, 1000, $snapshot->errors)->toArray())
        ->and(array_column($snapshot->errors, 'source'))->toBe(['horizon', 'queue:redis:default', 'queue:redis:slow', 'queue:redis:reports', 'queue:redis:mail', 'queue:redis:sms', 'ring'])
        ->and($snapshot->errors[0]['message'])->toBe('RuntimeException: Connection refused')
        ->and($snapshot->horizon['caption'])->toBe('Live unavailable')
        ->and($snapshot->pending['caption'])->toBe('Live unavailable');
});

it('shows processes as 0 and the supervisors red while Horizon is paused', function (): void {
    LiveState::horizon(app(), 'paused');
    LiveState::queues(app());

    $snapshot = liveBuild();

    expect($snapshot->horizon)->toBe(['status' => 0, 'level' => 2, 'color' => 'red', 'caption' => 'Paused'])
        ->and(array_column($snapshot->supervisors, 'processes'))->toBe([0, 0, 0])
        ->and(array_column($snapshot->supervisors, 'color'))->toBe(['red', 'red', 'red'])
        ->and(liveTileValues($snapshot, 'supervisor'))->toBe(['supervisor-default' => 0.0, 'supervisor-pool' => 0.0, 'supervisor-slow' => 0.0]);
});

it('reads Horizon as stopped without a master and lists no supervisor', function (): void {
    LiveState::queues(app());

    $snapshot = liveBuild();

    expect($snapshot->horizon)->toBe(['status' => -1, 'level' => 2, 'color' => 'red', 'caption' => 'Stopped'])
        ->and($snapshot->supervisors)->toBe([])
        ->and($snapshot->pending['value'])->toBe(5);
});

it('merges a supervisor that several masters run', function (): void {
    HorizonState::using(app())
        ->master('host-a', supervisors: ['host-a:supervisor-default'])
        ->master('host-b', supervisors: ['host-b:supervisor-default'])
        ->supervisor('host-a:supervisor-default', HorizonState::options('host-a:supervisor-default', maxProcesses: 3), ['redis:default' => 3])
        ->supervisor('host-b:supervisor-default', HorizonState::options('host-b:supervisor-default', maxProcesses: 5), ['redis:default' => 2]);

    $snapshot = liveBuild();

    expect($snapshot->supervisors)->toHaveCount(1)
        ->and($snapshot->supervisors[0]['processes'])->toBe(5)
        ->and($snapshot->supervisors[0]['max_processes'])->toBe(5)
        ->and($snapshot->supervisors[0]['caption'])->toBe("default \u{00B7} max 5");
});

it('skips the remaining reads once the build budget is spent', function (): void {
    liveSeed();
    $clock = 0.0;
    $builder = app()->make(LiveSnapshotBuilder::class, ['clock' => function () use (&$clock): float {
        $clock += 0.7; // every reading of the clock: the start, then before each queue and the ring

        return $clock;
    }]);

    $snapshot = liveBuild([], $builder);

    expect(array_column($snapshot->queues, 'queue'))->toBe(['default', 'slow'])
        ->and(array_column($snapshot->errors, 'source'))->toBe(['queue:redis:reports', 'queue:redis:mail', 'queue:redis:sms', 'ring'])
        ->and($snapshot->errors[0]['message'])->toBe('skipped: the build ran out of its 2 s budget.')
        ->and($snapshot->throughput['caption'])->toBe("RPM: \u{2014}");
});

it('skips queues of connections that are not redis', function (): void {
    config()->set('queue.connections.sync-like', ['driver' => 'sync']);
    config()->set('horizon.environments.testing.supervisor-sync', ['connection' => 'sync-like', 'queue' => ['jobs'], 'maxProcesses' => 1]);

    $snapshot = liveBuild();

    expect($snapshot->scope['queues'])->toContain('jobs')
        ->and(array_column($snapshot->queues, 'queue'))->not->toContain('jobs')
        ->and($snapshot->errors)->toBe([]);
});

it('builds 20 queues within 50 ms at the 95th percentile', function (): void {
    $queues = array_map(static fn (int $i): string => sprintf('q%02d', $i), range(1, 20));
    config()->set('horizon.environments.testing', ['supervisor-perf' => ['connection' => 'redis', 'queue' => $queues, 'maxProcesses' => 20, 'minProcesses' => 1]]);
    $redis = LiveState::redis(app());
    $filler = str_repeat('x', 1200);

    foreach ($queues as $queue) {
        $payloads = [];

        foreach (range(1, 500) as $i) {
            $payloads[] = QueuePayloads::encode(LiveState::job('App\\Jobs\\SendInvoice', LiveState::NOW - 30 + $i / 100, ['data' => ['commandName' => 'App\\Jobs\\SendInvoice', 'command' => $filler]]));
        }

        $redis->rpush('queues:'.$queue, ...$payloads);
    }

    HorizonState::using(app())
        ->master('host-a', supervisors: ['host-a:supervisor-perf'])
        ->supervisor('host-a:supervisor-perf', HorizonState::options('host-a:supervisor-perf', queue: implode(',', $queues), maxProcesses: 20), array_fill_keys(array_map(static fn (string $q): string => 'redis:'.$q, $queues), 1));

    $builder = app(LiveSnapshotBuilder::class);
    $scope = LiveScope::full(app(Topology::class));
    $builder->build($scope, LiveState::now()); // warm up: connections, script sources
    $durations = [];

    foreach (range(1, 40) as $ignored) {
        $start = hrtime(true);
        $snapshot = $builder->build($scope, LiveState::now());
        $durations[] = (hrtime(true) - $start) / 1e6;
    }

    sort($durations);

    expect($snapshot->pending['value'])->toBe(10000)
        ->and($snapshot->errors)->toBe([])
        ->and($durations[(int) ceil(0.95 * count($durations)) - 1])->toBeLessThan(50.0);
});
