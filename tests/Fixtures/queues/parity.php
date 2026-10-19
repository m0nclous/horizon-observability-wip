<?php

declare(strict_types=1);

/*
| Extra readiness parity cases of the queue reader, in the format of tests/Fixtures/payloads.php: raw payloads
| with the readiness PayloadClock::readyAt() computes, whether they are first attempts, and the decoded display name.
| They cover the edges of the string extraction in queue_state.lua: JSON escapes, PHP's is_numeric() and is_int()
| rules, whitespace after a colon, keys that occur twice (string jobs whose data has a key of the same name), and
| attempts outside the integer and the double range (a finite |attempts| >= 1 is a retry, INF and -INF truncate to 0
| like PHP's (int)). tests/Contract/ReadinessParityTest runs them with the shared fixtures.
*/

return [
    'unicode and slash escapes as json_encode() and cjson write them' => [
        'payload' => <<<'JSON'
{"uuid":"c1","displayName":"App\\Jobs\\Отчёт","job":"Illuminate\\Queue\\CallQueuedHandler@call","data":{"commandName":"App\\Jobs\\Отчёт","command":"x"},"createdAt":1700000000,"delay":null,"id":"c1","attempts":0,"tags":["a\/b"],"pushedAt":"1700000000.5"}
JSON,
        'ready_at' => 1700000000.5,
        'first_attempt' => true,
        'display_name' => "App\\Jobs\\\u{041E}\u{0442}\u{0447}\u{0451}\u{0442}",
    ],

    'closure name with an escaped slash' => [
        'payload' => <<<'JSON'
{"attempts":0,"data":{"command":"x","commandName":"Illuminate\\Queue\\CallQueuedClosure"},"displayName":"Closure (routes\/web.php:42)","id":"c2","pushedAt":"1700000001.25","uuid":"c2"}
JSON,
        'ready_at' => 1700000001.25,
        'first_attempt' => true,
        'display_name' => 'Closure (routes/web.php:42)',
    ],

    'attempts as the string "0"' => [
        'payload' => '{"displayName":"App\\\\Jobs\\\\A","attempts":"0","pushedAt":"1700000002.5"}',
        'ready_at' => 1700000002.5,
        'first_attempt' => true,
        'display_name' => 'App\Jobs\A',
    ],

    'attempts as the string "2"' => [
        'payload' => '{"displayName":"App\\\\Jobs\\\\A","attempts":"2","pushedAt":"1700000003.5"}',
        'ready_at' => 1700000003.5,
        'first_attempt' => false,
        'display_name' => 'App\Jobs\A',
    ],

    'fractional attempts truncate to 0' => [
        'payload' => '{"displayName":"App\\\\Jobs\\\\A","attempts":0.5,"pushedAt":"1700000004.5"}',
        'ready_at' => 1700000004.5,
        'first_attempt' => true,
        'display_name' => 'App\Jobs\A',
    ],

    'attempts that is not numeric, or null, is a first attempt' => [
        'payload' => '{"displayName":"App\\\\Jobs\\\\A","attempts":"many","pushedAt":"1700000005.5"}',
        'ready_at' => 1700000005.5,
        'first_attempt' => true,
        'display_name' => 'App\Jobs\A',
    ],

    'attempts null' => [
        'payload' => '{"displayName":"App\\\\Jobs\\\\A","attempts":null,"pushedAt":"1700000006.5"}',
        'ready_at' => 1700000006.5,
        'first_attempt' => true,
        'display_name' => 'App\Jobs\A',
    ],

    'pushedAt with an exponent and surrounding whitespace' => [
        'payload' => '{"displayName":"App\\\\Jobs\\\\A","attempts":0,"pushedAt":" 1.7000000075e9 "}',
        'ready_at' => 1700000007.5,
        'first_attempt' => true,
        'display_name' => 'App\Jobs\A',
    ],

    'hexadecimal pushedAt is not numeric: createdAt is the base' => [
        'payload' => '{"displayName":"App\\\\Jobs\\\\A","attempts":0,"pushedAt":"0x10","createdAt":1700000008}',
        'ready_at' => 1700000008.0,
        'first_attempt' => true,
        'display_name' => 'App\Jobs\A',
    ],

    'pushedAt null: createdAt + delay' => [
        'payload' => '{"displayName":"App\\\\Jobs\\\\A","attempts":0,"pushedAt":null,"createdAt":1700000000,"delay":9}',
        'ready_at' => 1700000009.0,
        'first_attempt' => true,
        'display_name' => 'App\Jobs\A',
    ],

    'float createdAt is not an integer: the delay is ignored' => [
        'payload' => '{"displayName":"App\\\\Jobs\\\\A","attempts":0,"createdAt":1700000000.0,"delay":60,"pushedAt":"1700000010.5"}',
        'ready_at' => 1700000010.5,
        'first_attempt' => true,
        'display_name' => 'App\Jobs\A',
    ],

    'string delay is not an integer' => [
        'payload' => '{"displayName":"App\\\\Jobs\\\\A","attempts":0,"createdAt":1700000000,"delay":"60","pushedAt":"1700000011.5"}',
        'ready_at' => 1700000011.5,
        'first_attempt' => true,
        'display_name' => 'App\Jobs\A',
    ],

    'negative delay' => [
        'payload' => '{"displayName":"App\\\\Jobs\\\\A","attempts":0,"createdAt":1700000012,"delay":-30}',
        'ready_at' => 1700000012.0,
        'first_attempt' => true,
        'display_name' => 'App\Jobs\A',
    ],

    'createdAt beyond the int64 range is a float: readiness unknown' => [
        'payload' => '{"displayName":"App\\\\Jobs\\\\A","attempts":0,"createdAt":9223372036854775808,"delay":0}',
        'ready_at' => null,
        'first_attempt' => true,
        'display_name' => 'App\Jobs\A',
    ],

    'whitespace after the colons (pretty-printed JSON)' => [
        'payload' => '{"displayName": "App\\\\Jobs\\\\A", "attempts": 0, "createdAt": 1700000000, "delay": 13, "pushedAt": "1700000001.5"}',
        'ready_at' => 1700000013.0,
        'first_attempt' => true,
        'display_name' => 'App\Jobs\A',
    ],

    'missing displayName' => [
        'payload' => '{"job":"Handler@handle","attempts":0,"pushedAt":"1700000014.5"}',
        'ready_at' => 1700000014.5,
        'first_attempt' => true,
        'display_name' => '',
    ],

    'string job whose data repeats the top-level keys' => [
        'payload' => <<<'JSON'
{"uuid":"c3","displayName":"Handler","job":"Handler@handle","maxTries":null,"data":{"displayName":"Inner","attempts":5,"pushedAt":"1","createdAt":5,"delay":999},"createdAt":1700000000,"delay":15,"id":"c3","attempts":0,"pushedAt":"1700000000.5"}
JSON,
        'ready_at' => 1700000015.0,
        'first_attempt' => true,
        'display_name' => 'Handler',
    ],

    'repeated keys in a released, cjson-encoded payload' => [
        'payload' => <<<'JSON'
{"attempts":3,"data":{"attempts":0,"displayName":"App\\Jobs\\fetch","pushedAt":"2"},"displayName":"App\\Jobs\\fetch","id":"c4","job":"App\\Jobs\\fetch@handle","pushedAt":"1700000016.5"}
JSON,
        'ready_at' => 1700000016.5,
        'first_attempt' => false,
        'display_name' => 'App\Jobs\fetch',
    ],

    'attempts 2^64 is a retry (no integer wrap to 0)' => [
        'payload' => '{"displayName":"App\\\\Jobs\\\\A","attempts":18446744073709551616,"pushedAt":"1700000017.5"}',
        'ready_at' => 1700000017.5,
        'first_attempt' => false,
        'display_name' => 'App\Jobs\A',
    ],

    'attempts 1e30 is a retry' => [
        'payload' => '{"displayName":"App\\\\Jobs\\\\A","attempts":1e30,"pushedAt":"1700000018.5"}',
        'ready_at' => 1700000018.5,
        'first_attempt' => false,
        'display_name' => 'App\Jobs\A',
    ],

    'attempts "1e30" as a numeric string is a retry' => [
        'payload' => '{"displayName":"App\\\\Jobs\\\\A","attempts":"1e30","pushedAt":"1700000019.5"}',
        'ready_at' => 1700000019.5,
        'first_attempt' => false,
        'display_name' => 'App\Jobs\A',
    ],

    'attempts 1e999 (INF after json_decode) truncates to 0: a first attempt' => [
        'payload' => '{"displayName":"App\\\\Jobs\\\\A","attempts":1e999,"pushedAt":"1700000020.5"}',
        'ready_at' => 1700000020.5,
        'first_attempt' => true,
        'display_name' => 'App\Jobs\A',
    ],

    'attempts -1e999 (-INF after json_decode) truncates to 0: a first attempt' => [
        'payload' => '{"displayName":"App\\\\Jobs\\\\A","attempts":-1e999,"pushedAt":"1700000021.5"}',
        'ready_at' => 1700000021.5,
        'first_attempt' => true,
        'display_name' => 'App\Jobs\A',
    ],

    'attempts "1e999" (INF after the float cast) truncates to 0: a first attempt' => [
        'payload' => '{"displayName":"App\\\\Jobs\\\\A","attempts":"1e999","pushedAt":"1700000022.5"}',
        'ready_at' => 1700000022.5,
        'first_attempt' => true,
        'display_name' => 'App\Jobs\A',
    ],
];
