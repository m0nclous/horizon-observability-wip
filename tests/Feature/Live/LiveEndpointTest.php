<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\HorizonObservability;
use DF\HorizonObservability\HorizonObservabilityServiceProvider;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Integration\Live\Support\LiveState;
use DF\HorizonObservability\Tests\Support\Apps;
use DF\HorizonObservability\Tests\Support\RedisCommands;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\HorizonServiceProvider;

/*
| GET|HEAD {base}/live (Addendum R4.3): the schema v1 document over HTTP with the job lists' var-* filters,
| Cache-Control no-store, 200 with errors[] on failures, 422 on invalid input, and the same auth matrix as the job
| lists (api_token, rotation, IPs, the callback, open only in local).
*/

pest()->group('redis');

const LIVE_ENDPOINT = '/horizon/observability/live';
const LIVE_ENDPOINT_TOKEN = 'prometheus-token-0123456789abcdef';
const LIVE_ENDPOINT_API_TOKEN = 'grafana-api-token-0123456789abcdef';

beforeEach(function (): void {
    LiveState::freeze(LiveState::now());
    LiveState::horizon($this->app);
    LiveState::queues($this->app);
    LiveState::attempts();
    $this->app['env'] = 'local'; // open without configuration, like every package endpoint in local
});

afterEach(function (): void {
    LiveState::freeze(null);
});

/**
 * @param  array<string, mixed>  $config  horizon-observability.* values
 */
function liveEndpointConfig(array $config, string $environment = 'production'): void
{
    app()['env'] = $environment;

    foreach ($config as $key => $value) {
        config()->set('horizon-observability.'.$key, $value);
    }

    app()->forgetInstance(Config::class);
}

it('answers the snapshot JSON of schema v1 without caching headers', function (): void {
    $response = $this->get(LIVE_ENDPOINT)->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeader('Cache-Control', 'no-store, private');

    $document = $response->json();
    $golden = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/live/snapshot.json'), true);

    expect(array_keys($document))->toBe(['v', 'generated_at', 'age_ms', 'ttl_ms', 'scope', 'horizon', 'pending', 'delayed', 'reserved', 'throughput', 'supervisors', 'queues', 'tiles', 'errors'])
        ->and([...$document, 'age_ms' => 0])->toBe($golden)
        ->and($response->getContent())->toContain('"caption":"Oldest: 17 s '."\u{00B7}".' default"')
        ->and($response->getContent())->toContain('"value":5.0');
});

it('applies the dashboard filters in the ${var:queryparam} form', function (string $query, array $expected): void {
    $document = $this->get(LIVE_ENDPOINT.'?'.$query)->assertOk()->json();

    expect([
        'queues' => $document['scope']['queues'],
        'job_filter' => $document['scope']['job_filter'],
        'pending' => $document['pending']['value'],
        'supervisors' => array_column($document['supervisors'], 'name'),
    ])->toBe($expected);
})->with([
    'all' => ['var-supervisor=%24__all&var-batch_kind=%24__all&var-queue=%24__all&var-job_class=%24__all', ['queues' => ['default', 'slow', 'reports', 'mail', 'sms'], 'job_filter' => false, 'pending' => 5, 'supervisors' => ['supervisor-default', 'supervisor-pool', 'supervisor-slow']]],
    'queues' => ['var-queue=slow&var-queue=sms', ['queues' => ['slow', 'sms'], 'job_filter' => false, 'pending' => 2, 'supervisors' => ['supervisor-pool', 'supervisor-slow']]],
    'supervisor' => ['var-supervisor=supervisor-pool', ['queues' => ['mail', 'sms'], 'job_filter' => false, 'pending' => 0, 'supervisors' => ['supervisor-pool']]],
    'job class' => ['var-job_class=App%5CJobs%5CSendInvoice', ['queues' => ['default', 'slow', 'reports', 'mail', 'sms'], 'job_filter' => true, 'pending' => null, 'supervisors' => ['supervisor-default', 'supervisor-pool', 'supervisor-slow']]],
    'unknown parameters' => ['from=1&to=2&var-other=x', ['queues' => ['default', 'slow', 'reports', 'mail', 'sms'], 'job_filter' => false, 'pending' => 5, 'supervisors' => ['supervisor-default', 'supervisor-pool', 'supervisor-slow']]],
    'no match' => ['var-queue=missing', ['queues' => [], 'job_filter' => false, 'pending' => 0, 'supervisors' => []]],
]);

it('answers 422 for invalid filter values', function (): void {
    $this->get(LIVE_ENDPOINT.'?var-queue=%FF')->assertUnprocessable()->assertJsonStructure(['message', 'errors']);
});

it('answers HEAD without building a snapshot', function (): void {
    $commands = RedisCommands::start($this->app);

    $this->call('HEAD', LIVE_ENDPOINT)->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    expect($commands->count())->toBe(0);
});

