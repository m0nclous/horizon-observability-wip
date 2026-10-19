<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Support;

use Closure;
use DF\HorizonObservability\Attempts\AttemptStore;
use DF\HorizonObservability\Batches\BatchReader;
use DF\HorizonObservability\Collectors\Collector;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Discovery\JobClassCache;
use DF\HorizonObservability\Horizon\Supervisors;
use DF\HorizonObservability\Horizon\Topology;
use DF\HorizonObservability\Http\AccessPolicy;
use DF\HorizonObservability\Http\JobList;
use DF\HorizonObservability\Http\Middleware\InjectGrafanaLink;
use DF\HorizonObservability\Http\RoutePath;
use DF\HorizonObservability\Live\LiveScope;
use DF\HorizonObservability\Live\LiveSnapshot;
use DF\HorizonObservability\Live\Stream\GrafanaLivePusher;
use DF\HorizonObservability\Live\Stream\LineProtocol;
use DF\HorizonObservability\Metrics\Exporter;
use DF\HorizonObservability\Metrics\InvalidMetric;
use DF\HorizonObservability\Metrics\MetricFamily;
use DF\HorizonObservability\Metrics\ScrapeContext;
use DF\HorizonObservability\Metrics\TextFormat;
use DF\HorizonObservability\Queues\UnsupportedTopology;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Routing\Router;
use LogicException;
use PDO;
use PDOException;
use Throwable;

/**
 * Data of the `about` section and of `horizon-observability:check` (Arch Q7, Addendum Q9' WP10, owner decision 12).
 *
 * {@see self::about()} reads the configuration only: no Redis, no database, never throws. {@see self::check()} runs
 * every check of `horizon-observability:check` and returns one {@see CheckResult} per item; every warning and error
 * carries exactly one actionable fix line. Secrets are never part of a result: tokens are reported as set or missing.
 *
 * The checks: configuration problems; access mode (locked outside `local` is an error, open in `local` and IP-only
 * access are warnings); route precedence against the live router, on the host of the package domain as Laravel binds
 * it; the storage and reader Redis connections (reachable, latency, client, cluster, persistent flag, braces in the
 * prefix); the timeouts of Horizon's `horizon` connection, of the default cache store (a `failover` store by the stores
 * it tries, a `storage` store by its disk) and of the batching database connection (a store without a timeout can cost
 * a scrape its budget). A Redis connection's timeouts, persistent flag and prefix are those the client Laravel builds
 * for it reads ({@see RedisClientSettings}: phpredis and predis, one server or a cluster), and the fix lines name a
 * place that client reads them from; Horizon (version, status, topology, the Grafana link in the `horizon` group); capabilities;
 * batches; the job-class cache and cap; a dry run of every collector; the live block (enabled, stream URL, token,
 * stream id, the last push, and with `$livePushTest` one `tiles` frame pushed to the stream `<id>_check`).
 *
 * Database timeouts are connect timeouts (`PDO::ATTR_TIMEOUT`, `login_timeout` on SQL Server): PDO has no portable
 * read timeout.
 *
 * @internal
 */
