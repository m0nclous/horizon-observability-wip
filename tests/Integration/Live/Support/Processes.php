<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Integration\Live\Support;

use Closure;
use DF\HorizonObservability\Redis\RedisClients;
use Illuminate\Container\Container;
use Illuminate\Redis\RedisManager;
use RuntimeException;
use Throwable;

/**
 * Forked worker processes for the concurrency tests (several PHP workers or replicas on one Redis): each child runs
 * a closure with its own Redis sockets and ends with SIGKILL, so no shutdown function, destructor or PHPUnit
 * teardown runs in it (the parent's test still owns the keys). Children report through Redis.
 */
final class Processes
{
    /**
     * @param  Closure(int): void  $work  receives the child index
     * @return list<int> the child pids
     */
    public static function fork(int $count, Closure $work): array
    {
        if (! function_exists('pcntl_fork')) {
            throw new RuntimeException('The concurrency tests need the pcntl extension.');
        }

        // No socket may be shared with a child: everyone reconnects on first use.
        self::purgeConnections();
        $pids = [];

        for ($index = 0; $index < $count; $index++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('pcntl_fork() failed.');
            }

            if ($pid === 0) {
                try {
                    $work($index);
                } catch (Throwable $e) {
                    fwrite(STDERR, sprintf("child %d: %s: %s\n", $index, $e::class, $e->getMessage()));
                }

                posix_kill(posix_getpid(), SIGKILL);

                exit(1); // not reached
            }

            $pids[] = $pid;
        }

        return $pids;
    }

    /**
     * Wait for the children; the ones still running after the timeout are killed.
     *
     * @param  list<int>  $pids
     */
    public static function wait(array $pids, float $timeoutSeconds = 30.0): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $running = array_fill_keys($pids, true);

        while ($running !== [] && microtime(true) < $deadline) {
            foreach (array_keys($running) as $pid) {
                if (pcntl_waitpid($pid, $status, WNOHANG) !== 0) {
                    unset($running[$pid]);
                }
            }

            usleep(10000);
        }

        foreach (array_keys($running) as $pid) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }

        self::purgeConnections();
    }

    /**
     * Drop every open connection of the app's and the package's Redis managers.
     */
    public static function purgeConnections(): void
    {
        $container = Container::getInstance();
        $managers = [$container->make('redis')];

        if ($container->resolved(RedisClients::class)) {
            $managers[] = $container->make(RedisClients::class)->manager();
        }

        foreach ($managers as $manager) {
            if ($manager instanceof RedisManager) {
                foreach (array_keys($manager->connections() ?? []) as $name) {
                    $manager->purge((string) $name);
                }
            }
        }
    }
}
