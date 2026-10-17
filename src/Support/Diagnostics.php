<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Support;

use Closure;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Http\AccessPolicy;
use DF\HorizonObservability\Http\RoutePath;
use DF\HorizonObservability\Redis\RedisClients;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Data of the `about` section and of `horizon-observability:check` (Arch Q7).
 *
 * Stub (T02): the basics (state, endpoints, access mode, namespace, storage, collectors, config problems) without
 * touching Redis. Owned by T14 (WP10).
 *
 * @internal
 */
final readonly class Diagnostics
{
    public function __construct(private Container $container) {}

    /**
     * The `about` section. Never throws: a value that cannot be computed reads `unavailable`.
     *
     * @return array<string, string>
     */
    public function about(): array
    {
        return [
            'Enabled' => $this->safely(fn (): string => $this->config()->enabled ? 'yes' : 'no'),
            'Endpoints' => $this->safely(fn (): string => url(RoutePath::apiPath($this->repository()))),
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
            'Live' => $this->safely(fn (): string => $this->config()->liveEnabled ? 'enabled' : 'disabled'),
            'Config problems' => $this->safely(fn (): string => (string) count($this->config()->problems)),
        ];
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
