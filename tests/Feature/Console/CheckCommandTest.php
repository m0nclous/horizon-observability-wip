<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Collectors\Collector;
use DF\HorizonObservability\Collectors\QueueCollector;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Discovery\JobClassCache;
use DF\HorizonObservability\HorizonObservabilityServiceProvider;
use DF\HorizonObservability\Metrics\ScrapeContext;
use DF\HorizonObservability\Queues\UnsupportedTopology;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Support\CheckResult;
use DF\HorizonObservability\Support\CheckStatus;
use DF\HorizonObservability\Support\Diagnostics;
use DF\HorizonObservability\Support\Versions;
use DF\HorizonObservability\Tests\Support\Apps;
use DF\HorizonObservability\Tests\Support\HorizonState;
use DF\HorizonObservability\Tests\Support\RegistersInBootProvider;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\HorizonServiceProvider;
use Predis\Client;

/*
| PUBLIC horizon-observability:check (Arch Q7 and the WP10 row, Addendum Q9' WP10, owner decision 12): every seeded
| problem is caught with the right exit code and --json, and every warning and error carries one fix line. Seeded:
| locked auth, shadowed route, missing table, no timeout, persistent client, config problem, cluster topology, and
| the live cases (missing token, 401 token, 403 token, invalid stream id).
*/

pest()->group('redis');

const CHECK_TOKEN = 'check-token-0123456789abcdef';

const CHECK_LIVE_TOKEN = 'glsa_check-live-token-0123456789';

beforeEach(function (): void {
    config()->set('horizon-observability.token', CHECK_TOKEN);
    $this->app->forgetInstance(Config::class);
});

/**
 * Run the check with --json; [exit code, decoded report].
 *
 * @param  array<string, mixed>  $options
 * @return array{0: int, 1: array{status: string, errors: int, warnings: int, checks: list<array{section: string, name: string, status: string, message: string, fix: string|null}>}}
 */
function checkReport(array $options = []): array
{
    app()->forgetInstance(Config::class);
    app()->forgetInstance(RedisClients::class);
    $code = Artisan::call('horizon-observability:check', ['--json' => true, ...$options]);

    /** @var array{status: string, errors: int, warnings: int, checks: list<array{section: string, name: string, status: string, message: string, fix: string|null}>} $report */
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    return [$code, $report];
}

/**
 * @param  array{checks: list<array{section: string, name: string, status: string, message: string, fix: string|null}>}  $report
 * @return array{section: string, name: string, status: string, message: string, fix: string|null}
 */
function checkItem(array $report, string $section, string $name): array
{
    foreach ($report['checks'] as $check) {
        if ($check['section'] === $section && $check['name'] === $name) {
            return $check;
        }
    }

    throw new RuntimeException(sprintf('No check %s / %s in: %s', $section, $name, json_encode(array_map(
        static fn (array $check): string => $check['section'].' / '.$check['name'],
        $report['checks'],
    ))));
}

/**
 * @param  array{checks: list<array{section: string, status: string}>}  $report
 * @return list<array{section: string, name: string, status: string, message: string, fix: string|null}>
 */
function checkProblems(array $report, string $status = 'error'): array
{
    return array_values(array_filter($report['checks'], static fn (array $check): bool => $check['status'] === $status));
}

it('passes a healthy setup with exit 0, and --strict fails on its warnings', function (): void {
    [$code, $report] = checkReport();

    expect($code)->toBe(0)
        ->and($report['errors'])->toBe(0)
        ->and(checkProblems($report))->toBe([])
        ->and($report['warnings'])->toBeGreaterThan(0)          // Horizon is not running, no read_timeout
        ->and($report['status'])->toBe('warning')
        ->and(checkItem($report, 'Access', 'Mode')['status'])->toBe('ok')
        ->and(checkItem($report, 'Storage', 'Connection')['status'])->toBe('ok')
        ->and(checkItem($report, 'Routes', 'Precedence')['status'])->toBe('ok')
        ->and(checkItem($report, 'Collectors', 'horizon')['status'])->toBe('ok')
        ->and(checkItem($report, 'Collectors', 'queues')['status'])->toBe('ok')
        ->and(checkItem($report, 'Collectors', 'attempts')['status'])->toBe('ok')
        ->and(checkItem($report, 'Collectors', 'job_classes')['status'])->toBe('ok');

    [$strict] = checkReport(['--strict' => true]);

    expect($strict)->toBe(1);
});

