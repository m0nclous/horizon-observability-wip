<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Support;

use DF\HorizonObservability\Redis\RedisClients;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\RedisManager;

/**
 * Counts Redis commands on the app's manager and on the package's private manager (`RedisClients::manager()`),
 * which bypasses `Redis::listen()`. Connections resolved before {@see self::start()} are purged, so they reconnect
 * with events.
 */
final class RedisCommands
{
    /**
     * @var list<array{connection: string, command: string, parameters: array<array-key, mixed>}>
     */
    private array $commands = [];

    private bool $recording = true;

    public static function start(Application $app): self
    {
        $recorder = new self;

        /** @var RedisManager $appManager */
        $appManager = $app->make('redis');

        foreach ([$appManager, $app->make(RedisClients::class)->manager()] as $manager) {
            $manager->enableEvents();

            foreach (array_keys($manager->connections() ?? []) as $name) {
                $manager->purge((string) $name);
            }
        }

        /** @var Dispatcher $events */
        $events = $app->make('events');
        $events->listen(CommandExecuted::class, static function (CommandExecuted $event) use ($recorder): void {
            if ($recorder->recording) {
                $recorder->commands[] = [
                    'connection' => (string) $event->connectionName,
                    'command' => strtoupper($event->command),
                    'parameters' => $event->parameters,
                ];
            }
        });

        return $recorder;
    }

    public function stop(): self
    {
        $this->recording = false;

        return $this;
    }

    public function reset(): self
    {
        $this->commands = [];

        return $this;
    }

    /**
     * Commands recorded, optionally of one connection (e.g. `default`, `horizon`,
     * `horizon-observability:storage`).
     */
    public function count(?string $connection = null): int
    {
        return count($this->commands($connection));
    }

    /**
     * @return list<array{connection: string, command: string, parameters: array<array-key, mixed>}>
     */
    public function commands(?string $connection = null): array
    {
        return array_values(array_filter(
            $this->commands,
            static fn (array $command): bool => $connection === null || $command['connection'] === $connection,
        ));
    }

    /**
     * Upper-case command names in order, e.g. `['EVAL', 'HGETALL']`.
     *
     * @return list<string>
     */
    public function names(?string $connection = null): array
    {
        return array_map(static fn (array $command): string => $command['command'], $this->commands($connection));
    }
}
