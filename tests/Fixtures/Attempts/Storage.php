<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Fixtures\Attempts;

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Attempts\AttemptStatus;
use DF\HorizonObservability\Attempts\AttemptStore;
use DF\HorizonObservability\Collectors\AttemptsCollector;
use DF\HorizonObservability\Metrics\MetricFamily;
use DF\HorizonObservability\Metrics\ScrapeContext;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\ServerClock;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Container\Container;
use Throwable;

/**
 * Test access to the attempt storage: readable hashes (`\x1f` shown as `|`), records with defaults, and the
 * attempts collector's samples.
 *
 * The storage Redis' clock ({@see ServerClock}), on which the new-series hold runs: {@see self::record()} and
 * {@see self::scrape()} pin it for their call to `$server`, or to `$now` when only that is given (the worker or the
 * exporter in sync with Redis), and restore it after; with neither it stays as it is (the server's `TIME` unless a
 * test pinned it).
 */
final class Storage
{
    public static function store(): AttemptStore
    {
        return Container::getInstance()->make(AttemptStore::class);
    }

    public static function keys(): Keys
    {
        return Container::getInstance()->make(Keys::class);
    }

    /**
     * A storage hash, fields with `|` instead of `\x1f`, sorted by field.
     *
     * @return array<string, string>
     */
    public static function hash(string $key): array
    {
        $raw = self::store()->reader()->hgetall($key);
        $hash = [];

        foreach (is_array($raw) ? $raw : [] as $field => $value) {
            $hash[str_replace(Keys::SEPARATOR, '|', (string) $field)] = (string) $value;
        }

        ksort($hash, SORT_STRING);

        return $hash;
    }

    /**
     * Attempts of one class per status (all connections and queues), sorted by status.
     *
     * @return array<string, int>
     */
    public static function statuses(string $jobClass): array
    {
        $counts = [];

        foreach (self::hash(self::keys()->attempts()) as $field => $count) {
            $parts = explode('|', $field);

            if (($parts[2] ?? null) === $jobClass) {
                $counts[$parts[3]] = ($counts[$parts[3]] ?? 0) + (int) $count;
            }
        }

        ksort($counts);

        return $counts;
    }

    /**
     * Observations of the duration histogram of one class (all connections and queues).
     */
    public static function durations(string $jobClass): int
    {
        $count = 0;

        foreach (self::hash(self::keys()->durationBuckets()) as $field => $value) {
            if ((explode('|', $field)[2] ?? null) === $jobClass) {
                $count += (int) $value;
            }
        }

        return $count;
    }

    /**
     * Observations of the wait histogram (all connections and queues).
     */
    public static function waits(): int
    {
        return array_sum(array_map(intval(...), self::hash(self::keys()->waitBuckets())));
    }

    /**
     * The package keys (under the storage hash tag) of this test process, with their full names.
     *
     * @return list<string>
     */
    public static function packageKeys(): array
    {
        return array_values(array_filter(
            RedisKeys::all(TestCase::redisPrefix()),
            static fn (string $key): bool => str_contains($key, '{horizon-observability}'),
        ));
    }

    /**
     * The number of keys of each recorded `EVAL` (phpredis records `[script, args, numKeys]`, predis
     * `[script, numKeys, ...args]`).
     *
     * @param  list<array{connection: string, command: string, parameters: array<array-key, mixed>}>  $commands
     * @return list<int>
     */
    public static function evalKeyCounts(array $commands): array
    {
        $counts = [];

        foreach ($commands as $command) {
            if ($command['command'] === 'EVAL') {
                $parameters = array_values($command['parameters']);
                $counts[] = (int) (is_array($parameters[1] ?? null) ? $parameters[2] : $parameters[1]);
            }
        }

        return $counts;
    }

    /**
     * Seconds to live of a storage key (-2 missing, -1 no TTL).
     */
    public static function ttl(string $key): int
    {
        return (int) self::store()->reader()->ttl($key);
    }

    public static function exists(string $key): bool
    {
        return (int) self::store()->reader()->exists($key) > 0;
    }

    public static function clock(): ServerClock
    {
        return Container::getInstance()->make(ServerClock::class);
    }

    /**
     * Record one attempt with defaults.
     *
     * @param  CarbonImmutable|null  $now  the worker's clock (the attempt end)
     * @param  float|null  $server  the storage Redis' clock during the record (Unix seconds); default `$now`
     */
    public static function record(
        string $jobClass = 'App\\Jobs\\SendInvoice',
        AttemptStatus $status = AttemptStatus::Completed,
        ?float $duration = 0.2,
        ?float $startedAt = null,
        ?float $readyAt = null,
        ?string $uuid = null,
        ?CarbonImmutable $now = null,
        string $connection = 'redis',
        string $queue = 'default',
        ?float $server = null,
    ): bool {
        return self::onServerClock($server ?? self::unix($now), static fn (): bool => self::store()->record($connection, $queue, $jobClass, $status, $duration, $startedAt, $readyAt, $uuid, $now ?? CarbonImmutable::now()));
    }

    /**
     * Run the attempts collector once.
     *
     * @param  CarbonImmutable|null  $now  the exporter's clock (the scrape's request time)
     * @param  float|null  $server  the storage Redis' clock during the scrape (Unix seconds); default `$now`
     * @return array{samples: array<string, array<string, int|float>>, failures: list<string>}
     */
    public static function scrape(?CarbonImmutable $now = null, ?float $server = null): array
    {
        $context = new ScrapeContext($now ?? CarbonImmutable::now(), 8.0);
        $families = self::onServerClock($server ?? self::unix($now), static fn (): array => [...Container::getInstance()->make(AttemptsCollector::class)->collect($context)]);

        return [
            'samples' => self::samples($families),
            'failures' => array_map(static fn (array $failure): string => $failure['source'].': '.self::message($failure['exception']), $context->takeFailures()),
        ];
    }

    /**
     * Run `$call` with the server clock pinned to `$server` (null: as it is), then restore its pin.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    public static function onServerClock(?float $server, callable $call): mixed
    {
        $clock = self::clock();
        $pinned = $clock->pinned();

        if ($server !== null) {
            $clock->pin($server);
        }

        try {
            return $call();
        } finally {
            $clock->pin($pinned);
        }
    }

    private static function unix(?CarbonImmutable $time): ?float
    {
        return $time === null ? null : (float) $time->format('U.u');
    }

    /**
     * Samples by series name (`<family><suffix>`) and `name=value,...` labels; empty families are left out.
     *
     * @param  iterable<MetricFamily>  $families
     * @return array<string, array<string, int|float>>
     */
    public static function samples(iterable $families): array
    {
        $samples = [];

        foreach ($families as $family) {
            foreach ($family->samples() as $sample) {
                $labels = implode(',', array_map(
                    static fn (string $name, string $value): string => $name.'='.$value,
                    array_keys($sample->labels),
                    $sample->labels,
                ));
                $samples[$family->metric()->value.$sample->suffix][$labels] = $sample->value;
            }
        }

        return $samples;
    }

    private static function message(Throwable $e): string
    {
        return $e->getMessage();
    }
}
