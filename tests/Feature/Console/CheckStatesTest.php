<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Support\CheckResult;
use DF\HorizonObservability\Support\Diagnostics;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/*
| The check states of horizon-observability:check that CheckCommandTest does not seed, each with its exact message
| and fix line, and the numbers check prints (they must equal the configuration and the state they report):
| - Configuration: the config file, Enabled = no, an empty namespace;
| - Storage: the braces in the prefix (an empty hash tag is a warning, and an error on a cluster; a tag is info) and
|   the client timeouts;
| - Queues: no supervisor in the environment, a queue connection that is not redis, an unreachable queue Redis;
| - Batches (the batching driver and table): collectors.batches = true with another driver, and the table found;
| - Live: live off with stream settings, the poll endpoint's shared TTL, the age of the last stream push;
| - the error and warning totals of check (JSON and text).
*/

pest()->group('redis');

/**
 * @return array<string, CheckResult> "section / name" => result
 */
function checkStates(): array
{
    app()->forgetInstance(Config::class);
    app()->forgetInstance(RedisClients::class);
    $results = [];

    foreach (app(Diagnostics::class)->check() as $result) {
        $results[$result->section.' / '.$result->name] = $result;
    }

    return $results;
}

/**
 * @return array{0: string, 1: string, 2: string|null}
 */
function checkState(string $key): array
{
    $results = checkStates();

    if (! isset($results[$key])) {
        throw new RuntimeException(sprintf('No check %s in: %s', $key, implode(', ', array_keys($results))));
    }

    return [$results[$key]->status->value, $results[$key]->message, $results[$key]->fix];
}

/**
 * @return list<string> the "section / name" keys of a section
 */
function checkStateKeys(string $section): array
{
    return array_values(array_filter(array_keys(checkStates()), static fn (string $key): bool => str_starts_with($key, $section.' / ')));
}

beforeEach(function (): void {
    config()->set('horizon-observability.token', 'check-states-token-0123456789');
    config()->set('app.url', 'http://localhost');
});

it('reports whether the config file is published', function (): void {
    $config = sys_get_temp_dir().'/ho-check-config-'.getmypid().'-'.bin2hex(random_bytes(3));
    mkdir($config, 0755, true);
    $previous = $this->app->configPath();

    try {
        $this->app->useConfigPath($config);

        expect(checkState('Configuration / Config file'))->toBe([
            'info',
            'not published: package defaults and HORIZON_OBSERVABILITY_* env vars apply',
            'Run php artisan horizon-observability:install to publish it.',
        ]);

        copy(dirname(__DIR__, 3).'/config/horizon-observability.php', $config.'/horizon-observability.php');

        expect(checkState('Configuration / Config file'))->toBe(['ok', 'config/horizon-observability.php', null]);
    } finally {
        $this->app->useConfigPath($previous);
        (new Filesystem)->deleteDirectory($config);
    }
});

it('warns about a disabled package, whose routes are then not checked', function (): void {
    expect(checkState('Configuration / Enabled'))->toBe(['ok', 'yes', null]);

    config()->set('horizon-observability.enabled', false);

    expect(checkState('Configuration / Enabled'))->toBe([
        'warning',
        'no: no endpoints, no attempt recording, no Horizon link',
        'Set HORIZON_OBSERVABILITY_ENABLED=true to turn the package on.',
    ])->and(checkState('Routes / Precedence'))->toBe(['info', 'not checked: the package is disabled', null])
        ->and(checkStateKeys('Routes'))->toBe(['Routes / Precedence']);
});

it('warns about an empty namespace and names the prefix of a set one', function (): void {
    expect(checkState('Configuration / Namespace'))->toBe(['ok', 'ho_* metrics', null]);

    config()->set('horizon-observability.namespace', 'office');

    expect(checkState('Configuration / Namespace'))->toBe(['ok', 'office_* metrics', null]);

    config()->set('horizon-observability.namespace', '');

    expect(checkState('Configuration / Namespace'))->toBe([
        'warning',
        'none: metric names such as queue_jobs_total may collide with other exporters',
        'Set HORIZON_OBSERVABILITY_NAMESPACE=ho (the default) and render the dashboard again.',
    ]);
});

