<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Console;

use Closure;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Support\FailureReporter;
use Illuminate\Console\Command;
use Illuminate\Redis\Connections\Connection;
use Predis\Client as PredisClient;
use Predis\Command\Processor\KeyPrefixProcessor;
use Redis;
use RedisCluster;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * PUBLIC `horizon-observability:flush` (Arch Q7, Appendix E, Addendum E'): deletes every package storage key, and
 * nothing else: the counters, histograms, extremes, `job_classes`, `batch_links`, `meta`, `ready_at:*` and `live:*`,
 * found with `SCAN MATCH <connection prefix>{horizon-observability}:v1:*` on the storage connection (on a Redis
 * Cluster on every master; the keys share one slot).
 *
 * It asks for confirmation (`--force` skips it); in production it refuses without `--force`. It is never hooked into
 * `optimize:clear`. Prometheus reads the drop of the counters as a counter reset, so `increase()` stays correct.
 */
final class FlushCommand extends Command
{
    /**
     * Keys per `SCAN` page and per `DEL` call.
     */
    public const BATCH = 500;

    /**
     * @var string
     */
    protected $signature = 'horizon-observability:flush
                            {--force : Run without confirmation, also in production}';

    /**
     * @var string
     */
    protected $description = 'Delete every package storage key (counters, histograms, links, live data)';

    public function handle(RedisClients $clients, Keys $keys): int
    {
        $force = $this->option('force') === true;

        if (! $force && $this->laravel->environment('production') === true) {
            $this->components->error('The application is in production: run horizon-observability:flush --force to delete the package keys.');

            return self::FAILURE;
        }

        $name = $clients->storageConnection();

        try {
            $connection = $clients->reader($name);
            $prefix = $this->prefix($connection);
            $found = $this->find($connection, $prefix, $keys->root());
        } catch (Throwable $e) {
            $this->components->error(sprintf('The storage connection %s cannot be read: %s', $name, FailureReporter::describe($e)));

            return self::FAILURE;
        }

        if ($found === []) {
            $this->components->info(sprintf('No package keys on the Redis connection %s (prefix "%s").', $name, $prefix));

            return self::SUCCESS;
        }

        $summary = $this->summary($found, $keys->root());
        $question = sprintf('Delete %d package keys (%s) from the Redis connection %s?', count($found), $summary, $name);

        if (! $force && ! $this->input->isInteractive()) {
            $this->components->info(sprintf('Found %d package keys (%s) on the Redis connection %s; run with --force to delete them.', count($found), $summary, $name));

            return self::SUCCESS;
        }

        if (! $force && ! $this->confirm($question, false)) {
            $this->components->info('Nothing was deleted.');

            return self::SUCCESS;
        }

        try {
            $deleted = $this->delete($connection, $found, $clients->isCluster($name));
        } catch (Throwable $e) {
            $this->components->error(sprintf('Deleting the package keys failed: %s', FailureReporter::describe($e)));

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Deleted %d package keys (%s) from the Redis connection %s. Prometheus reads the drop of the counters as a counter reset.',
            $deleted,
            $summary,
            $name,
        ));

        return self::SUCCESS;
    }

    /**
     * The package key names (without the connection prefix) on every node, sorted.
     *
     * @return list<string>
     */
    private function find(Connection $connection, string $prefix, string $root): array
    {
        $pattern = addcslashes($prefix.$root, '*?[]\\').'*';
        $names = [];

        foreach ($this->scan($connection, $pattern) as $key) {
            if (str_starts_with($key, $prefix.$root)) {
                $names[] = substr($key, strlen($prefix));
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * The raw keys matching the pattern on every node; the client applies no prefix to a raw `SCAN`.
     *
     * @return list<string>
     */
    private function scan(Connection $connection, string $pattern): array
    {
        $client = $connection->client();
        $arguments = ['MATCH', $pattern, 'COUNT', (string) self::BATCH];
        $keys = [];

        if ($client instanceof RedisCluster) {
            foreach ($client->_masters() as $master) {
                array_push($keys, ...$this->pages(static fn (string $cursor): mixed => $client->rawCommand($master, 'SCAN', $cursor, ...$arguments)));
            }
        } elseif ($client instanceof Redis) {
            $keys = $this->pages(static fn (string $cursor): mixed => $client->rawCommand('SCAN', $cursor, ...$arguments));
        } elseif ($client instanceof PredisClient) {
            // A client per node: one for a single server, one per master of a cluster.
            foreach ($client as $node) {
                array_push($keys, ...$this->pages(static fn (string $cursor): mixed => $node->executeRaw(['SCAN', $cursor, ...$arguments])));
            }
        } else {
            throw new RuntimeException(sprintf('the Redis client %s is not supported.', get_debug_type($client)));
        }

        return $keys;
    }

    /**
     * Every page of one node's `SCAN`.
     *
     * @param  Closure(string): mixed  $scan  cursor => raw reply
     * @return list<string>
     */
    private function pages(Closure $scan): array
    {
        $cursor = '0';
        $keys = [];

        do {
            $reply = $scan($cursor);

            if (! is_array($reply) || count($reply) !== 2) {
                throw new UnexpectedValueException('SCAN returned '.(is_string($reply) ? $reply : get_debug_type($reply)).'.');
            }

            [$next, $batch] = array_values($reply);
            $cursor = is_scalar($next) ? (string) $next : '0';

            foreach (is_array($batch) ? $batch : [] as $key) {
                if (is_string($key)) {
                    $keys[] = $key;
                }
            }
        } while ($cursor !== '0');

        return $keys;
    }

    /**
     * Delete the keys (names without the prefix, which the client adds); the number deleted.
     *
     * @param  list<string>  $names
     */
    private function delete(Connection $connection, array $names, bool $cluster): int
    {
        $deleted = 0;

        // On a cluster one key per call: a prefix with its own hash tag could still spread them over several slots.
        foreach (array_chunk($names, $cluster ? 1 : self::BATCH) as $chunk) {
            $result = $connection->command('del', $chunk);
            $deleted += is_numeric($result) ? (int) $result : 0;
        }

        return $deleted;
    }

    /**
     * The connection prefix the client puts before every key (phpredis `OPT_PREFIX`, predis `prefix`).
     */
    private function prefix(Connection $connection): string
    {
        $client = $connection->client();

        if ($client instanceof Redis || $client instanceof RedisCluster) {
            $prefix = $client->getOption(Redis::OPT_PREFIX);
        } elseif ($client instanceof PredisClient) {
            $processor = $client->getOptions()->prefix;
            $prefix = $processor instanceof KeyPrefixProcessor ? $processor->getPrefix() : '';
        } else {
            $prefix = '';
        }

        return is_string($prefix) ? $prefix : '';
    }

    /**
     * Counts per key kind, e.g. `attempts 1, live 3, ready_at 12`.
     *
     * @param  list<string>  $names
     */
    private function summary(array $names, string $root): string
    {
        $kinds = [];

        foreach ($names as $name) {
            $kind = explode(':', substr($name, strlen($root)), 2)[0];
            $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
        }

        ksort($kinds);

        return implode(', ', array_map(static fn (string $kind, int $count): string => $kind.' '.$count, array_keys($kinds), $kinds));
    }
}
