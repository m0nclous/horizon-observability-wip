<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Predis\Client;

/*
| T14 QA round 2 (adversarial): horizon-observability:flush on a Redis Cluster with any number of masters.
|
| FlushClusterTest seeds its keys through a plain client of one node, so it only runs on the single-node cluster of the
| CI leg (on a 3-master cluster its setup gets MOVED). The page-by-page rewrite of round 1 changed the cluster path
| (SCAN per master, DEL one key per call), so these cases seed through a cluster-aware client and run on 1 or more
| masters: the package keys of the slot of `{horizon-observability}` (or of a connection prefix with its own hash tag),
| more than one SCAN page of them, the other keys kept, and the count without --force equal to what is deleted.
| Probed on a 3-master cluster (REDIS_CLUSTER_PORT=17001) and the 1-node leg. Skipped when no cluster answers.
*/

pest()->group('cluster');

function adversarialRound2ClusterSeed(): string
{
    return getenv('REDIS_CLUSTER_HOST') ?: '127.0.0.1'.':'.(int) (getenv('REDIS_CLUSTER_PORT') ?: 7000);
}

function adversarialRound2Cluster(): Client
{
    return new Client(['tcp://'.adversarialRound2ClusterSeed()], ['cluster' => 'redis', 'parameters' => ['timeout' => 0.5]]);
}

/**
 * Every key of every master matching the pattern, sorted.
 *
 * @return list<string>
 */
function adversarialRound2ClusterKeys(Client $cluster, string $pattern): array
{
    $keys = [];

    foreach ($cluster as $node) {
        $cursor = '0';

        do {
            /** @var array{0: string, 1: list<string>} $page */
            $page = $node->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 1000]);
            [$cursor, $batch] = $page;
            array_push($keys, ...$batch);
        } while ($cursor !== '0');
    }

    sort($keys);

    return array_values(array_unique($keys));
}

function adversarialRound2ClusterClean(Client $cluster): void
{
    foreach (adversarialRound2ClusterKeys($cluster, '*'.rtrim(TestCase::redisPrefix(), ':').'*') as $key) {
        $cluster->del([$key]);
    }
}

beforeEach(function (): void {
    try {
        $cluster = adversarialRound2Cluster();
        $info = '';

        foreach ($cluster as $node) {
            $info = (string) $node->executeRaw(['CLUSTER', 'INFO']);
        }
    } catch (Throwable $e) {
        $this->markTestSkipped('No Redis Cluster at the cluster leg address: '.$e->getMessage());
    }

    if (! str_contains($info, 'cluster_state:ok')) {
        $this->markTestSkipped('The Redis Cluster is not ready (cluster_state is not ok).');
    }

    adversarialRound2ClusterClean($cluster);
    $this->cluster = $cluster;
    [$host, $port] = explode(':', adversarialRound2ClusterSeed());
    config()->set('database.redis.clusters.metrics', [['host' => $host, 'port' => (int) $port, 'database' => 0]]);
    config()->set('horizon-observability.redis.connection', 'metrics');
});

afterEach(function (): void {
    if (property_exists($this, 'cluster') && $this->cluster instanceof Client) {
        adversarialRound2ClusterClean($this->cluster);
    }
});

it('counts and deletes more than a SCAN page of package keys on every master', function (bool $tagged): void {
    $prefix = $tagged ? '{'.rtrim(TestCase::redisPrefix(), ':').'}:' : TestCase::redisPrefix();
    config()->set('database.redis.clusters.options', ['cluster' => 'redis', 'prefix' => $prefix]);
    app()->forgetInstance(Config::class);
    app()->forgetInstance(RedisClients::class);

    $keys = new Keys;
    $count = 1200;     // more than two pages of FlushCommand::BATCH

    for ($i = 0; $i < $count; $i++) {
        $this->cluster->set($prefix.$keys->readyAt(sprintf('%08d-0000-4000-8000-%012d', $i, $i)), '1');
    }

    foreach ([$keys->attempts(), $keys->meta(), $keys->liveLeader(), $keys->ring(7)] as $key) {
        $this->cluster->set($prefix.$key, '1');
    }

    // Other keys of the app on any master, and the package root under another prefix: kept.
    foreach (['{default}:queue', 'cache:a', 'cache:b', 'cache:c', 'session:x'] as $key) {
        $this->cluster->set($prefix.$key, '1');
    }

    $this->cluster->set('other_'.TestCase::redisPrefix().$keys->meta(), '1');

    expect(Artisan::call('horizon-observability:flush', ['--no-interaction' => true]))->toBe(0)
        ->and(Artisan::output())->toContain(sprintf('Found %d package keys (attempts 1, live 2, meta 1, ready_at %d)', $count + 4, $count));

    expect(Artisan::call('horizon-observability:flush', ['--force' => true]))->toBe(0)
        ->and(Artisan::output())->toContain(sprintf('Deleted %d package keys (attempts 1, live 2, meta 1, ready_at %d)', $count + 4, $count))
        ->and(adversarialRound2ClusterKeys($this->cluster, $prefix.(new Keys)->root().'*'))->toBe([])
        ->and(adversarialRound2ClusterKeys($this->cluster, '*'.rtrim(TestCase::redisPrefix(), ':').'*'))->toHaveCount(6);
})->with([
    'plain prefix' => [false],
    'prefix with its own hash tag' => [true],
]);