it('prints the client timeouts of the package storage as configured', function (mixed $worker, mixed $scrape, string $message): void {
    config()->set('horizon-observability.redis.worker_timeout', $worker);
    config()->set('horizon-observability.redis.scrape_timeout', $scrape);

    expect(checkState('Storage / Timeouts'))->toBe(['info', $message, null]);
})->with([
    'the defaults' => [null, null, 'private clients, never persistent: worker writes 1 s (redis.worker_timeout), scrape reads 2 s (redis.scrape_timeout)'],
    'set' => [0.25, 3.5, 'private clients, never persistent: worker writes 0.25 s (redis.worker_timeout), scrape reads 3.5 s (redis.scrape_timeout)'],
    'set as strings, as env() returns them' => ['0.5', '4', 'private clients, never persistent: worker writes 0.5 s (redis.worker_timeout), scrape reads 4 s (redis.scrape_timeout)'],
]);

it('reports the braces in the storage prefix', function (string $braces, ?string $status, ?string $message, ?string $fix): void {
    // Under the test prefix, so the keys the dry run writes are deleted after the test.
    $prefix = TestCase::redisPrefix().$braces.'horizon:';
    config()->set('database.redis.horizon.options.prefix', $prefix);
    $results = checkStates();

    expect(isset($results['Storage / Prefix']) ? [$results['Storage / Prefix']->status->value, $results['Storage / Prefix']->message, $results['Storage / Prefix']->fix] : null)
        ->toBe($status === null ? null : [$status, sprintf((string) $message, $prefix), $fix]);
})->with([
    'none' => ['', null, null, null],
    'an empty hash tag' => ['{}', 'warning', 'the prefix "%s" starts an empty hash tag "{}": on a Redis Cluster every package key gets its own slot and the scripts fail with CROSSSLOT', 'Remove the braces from the prefix of database.redis.horizon (or give them content, e.g. {app}).'],
    'a hash tag' => ['{app}', 'info', 'the hash tag {app} of the prefix puts every package key in one slot', null],
    // Redis hashes up to the first "}" after the first "{": the one of the package's own {horizon-observability}.
    'an unclosed brace' => ['{app', 'info', 'the hash tag {apphorizon:{horizon-observability} of the prefix puts every package key in one slot', null],
]);

it('reports an empty hash tag in the prefix of a cluster storage as an error', function (): void {
    // Unreachable: the check reports the prefix from the configuration alone.
    config()->set('database.redis.clusters.braced', [['host' => '127.0.0.1', 'port' => 1, 'database' => 0], 'options' => ['prefix' => '{}app:']]);
    config()->set('horizon-observability.redis.connection', 'braced');

    expect(checkState('Storage / Prefix'))->toBe([
        'error',
        'the prefix "{}app:" starts an empty hash tag "{}": on a Redis Cluster every package key gets its own slot and the scripts fail with CROSSSLOT',
        'Remove the braces from the prefix of database.redis.braced (or give them content, e.g. {app}).',
    ])->and(checkState('Storage / Connection')[0])->toBe('error');

    config()->set('database.redis.clusters.braced.options.prefix', '{app}:');

    expect(checkState('Storage / Prefix'))->toBe(['info', 'the hash tag {app} of the prefix puts every package key in one slot', null]);
});

it('reports the queue topology of the environment', function (): void {
    expect(checkState('Queues / Topology'))->toBe(['ok', 'environment "testing": redis (default, slow, reports, mail, sms)', null])
        ->and(checkState('Queues / Redis default')[0])->toBe('ok')
        ->and(checkState('Queues / Redis default')[1])->toMatch('/^reachable in \d+\.\d ms$/');
});

it('warns when no supervisor runs a queue in the environment', function (): void {
    config()->set('horizon.environments', ['production' => ['supervisor-1' => ['connection' => 'redis', 'queue' => ['default'], 'maxProcesses' => 1]]]);

    expect(checkState('Queues / Topology'))->toBe([
        'warning',
        'no supervisor runs a queue in the environment "testing": queue metrics stay empty',
        'Add an environments entry matching "testing" to config/horizon.php (or set HORIZON_ENV).',
    ])->and(checkStateKeys('Queues'))->toBe(['Queues / Topology']);

    config()->set('horizon.env', 'production');

    expect(checkState('Queues / Topology'))->toBe(['ok', 'environment "production": redis (default)', null]);
});

