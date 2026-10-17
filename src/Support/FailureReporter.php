<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Support;

use Closure;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Config\ConfigProblem;
use Illuminate\Cache\NullStore;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Log\LogManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Throttled logging of the package's failures and warnings (Arch Q1.3, Q6.6, C24).
 *
 * - A key logs at most once per {@see self::THROTTLE_SECONDS} per process, and across processes through
 *   `Cache::add()` on the default store. If the cache itself fails, or keeps nothing (the `null` store, whose
 *   `add()` always fails), only the in-process throttle applies.
 * - Messages are English and start with `horizon-observability:`; exceptions go into the context as objects.
 * - It never calls `report()`, so nothing reaches Sentry or the exception handler, and it never throws.
 * - The channel is `log_channel` (default channel when null); the logger and the config are resolved per call,
 *   so the reporter is safe to construct at boot.
 *
 * @internal
 */
final class FailureReporter
{
    public const THROTTLE_SECONDS = 300;

    public const PREFIX = 'horizon-observability: ';

    /**
     * @var Closure(): float
     */
    private readonly Closure $clock;

    /**
     * @var array<string, float> key => monotonic seconds of the last line
     */
    private array $logged = [];

    /**
     * @param  (Closure(): float)|null  $clock  monotonic seconds, for tests; default `hrtime()`
     */
    public function __construct(private readonly Container $container, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    /**
     * Log a failure (warning level), throttled per key.
     *
     * @param  array<string, mixed>  $context
     */
    public function report(string $key, Throwable $e, array $context = []): void
    {
        if ($this->throttled($key)) {
            return;
        }

        $this->log('warning', sprintf('%s%s failed: %s', self::PREFIX, $key, self::describe($e)), ['exception' => $e, ...$context]);
    }

    /**
     * Log a warning, throttled per key (once per process, and once per throttle window across processes).
     *
     * @param  array<string, mixed>  $context
     */
    public function warnOnce(string $key, string $message, array $context = []): void
    {
        if ($this->throttled($key)) {
            return;
        }

        $this->log('warning', $this->prefixed($message), $context);
    }

    /**
     * The `config.problems` warning (throttled like {@see self::warnOnce()}), when the snapshot has problems.
     *
     * It costs a cache round trip, so it runs where that is acceptable: the console (commands and workers) and an
     * authorised scrape, never on the path of a request that may be denied (Arch Q2.4).
     */
    public function configProblems(Config $config): void
    {
        if ($config->problems === []) {
            return;
        }

        $this->warnOnce('config.problems', sprintf(
            '%d invalid configuration values were replaced by defaults; run php artisan horizon-observability:check.',
            count($config->problems),
        ), ['problems' => array_map(static fn (ConfigProblem $problem): string => $problem->path.': '.$problem->message, $config->problems)]);
    }

    /**
     * Log an info line without throttling; for rare events such as a recovery after an outage.
     *
     * @param  array<string, mixed>  $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $this->prefixed($message), $context);
    }

    /**
     * `ShortClass: first line of the message`, at most 500 bytes.
     */
    public static function describe(Throwable $e): string
    {
        $class = $e::class;
        $short = str_contains($class, '@anonymous') ? 'class@anonymous' : substr((string) strrchr('\\'.$class, '\\'), 1);
        $message = strtok(trim($e->getMessage()), "\r\n");
        $text = $message === false ? $short : $short.': '.$message;

        return strlen($text) > 500 ? mb_strcut($text, 0, 497, 'UTF-8').'...' : $text;
    }

    private function prefixed(string $message): string
    {
        return str_starts_with($message, self::PREFIX) ? $message : self::PREFIX.$message;
    }

    private function throttled(string $key): bool
    {
        $now = ($this->clock)();

        if (isset($this->logged[$key]) && $now - $this->logged[$key] < self::THROTTLE_SECONDS) {
            return true;
        }

        $this->logged[$key] = $now;

        try {
            /** @var Factory $cache */
            $cache = $this->container->make('cache');
            $store = $cache->store();

            // The null store's add() always fails: every key would count as throttled and nothing would be logged.
            if ($store->getStore() instanceof NullStore) {
                return false;
            }

            return ! $store->add('horizon-observability:log:'.sha1($key), 1, self::THROTTLE_SECONDS);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $level, string $message, array $context): void
    {
        try {
            $channel = null;

            try {
                $channel = $this->container->make(Config::class)->logChannel;
            } catch (Throwable) {
                // An unusable config must not stop the log line.
            }

            /** @var LogManager $manager */
            $manager = $this->container->make('log');
            $logger = $channel === null ? $manager : $manager->channel($channel);

            if ($logger instanceof LoggerInterface) {
                $logger->log($level, $message, $context);
            }
        } catch (Throwable) {
            // Logging must never break a request, a scrape or a worker.
        }
    }
}