it('gives every warning and error exactly one fix line', function (): void {
    config()->set('horizon-observability.token');
    config()->set('horizon-observability.namespace', '');
    config()->set('horizon-observability.live.stream.grafana_url', 'http://grafana:3000');
    config()->set('database.redis.options.persistent', true);

    [, $report] = checkReport();
    $problems = [...checkProblems($report, 'warning'), ...checkProblems($report, 'error')];

    expect(count($problems))->toBeGreaterThan(4);

    foreach ($problems as $problem) {
        expect($problem['fix'])->toBeString()->not->toBe('')
            ->and(substr_count((string) $problem['fix'], "\n"))->toBe(0);
    }
});

it('reports a locked endpoint outside local as an error naming the token', function (): void {
    config()->set('horizon-observability.token');

    [$code, $report] = checkReport();
    $access = checkItem($report, 'Access', 'Mode');

    expect($code)->toBe(1)
        ->and($report['status'])->toBe('error')
        ->and($access['status'])->toBe('error')
        ->and($access['message'])->toStartWith('locked')
        ->and($access['fix'])->toContain('HORIZON_OBSERVABILITY_TOKEN');
});

it('warns about an endpoint open only because of the local environment, and about IP-only access', function (): void {
    config()->set('horizon-observability.token');
    $this->app['env'] = 'local';

    [$code, $report] = checkReport();

    expect($code)->toBe(0)
        ->and(checkItem($report, 'Access', 'Mode')['status'])->toBe('warning')
        ->and(checkItem($report, 'Access', 'Mode')['message'])->toContain('open (local, no token)');

    config()->set('horizon-observability.allowed_ips', '10.0.0.0/8');
    [, $report] = checkReport();

    expect(checkItem($report, 'Access', 'Mode')['status'])->toBe('warning')
        ->and(checkItem($report, 'Access', 'Mode')['fix'])->toContain('TrustProxies');
});

it('reports a token setting without a valid token as an error', function (): void {
    config()->set('horizon-observability.token', 'short');

    [$code, $report] = checkReport();

    expect($code)->toBe(1)
        ->and(checkItem($report, 'Access', 'Mode')['message'])->toContain('none valid')
        ->and(checkItem($report, 'Configuration', 'token')['status'])->toBe('error');
});

it('reports a config problem as an error with the env var to set', function (): void {
    config()->set('horizon-observability.namespace', '1bad');

    [$code, $report] = checkReport();
    $problem = checkItem($report, 'Configuration', 'namespace');

    expect($code)->toBe(1)
        ->and($problem['status'])->toBe('error')
        ->and($problem['message'])->toContain('namespace must match')
        ->and($problem['fix'])->toBe('Set a valid HORIZON_OBSERVABILITY_NAMESPACE in .env (or fix namespace in config/horizon-observability.php).');
});

it('names the config file for a problem without an env var', function (): void {
    config()->set('horizon-observability.max_job_classes', 0);

    [, $report] = checkReport();

    expect(checkItem($report, 'Configuration', 'max_job_classes')['fix'])->toBe('Fix max_job_classes in config/horizon-observability.php.');
});

it('reports an endpoint answered by another route', function (): void {
    Route::get('horizon/observability/metrics', static fn (): string => 'app')->name('app.metrics');

    [$code, $report] = checkReport();
    $route = checkItem($report, 'Routes', 'GET /horizon/observability/metrics');

    expect($code)->toBe(1)
        ->and($route['status'])->toBe('error')
        ->and($route['message'])->toBe('answered by app.metrics (horizon/observability/metrics) instead of horizon-observability.metrics')
        ->and($route['fix'])->toContain('HORIZON_OBSERVABILITY_PATH');
});