it('warns about a supervisor on a queue connection that is not redis', function (): void {
    config()->set('queue.connections.database', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default']);
    config()->set('horizon.environments.testing', [
        'supervisor-1' => ['connection' => 'redis', 'queue' => ['default'], 'maxProcesses' => 1],
        'supervisor-2' => ['connection' => 'database', 'queue' => ['emails'], 'maxProcesses' => 1],
        'supervisor-3' => ['connection' => 'missing', 'queue' => ['sms'], 'maxProcesses' => 1],
    ]);
    $results = checkStates();

    expect($results['Queues / Topology']->message)->toBe('environment "testing": redis (default); database (emails); missing (sms)')
        ->and([$results['Queues / Connection database']->status->value, $results['Queues / Connection database']->message, $results['Queues / Connection database']->fix])->toBe([
            'warning',
            'not a configured redis queue connection: its queues are not inspected',
            'Point the Horizon supervisors at a redis queue connection, or add queue.connections.database with the redis driver.',
        ])
        ->and($results['Queues / Connection missing']->fix)->toBe('Point the Horizon supervisors at a redis queue connection, or add queue.connections.missing with the redis driver.')
        ->and($results['Queues / Redis default']->status->value)->toBe('ok');
});

it('reports an unreachable queue Redis without hanging', function (): void {
    config()->set('database.redis.queues_down', ['host' => '127.0.0.1', 'port' => 1, 'database' => 0, 'timeout' => 1, 'read_timeout' => 1]);
    config()->set('queue.connections.down', ['driver' => 'redis', 'connection' => 'queues_down', 'queue' => 'default']);
    config()->set('horizon.environments.testing', ['supervisor-1' => ['connection' => 'down', 'queue' => ['default'], 'maxProcesses' => 1]]);

    $started = microtime(true);
    [$status, $message, $fix] = checkState('Queues / Redis queues_down');

    expect($status)->toBe('error')
        ->and($message)->toStartWith('unreachable: ')
        ->and($fix)->toBe('Start Redis or fix database.redis.queues_down in config/database.php (the queue connection down uses it).')
        ->and(microtime(true) - $started)->toBeLessThan(30.0);
});

it('reports collectors.batches = true with a batching driver other than database as an error', function (): void {
    config()->set('queue.batching.driver', 'dynamodb');
    config()->set('horizon-observability.collectors.batches', true);

    expect(checkState('Batches / Batching'))->toBe([
        'error',
        'collectors.batches is true but the batching driver is [dynamodb]',
        'Use the database batching driver (queue.batching.driver), or set collectors.batches to null (auto) or false.',
    ]);

    config()->set('horizon-observability.collectors.batches');

    expect(checkState('Batches / Batching'))->toBe(['info', 'off (auto): the dynamodb batching driver is not read', null]);

    config()->set('horizon-observability.collectors.batches', false);

    expect(checkState('Batches / Batching'))->toBe(['info', 'off (collectors.batches = false)', null])
        ->and(checkState('Collectors / batches'))->toBe(['info', 'off (collectors.batches = false)', null]);
});

it('reports the batching table it reads', function (?bool $mode): void {
    Schema::connection('testing')->create('job_batches', static function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->integer('total_jobs');
        $table->integer('pending_jobs');
        $table->integer('failed_jobs');
        $table->longText('failed_job_ids');
        $table->mediumText('options')->nullable();
        $table->integer('cancelled_at')->nullable();
        $table->integer('created_at');
        $table->integer('finished_at')->nullable();
    });
    config()->set('horizon-observability.collectors.batches', $mode);

    expect(checkState('Batches / Batching'))->toBe(['ok', 'database: table job_batches on connection testing', null])
        ->and(checkState('Collectors / batches')[0])->toBe('ok');
})->with(['auto' => [null], 'on' => [true]]);

it('warns about stream settings while live is off', function (string $key, string $value): void {
    config()->set('horizon-observability.live.enabled', false);

    expect(checkState('Live / Enabled'))->toBe(['info', 'no: the dashboard must be rendered with --live=off', null]);

    config()->set('horizon-observability.live.stream.'.$key, $value);

    expect(checkState('Live / Enabled'))->toBe([
        'warning',
        'no, but the stream settings are set: the stream command refuses to start',
        'Set HORIZON_OBSERVABILITY_LIVE_ENABLED=true, or remove the HORIZON_OBSERVABILITY_LIVE_GRAFANA_* settings.',
    ])->and(checkStateKeys('Live'))->toBe(['Live / Enabled']);
})->with([
    'a Grafana URL' => ['grafana_url', 'http://grafana:3000'],
    'an invalid Grafana URL' => ['grafana_url', 'grafana:3000'],
    'a token' => ['token', 'glsa_check-states-token-0123456789'],
]);

