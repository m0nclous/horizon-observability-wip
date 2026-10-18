<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Truth\Support;

use LogicException;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * A private Redis server the truth tests may restart (`ResetTruthTest`): never the shared 127.0.0.1:6379.
 *
 * It runs a `redis-server` binary when there is one, or the Redis image of `TRUTH_REDIS_IMAGE` (default
 * `redis:7.4-alpine`; CI passes the server image of its leg) in Docker. `TRUTH_REDIS_IMAGE` also selects Docker when
 * a binary exists. Without either, {@see self::unavailable()} says why; on CI (`CI` set) the caller fails instead of
 * skipping, so the real restart is part of every CI leg.
 *
 * - {@see self::stop()} with `save` keeps the dataset (`SAVE`, then `SHUTDOWN NOSAVE`), so the next {@see self::start()}
 *   loads it under a new run id, like a restore of an older snapshot; without `save` the dataset is gone.
 * - Persistence is off while it runs (`--save ''`, `--appendonly no`).
 */
final class PrivateRedis
{
    public const DEFAULT_IMAGE = 'redis:7.4-alpine';

    public readonly int $port;

    private ?Process $process = null;

    private bool $created = false;

    private function __construct(
        private readonly ?string $binary,
        private readonly ?string $image,
        private readonly string $name,
        private readonly string $directory,
    ) {
        $this->port = self::freePort();
    }

    /**
     * A server on a free port (not started), or null when neither a binary nor Docker is available.
     */
    public static function create(): ?self
    {
        $image = getenv('TRUTH_REDIS_IMAGE');
        $image = is_string($image) && $image !== '' ? $image : null;
        $binary = $image === null ? (new ExecutableFinder)->find('redis-server') : null;
        $name = 'ho-truth-redis-'.getmypid().'-'.bin2hex(random_bytes(3));

        if ($binary !== null) {
            $directory = sys_get_temp_dir().'/'.$name;
            mkdir($directory);

            return new self($binary, null, $name, $directory);
        }

        if (! self::dockerAvailable()) {
            return null;
        }

        return new self(null, $image ?? self::DEFAULT_IMAGE, $name, '');
    }

    /**
     * Why there is no private server.
     */
    public static function unavailable(): string
    {
        return 'No redis-server binary and no Docker to run a private Redis'
            .(is_string(getenv('TRUTH_REDIS_IMAGE')) && getenv('TRUTH_REDIS_IMAGE') !== '' ? ' (TRUTH_REDIS_IMAGE='.getenv('TRUTH_REDIS_IMAGE').')' : '').'.';
    }

    /**
     * True on a CI runner, where a missing private server is a failure, not a skip.
     */
    public static function onCi(): bool
    {
        $ci = getenv('CI');

        return is_string($ci) && $ci !== '' && $ci !== '0' && strtolower($ci) !== 'false';
    }

    /**
     * What runs the server, e.g. `redis-server` or `docker redis:6.2-alpine`.
     */
    public function describe(): string
    {
        return $this->image === null ? (string) $this->binary : 'docker '.$this->image;
    }

    /**
     * Start the server (again) and wait until it answers PING, i.e. it has loaded its dataset.
     */
    public function start(): void
    {
        if ($this->binary !== null) {
            $this->process = new Process([$this->binary, '--port', (string) $this->port, '--dir', $this->directory, '--save', '', '--appendonly', 'no']);
            $this->process->start();
        } elseif ($this->created) {
            // The stopped container keeps its /data volume, and so a saved dump.rdb.
            self::docker(['start', $this->name]);
        } else {
            self::docker(['run', '-d', '--name', $this->name, '-p', '127.0.0.1:'.$this->port.':6379', (string) $this->image, 'redis-server', '--save', '', '--appendonly', 'no']);
            $this->created = true;
        }

        $deadline = microtime(true) + 30;

        while (! $this->answers()) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException(sprintf('The private Redis (%s) did not start: %s', $this->describe(), $this->process?->getErrorOutput() ?? $this->logs()));
            }

            usleep(50_000);
        }
    }

    /**
     * Shut the server down; with `$save` its dataset is loaded by the next start, otherwise it is gone.
     */
    public function stop(bool $save): void
    {
        $connection = fsockopen('127.0.0.1', $this->port, $code, $message, 5.0);

        if ($connection === false) {
            throw new RuntimeException(sprintf('Cannot reach the private Redis: %s', $message));
        }

        stream_set_timeout($connection, 30);

        if ($save) {
            fwrite($connection, "*1\r\n$4\r\nSAVE\r\n");

            if (! str_starts_with((string) fgets($connection), '+OK')) {
                throw new RuntimeException('SAVE failed on the private Redis.');
            }
        }

        fwrite($connection, "*2\r\n$8\r\nSHUTDOWN\r\n$6\r\nNOSAVE\r\n");
        fclose($connection);

        if ($this->process instanceof Process) {
            $this->process->wait();
        } else {
            self::docker(['wait', $this->name]);
        }

        if (! $save) {
            $this->forgetDataset();
        }
    }

    /**
     * Stop the server and remove everything it left.
     */
    public function close(): void
    {
        if ($this->process instanceof Process && $this->process->isRunning()) {
            $this->process->stop(0);
        }

        if ($this->created) {
            self::docker(['rm', '-f', '-v', $this->name], check: false);
            $this->created = false;
        }

        if ($this->directory !== '' && is_dir($this->directory)) {
            array_map(unlink(...), glob($this->directory.'/*') ?: []);
            rmdir($this->directory);
        }
    }

    private function forgetDataset(): void
    {
        if ($this->directory !== '') {
            @unlink($this->directory.'/dump.rdb');
        } else {
            // A new container with an empty volume on the same port.
            self::docker(['rm', '-v', $this->name]);
            $this->created = false;
        }
    }

    /**
     * PONG, not LOADING: a connection alone is not enough, Docker's proxy accepts before Redis listens.
     */
    private function answers(): bool
    {
        $connection = @fsockopen('127.0.0.1', $this->port, $code, $message, 1.0);

        if ($connection === false) {
            return false;
        }

        stream_set_timeout($connection, 1);
        fwrite($connection, "*1\r\n$4\r\nPING\r\n");
        $reply = fgets($connection);
        fclose($connection);

        return is_string($reply) && str_starts_with($reply, '+PONG');
    }

    private function logs(): string
    {
        return $this->created ? self::docker(['logs', $this->name], check: false) : '';
    }

    private static function dockerAvailable(): bool
    {
        if ((new ExecutableFinder)->find('docker') === null) {
            return false;
        }

        $process = new Process(['docker', 'version', '--format', '{{.Server.Version}}']);
        $process->setTimeout(30)->run();

        return $process->isSuccessful();
    }

    /**
     * @param  list<string>  $arguments
     */
    private static function docker(array $arguments, bool $check = true): string
    {
        $process = new Process(['docker', ...$arguments]);
        $process->setTimeout(300)->run();

        if ($check && ! $process->isSuccessful()) {
            throw new RuntimeException(sprintf('docker %s failed: %s', implode(' ', $arguments), $process->getErrorOutput()));
        }

        return $process->getOutput().$process->getErrorOutput();
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');

        if ($socket === false) {
            throw new LogicException('No free port for the private Redis.');
        }

        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    }
}