it("reports Horizon's catch-all shadowing a late registration", function (): void {
    $results = Apps::inspect(
        [HorizonServiceProvider::class, RegistersInBootProvider::class],
        ['horizon-observability.token' => CHECK_TOKEN],
        static fn (Application $app): array => array_map(
            static fn (CheckResult $result): array => $result->toArray(),
            array_values(array_filter($app->make(Diagnostics::class)->check(), static fn (CheckResult $result): bool => $result->section === 'Routes')),
        ),
    );

    expect($results[0])->toMatchArray([
        'name' => 'GET /horizon/observability/metrics',
        'status' => 'error',
        'message' => "shadowed by Horizon's catch-all route (horizon.index)",
    ])->and($results[0]['fix'])->toContain('package discovery');
});

it('reports missing routes as an error', function (): void {
    config()->set('horizon-observability.enabled', true);
    $this->app['router']->setRoutes(new RouteCollection);

    [$code, $report] = checkReport();

    expect($code)->toBe(1)
        ->and(checkItem($report, 'Routes', 'GET /horizon/observability/metrics')['message'])->toBe('no route answers it');
});

it('reports a missing batch table when batches are required, and only a tip in auto mode', function (): void {
    config()->set('horizon-observability.collectors.batches', true);

    [$code, $report] = checkReport();
    $batching = checkItem($report, 'Batches', 'Batching');

    expect($code)->toBe(1)
        ->and($batching['status'])->toBe('error')
        ->and($batching['message'])->toBe('the table job_batches does not exist on connection testing')
        ->and($batching['fix'])->toContain('make:queue-batches-table')
        ->and(checkItem($report, 'Collectors', 'batches')['status'])->toBe('error');

    config()->set('horizon-observability.collectors.batches');
    [$auto, $report] = checkReport();

    expect($auto)->toBe(0)
        ->and(checkItem($report, 'Batches', 'Batching')['status'])->toBe('info')
        ->and(checkItem($report, 'Collectors', 'batches')['message'])->toBe('off (auto: no database batching table)');
});

it('warns about a horizon connection, a cache store and a batching database without timeouts', function (): void {
    config()->set('database.redis.client', 'phpredis');
    config()->set('database.redis.horizon.timeout');
    config()->set('database.redis.horizon.read_timeout');
    config()->set('cache.default', 'redis');
    config()->set('cache.stores.redis', ['driver' => 'redis', 'connection' => 'cache']);
    config()->set('database.redis.cache', ['host' => '127.0.0.1', 'port' => 6379, 'database' => 1]);
    config()->set('database.connections.batches', ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1, 'database' => 'app']);
    config()->set('queue.batching.database', 'batches');

    $results = array_values(array_filter(app(Diagnostics::class)->check(), static fn (CheckResult $result): bool => $result->section === 'Timeouts'));
    $byName = array_column(array_map(static fn (CheckResult $result): array => $result->toArray(), $results), null, 'name');

    expect($byName['Horizon connection']['status'])->toBe('warning')
        ->and($byName['Horizon connection']['message'])->toContain('no timeout or read_timeout')
        ->and($byName['Horizon connection']['fix'])->toBe("Add 'timeout' => 2.0 and 'read_timeout' => 2.0 to database.redis.default in config/database.php; Horizon copies it into its horizon connection.")
        ->and($byName['Default cache store']['status'])->toBe('warning')
        ->and($byName['Default cache store']['fix'])->toBe("Add 'timeout' => 2.0 and 'read_timeout' => 2.0 to database.redis.cache in config/database.php.")
        ->and($byName['Batching database']['status'])->toBe('warning')
        ->and($byName['Batching database']['fix'])->toBe('Add PDO::ATTR_TIMEOUT => 2 to the options of database.connections.batches in config/database.php.');

    config()->set('database.redis.horizon.timeout', 1.5);
    config()->set('database.redis.horizon.read_timeout', 2);
    config()->set('database.redis.cache.timeout', 1);
    config()->set('database.redis.cache.read_timeout', 1);
    config()->set('database.connections.batches.options', [PDO::ATTR_TIMEOUT => 2]);

    $results = array_values(array_filter(app(Diagnostics::class)->check(), static fn (CheckResult $result): bool => $result->section === 'Timeouts'));

    expect(array_map(static fn (CheckResult $result): string => $result->status->value, $results))->toBe(['ok', 'ok', 'ok'])
        ->and($results[0]->message)->toBe('horizon has timeout 1.5 s, read_timeout 2 s');
});