it('prints the time the poll endpoint shares its snapshot', function (mixed $ttl, string $shared): void {
    config()->set('horizon-observability.live.cache_ttl', $ttl);

    expect(checkState('Live / Enabled'))->toBe(['ok', 'poll endpoint http://localhost/horizon/observability/live (shared for '.$shared.' s)', null]);
})->with([
    'the default' => [null, '1'],
    'off' => [0, '0'],
    'a fraction' => [2.5, '2.5'],
    'a string, as env() returns it' => ['0.25', '0.25'],
]);

it('prints the exact age of the last stream push', function (float $ago, string $status, string $message): void {
    config()->set('horizon-observability.live.stream.grafana_url', 'http://grafana:3000');
    config()->set('horizon-observability.live.stream.token', 'glsa_check-states-token-0123456789');
    $now = CarbonImmutable::parse('2026-10-18 12:00:00.500000', 'UTC');
    $this->travelTo($now);
    RedisKeys::client()->hset(TestCase::redisPrefix().'horizon:'.(new Keys)->meta(), 'live_push_at', (string) ((float) $now->format('U.u') - $ago));

    expect(array_slice(checkState('Live / Last push'), 0, 2))->toBe([$status, $message]);
})->with([
    'just now' => [0.0, 'ok', '0.0 s ago'],
    'a fraction' => [2.3, 'ok', '2.3 s ago'],
    'at the alert age' => [30.0, 'ok', '30.0 s ago'],
    'past the alert age' => [31.0, 'warning', '31 s ago: the live tiles are frozen'],
    'long ago' => [100.0, 'warning', '100 s ago: the live tiles are frozen'],
    // A push stamped by a clock ahead of this one.
    'in the future' => [-5.0, 'ok', '0.0 s ago'],
]);

it('counts the errors and warnings it reports, in JSON and in the text summary', function (): void {
    [, $healthy] = checkStatesReport();
    $baseline = $healthy['warnings'];

    // Two errors (no token outside local; a stream URL without a stream token) and two more warnings (no namespace;
    // no stream push yet).
    config()->set('horizon-observability.token');
    config()->set('horizon-observability.namespace', '');
    config()->set('horizon-observability.live.stream.grafana_url', 'http://grafana:3000');
    [$code, $report] = checkStatesReport();
    $count = static fn (string $status): int => count(array_filter($report['checks'], static fn (array $check): bool => $check['status'] === $status));

    expect($code)->toBe(1)
        ->and($report['status'])->toBe('error')
        ->and($report['errors'])->toBe(2)
        ->and($report['errors'])->toBe($count('error'))
        ->and($report['warnings'])->toBe($baseline + 2)
        ->and($report['warnings'])->toBe($count('warning'))
        ->and($healthy['warnings'])->toBe(count(array_filter($healthy['checks'], static fn (array $check): bool => $check['status'] === 'warning')));

    Artisan::call('horizon-observability:check');

    expect(Artisan::output())->toContain(sprintf('2 errors, %d warnings. Fix the errors above.', $baseline + 2));

    // Warnings only: the summary names them, and --strict fails on them.
    config()->set('horizon-observability.token', 'check-states-token-0123456789');
    config()->set('horizon-observability.live.stream.grafana_url');
    app()->forgetInstance(Config::class);

    expect(Artisan::call('horizon-observability:check'))->toBe(0)
        ->and(Artisan::output())->toContain(sprintf('0 errors, %d warnings.', $baseline + 1))
        ->and(Artisan::call('horizon-observability:check', ['--strict' => true]))->toBe(1)
        ->and(Artisan::output())->toContain(sprintf('0 errors, %d warnings. --strict fails on warnings.', $baseline + 1));
});

/**
 * @return array{0: int, 1: array{status: string, errors: int, warnings: int, checks: list<array{section: string, name: string, status: string, message: string, fix: string|null}>}}
 */
function checkStatesReport(): array
{
    app()->forgetInstance(Config::class);
    app()->forgetInstance(RedisClients::class);
    $code = Artisan::call('horizon-observability:check', ['--json' => true]);

    /** @var array{status: string, errors: int, warnings: int, checks: list<array{section: string, name: string, status: string, message: string, fix: string|null}>} $report */
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    return [$code, $report];
}