it('answers 200 with errors and Live unavailable when the storage Redis fails', function (): void {
    config()->set('database.redis.dead', ['host' => '127.0.0.1', 'port' => 1, 'database' => 0, 'timeout' => 0.2]);
    config()->set('horizon-observability.redis.connection', 'dead');
    $this->app->forgetInstance(Config::class);
    $this->app->forgetInstance(RedisClients::class);

    $document = $this->get(LIVE_ENDPOINT)->assertOk()->json();

    expect($document['errors'][0]['source'])->toBe('cache')
        ->and($document['pending'])->toMatchArray(['value' => null, 'caption' => 'Live unavailable', 'color' => 'text'])
        ->and($document['horizon']['caption'])->toBe('Live unavailable')
        ->and(array_column($document['tiles'], 'caption'))->toBe(['Live unavailable', '', 'Live unavailable']);
});

it('answers 200 with errors when a Supervisor filter cannot be resolved', function (): void {
    $repository = Mockery::mock(SupervisorRepository::class);
    $repository->shouldReceive('all')->andThrow(new RuntimeException('Connection refused'));
    $this->app->instance(SupervisorRepository::class, $repository);

    $document = $this->get(LIVE_ENDPOINT.'?var-supervisor=supervisor-pool')->assertOk()->json();

    expect($document['errors'])->toBe([['source' => 'scope', 'message' => 'RuntimeException: Connection refused']])
        ->and($document['scope'])->toBe(['supervisors' => ['supervisor-pool'], 'queues' => [], 'job_filter' => false])
        ->and($document['pending']['caption'])->toBe('Live unavailable');
});

it('has no route when live is disabled', function (): void {
    $routes = Apps::inspect(
        [HorizonServiceProvider::class, HorizonObservabilityServiceProvider::class],
        ['horizon-observability.live.enabled' => false],
        static function (Application $app): array {
            /** @var Router $router */
            $router = $app['router'];

            return [Apps::match($router->getRoutes(), LIVE_ENDPOINT), Apps::match($router->getRoutes(), '/horizon/observability/jobs/pending')];
        },
    );

    // The package route is absent; the path then falls to Horizon's own catch-all (its SPA, behind its gate).
    expect($routes)->toBe(['horizon.index', 'horizon-observability.jobs']);
});

it('takes the job lists\' auth: api_token, rotation, IPs and the callback', function (array $config, array $server, int $status): void {
    liveEndpointConfig($config);

    $this->withServerVariables($server)->get(LIVE_ENDPOINT)->assertStatus($status);
})->with([
    'locked outside local' => [[], [], 403],
    'token' => [['token' => LIVE_ENDPOINT_TOKEN], ['HTTP_AUTHORIZATION' => 'Bearer '.LIVE_ENDPOINT_TOKEN], 200],
    'no token' => [['token' => LIVE_ENDPOINT_TOKEN], [], 401],
    'rotated token' => [['token' => 'old-token-0123456789abcdef, '.LIVE_ENDPOINT_TOKEN], ['HTTP_AUTHORIZATION' => 'Bearer '.LIVE_ENDPOINT_TOKEN], 200],
    'api_token' => [['token' => LIVE_ENDPOINT_TOKEN, 'api_token' => LIVE_ENDPOINT_API_TOKEN], ['HTTP_AUTHORIZATION' => 'Bearer '.LIVE_ENDPOINT_API_TOKEN], 200],
    'metrics token where an api_token is set' => [['token' => LIVE_ENDPOINT_TOKEN, 'api_token' => LIVE_ENDPOINT_API_TOKEN], ['HTTP_AUTHORIZATION' => 'Bearer '.LIVE_ENDPOINT_TOKEN], 401],
    'allowed IP' => [['allowed_ips' => '10.20.0.0/16'], ['REMOTE_ADDR' => '10.20.3.4'], 200],
    'other IP' => [['allowed_ips' => '10.20.0.0/16'], ['REMOTE_ADDR' => '10.21.3.4'], 403],
    'IP and token' => [['allowed_ips' => '10.20.0.0/16', 'token' => LIVE_ENDPOINT_TOKEN], ['REMOTE_ADDR' => '10.20.3.4', 'HTTP_AUTHORIZATION' => 'Bearer '.LIVE_ENDPOINT_TOKEN], 200],
    'IP without token' => [['allowed_ips' => '10.20.0.0/16', 'token' => LIVE_ENDPOINT_TOKEN], ['REMOTE_ADDR' => '10.20.3.4'], 401],
]);

it('lets the auth callback decide', function (): void {
    liveEndpointConfig(['token' => LIVE_ENDPOINT_TOKEN]);
    HorizonObservability::auth(static fn (Request $request): bool => $request->header('X-Client-Verify') === 'SUCCESS');

    $this->get(LIVE_ENDPOINT, ['X-Client-Verify' => 'SUCCESS'])->assertOk()->assertJsonPath('v', 1);
    $this->get(LIVE_ENDPOINT, ['Authorization' => 'Bearer '.LIVE_ENDPOINT_TOKEN])->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
});

it('runs no snapshot work for a denied request', function (): void {
    liveEndpointConfig(['token' => LIVE_ENDPOINT_TOKEN]);
    $commands = RedisCommands::start($this->app);

    $this->get(LIVE_ENDPOINT)->assertUnauthorized()->assertExactJson(['message' => 'Unauthorized.']);

    expect($commands->count())->toBe(0);
});