it('reads the predis timeout keys', function (): void {
    config()->set('database.redis.client', 'predis');
    config()->set('database.redis.horizon.read_write_timeout');

    $timeouts = array_values(array_filter(app(Diagnostics::class)->check(), static fn (CheckResult $result): bool => $result->section === 'Timeouts' && $result->name === 'Horizon connection'));

    expect($timeouts[0]->fix)->toContain("'read_write_timeout' => 2.0");
})->skip(fn (): bool => ! class_exists(Client::class), 'predis is not installed');

it('warns about a persistent client, which --strict turns into a failure', function (): void {
    // Laravel merges the global options over the connection, so REDIS_PERSISTENT=true makes every connection persistent.
    config()->set('database.redis.options.persistent', true);

    [$code, $report] = checkReport();
    $persistent = checkItem($report, 'Storage', 'Persistent');

    expect($code)->toBe(0)
        ->and($persistent['status'])->toBe('warning')
        ->and($persistent['fix'])->toBe("Set 'persistent' => false in database.redis.options (REDIS_PERSISTENT=false) and in the options of database.redis.horizon.")
        ->and(checkItem($report, 'Queues', 'Persistent')['message'])->toStartWith('database.redis.default is persistent');

    [$strict] = checkReport(['--strict' => true]);

    expect($strict)->toBe(1);
});

it('reports an unreachable storage Redis without hanging', function (): void {
    config()->set('database.redis.horizon', ['host' => '127.0.0.1', 'port' => 1, 'database' => 0, 'options' => ['prefix' => TestCase::redisPrefix().'horizon:']]);

    $started = microtime(true);
    [$code, $report] = checkReport();

    expect($code)->toBe(1)
        ->and(checkItem($report, 'Storage', 'Connection')['status'])->toBe('error')
        ->and(checkItem($report, 'Storage', 'Connection')['fix'])->toContain('database.redis.horizon')
        ->and(microtime(true) - $started)->toBeLessThan(30.0);
});

it('reports a queue in an unsupported cluster topology with the hash tag fix', function (): void {
    $this->app->instance(QueueCollector::class, new class implements Collector
    {
        public function name(): string
        {
            return 'queues';
        }

        public function collect(ScrapeContext $context): iterable
        {
            $context->reportFailure('redis:default', UnsupportedTopology::clusterQueue('redis', 'default', 'queues'));

            return [];
        }
    });

    [$code, $report] = checkReport();
    $queues = checkItem($report, 'Collectors', 'queues');

    expect($code)->toBe(1)
        ->and($queues['message'])->toStartWith('failed (redis:default): UnsupportedTopology: unsupported topology')
        ->and($queues['fix'])->toContain('hash tag');
});

it('shows Horizon running with the number of its supervisors', function (): void {
    HorizonState::using($this->app)
        ->master('host-a', 'running', ['host-a:supervisor-1'])
        ->supervisor('host-a:supervisor-1', HorizonState::options('host-a:supervisor-1'), ['redis:default' => 2]);

    [, $report] = checkReport();

    expect(checkItem($report, 'Horizon', 'Status'))->toMatchArray(['status' => 'ok', 'message' => 'running (1 supervisor)']);

    HorizonState::using($this->app)
        ->master('host-a', 'running', ['host-a:supervisor-1', 'host-a:supervisor-2'])
        ->supervisor('host-a:supervisor-2', HorizonState::options('host-a:supervisor-2', queue: 'emails'), ['redis:emails' => 1]);

    [, $report] = checkReport();

    expect(checkItem($report, 'Horizon', 'Status'))->toMatchArray(['status' => 'ok', 'message' => 'running (2 supervisors)']);
});

