<?php

declare(strict_types=1);

use DF\HorizonObservability\Queues\PayloadClock;
use DF\HorizonObservability\Tests\Support\QueuePayloads;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;

pest()->group('redis');

it('builds Laravel and Horizon payloads', function (): void {
    $payload = QueuePayloads::job('App\\Jobs\\SendInvoice', ['attempts' => 2], ['createdAt', 'delay']);

    expect($payload['displayName'])->toBe('App\\Jobs\\SendInvoice')
        ->and($payload['attempts'])->toBe(2)
        ->and($payload)->not->toHaveKeys(['createdAt', 'delay'])
        ->and(PayloadClock::readyAt($payload))->toBe(1700000000.1234)
        ->and(json_decode(QueuePayloads::encode($payload), true))->toBe($payload);
});

it('places payloads in the Laravel queue keys', function (): void {
    $redis = app('redis')->connection('default');
    QueuePayloads::push($redis, 'default', QueuePayloads::job());
    QueuePayloads::delay($redis, 'default', QueuePayloads::job(), 1700000060.0);
    QueuePayloads::reserve($redis, 'default', '{"raw":true}', 1700000090.0);

    $prefix = TestCase::redisPrefix();

    expect(RedisKeys::all($prefix))->toBe([$prefix.'queues:default', $prefix.'queues:default:delayed', $prefix.'queues:default:reserved'])
        ->and(RedisKeys::client()->zrange($prefix.'queues:default:reserved', 0, -1, ['withscores' => true]))->toBe(['{"raw":true}' => '1700000090']);
});
