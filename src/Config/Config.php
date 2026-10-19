<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Config;

use DF\HorizonObservability\Http\RoutePath;
use Illuminate\Contracts\Config\Repository;
use SensitiveParameter;
use Throwable;

/**
 * Immutable, validated snapshot of `config/horizon-observability.php`.
 *
 * Built lazily by {@see self::fromRepository()} as a container singleton. It normalises and reports: an invalid
 * non-security value falls back to its default and adds a {@see ConfigProblem}; security values (tokens, IPs) fail
 * closed. It never throws.
 *
 * Fail closed for access: `tokens`, `apiTokens` and `allowedIps` hold only the valid entries. Whether a list was
 * configured at all, valid or not, is answered by {@see self::requiresToken()} and {@see self::requiresIp()}: a list
 * whose every entry was invalid still requires a match, so nothing can pass it.
 *
 * @internal
 */
final readonly class Config
{
    public const DEFAULT_NAMESPACE = 'ho';

    public const DEFAULT_REDIS_CONNECTION = 'horizon';

    public const DEFAULT_WORKER_TIMEOUT = 1.0;

    public const DEFAULT_SCRAPE_TIMEOUT = 2.0;

    public const MAX_TIMEOUT = 30.0;

    public const MIN_TOKEN_LENGTH = 16;

    public const DEFAULT_QUEUE_SCAN_LIMIT = 1000;

    public const DEFAULT_MAX_JOB_CLASSES = 500;

    public const MAX_JOB_CLASSES = 10000;

    /**
     * @var list<float>
     */
    public const DEFAULT_BUCKETS = [0.05, 0.1, 0.25, 0.5, 1.0, 2.5, 5.0, 10.0, 30.0, 60.0, 120.0, 300.0, 600.0, 1800.0, 3600.0];

    public const DEFAULT_LIVE_CACHE_TTL = 1.0;

    public const MAX_LIVE_CACHE_TTL = 10.0;

    public const DEFAULT_LIVE_STREAM_ID = 'horizon_observability';

    public const DEFAULT_LIVE_STREAM_INTERVAL = 1.0;

    public const MIN_LIVE_STREAM_INTERVAL = 0.5;

    public const MAX_LIVE_STREAM_INTERVAL = 10.0;

    /**
     * Collector ids in exporter order.
     */
    public const COLLECTORS = ['horizon', 'queues', 'attempts', 'job_classes', 'batches'];

    private const NAMESPACE_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private const STREAM_ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    private const ENDPOINTS = ['metrics', 'api'];

    /**
     * @param  list<string>  $tokens  valid bearer tokens for every endpoint
     * @param  list<string>  $apiTokens  valid bearer tokens for the job lists and `/live`; empty = `tokens`
     * @param  list<string>  $allowedIps  valid IPs and CIDRs
     * @param  list<string>  $middleware  extra route middleware, run after `Authorize`
     * @param  array{horizon: bool, queues: bool, attempts: bool, job_classes: bool, batches: bool|null}  $collectors
     * @param  list<float>  $buckets  ascending, unique, finite and positive histogram bounds (seconds)
     * @param  list<string>  $jobClassPaths  existing directories scanned for queueable classes
     * @param  list<string>  $liveGrafanaUrls  Grafana base URLs for the stream command, without a trailing slash
     * @param  list<ConfigProblem>  $problems
     */
    public function __construct(
        public bool $enabled,
        public string $namespace,
        public ?string $path,
        public ?string $domain,
        #[SensitiveParameter] public array $tokens,
        #[SensitiveParameter] public array $apiTokens,
        public array $allowedIps,
        public array $middleware,
        public bool $availableDuringMaintenance,
        public ExceptionMode $exceptions,
        public string $redisConnection,
        public float $workerTimeout,
        public float $scrapeTimeout,
        public array $collectors,
        public array $buckets,
        public int $queueScanLimit,
        public int $maxJobClasses,
        public array $jobClassPaths,
        public ?string $horizonUrl,
        public ?string $grafanaUrl,
        public ?string $ttsUrl,
        public bool $liveEnabled,
        public float $liveCacheTtl,
        public array $liveGrafanaUrls,
        #[SensitiveParameter] public ?string $liveToken,
        public string $liveStreamId,
        public float $liveStreamInterval,
        public ?string $logChannel,
        public array $problems,
    ) {}

    /**
     * Build the snapshot from the application config. Never throws.
     */
    public static function fromRepository(Repository $config): self
    {
        /** @var list<ConfigProblem> $problems */
        $problems = [];

        if (! is_array($config->get('horizon-observability', []))) {
            $problems[] = new ConfigProblem('', 'horizon-observability must be an array; using the defaults.');
        }

        $get = static function (string $key) use ($config): mixed {
            $root = $config->get('horizon-observability');

            return is_array($root) ? data_get($root, $key) : null;
        };

        $tokens = self::tokenList($get('token'), 'token', $problems);
        $apiTokens = self::tokenList($get('api_token'), 'api_token', $problems);

        return new self(
            enabled: self::boolean($get('enabled'), true, 'enabled', $problems),
            namespace: self::namespace($get('namespace'), $problems),
            path: self::path($get('path'), $problems),
            domain: self::optionalString($get('domain'), 'domain', $problems),
            tokens: $tokens,
            apiTokens: $apiTokens,
            allowedIps: self::ipList($get('allowed_ips'), $problems),
            middleware: self::middleware($get('middleware'), $problems),
            availableDuringMaintenance: self::boolean($get('available_during_maintenance'), true, 'available_during_maintenance', $problems),
            exceptions: self::exceptionMode($get('exceptions'), $problems),
            redisConnection: self::redisConnection($get('redis.connection'), $config, $problems),
            workerTimeout: self::range($get('redis.worker_timeout'), self::DEFAULT_WORKER_TIMEOUT, 0.0, self::MAX_TIMEOUT, 'redis.worker_timeout', $problems, minExclusive: true),
            scrapeTimeout: self::range($get('redis.scrape_timeout'), self::DEFAULT_SCRAPE_TIMEOUT, 0.0, self::MAX_TIMEOUT, 'redis.scrape_timeout', $problems, minExclusive: true),
            collectors: self::collectors($get('collectors'), $problems),
            buckets: self::buckets($get('buckets'), $problems),
            queueScanLimit: self::integer($get('queue_scan_limit'), self::DEFAULT_QUEUE_SCAN_LIMIT, 0, PHP_INT_MAX, 'queue_scan_limit', $problems),
            maxJobClasses: self::integer($get('max_job_classes'), self::DEFAULT_MAX_JOB_CLASSES, 1, self::MAX_JOB_CLASSES, 'max_job_classes', $problems),
            jobClassPaths: self::jobClassPaths($get('job_class_paths'), $problems),
            horizonUrl: self::url($get('horizon_url'), 'horizon_url', $problems),
            grafanaUrl: self::url($get('grafana.url'), 'grafana.url', $problems),
            ttsUrl: self::ttsUrl($get('grafana.tts_url'), $problems),
            liveEnabled: self::boolean($get('live.enabled'), true, 'live.enabled', $problems),
            liveCacheTtl: self::range($get('live.cache_ttl'), self::DEFAULT_LIVE_CACHE_TTL, 0.0, self::MAX_LIVE_CACHE_TTL, 'live.cache_ttl', $problems),
            liveGrafanaUrls: self::urlList($get('live.stream.grafana_url'), 'live.stream.grafana_url', $problems),
            liveToken: self::secret($get('live.stream.token'), 'live.stream.token', $problems),
            liveStreamId: self::streamId($get('live.stream.id'), $problems),
            liveStreamInterval: self::range($get('live.stream.interval'), self::DEFAULT_LIVE_STREAM_INTERVAL, self::MIN_LIVE_STREAM_INTERVAL, self::MAX_LIVE_STREAM_INTERVAL, 'live.stream.interval', $problems),
            logChannel: self::logChannel($get('log_channel'), $config, $problems),
            problems: $problems,
        );
    }

    /**
     * Boot-time cast used by the provider and the route file (safe casts, no validation): booleans,
     * `1`/`0` and the strings `true`/`false`/`on`/`off`/`yes`/`no`/`1`/`0`/`''`; anything else is `$default`.
     */
    public static function flag(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (! is_string($value) && ! is_int($value)) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * Metric name prefix: `''` or `"<namespace>_"`.
     */
    public function prefix(): string
    {
        return $this->namespace === '' ? '' : $this->namespace.'_';
    }

    /**
     * Valid tokens accepted on an endpoint: `metrics`, or `api` (job lists and `/live`, which use `api_token` when it
     * is configured and `token` otherwise).
     *
     * @return list<string>
     */
    public function tokensFor(string $endpoint): array
    {
        return $endpoint === 'api' && $this->apiTokenConfigured() ? $this->apiTokens : $this->tokens;
    }

    /**
     * Whether a request to the endpoint must present one of {@see self::tokensFor()}. True when the relevant token
     * setting was given at all, even if every entry was invalid (then nothing matches: fail closed).
     */
    public function requiresToken(string $endpoint): bool
    {
        if ($endpoint === 'api' && $this->apiTokenConfigured()) {
            return true;
        }

        return in_array($endpoint, self::ENDPOINTS, true) && ($this->tokens !== [] || $this->hasProblem('token'));
    }

    /**
     * Whether the request IP must match {@see self::$allowedIps}. True when `allowed_ips` was given at all, even if
     * every entry was invalid (then nothing matches: fail closed).
     */
    public function requiresIp(): bool
    {
        return $this->allowedIps !== [] || $this->hasProblem('allowed_ips');
    }

    /**
     * Whether a problem was reported for the key (e.g. `token`, `live.stream.id`).
     */
    public function hasProblem(string $path): bool
    {
        foreach ($this->problems as $problem) {
            if ($problem->path === $path) {
                return true;
            }
        }

        return false;
    }

    /**
     * Secrets are never dumped.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $properties = get_object_vars($this);
        $properties['tokens'] = array_fill(0, count($this->tokens), '[redacted]');
        $properties['apiTokens'] = array_fill(0, count($this->apiTokens), '[redacted]');
        $properties['liveToken'] = $this->liveToken === null ? null : '[redacted]';

        return $properties;
    }

    private function apiTokenConfigured(): bool
    {
        return $this->apiTokens !== [] || $this->hasProblem('api_token');
    }

    /**
     * @param  list<ConfigProblem>  $problems
     */
    private static function boolean(mixed $value, bool $default, string $path, array &$problems): bool
    {
        if ($value === null) {
            return $default;
        }

        $bool = is_bool($value) ? $value : (is_string($value) || is_int($value) ? filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : null);

        if ($bool === null) {
            $problems[] = new ConfigProblem($path, sprintf('%s must be a boolean; using %s.', $path, $default ? 'true' : 'false'));

            return $default;
        }

        return $bool;
    }

    /**
     * @param  list<ConfigProblem>  $problems
     */
    private static function namespace(mixed $value, array &$problems): string
    {
        if ($value === null) {
            return self::DEFAULT_NAMESPACE;
        }

        if (! is_string($value)) {
            $problems[] = new ConfigProblem('namespace', 'namespace must be a string; using "ho".');

            return self::DEFAULT_NAMESPACE;
        }

        $namespace = trim($value);

        if ($namespace === '') {
            return '';
        }

        $stripped = rtrim($namespace, '_');

        if (preg_match(self::NAMESPACE_PATTERN, $namespace) !== 1 || $stripped === '') {
            $problems[] = new ConfigProblem('namespace', 'namespace must match [A-Za-z_][A-Za-z0-9_]* or be empty; using "ho".');

            return self::DEFAULT_NAMESPACE;
        }

        return $stripped;
    }

    /**
     * @param  list<ConfigProblem>  $problems
     */
    private static function path(mixed $value, array &$problems): ?string
    {
        if ($value === null || (is_string($value) && trim($value, '/') === '')) {
            return null;
        }

        $path = RoutePath::normalize($value);

        if ($path === null) {
            $problems[] = new ConfigProblem('path', 'path must be a string without "{", "}", ".." or whitespace; using the derived default.');
        }

        return $path;
    }

    /**
     * @param  list<ConfigProblem>  $problems
     */
    private static function optionalString(mixed $value, string $path, array &$problems): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            $problems[] = new ConfigProblem($path, sprintf('%s must be a string or null; using null.', $path));

            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * A comma-separated string or a list of strings, trimmed, without empty entries and duplicates.
     *
     * @param  list<ConfigProblem>  $problems
     * @return list<string>|null null when the value has an unusable type
     */
    private static function stringList(mixed $value, string $path, array &$problems): ?array
    {
        if ($value === null) {
            return [];
        }

        if (is_string($value)) {
            $value = explode(',', $value);
        }

        if (! is_array($value)) {
            $problems[] = new ConfigProblem($path, sprintf('%s must be a comma-separated string or a list of strings.', $path));

            return null;
        }

        $entries = [];
        $invalid = 0;

        foreach ($value as $entry) {
            if (! is_string($entry)) {
                $invalid++;

                continue;
            }

            $entry = trim($entry);

            if ($entry !== '' && ! in_array($entry, $entries, true)) {
                $entries[] = $entry;
            }
        }

        if ($invalid > 0) {
            $problems[] = new ConfigProblem($path, sprintf('%s: %d non-string entries ignored.', $path, $invalid));
        }

        return $entries;
    }

    /**
     * @param  list<ConfigProblem>  $problems
     * @return list<string>
     */
    private static function tokenList(mixed $value, string $path, array &$problems): array
    {
        $entries = self::stringList($value, $path, $problems) ?? [];
        $valid = array_values(array_filter($entries, static fn (string $token): bool => strlen($token) >= self::MIN_TOKEN_LENGTH));
        $short = count($entries) - count($valid);

        if ($short > 0) {
            $problems[] = new ConfigProblem($path, sprintf(
                '%s: %d of %d tokens are shorter than %d characters and were ignored (fail closed).',
                $path,
                $short,
                count($entries),
                self::MIN_TOKEN_LENGTH,
            ));
        }

        return $valid;
    }

    /**
     * @param  list<ConfigProblem>  $problems
     * @return list<string>
     */
    private static function ipList(mixed $value, array &$problems): array
    {
        $entries = self::stringList($value, 'allowed_ips', $problems) ?? [];
        $valid = array_values(array_filter($entries, self::isIpOrCidr(...)));
        $invalid = count($entries) - count($valid);

        if ($invalid > 0) {
            $problems[] = new ConfigProblem('allowed_ips', sprintf(
                'allowed_ips: %d of %d entries are not an IP address or CIDR and were ignored (fail closed).',
                $invalid,
                count($entries),
            ));
        }

        return $valid;
    }

    private static function isIpOrCidr(string $entry): bool
    {
        $slash = strpos($entry, '/');
        $address = $slash === false ? $entry : substr($entry, 0, $slash);
        $mask = $slash === false ? null : substr($entry, $slash + 1);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($mask === null) {
            return true;
        }

        return ctype_digit($mask) && (int) $mask <= (str_contains($address, ':') ? 128 : 32);
    }

    /**
     * @param  list<ConfigProblem>  $problems
     * @return list<string>
     */
    private static function middleware(mixed $value, array &$problems): array
    {
        if ($value === null) {
            return [];
        }

        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            $problems[] = new ConfigProblem('middleware', 'middleware must be a list of middleware names; using none.');

            return [];
        }

        $middleware = array_values(array_filter($value, static fn (mixed $entry): bool => is_string($entry) && $entry !== ''));

        if (count($middleware) !== count($value)) {
            $problems[] = new ConfigProblem('middleware', sprintf('middleware: %d entries that are not names were ignored.', count($value) - count($middleware)));
        }

        return $middleware;
    }

    /**
     * @param  list<ConfigProblem>  $problems
     */
    private static function exceptionMode(mixed $value, array &$problems): ExceptionMode
    {
        if ($value === null) {
            return ExceptionMode::Full;
        }

        $mode = is_string($value) ? ExceptionMode::tryFrom(strtolower(trim($value))) : null;

        if ($mode === null) {
            $problems[] = new ConfigProblem('exceptions', 'exceptions must be full, class or none; using full.');

            return ExceptionMode::Full;
        }

        return $mode;
    }

    /**
     * @param  list<ConfigProblem>  $problems
     */
    private static function redisConnection(mixed $value, Repository $config, array &$problems): string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            $name = self::DEFAULT_REDIS_CONNECTION;
        } elseif (is_string($value)) {
            $name = trim($value);
        } else {
            $problems[] = new ConfigProblem('redis.connection', 'redis.connection must be a connection name; using "horizon".');
            $name = self::DEFAULT_REDIS_CONNECTION;
        }

        $reserved = in_array($name, ['client', 'options', 'clusters'], true);
        $standalone = ! $reserved && is_array($config->get('database.redis.'.$name));
        $cluster = $name !== 'options' && is_array($config->get('database.redis.clusters.'.$name));

        if (! $standalone && ! $cluster) {
            $problems[] = new ConfigProblem('redis.connection', sprintf(
                'Redis connection [%s] is not configured in database.redis or database.redis.clusters; storage writes will fail.',
                $name,
            ));
        }

        return $name;
    }

    /**
     * A number in [$min, $max] (or ($min, $max] with $minExclusive); numeric strings are accepted.
     *
     * @param  list<ConfigProblem>  $problems
     */
    private static function range(mixed $value, float $default, float $min, float $max, string $path, array &$problems, bool $minExclusive = false): float
    {
        if ($value === null) {
            return $default;
        }

        $number = is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value))) ? (float) (is_string($value) ? trim($value) : $value) : null;

        if ($number === null || ! is_finite($number) || $number > $max || ($minExclusive ? $number <= $min : $number < $min)) {
            $problems[] = new ConfigProblem($path, sprintf(
                '%s must be a number in %s%s, %s]; using %s.',
                $path,
                $minExclusive ? '(' : '[',
                self::number($min),
                self::number($max),
                self::number($default),
            ));

            return $default;
        }

        return $number;
    }

    /**
     * An integer in [$min, $max]; integral numeric strings and floats are accepted.
     *
     * @param  list<ConfigProblem>  $problems
     */
    private static function integer(mixed $value, int $default, int $min, int $max, string $path, array &$problems): int
    {
        if ($value === null) {
            return $default;
        }

        $integer = match (true) {
            is_int($value) => $value,
            is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 2 ** 53 => (int) $value,
            is_string($value) && preg_match('/^-?\d+$/', trim($value)) === 1 => filter_var(trim($value), FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
            default => null,
        };

        if ($integer === null || $integer < $min || $integer > $max) {
            $problems[] = new ConfigProblem($path, $max === PHP_INT_MAX
                ? sprintf('%s must be an integer >= %d; using %d.', $path, $min, $default)
                : sprintf('%s must be an integer in [%d, %d]; using %d.', $path, $min, $max, $default));

            return $default;
        }

        return $integer;
    }

    /**
     * @param  list<ConfigProblem>  $problems
     * @return array{horizon: bool, queues: bool, attempts: bool, job_classes: bool, batches: bool|null}
     */
    private static function collectors(mixed $value, array &$problems): array
    {
        $collectors = ['horizon' => true, 'queues' => true, 'attempts' => true, 'job_classes' => true, 'batches' => null];

        if ($value === null) {
            return $collectors;
        }

        if (! is_array($value)) {
            $problems[] = new ConfigProblem('collectors', 'collectors must be an array; using the defaults.');

            return $collectors;
        }

        foreach ($value as $id => $enabled) {
            $id = (string) $id;

            if (! array_key_exists($id, $collectors)) {
                $problems[] = new ConfigProblem('collectors.'.$id, sprintf('Unknown collector [%s] ignored; known: %s.', $id, implode(', ', self::COLLECTORS)));

                continue;
            }

            if ($id !== 'batches') {
                $collectors[$id] = self::boolean($enabled, true, 'collectors.'.$id, $problems);

                continue;
            }

            if ($enabled === null || (is_string($enabled) && in_array(strtolower(trim($enabled)), ['auto', 'null'], true))) {
                $collectors['batches'] = null;

                continue;
            }

            $bool = is_bool($enabled) ? $enabled : (is_string($enabled) || is_int($enabled) ? filter_var($enabled, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : null);

            if ($bool === null) {
                $problems[] = new ConfigProblem('collectors.batches', 'collectors.batches must be true, false or null (auto); using null.');
            }

            $collectors['batches'] = $bool;
        }

        return $collectors;
    }

    /**
     * @param  list<ConfigProblem>  $problems
     * @return list<float>
     */
    private static function buckets(mixed $value, array &$problems): array
    {
        if ($value === null) {
            return self::DEFAULT_BUCKETS;
        }

        if (! is_array($value) || $value === []) {
            $problems[] = new ConfigProblem('buckets', 'buckets must be a non-empty list of positive numbers; using the defaults.');

            return self::DEFAULT_BUCKETS;
        }

        $buckets = [];
        $invalid = 0;

        foreach ($value as $bound) {
            $number = is_int($bound) || is_float($bound) || (is_string($bound) && is_numeric($bound)) ? (float) $bound : null;

            if ($number === null || ! is_finite($number) || $number <= 0) {
                $invalid++;

                continue;
            }

            $buckets[] = $number;
        }

        if ($buckets === []) {
            $problems[] = new ConfigProblem('buckets', 'buckets must be a non-empty list of positive numbers; using the defaults.');

            return self::DEFAULT_BUCKETS;
        }

        if ($invalid > 0) {
            $problems[] = new ConfigProblem('buckets', sprintf('buckets: %d entries that are not finite positive numbers were ignored.', $invalid));
        }

        $normalized = array_values(array_unique($buckets, SORT_NUMERIC));
        sort($normalized, SORT_NUMERIC);

        if ($normalized !== $buckets) {
            $problems[] = new ConfigProblem('buckets', 'buckets were not ascending and unique; they were sorted and deduplicated.');
        }

        return $normalized;
    }

    /**
     * @param  list<ConfigProblem>  $problems
     * @return list<string>
     */
    private static function jobClassPaths(mixed $value, array &$problems): array
    {
        if ($value === null) {
            try {
                return [app_path()];
            } catch (Throwable) {
                return [];
            }
        }

        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            $problems[] = new ConfigProblem('job_class_paths', 'job_class_paths must be a list of directories or null; using none.');

            return [];
        }

        $paths = [];

        foreach ($value as $path) {
            if (! is_string($path) || ! is_dir($path)) {
                $problems[] = new ConfigProblem('job_class_paths', sprintf(
                    'job_class_paths: %s is not an existing directory and was ignored.',
                    is_string($path) ? '['.$path.']' : 'a non-string entry',
                ));

                continue;
            }

            $paths[] = $path;
        }

        return array_values(array_unique($paths));
    }

    private static function isHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true) && is_string($host) && $host !== '';
    }

    /**
     * @param  list<ConfigProblem>  $problems
     */
    private static function url(mixed $value, string $path, array &$problems): ?string
    {
        $url = self::optionalString($value, $path, $problems);

        if ($url !== null && ! self::isHttpUrl($url)) {
            $problems[] = new ConfigProblem($path, sprintf('%s must be an http(s) URL or null; using null.', $path));

            return null;
        }

        return $url;
    }

    /**
     * @param  list<ConfigProblem>  $problems
     */
    private static function ttsUrl(mixed $value, array &$problems): ?string
    {
        $url = self::optionalString($value, 'grafana.tts_url', $problems);

        if ($url === null) {
            return null;
        }

        if (strtolower($url) === 'browser') {
            return 'browser';
        }

        if (! self::isHttpUrl($url)) {
            $problems[] = new ConfigProblem('grafana.tts_url', 'grafana.tts_url must be null, "browser" or an http(s) URL; using null (tone only).');

            return null;
        }

        return $url;
    }

    /**
     * @param  list<ConfigProblem>  $problems
     * @return list<string>
     */
    private static function urlList(mixed $value, string $path, array &$problems): array
    {
        $entries = self::stringList($value, $path, $problems) ?? [];
        $valid = [];

        foreach ($entries as $entry) {
            if (self::isHttpUrl($entry)) {
                $valid[] = rtrim($entry, '/');
            }
        }

        if (count($valid) !== count($entries)) {
            $problems[] = new ConfigProblem($path, sprintf('%s: %d entries that are not http(s) URLs were ignored.', $path, count($entries) - count($valid)));
        }

        return array_values(array_unique($valid));
    }

    /**
     * @param  list<ConfigProblem>  $problems
     */
    private static function secret(mixed $value, string $path, array &$problems): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            $problems[] = new ConfigProblem($path, sprintf('%s must be a string or null; it is treated as missing.', $path));

            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  list<ConfigProblem>  $problems
     */
    private static function streamId(mixed $value, array &$problems): string
    {
        if ($value === null) {
            return self::DEFAULT_LIVE_STREAM_ID;
        }

        if (! is_string($value) || preg_match(self::STREAM_ID_PATTERN, $value) !== 1) {
            $problems[] = new ConfigProblem('live.stream.id', sprintf('live.stream.id must match [A-Za-z0-9_-]{1,64}; using "%s".', self::DEFAULT_LIVE_STREAM_ID));

            return self::DEFAULT_LIVE_STREAM_ID;
        }

        return $value;
    }

    /**
     * @param  list<ConfigProblem>  $problems
     */
    private static function logChannel(mixed $value, Repository $config, array &$problems): ?string
    {
        $channel = self::optionalString($value, 'log_channel', $problems);

        if ($channel !== null && ! is_array($config->get('logging.channels.'.$channel))) {
            $problems[] = new ConfigProblem('log_channel', sprintf('Log channel [%s] is not configured in logging.channels; using the default channel.', $channel));

            return null;
        }

        return $channel;
    }

    private static function number(float $value): string
    {
        return floor($value) === $value ? number_format($value, 1, '.', '') : (string) $value;
    }
}