it('reports the Grafana link as off, invalid or not on the route, each with its fix', function (): void {
    [, $report] = checkReport();

    expect(checkItem($report, 'Horizon', 'Grafana link'))->toMatchArray([
        'status' => 'info',
        'message' => 'off',
        'fix' => "Set HORIZON_OBSERVABILITY_GRAFANA_URL to the dashboard URL to add a Grafana link to Horizon's sidebar.",
    ]);

    config()->set('horizon-observability.grafana.url', '/d/horizon-observability');
    [, $report] = checkReport();

    expect(checkItem($report, 'Horizon', 'Grafana link'))->toMatchArray([
        'status' => 'warning',
        'message' => 'off: grafana.url is not a valid URL',
        'fix' => 'Set HORIZON_OBSERVABILITY_GRAFANA_URL to the dashboard URL, e.g. http://localhost:3000/d/horizon-observability.',
    ]);

    // Set after Horizon registered its routes (a cached config or route list from before the URL).
    config()->set('horizon-observability.grafana.url', 'http://localhost:3000/d/horizon-observability');
    [, $report] = checkReport();

    expect(checkItem($report, 'Horizon', 'Grafana link'))->toMatchArray([
        'status' => 'warning',
        'message' => "grafana.url is set but Horizon's route does not run the link middleware",
        'fix' => 'Run php artisan config:clear and php artisan route:clear (or cache both again): the cached Horizon routes predate the URL.',
    ]);

    config()->set('horizon-observability.enabled', false);
    [, $report] = checkReport();

    expect(checkItem($report, 'Horizon', 'Grafana link'))->toMatchArray([
        'status' => 'warning',
        'fix' => 'Set HORIZON_OBSERVABILITY_ENABLED=true: a disabled package adds no link.',
    ]);

    config()->set('horizon-observability.enabled', true);
    $this->app['router']->setRoutes(new RouteCollection);
    [, $report] = checkReport();

    expect(checkItem($report, 'Horizon', 'Grafana link'))->toMatchArray([
        'status' => 'warning',
        'message' => "Horizon's horizon.index route is not registered",
    ]);
});

it('reports the Grafana link as active when Horizon runs the link middleware', function (array $providers): void {
    $url = 'http://localhost:3000/d/horizon-observability?orgId=1';
    $link = Apps::inspect(
        $providers,
        ['horizon-observability.token' => CHECK_TOKEN, 'horizon-observability.grafana.url' => $url],
        static fn (Application $app): array => array_values(array_filter(
            array_map(static fn (CheckResult $result): array => $result->toArray(), $app->make(Diagnostics::class)->check()),
            static fn (array $result): bool => $result['section'] === 'Horizon' && $result['name'] === 'Grafana link',
        ))[0],
    );

    expect($link)->toMatchArray(['status' => 'ok', 'message' => 'active: '.$url, 'fix' => null]);
})->with([
    'Horizon first' => [[HorizonServiceProvider::class, HorizonObservabilityServiceProvider::class]],
    'package first' => [[HorizonObservabilityServiceProvider::class, HorizonServiceProvider::class]],
]);

it('reports the capabilities of the installed Laravel and Horizon', function (string $laravel, ?string $horizon, string $delay): void {
    $this->app->instance(Versions::class, new Versions(
        $this->app['config'],
        $laravel,
        static fn (string $package): ?string => $package === 'laravel/horizon' ? $horizon : null,
    ));

    [, $report] = checkReport();
    $timedOut = property_exists(JobTimedOut::class, 'timeout');
    $optimize = Versions::hasOptimize(ServiceProvider::class);

    expect(checkItem($report, 'Capabilities', 'Payload delay'))->toMatchArray($delay === 'native'
        ? ['status' => 'ok', 'message' => 'native: payloads carry createdAt and delay', 'fix' => null]
        : ['status' => 'info', 'message' => 'legacy: workers keep a ready_at key per delayed job (Laravel < 12.11 or Horizon < 5.49)', 'fix' => null])
        ->and(checkItem($report, 'Capabilities', 'JobTimedOut::$timeout'))->toMatchArray($timedOut
            ? ['status' => 'ok', 'message' => 'yes: timed-out attempts record the job timeout as their duration']
            : ['status' => 'info', 'message' => 'no (Laravel < 13.25): timed-out attempts record the measured duration'])
        ->and(checkItem($report, 'Capabilities', 'optimize'))->toMatchArray($optimize
            ? ['status' => 'ok', 'message' => 'php artisan optimize caches the job classes (horizon-observability:cache)', 'fix' => null]
            : ['status' => 'info', 'fix' => 'Add php artisan horizon-observability:cache to your deploy.'])
        ->and(Diagnostics::worst(array_map(
            static fn (array $check): CheckResult => new CheckResult($check['section'], $check['name'], CheckStatus::from($check['status']), $check['message'], $check['fix']),
            array_values(array_filter($report['checks'], static fn (array $check): bool => $check['section'] === 'Capabilities')),
        )))->toBe(CheckStatus::Ok);
})->with([
    'Laravel 12.11 and Horizon 5.49' => ['12.11.0', '5.49.0.0', 'native'],
    'Laravel 12.10' => ['12.10.2', '5.49.0.0', 'legacy'],
    'Horizon 5.48' => ['13.0.0', '5.48.1.0', 'legacy'],
    'Horizon unknown' => ['13.0.0', null, 'legacy'],
]);