final readonly class Diagnostics
{
    /**
     * Env var of each config path that has one (config/horizon-observability.php), for the fix lines.
     *
     * @var array<string, string>
     */
    public const ENV_VARS = [
        'enabled' => 'HORIZON_OBSERVABILITY_ENABLED',
        'namespace' => 'HORIZON_OBSERVABILITY_NAMESPACE',
        'path' => 'HORIZON_OBSERVABILITY_PATH',
        'domain' => 'HORIZON_OBSERVABILITY_DOMAIN',
        'token' => 'HORIZON_OBSERVABILITY_TOKEN',
        'api_token' => 'HORIZON_OBSERVABILITY_API_TOKEN',
        'allowed_ips' => 'HORIZON_OBSERVABILITY_ALLOWED_IPS',
        'exceptions' => 'HORIZON_OBSERVABILITY_EXCEPTIONS',
        'redis.connection' => 'HORIZON_OBSERVABILITY_REDIS_CONNECTION',
        'queue_scan_limit' => 'HORIZON_OBSERVABILITY_QUEUE_SCAN_LIMIT',
        'horizon_url' => 'HORIZON_OBSERVABILITY_HORIZON_URL',
        'grafana.url' => 'HORIZON_OBSERVABILITY_GRAFANA_URL',
        'grafana.tts_url' => 'HORIZON_OBSERVABILITY_TTS_URL',
        'live.enabled' => 'HORIZON_OBSERVABILITY_LIVE_ENABLED',
        'live.cache_ttl' => 'HORIZON_OBSERVABILITY_LIVE_CACHE_TTL',
        'live.stream.grafana_url' => 'HORIZON_OBSERVABILITY_LIVE_GRAFANA_URL',
        'live.stream.token' => 'HORIZON_OBSERVABILITY_LIVE_GRAFANA_TOKEN',
        'live.stream.id' => 'HORIZON_OBSERVABILITY_LIVE_STREAM_ID',
        'live.stream.interval' => 'HORIZON_OBSERVABILITY_LIVE_STREAM_INTERVAL',
        'log_channel' => 'HORIZON_OBSERVABILITY_LOG_CHANNEL',
    ];

    /**
     * Suffix of the stream id `--live-push-test` pushes to, so the test frame never reaches the dashboard's tiles.
     */
    public const PUSH_TEST_SUFFIX = '_check';

    /**
     * A stream push older than this (seconds) is reported: the `<uid>-live` alert rule fires at the same age.
     */
    public const STALE_PUSH_SECONDS = 30;

    /**
     * Share of `max_job_classes` from which the cap usage is a warning.
     */
    public const CLASS_CAP_WARNING = 0.9;

    /**
     * A dry run slower than this (seconds) is a warning: the scrape budget at the smallest supported scrape interval
     * (5 s scrape timeout minus the 0.5 s margin, Addendum R3.2).
     */
    public const SLOW_SCRAPE_SECONDS = 4.5;

    /**
     * A domain (after Laravel drops `http://` or `https://`) that can equal a request host: host characters and route
     * parameters such as `{tenant}` or `{tenant?}`, or a bracketed IPv6 address. A port, a path or anything else never
     * matches, as Laravel compares the domain with the request host alone.
     */
    private const DOMAIN_PATTERN = '/^(?:\[[0-9A-Fa-f:.]+\]|(?:[A-Za-z0-9_-]|\{\w+\??\}|\.)+)$/';

    /**
     * Values tried for the parameters of a domain such as `{tenant}.example.com`, in this order: a word, a number, a
     * UUID.
     *
     * @var list<string>
     */
    public const DOMAIN_SAMPLES = ['acme', '1', '00000000-0000-4000-8000-000000000000'];

    /**
     * Cache drivers that keep their data in the process, a local file or the request's session: a call never waits on
     * the network.
     *
     * @var list<string>
     */
    private const LOCAL_CACHE_DRIVERS = ['array', 'file', 'null', 'apc', 'octane', 'session'];

    /**
     * @var Closure(): float
     */
    private Closure $clock;

    /**
     * @param  (Closure(): float)|null  $clock  monotonic seconds for the dry-run durations, for tests; default `hrtime()`
     */
    public function __construct(private Container $container, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    /**
     * The `about` section. Reads the configuration only and never throws: a value that cannot be computed reads
     * `unavailable`.
     *
     * @return array<string, string>
     */
    public function about(): array
    {
        return [
            'Version' => $this->safely(fn (): string => $this->container->make(Versions::class)->package() ?? 'unknown'),
            'Enabled' => $this->safely(fn (): string => $this->config()->enabled ? 'yes' : 'no'),
            'Endpoints' => $this->safely(fn (): string => EndpointUrl::to($this->repository())),
            'Access' => $this->safely(fn (): string => $this->container->make(AccessPolicy::class)->mode()),
            'Namespace' => $this->safely(fn (): string => $this->config()->namespace === '' ? '(none)' : $this->config()->namespace),
            'Storage' => $this->safely(fn (): string => sprintf(
                '%s (%s)%s',
                $this->config()->redisConnection,
                $this->container->make(Versions::class)->redisClient(),
                $this->container->make(RedisClients::class)->isCluster($this->config()->redisConnection) ? ', cluster' : '',
            )),
            'Collectors' => $this->safely(fn (): string => implode(', ', array_map(
                static fn (string $id, ?bool $enabled): string => $id.' '.($enabled === null ? 'auto' : ($enabled ? 'on' : 'off')),
                array_keys($this->config()->collectors),
                $this->config()->collectors,
            ))),
            'Payload delay' => $this->safely(fn (): string => $this->container->make(Versions::class)->payloadCarriesDelay() ? 'native' : 'legacy (ready_at keys)'),
            'Batching' => $this->safely(fn (): string => $this->batchingSummary()),
            'Job class cache' => $this->safely(fn (): string => $this->jobClassCacheSummary()),
            'Live' => $this->safely(fn (): string => $this->config()->liveEnabled ? 'enabled' : 'disabled'),
            'Config problems' => $this->safely(fn (): string => (string) count($this->config()->problems)),
        ];
    }

    /**
     * Every check of `horizon-observability:check`, in report order. Never throws: a check that fails to run is an
     * error result.
     *
     * @return list<CheckResult>
     */
    public function check(bool $livePushTest = false): array
    {
        $config = $this->config();
        $results = [];

        $sections = [
            fn (): array => $this->configuration($config),
            $this->access(...),
            fn (): array => $this->routes($config),
            fn (): array => $this->storage($config),
            $this->queues(...),
            fn (): array => $this->timeouts($config),
            fn (): array => $this->horizon($config),
            $this->capabilities(...),
            fn (): array => $this->batches($config),
            fn (): array => $this->jobClasses($config),
            fn (): array => $this->collectors($config),
            fn (): array => $this->live($config, $livePushTest),
            fn (): array => $this->prometheus($config),
        ];

        foreach ($sections as $section) {
            try {
                array_push($results, ...$section());
            } catch (Throwable $e) {
                $results[] = CheckResult::error('Check', 'internal', 'a check failed to run: '.FailureReporter::describe($e), 'Report this at https://github.com/sushi-market/horizon-observability/issues with the output of php artisan about.');
            }
        }

        return $results;
    }

    /**
     * The worst status of the results: error, warning, or ok.
     *
     * @param  list<CheckResult>  $results
     */
    public static function worst(array $results): CheckStatus
    {
        $worst = CheckStatus::Ok;

        foreach ($results as $result) {
            if ($result->status->severity() > $worst->severity()) {
                $worst = $result->status;
            }
        }

        return $worst;
    }

    /**
     * @return list<CheckResult>
     */
    private function configuration(Config $config): array
    {
        $section = 'Configuration';
        $results = [];

        $results[] = is_file($this->path('path.config').'/horizon-observability.php')
            ? CheckResult::ok($section, 'Config file', 'config/horizon-observability.php')
            : CheckResult::info($section, 'Config file', 'not published: package defaults and HORIZON_OBSERVABILITY_* env vars apply', 'Run php artisan horizon-observability:install to publish it.');

        if ($config->problems === []) {
            $results[] = CheckResult::ok($section, 'Problems', 'none');
        }

        foreach ($config->problems as $problem) {
            $results[] = CheckResult::error($section, $problem->path === '' ? 'horizon-observability' : $problem->path, $problem->message, $this->configFix($problem->path));
        }

        $results[] = $config->enabled
            ? CheckResult::ok($section, 'Enabled', 'yes')
            : CheckResult::warning($section, 'Enabled', 'no: no endpoints, no attempt recording, no Horizon link', 'Set HORIZON_OBSERVABILITY_ENABLED=true to turn the package on.');

        $results[] = $config->namespace === ''
            ? CheckResult::warning($section, 'Namespace', 'none: metric names such as queue_jobs_total may collide with other exporters', 'Set HORIZON_OBSERVABILITY_NAMESPACE=ho (the default) and render the dashboard again.')
            : CheckResult::ok($section, 'Namespace', $config->prefix().'* metrics');

        return $results;
    }

    /**
     * @return list<CheckResult>
     */
    private function access(): array
    {
        $mode = $this->container->make(AccessPolicy::class)->mode();
        $parts = str_starts_with($mode, 'metrics: ') ? explode('; api: ', substr($mode, 9), 2) : [$mode];
        // The worse of the two endpoints when they differ (a separate api_token).
        $results = array_map(fn (string $part): CheckResult => $this->accessResult($part, $mode), $parts);
        usort($results, static fn (CheckResult $a, CheckResult $b): int => $b->status->severity() <=> $a->status->severity());

        return [$results[0]];
    }

    private function accessResult(string $part, string $mode): CheckResult
    {
        $section = 'Access';

        return match (true) {
            $part === 'callback' => CheckResult::ok($section, 'Mode', 'callback (HorizonObservability::auth())'),
            str_contains($part, 'none valid') => CheckResult::error($section, 'Mode', $mode, 'Use tokens of at least 16 characters (HORIZON_OBSERVABILITY_TOKEN) and valid IPs or CIDRs (HORIZON_OBSERVABILITY_ALLOWED_IPS).'),
            str_starts_with($part, 'locked') => CheckResult::error($section, 'Mode', $mode.': every request gets 403', 'Set HORIZON_OBSERVABILITY_TOKEN (php artisan horizon-observability:install generates one) and give the same token to Prometheus and Grafana.'),
            str_starts_with($part, 'open') => CheckResult::warning($section, 'Mode', $mode.': open only because APP_ENV is local', 'Set HORIZON_OBSERVABILITY_TOKEN before this environment is reachable by others (php artisan horizon-observability:install).'),
            $part === 'IPs' => CheckResult::warning($section, 'Mode', $mode.': the IP allow-list is the only control and depends on TrustProxies', 'Add HORIZON_OBSERVABILITY_TOKEN as well, or make sure TrustProxies only trusts your proxies.'),
            default => CheckResult::ok($section, 'Mode', $mode),
        };
    }

    /**
     * Route precedence (Arch Q2.2): every endpoint must be answered by the package route, matched against the live
     * router (cached or not) on the host the routes are bound to: `app.url`'s host without a domain, else the domain as
     * `Route::getDomain()` returns it (a sample host for a domain with parameters). A domain no request host can equal
     * is one error with the domain fix.
     *
     * @return list<CheckResult>
     */
    private function routes(Config $config): array
    {
        $section = 'Routes';

        if (! $config->enabled) {
            return [CheckResult::info($section, 'Precedence', 'not checked: the package is disabled')];
        }

        $repository = $this->repository();
        $base = RoutePath::base($repository);
        /** @var Router $router */
        $router = $this->container->make('router');
        $routes = $router->getRoutes();
        $endpoints = CheckResult::info($section, 'Endpoints', implode(', ', array_map(
            fn (string $path): string => EndpointUrl::to($repository, $path),
            $config->liveEnabled ? ['/metrics', '/jobs/{list}', '/live'] : ['/metrics', '/jobs/{list}'],
        )));
        // The domain as the routes are bound to it: Route::getDomain() drops a scheme written in it.
        $domain = $config->domain === null ? null : str_replace(['http://', 'https://'], '', $config->domain);

        if ($domain !== null && preg_match(self::DOMAIN_PATTERN, $domain) !== 1) {
            return [CheckResult::error($section, 'Domain', sprintf(
                'the routes are bound to the domain "%s", which no request host equals: Laravel compares it with the host alone (no port, path or upper-case scheme), so every endpoint gets a 404',
                $domain,
            ), 'Set HORIZON_OBSERVABILITY_DOMAIN to the host alone, e.g. metrics.example.com.'), $endpoints];
        }

        $first = '/'.$base.'/metrics';
        [$host, $sampled] = $domain === null ? [$this->appHost(), false] : $this->domainHost($routes, $domain, $first);
        $where = $domain === null ? '' : ' on '.$host.($sampled ? sprintf(' (a sample host of %s)', $domain) : '');

        if ($host === null) {
            return [CheckResult::info($section, 'Precedence', sprintf(
                'not checked: no sample value (%s) for the parameters of the domain %s matches the package route',
                implode(', ', self::DOMAIN_SAMPLES),
                $domain,
            ), sprintf('Run php artisan route:list --path=%s and check that the package routes come first for your hosts.', $base)), $endpoints];
        }

        $expected = ['metrics' => 'horizon-observability.metrics'];

        foreach (JobList::cases() as $list) {
            $expected['jobs/'.$list->value] = 'horizon-observability.jobs';
        }

        if ($config->liveEnabled) {
            $expected['live'] = 'horizon-observability.live';
        }

        $cached = $this->container instanceof CachesRoutes && $this->container->routesAreCached();
        $results = [];

        foreach ($expected as $path => $name) {
            $uri = '/'.$base.'/'.$path;

            try {
                $route = $routes->match(Request::create('http://'.$host.$uri, 'GET'));
            } catch (Throwable) {
                $route = null;
            }

            $actual = $route instanceof Route ? $route->getName() : null;

            if ($actual === $name) {
                continue;
            }

            $results[] = match (true) {
                ! $route instanceof Route => CheckResult::error($section, 'GET '.$uri, 'no route answers it'.$where, $cached
                    ? 'The route cache predates the package or its path and domain settings: run php artisan route:cache again (or route:clear).'
                    : 'Register the package provider (package discovery, or bootstrap/providers.php), not from another provider\'s boot().'),
                $actual === 'horizon.index' => CheckResult::error($section, 'GET '.$uri, "shadowed by Horizon's catch-all route (horizon.index)".$where, 'Let package discovery register the provider (not App::register() at runtime), then run php artisan route:cache again if routes are cached.'),
                default => CheckResult::error($section, 'GET '.$uri, sprintf('answered by %s (%s) instead of %s%s', $actual ?? 'an unnamed route', $route->uri(), $name, $where), 'Set HORIZON_OBSERVABILITY_PATH to a path no other route uses, e.g. internal/horizon-observability.'),
            };
        }

        if ($results === []) {
            $results[] = CheckResult::ok($section, 'Precedence', sprintf('%s/{metrics,jobs/*%s} answer the package routes%s%s', '/'.$base, $config->liveEnabled ? ',live' : '', $where, $cached ? ' (cached)' : ''));
        }

        $results[] = $endpoints;

        return $results;
    }

    /**
     * The host the precedence is checked on for a domain: the domain itself, or for a domain with route parameters
     * (`{tenant}.example.com`) the domain with the first of {@see self::DOMAIN_SAMPLES} the package route accepts
     * (global patterns such as `Route::pattern('tenant', '[0-9]+')` constrain it). [host, whether it is a sample]; the
     * host is null when the package route accepts no sample. Without the package route the first sample is used, and
     * the check reports that no route answers.
     *
     * @return array{0: string|null, 1: bool}
     */
    private function domainHost(RouteCollectionInterface $routes, string $domain, string $uri): array
    {
        if (! str_contains($domain, '{')) {
            return [$domain, false];
        }

        $package = $routes->getByName('horizon-observability.metrics');

        foreach (self::DOMAIN_SAMPLES as $sample) {
            $host = (string) preg_replace('/\{\w+\??\}/', $sample, $domain);

            try {
                if (! $package instanceof Route || $package->matches(Request::create('http://'.$host.$uri, 'GET'))) {
                    return [$host, true];
                }
            } catch (Throwable) {
                // A domain Symfony cannot compile: the next sample fails the same way.
            }
        }

        return [null, true];
    }

    /**
     * @return list<CheckResult>
     */
    private function storage(Config $config): array
    {
        $section = 'Storage';
        $name = $config->redisConnection;
        $settings = $this->redis($name);
        /** @var RedisClients $clients */
        $clients = $this->container->make(RedisClients::class);
        $client = $this->container->make(Versions::class)->redisClient();
        $cluster = $clients->isCluster($name);

        if ($settings === null) {
            return [CheckResult::error($section, 'Connection', sprintf('Redis connection [%s] is not configured', $name), sprintf('Add database.redis.%s to config/database.php, or set HORIZON_OBSERVABILITY_REDIS_CONNECTION to an existing connection.', $name))];
        }

        $results = [];
        $prefix = $settings->prefix();

        try {
            $started = hrtime(true);
            $clients->reader($name)->exists($this->container->make(Keys::class)->meta());
            $results[] = CheckResult::ok($section, 'Connection', sprintf(
                '%s reachable in %s ms (%s%s, prefix "%s")',
                $name,
                $this->milliseconds($started),
                $client,
                $cluster ? ', cluster' : '',
                $prefix,
            ));
        } catch (Throwable $e) {
            $results[] = CheckResult::error($section, 'Connection', sprintf('%s unreachable: %s', $name, FailureReporter::describe($e)), sprintf(
                'Start Redis or fix database.redis.%s (host, port, password) in config/database.php; the package storage is redis.connection (HORIZON_OBSERVABILITY_REDIS_CONNECTION).',
                $name,
            ));
        }

        $results[] = CheckResult::info($section, 'Timeouts', sprintf(
            'private clients, never persistent: worker writes %s s (redis.worker_timeout), scrape reads %s s (redis.scrape_timeout)',
            TextFormat::number($config->workerTimeout),
            TextFormat::number($config->scrapeTimeout),
        ));

        if ($settings->persistentPlaces() !== []) {
            $results[] = $this->persistentWarning($section, $name, $settings);
        }

        $tag = self::hashTag($prefix.Keys::ROOT.'meta');

        if ($tag === '' && str_contains($prefix, '{')) {
            $results[] = new CheckResult(
                $section,
                'Prefix',
                $cluster ? CheckStatus::Error : CheckStatus::Warning,
                sprintf('the prefix "%s" starts an empty hash tag "{}": on a Redis Cluster every package key gets its own slot and the scripts fail with CROSSSLOT', $prefix),
                sprintf('Remove the braces from the prefix of database.redis.%s (or give them content, e.g. {app}).', $name),
            );
        } elseif (str_contains($prefix, '{')) {
            $results[] = CheckResult::info($section, 'Prefix', sprintf('the hash tag {%s} of the prefix puts every package key in one slot', $tag));
        }

        return $results;
    }

    /**
     * The Redis connections of Horizon's queues (the readers of the queue state).
     *
     * @return list<CheckResult>
     */
    private function queues(): array
    {
        $section = 'Queues';
        /** @var Topology $topology */
        $topology = $this->container->make(Topology::class);

        try {
            $byConnection = $topology->queuesByConnection();
        } catch (Throwable $e) {
            return [CheckResult::error($section, 'Topology', 'Horizon\'s configuration cannot be read: '.FailureReporter::describe($e), 'Fix horizon.environments and horizon.defaults in config/horizon.php (php artisan horizon fails the same way).')];
        }

        $environment = $topology->environment();

        if ($byConnection === []) {
            return [CheckResult::warning($section, 'Topology', sprintf('no supervisor runs a queue in the environment "%s": queue metrics stay empty', $environment), sprintf('Add an environments entry matching "%s" to config/horizon.php (or set HORIZON_ENV).', $environment))];
        }

        $results = [CheckResult::ok($section, 'Topology', sprintf('environment "%s": %s', $environment, implode('; ', array_map(
            static fn (string $connection, array $queues): string => $connection.' ('.implode(', ', $queues).')',
            array_keys($byConnection),
            $byConnection,
        ))))];

        /** @var RedisClients $clients */
        $clients = $this->container->make(RedisClients::class);
        $seen = [];

        foreach (array_keys($byConnection) as $connection) {
            $redis = $topology->redisConnection($connection);

            if ($redis === null) {
                $results[] = CheckResult::warning($section, 'Connection '.$connection, 'not a configured redis queue connection: its queues are not inspected', sprintf('Point the Horizon supervisors at a redis queue connection, or add queue.connections.%s with the redis driver.', $connection));

                continue;
            }

            if (isset($seen[$redis])) {
                continue;
            }

            $seen[$redis] = true;
            $settings = $this->redis($redis);

            try {
                $started = hrtime(true);
                $clients->reader($redis)->exists('horizon-observability:check');
                $results[] = CheckResult::ok($section, 'Redis '.$redis, sprintf('reachable in %s ms%s', $this->milliseconds($started), $clients->isCluster($redis) ? ' (cluster)' : ''));
            } catch (Throwable $e) {
                $results[] = CheckResult::error($section, 'Redis '.$redis, 'unreachable: '.FailureReporter::describe($e), sprintf('Start Redis or fix database.redis.%s in config/database.php (the queue connection %s uses it).', $redis, $connection));
            }

            if ($settings !== null && $settings->persistentPlaces() !== []) {
                $results[] = $this->persistentWarning($section, $redis, $settings);
            }
        }

        return $results;
    }

    /**
     * Connections the scrape waits for with the app's own settings: Horizon's `horizon` connection (its repositories),
     * the default cache store (throttled logs, batch detection, job-class cache) and the batching database. A timeout
     * set where the client does not read it (predis: any `options` array; a phpredis cluster: its nodes) is named in
     * the warning.
     *
     * @return list<CheckResult>
     */
    private function timeouts(Config $config): array
    {
        $section = 'Timeouts';
        $results = [];
        $horizon = $this->redis('horizon');

        if ($horizon !== null) {
            $results[] = $this->redisTimeout($section, 'Horizon connection', 'horizon', sprintf('horizon (a copy of %s)', $this->horizonUse()), $horizon, sprintf(
                "a hung Redis holds every scrape until PHP's default_socket_timeout (%s s)",
                $this->scalarText(ini_get('default_socket_timeout')),
            ), '; Horizon copies it into its horizon connection.');
        }

        $results[] = $this->cacheTimeout($section);

        if ($config->collectors['batches'] !== false) {
            /** @var BatchReader $reader */
            $reader = $this->container->make(BatchReader::class);

            if ($reader->driver() === 'database') {
                $results[] = $this->databaseTimeout($section, 'Batching database', $reader->connectionName());
            }
        }

        return $results;
    }

    /**
     * The default cache store: it backs the throttled logs, the batching detection and the job-class runtime cache.
     */
    private function cacheTimeout(string $section): CheckResult
    {
        $store = $this->repository()->get('cache.default');
        $store = is_string($store) && $store !== '' ? $store : 'file';

        return $this->storeTimeout($section, 'Default cache store', $store);
    }

    /**
     * The timeouts of the client Laravel builds for a Redis connection ({@see RedisClientSettings}): ok with the
     * timeouts it has, or a warning naming the place the client reads them from.
     */
    private function redisTimeout(string $section, string $name, string $label, string $missingLabel, RedisClientSettings $settings, string $consequence, string $fixSuffix = '.'): CheckResult
    {
        if (! $settings->known()) {
            return CheckResult::info($section, $name, sprintf('%s uses the Redis client %s, whose timeouts this check does not know', $label, $settings->client), 'Give its client connect and read timeouts of about 2 s: a hung store can cost a scrape its budget.');
        }

        $missing = $settings->missingTimeouts();

        if ($missing === []) {
            return CheckResult::ok($section, $name, $label.' has '.$settings->timeoutText());
        }

        $unread = $settings->unreadTimeouts($missing);

        return CheckResult::warning($section, $name, sprintf(
            '%s has no %s%s: %s',
            $missingLabel,
            implode(' or ', $missing),
            $unread === [] ? '' : sprintf(' (%s does not read %s from %s)', $settings->client, implode(' or ', $missing), implode(' or ', $unread)),
            $consequence,
        ), $settings->timeoutFix($missing).' in config/database.php'.$fixSuffix);
    }

    /**
     * Whether a call to the cache store can wait on the network, judged by its driver: a `failover` store by the stores
     * it tries in order, a `storage` store by its filesystem disk. A driver this check does not know is info, never
     * "no network".
     *
     * @param  list<string>  $failovers  the failover stores that lead to this one, outermost first
     */
    private function storeTimeout(string $section, string $name, string $store, array $failovers = []): CheckResult
    {
        $settings = $this->repository()->get('cache.stores.'.$store);

        if (! is_array($settings)) {
            return CheckResult::warning($section, $name, sprintf('the cache store %s is not configured: every call to it fails', $store), $failovers === []
                ? sprintf('Add cache.stores.%s to config/cache.php, or set CACHE_STORE to a configured store.', $store)
                : sprintf('Add cache.stores.%s to config/cache.php, or remove it from cache.stores.%s.stores.', $store, $failovers[count($failovers) - 1]));
        }

        $driver = is_string($settings['driver'] ?? null) ? $settings['driver'] : 'unknown';

        if ($driver === 'redis') {
            $connection = is_string($settings['connection'] ?? null) && $settings['connection'] !== '' ? $settings['connection'] : 'default';
            $redis = $this->redis($connection);

            if ($redis === null) {
                return CheckResult::error($section, $name, sprintf('%s uses the Redis connection [%s], which is not configured', $store, $connection), sprintf('Add database.redis.%s to config/database.php or point cache.stores.%s.connection at an existing connection.', $connection, $store));
            }

            $label = sprintf('%s (redis %s)', $store, $connection);

            return $this->redisTimeout($section, $name, $label, $label, $redis, 'a hung store can cost a scrape its budget');
        }

        if ($driver === 'database') {
            $connection = is_string($settings['connection'] ?? null) && $settings['connection'] !== '' ? $settings['connection'] : $this->defaultDatabase();

            return $this->databaseTimeout($section, $name, $connection, $store);
        }

        if ($driver === 'failover') {
            return $this->failoverTimeout($section, $name, $store, $settings, $failovers);
        }

        if ($driver === 'storage') {
            $disk = is_string($settings['disk'] ?? null) && $settings['disk'] !== '' ? $settings['disk'] : $this->repository()->get('filesystems.default');

            return $this->diskTimeout($section, $name, $store, is_string($disk) && $disk !== '' ? $disk : 'local');
        }

        if (in_array($driver, ['memcached', 'dynamodb'], true)) {
            return CheckResult::info($section, $name, sprintf('%s (%s): its client timeouts apply to the package\'s throttled logs and detection caches', $store, $driver));
        }

        if (in_array($driver, self::LOCAL_CACHE_DRIVERS, true)) {
            return CheckResult::ok($section, $name, sprintf('%s (%s, no network)', $store, $driver));
        }

        return CheckResult::info($section, $name, sprintf('%s (%s): a driver this check does not know, so its timeouts are not checked', $store, $driver), 'Give its client connect and read timeouts of about 2 s: a hung store can cost a scrape its budget.');
    }

    /**
     * A `failover` store tries its stores in order on every call, so each store in front of the first local one can
     * hold the call (a hung one until its timeout, then the next one is tried). The worst of them, the first on a tie.
     *
     * @param  array<array-key, mixed>  $settings
     * @param  list<string>  $failovers
     */
    private function failoverTimeout(string $section, string $name, string $store, array $settings, array $failovers): CheckResult
    {
        $stores = is_array($settings['stores'] ?? null) ? array_values(array_filter($settings['stores'], is_string(...))) : [];

        if ($stores === []) {
            return CheckResult::warning($section, $name, sprintf('%s (failover) lists no stores: every call to it fails', $store), sprintf("List the stores to try in cache.stores.%s.stores, e.g. ['redis', 'array'].", $store));
        }

        $failovers[] = $store;
        $tried = [];
        $results = [];

        foreach ($stores as $child) {
            $tried[] = $child;

            if (in_array($child, $failovers, true)) {
                $results[] = CheckResult::warning($section, $name, sprintf('%s lists the failover store %s, which leads back to itself', $store, $child), sprintf('Remove %s from cache.stores.%s.stores.', $child, $store));

                break;
            }

            $results[] = $this->storeTimeout($section, $name, $child, $failovers);
            $driver = $this->repository()->get('cache.stores.'.$child.'.driver');

            if (in_array($driver, self::LOCAL_CACHE_DRIVERS, true)) {
                break; // a local store answers: the stores after it are not reached
            }
        }

        $worst = $results[0];

        foreach ($results as $result) {
            if ($result->status->severity() > $worst->status->severity()) {
                $worst = $result;
            }
        }

        $label = sprintf('%s (failover: %s)', $store, implode(', then ', $tried));

        if ($worst->status->severity() > 0) {
            return new CheckResult($section, $name, $worst->status, $label.': '.$worst->message, $worst->fix);
        }

        $info = array_values(array_filter($results, static fn (CheckResult $result): bool => $result->status === CheckStatus::Info));
        $message = $label.': '.implode('; ', array_map(static fn (CheckResult $result): string => $result->message, $results));

        return $info === []
            ? CheckResult::ok($section, $name, $message)
            : CheckResult::info($section, $name, $message, $info[0]->fix);
    }

    /**
     * A `storage` store keeps the cache on a filesystem disk; a network disk waits as long as its client lets it.
     */
    private function diskTimeout(string $section, string $name, string $store, string $disk, int $depth = 0): CheckResult
    {
        $settings = $this->repository()->get('filesystems.disks.'.$disk);
        $label = sprintf('%s (storage on disk %s', $store, $disk);

        if (! is_array($settings)) {
            return CheckResult::warning($section, $name, $label.'): the disk is not configured, so every call fails', sprintf('Add filesystems.disks.%s to config/filesystems.php, or set the disk of cache.stores.%s (CACHE_STORAGE_DISK) to a configured disk.', $disk, $store));
        }

        $driver = is_string($settings['driver'] ?? null) ? $settings['driver'] : 'unknown';

        if ($driver === 'scoped' && $depth < 5 && is_string($settings['disk'] ?? null) && $settings['disk'] !== '') {
            return $this->diskTimeout($section, $name, $store, $settings['disk'], $depth + 1);
        }

        if ($driver === 'local') {
            return CheckResult::ok($section, $name, $label.', local, no network)');
        }

        if ($driver === 's3') {
            $http = is_array($settings['http'] ?? null) ? $settings['http'] : [];
            $missing = array_values(array_filter(['connect_timeout', 'timeout'], static fn (string $key): bool => ! self::positive($http[$key] ?? null)));

            return $missing === []
                ? CheckResult::ok($section, $name, sprintf('%s, s3) has an HTTP connect_timeout of %s s and a timeout of %s s', $label, $this->scalarText($http['connect_timeout']), $this->scalarText($http['timeout'])))
                : CheckResult::warning($section, $name, sprintf('%s, s3) has no HTTP %s (the AWS SDK waits without a limit): a hung endpoint can cost a scrape its budget', $label, implode(' or ', $missing)), sprintf(
                    "Add 'http' => ['connect_timeout' => 2, 'timeout' => 2] to filesystems.disks.%s in config/filesystems.php.",
                    $disk,
                ));
        }

        if (in_array($driver, ['ftp', 'sftp'], true)) {
            return self::positive($settings['timeout'] ?? null)
                ? CheckResult::ok($section, $name, sprintf('%s, %s) has a timeout of %s s', $label, $driver, $this->scalarText($settings['timeout'])))
                : CheckResult::warning($section, $name, sprintf(
                    '%s, %s) has no timeout, so the driver\'s default of %d s applies: a hung server can cost a scrape its budget',
                    $label,
                    $driver,
                    $driver === 'ftp' ? 90 : 10,
                ), sprintf("Add 'timeout' => 2 to filesystems.disks.%s in config/filesystems.php.", $disk));
        }

        return CheckResult::info($section, $name, sprintf('%s, %s): a disk driver this check does not know, so its timeouts are not checked', $label, $driver), 'Give its client connect and read timeouts of about 2 s: a hung disk can cost a scrape its budget.');
    }

    private function databaseTimeout(string $section, string $name, string $connection, ?string $store = null): CheckResult
    {
        $settings = $this->repository()->get('database.connections.'.$connection);
        $label = ($store === null ? '' : $store.' on ').'connection '.$connection;

        if (! is_array($settings)) {
            return CheckResult::error($section, $name, sprintf('%s is not configured', $label), sprintf('Add database.connections.%s to config/database.php.', $connection));
        }

        $driver = is_string($settings['driver'] ?? null) ? $settings['driver'] : 'unknown';

        if ($driver === 'sqlite') {
            return CheckResult::ok($section, $name, $label.' (sqlite, no network)');
        }

        if ($driver === 'sqlsrv') {
            return self::positive($settings['login_timeout'] ?? null)
                ? CheckResult::ok($section, $name, sprintf('%s (sqlsrv) has a login timeout of %s s', $label, $this->scalarText($settings['login_timeout'])))
                : CheckResult::warning($section, $name, sprintf('%s (sqlsrv) has no login_timeout: an unreachable server can cost a scrape its budget', $label), sprintf("Add 'login_timeout' => 2 to database.connections.%s in config/database.php.", $connection));
        }

        $options = is_array($settings['options'] ?? null) ? $settings['options'] : [];

        return self::positive($options[PDO::ATTR_TIMEOUT] ?? null)
            ? CheckResult::ok($section, $name, sprintf('%s (%s) has a connect timeout of %s s', $label, $driver, $this->scalarText($options[PDO::ATTR_TIMEOUT])))
            : CheckResult::warning($section, $name, sprintf('%s (%s) has no connect timeout (PDO::ATTR_TIMEOUT): an unreachable server can cost a scrape its budget', $label, $driver), sprintf(
                'Add PDO::ATTR_TIMEOUT => 2 to the options of database.connections.%s in config/database.php.',
                $connection,
            ));
    }

    /**
     * @return list<CheckResult>
     */
    private function horizon(Config $config): array
    {
        $section = 'Horizon';
        $versions = $this->container->make(Versions::class);
        $results = [CheckResult::ok($section, 'Version', ($versions->horizon() ?? 'unknown').' on Laravel '.$versions->laravel())];

        try {
            /** @var Supervisors $supervisors */
            $supervisors = $this->container->make(Supervisors::class);
            $status = $supervisors->status();
            $results[] = match ($status) {
                1 => CheckResult::ok($section, 'Status', sprintf('running (%s)', $this->plural(count($supervisors->all()), 'supervisor', 'supervisors'))),
                0 => CheckResult::warning($section, 'Status', 'paused: no job is processed', 'Run php artisan horizon:continue.'),
                default => CheckResult::warning($section, 'Status', 'not running: no job is processed and the dashboard shows Horizon as down', 'Start it with php artisan horizon (under supervisord or systemd in production).'),
            };
        } catch (Throwable $e) {
            $results[] = CheckResult::error($section, 'Status', 'cannot be read: '.FailureReporter::describe($e), "Check Horizon's Redis connection (horizon.use and database.redis in config/database.php).");
        }

        $results[] = $this->grafanaLink($section, $config);

        return $results;
    }

    /**
     * Whether the Horizon sidebar gets the Grafana link (T09): a valid `grafana.url` and the middleware on Horizon's
     * `horizon.index` route (directly, or through the `horizon` group of Horizon 5.45+).
     */
    private function grafanaLink(string $section, Config $config): CheckResult
    {
        if ($config->grafanaUrl === null) {
            return $config->hasProblem('grafana.url')
                ? CheckResult::warning($section, 'Grafana link', 'off: grafana.url is not a valid URL', 'Set HORIZON_OBSERVABILITY_GRAFANA_URL to the dashboard URL, e.g. http://localhost:3000/d/horizon-observability.')
                : CheckResult::info($section, 'Grafana link', 'off', 'Set HORIZON_OBSERVABILITY_GRAFANA_URL to the dashboard URL to add a Grafana link to Horizon\'s sidebar.');
        }

        /** @var Router $router */
        $router = $this->container->make('router');
        $route = $router->getRoutes()->getByName(InjectGrafanaLink::ROUTE);

        if (! $route instanceof Route) {
            return CheckResult::warning($section, 'Grafana link', 'Horizon\'s horizon.index route is not registered', 'Make sure Horizon is installed and its routes are loaded (php artisan route:list --name=horizon).');
        }

        if (! $config->enabled || ! in_array(InjectGrafanaLink::class, $this->middleware($router, $route->gatherMiddleware()), true)) {
            return CheckResult::warning($section, 'Grafana link', 'grafana.url is set but Horizon\'s route does not run the link middleware', $config->enabled
                ? 'Run php artisan config:clear and php artisan route:clear (or cache both again): the cached Horizon routes predate the URL.'
                : 'Set HORIZON_OBSERVABILITY_ENABLED=true: a disabled package adds no link.');
        }

        return CheckResult::ok($section, 'Grafana link', 'active: '.$config->grafanaUrl);
    }

    /**
     * Route middleware with the groups expanded.
     *
     * @param  array<array-key, mixed>  $middleware
     * @return list<string>
     */
    private function middleware(Router $router, array $middleware, int $depth = 0): array
    {
        $groups = $router->getMiddlewareGroups();
        $result = [];

        foreach ($middleware as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            if ($depth < 5 && isset($groups[$entry]) && is_array($groups[$entry])) {
                array_push($result, ...$this->middleware($router, $groups[$entry], $depth + 1));

                continue;
            }

            $result[] = explode(':', $entry, 2)[0];
        }

        return $result;
    }

    /**
     * @return list<CheckResult>
     */
    private function capabilities(): array
    {
        $section = 'Capabilities';
        $versions = $this->container->make(Versions::class);

        return [
            $versions->payloadCarriesDelay()
                ? CheckResult::ok($section, 'Payload delay', 'native: payloads carry createdAt and delay')
                : CheckResult::info($section, 'Payload delay', 'legacy: workers keep a ready_at key per delayed job (Laravel < 12.11 or Horizon < 5.49)'),
            property_exists(JobTimedOut::class, 'timeout')
                ? CheckResult::ok($section, 'JobTimedOut::$timeout', 'yes: timed-out attempts record the job timeout as their duration')
                : CheckResult::info($section, 'JobTimedOut::$timeout', 'no (Laravel < 13.25): timed-out attempts record the measured duration'),
            $versions->supportsOptimize()
                ? CheckResult::ok($section, 'optimize', 'php artisan optimize caches the job classes (horizon-observability:cache)')
                : CheckResult::info($section, 'optimize', 'no optimize hook (Laravel < 11.27.1)', 'Add php artisan horizon-observability:cache to your deploy.'),
        ];
    }

    /**
     * @return list<CheckResult>
     */
    private function batches(Config $config): array
    {
        $section = 'Batches';
        $mode = $config->collectors['batches'];

        if ($mode === false) {
            return [CheckResult::info($section, 'Batching', 'off (collectors.batches = false)')];
        }

        /** @var BatchReader $reader */
        $reader = $this->container->make(BatchReader::class);
        $driver = $reader->driver();
        $connection = $reader->connectionName();
        $table = $reader->table();

        if ($driver !== 'database') {
            return [$mode === true
                ? CheckResult::error($section, 'Batching', sprintf('collectors.batches is true but the batching driver is [%s]', $driver), 'Use the database batching driver (queue.batching.driver), or set collectors.batches to null (auto) or false.')
                : CheckResult::info($section, 'Batching', sprintf('off (auto): the %s batching driver is not read', $driver))];
        }

        try {
            $available = $reader->available();
        } catch (Throwable $e) {
            return [CheckResult::error($section, 'Batching', sprintf('the batching database [%s] cannot be reached: %s', $connection, FailureReporter::describe($e)), sprintf('Check database.connections.%s (queue.batching.database) in config/database.php.', $connection))];
        }

        if ($available) {
            return [CheckResult::ok($section, 'Batching', sprintf('database: table %s on connection %s', $table, $connection))];
        }

        return [$mode === true
            ? CheckResult::error($section, 'Batching', sprintf('the table %s does not exist on connection %s', $table, $connection), 'Run php artisan make:queue-batches-table and php artisan migrate, or set collectors.batches to null (auto).')
            : CheckResult::info($section, 'Batching', sprintf('off (auto): no table %s on connection %s', $table, $connection), 'Run php artisan make:queue-batches-table and php artisan migrate for batch metrics.')];
    }

    /**
     * @return list<CheckResult>
     */
    private function jobClasses(Config $config): array
    {
        $section = 'Job classes';
        /** @var JobClassCache $cache */
        $cache = $this->container->make(JobClassCache::class);
        $generatedAt = $cache->generatedAt();
        $results = [$generatedAt === null
            ? CheckResult::info($section, 'Cache file', 'none: discovered at runtime and cached for 1 h in the default cache store', 'Run php artisan horizon-observability:cache on deploy (php artisan optimize runs it on Laravel >= 11.27.1).')
            : CheckResult::ok($section, 'Cache file', sprintf('written %s (%s)', gmdate('Y-m-d H:i:s', $generatedAt).' UTC', $this->relativePath($cache->path())))];

        try {
            $used = count($this->container->make(AttemptStore::class)->jobClasses());
            $share = $used / $config->maxJobClasses;
            $text = sprintf('%d of %d attempt classes in use (max_job_classes)', $used, $config->maxJobClasses);

            $results[] = $share >= self::CLASS_CAP_WARNING
                ? CheckResult::warning($section, 'Cap', $text.($used >= $config->maxJobClasses ? ': further classes are counted as (other)' : ': close to the cap'), 'Raise max_job_classes in config/horizon-observability.php (at most 10000); classes idle for 30 days are pruned.')
                : CheckResult::ok($section, 'Cap', $text);
        } catch (Throwable $e) {
            $results[] = CheckResult::error($section, 'Cap', 'the class index cannot be read: '.FailureReporter::describe($e), 'Fix the storage connection first (see Storage).');
        }

        return $results;
    }

    /**
     * A dry run of every enabled collector, as one scrape runs it (Arch Q1.3), with the error of a failed one.
     *
     * @return list<CheckResult>
     */
    private function collectors(Config $config): array
    {
        $section = 'Collectors';
        $results = [];
        $total = 0.0;
        $format = new TextFormat;

        foreach (Config::COLLECTORS as $id) {
            $enabled = $config->collectors[$id];

            if ($enabled === false) {
                $results[] = CheckResult::info($section, $id, sprintf('off (collectors.%s = false)', $id));

                continue;
            }

            if ($enabled === null) {
                try {
                    if (! $this->container->make(BatchReader::class)->available()) {
                        $results[] = CheckResult::info($section, $id, 'off (auto: no database batching table)');

                        continue;
                    }
                } catch (Throwable $e) {
                    $results[] = $this->collectorFailure($section, $id, 'detection', $e, $config);

                    continue;
                }
            }

            $context = new ScrapeContext(now()->toImmutable(), Exporter::BUDGET);
            $started = ($this->clock)();

            try {
                // What the scrape renders of the collector: the families with samples (TextFormat leaves out the empty
                // ones), checked against the catalog as Exporter::scrape() checks them.
                $lines = explode("\n", $format->render($this->families($id, $context), $config->prefix()));
                $families = count(array_filter($lines, static fn (string $line): bool => str_starts_with($line, '# TYPE ')));
                $samples = count(array_filter($lines, static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#')));
                $failures = $context->takeFailures();
            } catch (Throwable $e) {
                $context->takeFailures();
                $total += ($this->clock)() - $started;
                $results[] = $this->collectorFailure($section, $id, null, $e, $config);

                continue;
            }

            $seconds = ($this->clock)() - $started;
            $total += $seconds;

            $results[] = $failures === []
                ? CheckResult::ok($section, $id, sprintf('%s, %s in %s s', $this->plural($families, 'family', 'families'), $this->plural($samples, 'sample', 'samples'), number_format($seconds, 3, '.', '')))
                : $this->collectorFailure($section, $id, $failures[0]['source'], $failures[0]['exception'], $config, count($failures));
        }

        if ($total > self::SLOW_SCRAPE_SECONDS) {
            $results[] = CheckResult::warning($section, 'Duration', sprintf('the dry run took %s s, more than the 4.5 s budget of a 5 s scrape', number_format($total, 3, '.', '')), 'Scrape every 10 s with scrape_timeout 8s, or lower queue_scan_limit and add the timeouts above.');
        }

        return $results;
    }

    /**
     * The collector's families, checked as {@see Exporter::scrape()} checks them: only families of this collector, each
     * once (a second one makes {@see TextFormat::render()} throw); otherwise the scrape drops all of them.
     *
     * @return list<MetricFamily>
     */
    private function families(string $id, ScrapeContext $context): array
    {
        $collector = $this->container->make(Exporter::COLLECTORS[$id]);

        if (! $collector instanceof Collector) {
            throw new LogicException(sprintf('%s does not implement %s.', Exporter::COLLECTORS[$id], Collector::class));
        }

        $families = [];

        foreach ($collector->collect($context) as $family) {
            if (! $family instanceof MetricFamily) {
                throw new InvalidMetric(sprintf('The %s collector yielded %s instead of a MetricFamily.', $id, get_debug_type($family)));
            }

            if ($family->metric()->collector() !== $id) {
                throw new InvalidMetric(sprintf('The %s collector yielded %s, a family of the %s collector.', $id, $family->metric()->value, $family->metric()->collector()));
            }

            $families[] = $family;
        }

        return $families;
    }

    private function collectorFailure(string $section, string $id, ?string $source, Throwable $e, Config $config, int $count = 1): CheckResult
    {
        $message = sprintf(
            'failed%s: %s%s',
            $source === null ? '' : ' ('.$source.')',
            FailureReporter::describe($e),
            $count > 1 ? sprintf(' (and %d more)', $count - 1) : '',
        );

        $fix = match (true) {
            $e instanceof UnsupportedTopology => 'Give the queue a hash tag, e.g. {default}, or run Laravel 13 with first-class cluster queues; or set collectors.queues to false.',
            $e instanceof QueryException, $e instanceof PDOException => 'Check the batching database connection (queue.batching.database) and its job_batches table.',
            str_contains(strtolower($e->getMessage()), 'connect') || str_contains(strtolower($e::class), 'redis') || str_contains(strtolower($e::class), 'connection') => 'Check that Redis is reachable with the settings in config/database.php (see Storage and Queues).',
            default => sprintf('Fix the error above; until then Prometheus sees %sexporter_collector_success{collector="%s"} 0.', $config->prefix(), $id),
        };

        return CheckResult::error($section, $id, $message, $fix);
    }

    /**
     * The live block (Addendum Q9' WP10): enabled, poll endpoint, stream URL, token set or missing, stream id, the last
     * push, and the optional push test.
     *
     * @return list<CheckResult>
     */
    private function live(Config $config, bool $pushTest): array
    {
        $section = 'Live';
        $urls = $config->liveGrafanaUrls;
        $token = $config->liveToken !== null;
        $stream = $urls !== [] || $token || $config->hasProblem('live.stream.grafana_url');
        $results = [];

        if (! $config->liveEnabled) {
            $results[] = $stream
                ? CheckResult::warning($section, 'Enabled', 'no, but the stream settings are set: the stream command refuses to start', 'Set HORIZON_OBSERVABILITY_LIVE_ENABLED=true, or remove the HORIZON_OBSERVABILITY_LIVE_GRAFANA_* settings.')
                : CheckResult::info($section, 'Enabled', 'no: the dashboard must be rendered with --live=off');

            return $results;
        }

        $results[] = CheckResult::ok($section, 'Enabled', sprintf('poll endpoint %s (shared for %s s)', EndpointUrl::to($this->repository(), '/live'), TextFormat::number($config->liveCacheTtl)));

        if ($config->hasProblem('live.stream.id')) {
            $results[] = CheckResult::error($section, 'Stream id', sprintf('invalid: using "%s"', $config->liveStreamId), 'Set HORIZON_OBSERVABILITY_LIVE_STREAM_ID to 1-64 characters of A-Z, a-z, 0-9, _ and -, and render the dashboard with the same --live-stream-id.');
        } else {
            $results[] = CheckResult::ok($section, 'Stream id', $config->liveStreamId.' (channels stream/'.$config->liveStreamId.'/tiles and /delayed)');
        }

        if (! $stream) {
            $results[] = CheckResult::info($section, 'Stream', 'not configured: poll mode needs nothing more', 'For 1 s tiles see the stream steps of php artisan horizon-observability:install.');

            if ($pushTest) {
                $results[] = CheckResult::error($section, 'Push test', 'not run: no Grafana URL and no token', 'Set HORIZON_OBSERVABILITY_LIVE_GRAFANA_URL and HORIZON_OBSERVABILITY_LIVE_GRAFANA_TOKEN first.');
            }

            return $results;
        }

        $results[] = $urls === []
            ? CheckResult::error($section, 'Stream URL', $config->hasProblem('live.stream.grafana_url') ? 'no valid http(s) URL' : 'missing', 'Set HORIZON_OBSERVABILITY_LIVE_GRAFANA_URL to the Grafana base URL reachable from the app (comma-separated for several instances).')
            : CheckResult::ok($section, 'Stream URL', implode(', ', array_map(GrafanaLivePusher::display(...), $urls)));

        $results[] = $token
            ? CheckResult::ok($section, 'Stream token', 'set')
            : CheckResult::error($section, 'Stream token', 'missing', 'Set HORIZON_OBSERVABILITY_LIVE_GRAFANA_TOKEN to a Grafana service account token with role Editor (see php artisan horizon-observability:install).');

        $results[] = $this->lastPush($section);

        if ($pushTest) {
            array_push($results, ...$this->pushTest($section, $config));
        }

        return $results;
    }

    private function lastPush(string $section): CheckResult
    {
        try {
            $meta = $this->container->make(AttemptStore::class)->meta();
        } catch (Throwable $e) {
            return CheckResult::error($section, 'Last push', 'cannot be read: '.FailureReporter::describe($e), 'Fix the storage connection first (see Storage).');
        }

        $failures = (int) ($meta['live_push_failures'] ?? 0);
        $at = $meta['live_push_at'] ?? null;
        $suffix = $failures > 0 ? sprintf(' (%d failed pushes so far)', $failures) : '';

        if ($at === null || ! is_numeric($at)) {
            return CheckResult::warning($section, 'Last push', 'never'.$suffix, 'Run php artisan horizon-observability:stream under supervisord or systemd, then check its output.');
        }

        $age = max(0.0, now()->getTimestamp() + (float) now()->format('u') / 1e6 - (float) $at);

        return $age > self::STALE_PUSH_SECONDS
            ? CheckResult::warning($section, 'Last push', sprintf('%d s ago%s: the live tiles are frozen', (int) $age, $suffix), 'Make sure php artisan horizon-observability:stream is running (and see its output), or run check --live-push-test.')
            : CheckResult::ok($section, 'Last push', sprintf('%s s ago%s', number_format($age, 1, '.', ''), $suffix));
    }

    /**
     * One `tiles` frame (an empty snapshot) pushed to the stream `<id>_check` of every configured Grafana.
     *
     * @return list<CheckResult>
     */
    private function pushTest(string $section, Config $config): array
    {
        if ($config->liveGrafanaUrls === [] || $config->liveToken === null) {
            return [CheckResult::error($section, 'Push test', 'not run: the stream URL or token is missing', 'Set HORIZON_OBSERVABILITY_LIVE_GRAFANA_URL and HORIZON_OBSERVABILITY_LIVE_GRAFANA_TOKEN first.')];
        }

        $streamId = self::pushTestStreamId($config->liveStreamId);
        $nowMs = now()->getTimestampMs();
        $frame = LineProtocol::encode(LiveSnapshot::empty(new LiveScope(null, [], false, null), $nowMs, 0), $nowMs * 1000000, []);
        $tiles = implode("\n", array_filter(explode("\n", $frame), static fn (string $line): bool => str_starts_with($line, LineProtocol::TILES.' ')))."\n";
        /** @var GrafanaLivePusher $pusher */
        $pusher = $this->container->make(GrafanaLivePusher::class);
        $results = [];

        foreach ($config->liveGrafanaUrls as $url) {
            $result = $pusher->push($url, $streamId, $config->liveToken, $tiles);
            $name = 'Push test '.GrafanaLivePusher::display($url);

            $results[] = $result->ok
                ? CheckResult::ok($section, $name, sprintf('HTTP %d: pushed one tiles frame to stream %s in %s s', $result->status ?? 200, $streamId, number_format($result->seconds, 3, '.', '')))
                : CheckResult::error($section, $name, ($result->error ?? 'failed').' (stream '.$streamId.')', ucfirst(GrafanaLivePusher::hint($result->status)).'.');
        }

        return $results;
    }

    /**
     * The stream id of the push test: `<id>_check`, the id cut to keep it at 64 characters.
     */
    public static function pushTestStreamId(string $streamId): string
    {
        return substr($streamId, 0, 64 - strlen(self::PUSH_TEST_SUFFIX)).self::PUSH_TEST_SUFFIX;
    }

    /**
     * @return list<CheckResult>
     */
    private function prometheus(Config $config): array
    {
        return [CheckResult::info('Prometheus', 'Scrape job', sprintf(
            'metrics_path %s/metrics, scrape_interval 10s (5s to 60s), scrape_timeout 8s, Bearer token%s; php artisan horizon-observability:install prints the job',
            RoutePath::apiPath($this->repository()),
            $config->tokens === [] ? '' : ' (HORIZON_OBSERVABILITY_TOKEN)',
        ))];
    }

    private function persistentWarning(string $section, string $connection, RedisClientSettings $settings): CheckResult
    {
        $places = array_map(
            static fn (string $place): string => $place === 'database.redis.options' ? $place.' (REDIS_PERSISTENT=false)' : $place,
            $settings->persistentPlaces(),
        );

        return CheckResult::warning(
            $section,
            'Persistent',
            sprintf('database.redis.%s is persistent: a timeout can leave a reply on the shared socket for the next request (Horizon\'s reads use it; the package\'s own clients never do)', $connection),
            sprintf("Set 'persistent' => false in %s.", implode(' and ', $places)),
        );
    }

    /**
     * The client settings of an app Redis connection ({@see RedisClientSettings}); for Horizon's `horizon` connection,
     * which Horizon copies from `horizon.use` at boot, with the places of the copied connection in config/database.php.
     */
    private function redis(string $name): ?RedisClientSettings
    {
        return RedisClientSettings::of($this->repository(), $name, $name === 'horizon' ? $this->horizonBase() : null);
    }

    /**
     * The place in config/database.php of the connection Horizon copies into `horizon` (`Horizon::use()`): a cluster
     * (Horizon with cluster support), its first node (older Horizon, or Laravel without cluster support), or the
     * connection itself. Null when `horizon.use` is `horizon` itself.
     */
    private function horizonBase(): ?string
    {
        $use = $this->horizonUse();

        if ($use === 'horizon') {
            return null;
        }

        if (! is_array($this->repository()->get('database.redis.clusters.'.$use))) {
            return 'database.redis.'.$use;
        }

        return is_array($this->repository()->get('database.redis.horizon')) ? 'database.redis.clusters.'.$use.'.0' : 'database.redis.clusters.'.$use;
    }

    private function horizonUse(): string
    {
        $use = $this->repository()->get('horizon.use');

        return is_string($use) && $use !== '' ? $use : 'default';
    }

    private function plural(int $count, string $one, string $many): string
    {
        return $count.' '.($count === 1 ? $one : $many);
    }

    private static function positive(mixed $value): bool
    {
        return is_numeric($value) && (float) $value > 0;
    }

    private function scalarText(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '?';
    }

    /**
     * The hash tag Redis Cluster hashes for a key: the content of the first `{...}`, or `''` when the key has no tag or
     * the first one is empty (then the whole key is hashed).
     */
    public static function hashTag(string $key): string
    {
        $open = strpos($key, '{');

        if ($open === false) {
            return '';
        }

        $close = strpos($key, '}', $open + 1);

        return $close === false ? '' : substr($key, $open + 1, $close - $open - 1);
    }

    private function configFix(string $path): string
    {
        if ($path === '') {
            return 'Make config/horizon-observability.php return an array (php artisan vendor:publish --tag=horizon-observability-config --force restores it).';
        }

        $env = self::ENV_VARS[$path] ?? null;

        return $env === null
            ? sprintf('Fix %s in config/horizon-observability.php.', $path)
            : sprintf('Set a valid %s in .env (or fix %s in config/horizon-observability.php).', $env, $path);
    }

    private function batchingSummary(): string
    {
        $mode = $this->config()->collectors['batches'];

        if ($mode === false) {
            return 'off';
        }

        /** @var BatchReader $reader */
        $reader = $this->container->make(BatchReader::class);
        $driver = $reader->driver();

        if ($driver !== 'database') {
            return $driver.($mode === null ? ' (not read)' : '');
        }

        return sprintf('database (%s on %s)%s', $reader->table(), $reader->connectionName(), $mode === null ? ', auto' : '');
    }

    private function jobClassCacheSummary(): string
    {
        $generatedAt = $this->container->make(JobClassCache::class)->generatedAt();

        return $generatedAt === null ? 'runtime (no cache file)' : 'cached '.gmdate('Y-m-d H:i:s', $generatedAt).' UTC';
    }

    private function relativePath(string $path): string
    {
        $base = rtrim($this->path('path.base'), '/').'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    private function appHost(): string
    {
        $url = $this->repository()->get('app.url');
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

        return is_string($host) && $host !== '' ? $host : 'localhost';
    }

    private function defaultDatabase(): string
    {
        $default = $this->repository()->get('database.default');

        return is_string($default) && $default !== '' ? $default : 'mysql';
    }

    private function milliseconds(int|float $startedNs): string
    {
        return number_format((hrtime(true) - $startedNs) / 1e6, 1, '.', '');
    }

    private function path(string $binding): string
    {
        $path = $this->container->make($binding);

        return is_string($path) ? $path : '';
    }

    private function config(): Config
    {
        return $this->container->make(Config::class);
    }

    private function repository(): Repository
    {
        /** @var Repository */
        return $this->container->make('config');
    }

    /**
     * @param  Closure(): string  $value
     */
    private function safely(Closure $value): string
    {
        try {
            return $value();
        } catch (Throwable) {
            return 'unavailable';
        }
    }
}
