<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Server;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Redis\ServerClock;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Manifest;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The real workbench application over a kept Redis world, for the router (design section 6.5): booted per request
 * through testbench's own bootstrap (`TESTBENCH_WORKING_PATH`, testbench.yaml), with the world's Redis prefix, SQLite
 * database and token, `live.enabled`, `live.cache_ttl = 0` and Carbon (and the storage `ServerClock`) pinned at the
 * dataset's end V. It serves the real package routes with the default `horizon.path`, so `api_path` is the default
 * `/horizon/observability`.
 *
 * The active world is `<run dir>/active-world.json` (`{"dataset": ..., "manifest": ...}`), which bin/run switches
 * between datasets; every request and response is appended to `<run dir>/router.log` as one JSON line.
 */
final class PinnedApp
{
    public const ACTIVE = 'active-world.json';

    public const LOG = 'router.log';

    /**
     * Serve the current request of the PHP built-in server.
     */
    public static function serve(string $runDir, string $root): void
    {
        $active = json_decode((string) @file_get_contents($runDir.'/'.self::ACTIVE), true);

        if (! is_array($active) || ! is_string($active['manifest'] ?? null)) {
            http_response_code(503);
            echo 'No active world in '.$runDir.'/'.self::ACTIVE;

            return;
        }

        $manifest = Manifest::read($active['manifest']);
        $app = self::boot($manifest, $root);
        $request = Request::capture();
        $kernel = $app->make(Kernel::class);
        $response = $kernel->handle($request);
        $body = (string) $response->getContent();

        self::log($runDir, [
            'dataset' => $manifest->dataset,
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'query' => $request->query->all(),
            'bearer' => str_starts_with((string) $request->header('Authorization'), 'Bearer '),
            'status' => $response->getStatusCode(),
            'sha1' => sha1($body),
            'bytes' => strlen($body),
        ]);

        $response->send();
        $kernel->terminate($request, $response);
    }

    /**
     * The workbench application on the world of a manifest, its clock pinned at V.
     */
    public static function boot(Manifest $manifest, string $root): Application
    {
        foreach ([
            'APP_ENV' => 'local',
            'REDIS_PREFIX' => $manifest->prefix,
            'HORIZON_PREFIX' => $manifest->prefix.'horizon:',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $manifest->sqlite,
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'redis',
            'HORIZON_OBSERVABILITY_TOKEN' => $manifest->token,
            'HORIZON_OBSERVABILITY_LIVE_ENABLED' => 'true',
            'HORIZON_OBSERVABILITY_LIVE_CACHE_TTL' => '0',
        ] as $name => $value) {
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            putenv($name.'='.$value);
        }

        if (! defined('TESTBENCH_WORKING_PATH')) {
            define('TESTBENCH_WORKING_PATH', $root);
        }

        $end = CarbonImmutable::createFromTimestamp($manifest->end);
        Carbon::setTestNow($end);
        CarbonImmutable::setTestNow($end);

        /** @var Application $app */
        $app = require $root.'/vendor/orchestra/testbench-core/laravel/bootstrap/app.php';

        if (! $app instanceof Application) {
            throw new RuntimeException('The testbench bootstrap did not return an application.');
        }

        $app['config']->set('horizon-observability.token', $manifest->token);
        $app['config']->set('horizon-observability.live.enabled', true);
        $app['config']->set('horizon-observability.live.cache_ttl', 0.0);
        $app['config']->set('queue.batching.database', $app['config']->get('database.default'));
        $app->forgetInstance(Config::class);
        $app->make(ServerClock::class)->pin((float) $manifest->end);

        return $app;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private static function log(string $runDir, array $entry): void
    {
        file_put_contents($runDir.'/'.self::LOG, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);
    }
}