it('reports the job-class cache file with the time it was written', function (): void {
    $bootstrap = sys_get_temp_dir().'/ho-check-cache-'.getmypid().'-'.bin2hex(random_bytes(3));
    mkdir($bootstrap.'/cache', 0755, true);
    $this->app->useBootstrapPath($bootstrap);
    $this->app->forgetInstance(JobClassCache::class);

    try {
        [, $report] = checkReport();

        expect(checkItem($report, 'Job classes', 'Cache file'))->toMatchArray([
            'status' => 'info',
            'message' => 'none: discovered at runtime and cached for 1 h in the default cache store',
            'fix' => 'Run php artisan horizon-observability:cache on deploy (php artisan optimize runs it on Laravel >= 11.27.1).',
        ]);

        $this->travelTo(CarbonImmutable::parse('2026-10-18 01:02:03', 'UTC'));
        expect(Artisan::call('horizon-observability:cache'))->toBe(0);
        $this->travelBack();
        $this->app->forgetInstance(JobClassCache::class);

        [, $report] = checkReport();

        expect(checkItem($report, 'Job classes', 'Cache file'))->toMatchArray([
            'status' => 'ok',
            'message' => 'written 2026-10-18 01:02:03 UTC ('.$bootstrap.'/cache/horizon-observability.php)',
            'fix' => null,
        ]);
    } finally {
        (new Filesystem)->deleteDirectory($bootstrap);
    }
});

it('warns about the attempt-class cap from 90 % and says when classes become (other)', function (int $used, string $status, string $message): void {
    config()->set('horizon-observability.max_job_classes', 10);
    $index = TestCase::redisPrefix().'horizon:'.(new Keys)->jobClasses();
    $raw = new Client(['host' => '127.0.0.1', 'port' => 6379]);

    for ($i = 1; $i <= $used; $i++) {
        $raw->zadd($index, [sprintf('App\\Jobs\\Job%02d', $i) => 1_760_000_000 + $i]);
    }

    [, $report] = checkReport();
    $cap = checkItem($report, 'Job classes', 'Cap');

    expect($cap['status'])->toBe($status)
        ->and($cap['message'])->toBe($message)
        ->and($cap['fix'])->toBe($status === 'ok' ? null : 'Raise max_job_classes in config/horizon-observability.php (at most 10000); classes idle for 30 days are pruned.');
})->with([
    'none' => [0, 'ok', '0 of 10 attempt classes in use (max_job_classes)'],
    '80 %' => [8, 'ok', '8 of 10 attempt classes in use (max_job_classes)'],
    '90 %' => [9, 'warning', '9 of 10 attempt classes in use (max_job_classes): close to the cap'],
    '100 %' => [10, 'warning', '10 of 10 attempt classes in use (max_job_classes): further classes are counted as (other)'],
]);

