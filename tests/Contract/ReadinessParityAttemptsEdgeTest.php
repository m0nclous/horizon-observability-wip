<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Queues\PayloadClock;
use DF\HorizonObservability\Queues\QueueInspector;
use DF\HorizonObservability\Tests\Support\QueuePayloads;

/*
| T18 QA round 3 (adversarial): 03e291d made PayloadClock::isFirstAttempt() read a non-finite attempts as a first
| attempt, like first_attempt() in queue_state.lua. The shared fixtures cover the plain string-extraction path with
| six out-of-range values. These cases probe the other paths of the Lua twin and the edges of the new float rule:
| the cjson path (a key that occurs twice, so the script decodes the payload), the boundaries of the double range
| and of |attempts| < 1, numeric strings with surrounding whitespace and long exponents. Both sides must agree on
| every case, or the queue wait (Lua) and the wait samples of the attempt tracker (PHP) read the payload differently.
*/

pest()->group('redis');

/**
 * A payload whose data repeats the attempts key, so queue_state.lua reads it from the cjson-decoded payload.
 */
function repeatedAttemptsPayload(string $attempts, string $pushedAt): string
{
    return '{"displayName":"App\\\\Jobs\\\\A","job":"Illuminate\\\\Queue\\\\CallQueuedHandler@call","data":{"attempts":0,"commandName":"App\\\\Jobs\\\\A"},"attempts":'.$attempts.',"pushedAt":"'.$pushedAt.'"}';
}

function plainAttemptsPayload(string $attempts, string $pushedAt): string
{
    return '{"displayName":"App\\\\Jobs\\\\A","attempts":'.$attempts.',"pushedAt":"'.$pushedAt.'"}';
}

dataset('attempts edges', [
    // The cjson path with the six out-of-range values of tests/Fixtures/queues/parity.php.
    'repeated key, 2^64' => [repeatedAttemptsPayload('18446744073709551616', '1700000030.5'), false],
    'repeated key, 1e30' => [repeatedAttemptsPayload('1e30', '1700000031.5'), false],
    'repeated key, "1e30"' => [repeatedAttemptsPayload('"1e30"', '1700000032.5'), false],
    'repeated key, 1e999' => [repeatedAttemptsPayload('1e999', '1700000033.5'), true],
    'repeated key, -1e999' => [repeatedAttemptsPayload('-1e999', '1700000034.5'), true],
    'repeated key, "1e999"' => [repeatedAttemptsPayload('"1e999"', '1700000035.5'), true],
    'repeated key, -0.999' => [repeatedAttemptsPayload('-0.999', '1700000036.5'), true],
    'repeated key, "-1"' => [repeatedAttemptsPayload('"-1"', '1700000037.5'), false],
    // The boundaries of the double range: DBL_MAX is finite (a retry), one ulp past half of the next step is INF.
    'DBL_MAX' => [plainAttemptsPayload('1.7976931348623157e308', '1700000040.5'), false],
    'just past DBL_MAX (INF)' => [plainAttemptsPayload('1.7976931348623159e308', '1700000041.5'), true],
    '"just past DBL_MAX" (INF)' => [plainAttemptsPayload('"1.7976931348623159e308"', '1700000042.5'), true],
    'uppercase exponent 1E999' => [plainAttemptsPayload('1E999', '1700000043.5'), true],
    'signed exponent 1e+999' => [plainAttemptsPayload('1e+999', '1700000044.5'), true],
    'underflow 1e-999 is 0' => [plainAttemptsPayload('1e-999', '1700000045.5'), true],
    'smallest subnormal' => [plainAttemptsPayload('4.9e-324', '1700000046.5'), true],
    'negative zero' => [plainAttemptsPayload('-0.0', '1700000047.5'), true],
    // The boundaries of |attempts| < 1.
    '-0.999' => [plainAttemptsPayload('-0.999', '1700000050.5'), true],
    '-1' => [plainAttemptsPayload('-1', '1700000051.5'), false],
    '0.9999999999999999 (below 1)' => [plainAttemptsPayload('0.9999999999999999', '1700000052.5'), true],
    '0.99999999999999999 (rounds to 1.0)' => [plainAttemptsPayload('0.99999999999999999', '1700000053.5'), false],
    '"-0.999"' => [plainAttemptsPayload('"-0.999"', '1700000054.5'), true],
    // Numeric strings with the whitespace PHP 8 allows around them, and long exponents.
    '" 1e999 "' => [plainAttemptsPayload('" 1e999 "', '1700000060.5'), true],
    '"\n-1e999\t"' => [plainAttemptsPayload('"\n-1e999\t"', '1700000061.5'), true],
    '" 1 "' => [plainAttemptsPayload('" 1 "', '1700000062.5'), false],
    '"+1e999"' => [plainAttemptsPayload('"+1e999"', '1700000063.5'), true],
    '".5e999"' => [plainAttemptsPayload('".5e999"', '1700000064.5'), true],
    'exponent with leading zeros 1e0000000000000000000001' => [plainAttemptsPayload('1e0000000000000000000001', '1700000065.5'), false],
    'exponent past the int range 1e99999999999999999999' => [plainAttemptsPayload('1e99999999999999999999', '1700000066.5'), true],
    '"inf" is not numeric' => [plainAttemptsPayload('"inf"', '1700000067.5'), true],
    '"0x10" is not numeric' => [plainAttemptsPayload('"0x10"', '1700000068.5'), true],
]);

it('reads the attempts edges of the float rule like PayloadClock in full and live mode (T18 QA round 3)', function (string $payload, bool $firstAttempt): void {
    QueuePayloads::push(app('redis')->connection('default'), 'default', $payload);
    $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    $now = CarbonImmutable::createFromTimestamp(1700100000);
    $inspector = app(QueueInspector::class);
    $expected = PayloadClock::isFirstAttempt($decoded) ? PayloadClock::readyAt($decoded) : null;

    expect(PayloadClock::isFirstAttempt($decoded))->toBe($firstAttempt)
        ->and($inspector->inspect('redis', 'default', $now)->firstFreshReadyAt)->toBe($expected)
        ->and($inspector->live('redis', 'default', $now, true)->firstFreshReadyAt)->toBe($expected);
})->with('attempts edges');
