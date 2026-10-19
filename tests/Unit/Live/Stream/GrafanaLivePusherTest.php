<?php

declare(strict_types=1);

use DF\HorizonObservability\Live\Stream\GrafanaLivePusher;
use DF\HorizonObservability\Live\Stream\PushResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
| The Grafana Live push: POST {url}/api/live/push/{id} with the bearer token and a text/plain
| line-protocol body, 2 s timeout and 1 s connect timeout; every answer, a refused connection and a timeout become a
| PushResult, never an exception, and the token never appears in the error.
*/

const PUSHER_TOKEN = 'glsa_secret-token-0123456789';

it('posts the body with the token to the push URL of the stream', function (): void {
    Http::fake(['*' => Http::response('', 200)]);

    $result = app(GrafanaLivePusher::class)->push('http://grafana:3000/', 'horizon_observability', PUSHER_TOKEN, "tiles value=1.0 1\n");

    expect($result)->toBeInstanceOf(PushResult::class)
        ->and($result->ok)->toBeTrue()
        ->and($result->status)->toBe(200)
        ->and($result->error)->toBeNull()
        ->and($result->seconds)->toBeGreaterThanOrEqual(0.0);

    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'http://grafana:3000/api/live/push/horizon_observability'
        && $request->header('Authorization') === ['Bearer '.PUSHER_TOKEN]
        && str_starts_with($request->header('Content-Type')[0] ?? '', 'text/plain')
        && $request->body() === "tiles value=1.0 1\n");
    Http::assertSentCount(1);
});

it('reports a refused push with its status and the first line of the answer', function (int $status, string $body, string $error): void {
    Http::fake(['*' => Http::response($body, $status)]);

    $result = app(GrafanaLivePusher::class)->push('http://grafana:3000', 'id', PUSHER_TOKEN, 'x');

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe($status)
        ->and($result->error)->toBe($error);
})->with([
    'invalid token' => [401, '{"message":"invalid API key"}', 'HTTP 401: {"message":"invalid API key"}'],
    'role too low' => [403, "{\"message\":\"Permissions needed: live:push\"}\n{\"more\":1}", 'HTTP 403: {"message":"Permissions needed: live:push"}'],
    'malformed body' => [500, '', 'HTTP 500'],
    'echoed token' => [400, 'bad token '.PUSHER_TOKEN, 'HTTP 400: bad token [redacted]'],
]);

it('cuts a long error answer', function (): void {
    Http::fake(['*' => Http::response(str_repeat('a', 5000), 500)]);

    $error = (string) app(GrafanaLivePusher::class)->push('http://grafana:3000', 'id', PUSHER_TOKEN, 'x')->error;

    expect(strlen($error))->toBeLessThanOrEqual(strlen('HTTP 500: ') + GrafanaLivePusher::MAX_ERROR_BYTES)
        ->and($error)->toEndWith('...');
});

it('turns a connection failure into a result without a status', function (): void {
    Http::fake(static fn (): never => throw new ConnectionException('cURL error 7: Failed to connect to grafana port 3000'));

    $result = app(GrafanaLivePusher::class)->push('http://grafana:3000', 'id', PUSHER_TOKEN, 'x');

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBeNull()
        ->and($result->error)->toBe('ConnectionException: cURL error 7: Failed to connect to grafana port 3000');
});

it('gives up on a Grafana that does not answer after the 2 s timeout', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();
    $address = (string) stream_socket_get_name($server, false);

    $result = app(GrafanaLivePusher::class)->push('http://'.$address, 'id', PUSHER_TOKEN, 'x');

    fclose($server);

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBeNull()
        ->and($result->seconds)->toBeGreaterThanOrEqual(1.9)->toBeLessThan(3.5)
        ->and((string) $result->error)->toStartWith('ConnectionException: ')
        ->and((string) $result->error)->not->toContain(PUSHER_TOKEN);
});

it('reports a refused connection quickly', function (): void {
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $address = (string) stream_socket_get_name($server, false);
    fclose($server); // nothing listens there any more

    $result = app(GrafanaLivePusher::class)->push('http://'.$address, 'id', PUSHER_TOKEN, 'x');

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBeNull()
        ->and($result->seconds)->toBeLessThan(1.5);
});

it('names what to check for each failure', function (?int $status, string $hint): void {
    expect(GrafanaLivePusher::hint($status))->toContain($hint);
})->with([
    [null, 'HORIZON_OBSERVABILITY_LIVE_GRAFANA_URL'],
    [401, 'HORIZON_OBSERVABILITY_LIVE_GRAFANA_TOKEN'],
    [403, 'role Editor needed on Grafana 13'],
    [404, 'Grafana Live is enabled'],
    [500, 'Grafana rejected'],
    [400, 'refused'],
]);

it('does not follow a redirect, which would resend the token or turn the POST into a GET', function (): void {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://grafana.example/api/live/push/id'])]);

    $result = app(GrafanaLivePusher::class)->push('http://grafana.example', 'id', PUSHER_TOKEN, 'x');

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe(302)
        ->and(GrafanaLivePusher::hint($result->status))->toContain('final URL');
    Http::assertSentCount(1);
});

it('keeps the user and password of a Grafana URL out of the output and the errors', function (): void {
    Http::fake(static fn (): never => throw new ConnectionException('cURL error 7: Failed to connect for http://admin:secret@grafana:3000/api/live/push/id'));

    $result = app(GrafanaLivePusher::class)->push('http://admin:secret@grafana:3000', 'id', PUSHER_TOKEN, 'x');

    expect(GrafanaLivePusher::display('https://admin:secret@grafana.example:3000/sub'))->toBe('https://grafana.example:3000/sub')
        ->and(GrafanaLivePusher::display('http://grafana:3000'))->toBe('http://grafana:3000')
        ->and((string) $result->error)->toBe('ConnectionException: cURL error 7: Failed to connect for http://grafana:3000/api/live/push/id');
});