it('names the package domain in the endpoints of check and about', function (): void {
    config()->set('horizon-observability.domain', 'metrics.shop.test');
    config()->set('app.url', 'https://shop.test:8443');

    [, $report] = checkReport();

    expect(checkItem($report, 'Routes', 'Endpoints')['message'])->toBe('https://metrics.shop.test:8443/horizon/observability/metrics, https://metrics.shop.test:8443/horizon/observability/jobs/{list}, https://metrics.shop.test:8443/horizon/observability/live')
        ->and(checkItem($report, 'Live', 'Enabled')['message'])->toStartWith('poll endpoint https://metrics.shop.test:8443/horizon/observability/live ')
        ->and(app(Diagnostics::class)->about()['Endpoints'])->toBe('https://metrics.shop.test:8443/horizon/observability');

    config()->set('horizon-observability.live.enabled', false);
    [, $report] = checkReport();

    expect(checkItem($report, 'Routes', 'Endpoints')['message'])->not->toContain('/live');
});

it('reports Horizon paused with the command that continues it', function (): void {
    HorizonState::using($this->app)->master('host-a', 'paused');

    [, $report] = checkReport();

    expect(checkItem($report, 'Horizon', 'Status'))->toMatchArray(['status' => 'warning', 'fix' => 'Run php artisan horizon:continue.']);
});

it('reports a stream URL without a token, naming the env var', function (): void {
    config()->set('horizon-observability.live.stream.grafana_url', 'http://grafana:3000');

    [$code, $report] = checkReport();
    $token = checkItem($report, 'Live', 'Stream token');

    expect($code)->toBe(1)
        ->and($token)->toMatchArray(['status' => 'error', 'message' => 'missing'])
        ->and($token['fix'])->toContain('HORIZON_OBSERVABILITY_LIVE_GRAFANA_TOKEN')
        ->and(checkItem($report, 'Live', 'Stream URL')['message'])->toBe('http://grafana:3000');
});

it('reports a token without a stream URL', function (): void {
    config()->set('horizon-observability.live.stream.token', CHECK_LIVE_TOKEN);

    [$code, $report] = checkReport();

    expect($code)->toBe(1)
        ->and(checkItem($report, 'Live', 'Stream URL')['fix'])->toContain('HORIZON_OBSERVABILITY_LIVE_GRAFANA_URL')
        ->and(Artisan::output())->not->toContain(CHECK_LIVE_TOKEN);
});

it('reports an invalid stream id in the config block and the live block', function (): void {
    config()->set('horizon-observability.live.stream.id', 'bad id!');

    [$code, $report] = checkReport();

    expect($code)->toBe(1)
        ->and(checkItem($report, 'Configuration', 'live.stream.id')['status'])->toBe('error')
        ->and(checkItem($report, 'Live', 'Stream id'))->toMatchArray(['status' => 'error', 'message' => 'invalid: using "horizon_observability"'])
        ->and(checkItem($report, 'Live', 'Stream id')['fix'])->toContain('--live-stream-id');
});

it('says when stream mode is not configured, and that the push test needs it', function (): void {
    [, $report] = checkReport();

    expect(checkItem($report, 'Live', 'Stream')['status'])->toBe('info');

    Http::fake();
    [$code, $report] = checkReport(['--live-push-test' => true]);

    expect($code)->toBe(1)
        ->and(checkItem($report, 'Live', 'Push test')['status'])->toBe('error');
    Http::assertNothingSent();
});

it('pushes one tiles frame to the <id>_check stream with --live-push-test', function (): void {
    config()->set('horizon-observability.live.stream.grafana_url', 'http://grafana-a:3000,http://grafana-b:3000');
    config()->set('horizon-observability.live.stream.token', CHECK_LIVE_TOKEN);
    Http::fake(['*' => Http::response('', 200)]);

    [, $report] = checkReport(['--live-push-test' => true]);

    expect(checkItem($report, 'Live', 'Push test http://grafana-a:3000')['status'])->toBe('ok')
        ->and(checkItem($report, 'Live', 'Push test http://grafana-b:3000')['message'])->toStartWith('HTTP 200: pushed one tiles frame to stream horizon_observability_check');

    Http::assertSentCount(2);
    Http::assertSent(static function (Request $request): bool {
        $lines = array_values(array_filter(explode("\n", $request->body())));

        return str_ends_with($request->url(), '/api/live/push/horizon_observability_check')
            && $request->hasHeader('Authorization', 'Bearer '.CHECK_LIVE_TOKEN)
            && $lines !== []
            && array_filter($lines, static fn (string $line): bool => ! str_starts_with($line, 'tiles ')) === [];
    });
});

