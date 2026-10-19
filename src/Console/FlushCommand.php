<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Console;

use Closure;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Support\FailureReporter;
use Generator;
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
 * PUBLIC `horizon-observability:flush`: deletes every package storage key, and
 * nothing else: the counters, histograms, extremes, `job_classes`, `batch_links`, `meta`, `ready_at:*` and `live:*`,
 * found with `SCAN MATCH <connection prefix>{horizon-observability}:v1:*` on the storage connection (on a Redis
 * Cluster on every master; the keys share one slot).
 *
 * It asks for confirmation (`--force` skips it); in production it refuses without `--force`. It is never hooked into
 * `optimize:clear`. Prometheus reads the drop of the counters as a counter reset, so `increase()` stays correct.
 *
 * Bounded memory whatever the number of keys (a legacy stack keeps a `ready_at` key per delayed job): the keys are
 * counted, and deleted, one `SCAN` page at a time, and only the counts per kind are kept. The count before the
 * confirmation can include a key twice when Redis rehashes during the `SCAN`; the deleted count is the sum of the
 * `DEL` replies, so it is exact.
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
        $root = $keys->root();

        try {
            $connection = $clients->reader($name);
            $prefix = $this->prefix($connection);
            // A lazy client (predis) connects here, so an unreachable server is reported as such.
            $connection->command('exists', [$keys->meta()]);
            // With --force there is nothing to ask: delete in the first pass.
            $found = $force ? null : $this->count($connection, $prefix, $root);
        } catch (Throwable $e) {
            $this->components->error(sprintf('The storage connection %s cannot be read: %s', $name, FailureReporter::describe($e)));

            return self::FAILURE;
        }

        if ($found !== null) {
            if ($found === []) {
                $this->components->info(sprintf('No package keys on the Redis connection %s (prefix "%s").', $name, $prefix));

                return self::SUCCESS;
            }

            $summary = $this->summary($found);

            if (! $this->input->isInteractive()) {
                $this->components->info(sprintf('Found %d package keys (%s) on the Redis connection %s; run with --force to delete them.', array_sum($found), $summary, $name));

                return self::SUCCESS;
            }

            if (! $this->confirm(sprintf('Delete %d package keys (%s) from the Redis connection %s?', array_sum($found), $summary, $name), false)) {
                $this->components->info('Nothing was deleted.');

                return self::SUCCESS;
            }
        }

        $deleted = [];

        try {
            $this->delete($connection, $prefix, $root, $clients->isCluster($name), $deleted);
        } catch (Throwable $e) {
            $this->components->error(sprintf(
                'Deleting the package keys failed%s: %s',
                $deleted === [] ? '' : sprintf(' after %d of them (%s)', array_sum($deleted), $this->summary($deleted)),
                FailureReporter::describe($e),
            ));

            return self::FAILURE;
        }

        if ($deleted === []) {
            $this->components->info(sprintf('No package keys on the Redis connection %s (prefix "%s").', $name, $prefix));

            return self::SUCCESS;
        }

        $this->components->info(sprintf(
            'Deleted %d package keys (%s) from the Redis connection %s. Prometheus reads the drop of the counters as a counter reset.',
            array_sum($deleted),
            $this->summary($deleted),
            $name,
        ));

        return self::SUCCESS;
    }

    /**
     * The package keys per kind, counted page by page.
     *
     * @return array<string, int> kind => keys
     */
    private function count(Connection $connection, string $prefix, string $root): array
    {
        $kinds = [];

        foreach ($this->pages($connection, $prefix, $root) as $names) {
            foreach ($names as $name) {
                $kind = $this->kind($name, $root);
                $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
            }
        }

        return $kinds;
    }

    /**
     * Delete the package keys page by page, adding the keys deleted per kind (from the `DEL` replies) to `$deleted`,
     * which also holds the progress when a page fails.
     *
     * @param  array<string, int>  $deleted  kind => keys
     */
    private function delete(Connection $connection, string $prefix, string $root, bool $cluster, array &$deleted): void
    {
        foreach ($this->pages($connection, $prefix, $root) as $names) {
            $byKind = [];

            foreach ($names as $name) {
                $byKind[$this->kind($name, $root)][] = $name;
            }

            foreach ($byKind as $kind => $group) {
                // On a cluster one key per call: a prefix with its own hash tag could still spread them over several slots.
                foreach (array_chunk($group, $cluster ? 1 : self::BATCH) as $chunk) {
                    // The names are without the prefix, which the client adds.
                    $result = $connection->command('del', $chunk);
                    $count = is_numeric($result) ? (int) $result : 0;

                    if ($count > 0) {
                        $deleted[$kind] = ($deleted[$kind] ?? 0) + $count;
                    }
                }
            }
        }
    }

    /**
     * The package key names (without the connection prefix) of every node, one `SCAN` page at a time; the client
     * applies no prefix to a raw `SCAN`, so the pattern carries it. Deleting the keys of a page before the next one is
     * safe: `SCAN` still returns every key that exists for the whole iteration.
     *
     * @return Generator<int, list<string>>
     */
    private function pages(Connection $connection, string $prefix, string $root): Generator
    {
        $client = $connection->client();
        $arguments = ['MATCH', addcslashes($prefix.$root, '*?[]\\').'*', 'COUNT', (string) self::BATCH];

        if ($client instanceof RedisCluster) {
            foreach ($client->_masters() as $master) {
                yield from $this->node(static fn (string $cursor): mixed => $client->rawCommand($master, 'SCAN', $cursor, ...$arguments), $prefix.$root, strlen($prefix));
            }
        } elseif ($client instanceof Redis) {
            yield from $this->node(static fn (string $cursor): mixed => $client->rawCommand('SCAN', $cursor, ...$arguments), $prefix.$root, strlen($prefix));
        } elseif ($client instanceof PredisClient) {
            // A client per node: one for a single server, one per master of a cluster.
            foreach ($client as $node) {
                yield from $this->node(static fn (string $cursor): mixed => $node->executeRaw(['SCAN', $cursor, ...$arguments]), $prefix.$root, strlen($prefix));
            }
        } else {
            throw new RuntimeException(sprintf('the Redis client %s is not supported.', get_debug_type($client)));
        }
    }

    /**
     * The pages of one node's `SCAN`: the names starting with `$start`, without the first `$strip` bytes.
     *
     * @param  Closure(string): mixed  $scan  cursor => raw reply
     * @return Generator<int, list<string>>
     */
    private function node(Closure $scan, string $start, int $strip): Generator
    {
        $cursor = '0';

        do {
            $reply = $scan($cursor);

            if (! is_array($reply) || count($reply) !== 2) {
                throw new UnexpectedValueException('SCAN returned '.(is_string($reply) ? $reply : get_debug_type($reply)).'.');
            }

            [$next, $batch] = array_values($reply);
            $cursor = is_scalar($next) ? (string) $next : '0';
            $names = [];

            foreach (is_array($batch) ? $batch : [] as $key) {
                if (is_string($key) && str_starts_with($key, $start)) {
                    $names[substr($key, $strip)] = true;
                }
            }

            if ($names !== []) {
                yield array_map(strval(...), array_keys($names));
            }
        } while ($cursor !== '0');
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
     * The kind of a package key: the first segment after the root, e.g. `ready_at` or `live`.
     */
    private function kind(string $name, string $root): string
    {
        return explode(':', substr($name, strlen($root)), 2)[0];
    }

    /**
     * Counts per key kind, e.g. `attempts 1, live 3, ready_at 12`.
     *
     * @param  array<string, int>  $kinds
     */
    private function summary(array $kinds): string
    {
        ksort($kinds);

        return implode(', ', array_map(static fn (string $kind, int $count): string => $kind.' '.$count, array_keys($kinds), $kinds));
    }
}