it('reports 401 and 403 from the push test with their hints', function (int $status, string $hint): void {
    config()->set('horizon-observability.live.stream.grafana_url', 'http://grafana:3000');
    config()->set('horizon-observability.live.stream.token', CHECK_LIVE_TOKEN);
    Http::fake(['*' => Http::response('{"message":"denied"}', $status)]);

    [$code, $report] = checkReport(['--live-push-test' => true]);
    $push = checkItem($report, 'Live', 'Push test http://grafana:3000');

    expect($code)->toBe(1)
        ->and($push['status'])->toBe('error')
        ->and($push['message'])->toStartWith('HTTP '.$status)
        ->and($push['fix'])->toContain($hint);

    Artisan::call('horizon-observability:check', ['--live-push-test' => true]);
    $output = Artisan::output();

    expect($output)->toContain($hint)
        ->and($output)->not->toContain(CHECK_LIVE_TOKEN)
        ->and($output)->not->toContain(CHECK_TOKEN);
})->with([
    '401' => [401, 'Service account token invalid or missing'],
    '403' => [403, 'role Editor needed on Grafana 13'],
]);

it('reports an unreachable Grafana in the push test', function (): void {
    config()->set('horizon-observability.live.stream.grafana_url', 'http://grafana:3000');
    config()->set('horizon-observability.live.stream.token', CHECK_LIVE_TOKEN);
    Http::fake(static fn () => throw new ConnectionException('Connection refused'));

    [, $report] = checkReport(['--live-push-test' => true]);

    expect(checkItem($report, 'Live', 'Push test http://grafana:3000')['fix'])->toContain('Grafana is unreachable from the app');
});

it('reports the last push of the stream', function (): void {
    config()->set('horizon-observability.live.stream.grafana_url', 'http://grafana:3000');
    config()->set('horizon-observability.live.stream.token', CHECK_LIVE_TOKEN);
    $meta = TestCase::redisPrefix().'horizon:'.(new Keys)->meta();
    $raw = new Client(['host' => '127.0.0.1', 'port' => 6379]);

    [, $report] = checkReport();

    expect(checkItem($report, 'Live', 'Last push'))->toMatchArray(['status' => 'warning', 'message' => 'never']);

    $raw->hset($meta, 'live_push_at', (string) (microtime(true) - 2), 'live_push_failures', '3');
    [, $report] = checkReport();

    expect(checkItem($report, 'Live', 'Last push')['status'])->toBe('ok')
        ->and(checkItem($report, 'Live', 'Last push')['message'])->toContain('(3 failed pushes so far)');

    $raw->hset($meta, 'live_push_at', (string) (microtime(true) - 120));
    [, $report] = checkReport();

    expect(checkItem($report, 'Live', 'Last push')['status'])->toBe('warning')
        ->and(checkItem($report, 'Live', 'Last push')['message'])->toContain('the live tiles are frozen');
});

it('prints the report with sections, fix lines and a summary', function (): void {
    config()->set('horizon-observability.token');

    $code = Artisan::call('horizon-observability:check');
    $output = Artisan::output();

    expect($code)->toBe(1)
        ->and($output)->toContain('Configuration')
        ->and($output)->toContain('Access')
        ->and($output)->toContain('Collectors')
        ->and($output)->toContain('ERROR')
        ->and($output)->toContain('Fix: Set HORIZON_OBSERVABILITY_TOKEN')
        ->and($output)->toMatch('/\d+ errors?, \d+ warnings?\. Fix the errors above\./');
});

it('keeps the env var map in line with the config file', function (): void {
    $file = (string) file_get_contents(dirname(__DIR__, 3).'/config/horizon-observability.php');
    preg_match_all("/env\\('(HORIZON_OBSERVABILITY_[A-Z_]+)'/", $file, $matches);

    $configured = array_values(array_unique($matches[1]));
    $mapped = array_values(Diagnostics::ENV_VARS);
    sort($configured);
    sort($mapped);

    expect($mapped)->toBe($configured);
});
