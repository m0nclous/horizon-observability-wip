<?php

declare(strict_types=1);

/*
| Readiness parity fixtures (Arch Q6.3, C8, C19): raw queue payloads exactly as stored in Redis, with the readiness
| PayloadClock::readyAt() computes from the decoded payload and that queue_state.lua must compute from the raw
| string, and whether the payload is a first attempt (attempts == 0). Shared by tests/Unit/Queues/PayloadClockTest
| (T02) and tests/Contract/ReadinessParityTest (T05).
|
| Each case: payload (raw JSON), ready_at (Unix seconds or null), first_attempt, display_name (decoded).
| The payloads are nowdocs, so every byte (escapes, key order) is literal.
*/

return [
    'modern dispatch (Laravel >= 12.11, Horizon >= 5.49)' => [
        'payload' => <<<'JSON'
{"uuid":"9a3c6f8e-1f2a-4c55-8f0e-0d1c2b3a4f50","displayName":"App\\Jobs\\SendInvoice","job":"Illuminate\\Queue\\CallQueuedHandler@call","maxTries":null,"maxExceptions":null,"failOnTimeout":false,"backoff":null,"timeout":null,"retryUntil":null,"data":{"commandName":"App\\Jobs\\SendInvoice","command":"O:20:\"App\\Jobs\\SendInvoice\":0:{}"},"createdAt":1700000000,"delay":null,"id":"9a3c6f8e-1f2a-4c55-8f0e-0d1c2b3a4f50","attempts":0,"type":"job","tags":[],"silenced":false,"pushedAt":"1700000000.5"}
JSON,
        'ready_at' => 1700000000.5,
        'first_attempt' => true,
        'display_name' => 'App\Jobs\SendInvoice',
    ],

    'delayed dispatch: createdAt + delay is later than pushedAt' => [
        'payload' => <<<'JSON'
{"uuid":"4f1d2e3c-5b6a-4789-9a0b-1c2d3e4f5a60","displayName":"App\\Jobs\\SendReminder","job":"Illuminate\\Queue\\CallQueuedHandler@call","maxTries":null,"maxExceptions":null,"failOnTimeout":false,"backoff":null,"timeout":null,"retryUntil":null,"data":{"commandName":"App\\Jobs\\SendReminder","command":"O:21:\"App\\Jobs\\SendReminder\":0:{}"},"createdAt":1700000000,"delay":60,"id":"4f1d2e3c-5b6a-4789-9a0b-1c2d3e4f5a60","attempts":0,"type":"job","tags":[],"silenced":false,"pushedAt":"1700000000.25"}
JSON,
        'ready_at' => 1700000060.0,
        'first_attempt' => true,
        'display_name' => 'App\Jobs\SendReminder',
    ],

    'legacy stack: no createdAt and no delay, microsecond pushedAt' => [
        'payload' => <<<'JSON'
{"uuid":"11111111-2222-4333-8444-555555555555","displayName":"App\\Jobs\\ImportFeed","job":"Illuminate\\Queue\\CallQueuedHandler@call","maxTries":3,"maxExceptions":null,"failOnTimeout":false,"backoff":null,"timeout":120,"retryUntil":null,"data":{"commandName":"App\\Jobs\\ImportFeed","command":"O:19:\"App\\Jobs\\ImportFeed\":0:{}"},"id":"11111111-2222-4333-8444-555555555555","attempts":0,"type":"job","tags":[],"silenced":false,"pushedAt":"1700000000.123456"}
JSON,
        'ready_at' => 1700000000.123456,
        'first_attempt' => true,
        'display_name' => 'App\Jobs\ImportFeed',
    ],

    'numeric pushedAt' => [
        'payload' => <<<'JSON'
{"uuid":"22222222-3333-4444-8555-666666666666","displayName":"App\\Jobs\\SendInvoice","job":"Illuminate\\Queue\\CallQueuedHandler@call","data":{"commandName":"App\\Jobs\\SendInvoice","command":"O:20:\"App\\Jobs\\SendInvoice\":0:{}"},"createdAt":1700000000,"delay":null,"id":"22222222-3333-4444-8555-666666666666","attempts":0,"pushedAt":1700000000.75}
JSON,
        'ready_at' => 1700000000.75,
        'first_attempt' => true,
        'display_name' => 'App\Jobs\SendInvoice',
    ],

    'plain Laravel without Horizon: no pushedAt, createdAt + delay' => [
        'payload' => <<<'JSON'
{"uuid":"33333333-4444-4555-8666-777777777777","displayName":"App\\Jobs\\SendReminder","job":"Illuminate\\Queue\\CallQueuedHandler@call","data":{"commandName":"App\\Jobs\\SendReminder","command":"O:21:\"App\\Jobs\\SendReminder\":0:{}"},"createdAt":1700000000,"delay":30,"id":"33333333-4444-4555-8666-777777777777","attempts":0}
JSON,
        'ready_at' => 1700000030.0,
        'first_attempt' => true,
        'display_name' => 'App\Jobs\SendReminder',
    ],

    'no timestamps at all: readiness unknown' => [
        'payload' => <<<'JSON'
{"uuid":"44444444-5555-4666-8777-888888888888","displayName":"App\\Jobs\\SendInvoice","job":"Illuminate\\Queue\\CallQueuedHandler@call","data":{"commandName":"App\\Jobs\\SendInvoice","command":"O:20:\"App\\Jobs\\SendInvoice\":0:{}"},"id":"44444444-5555-4666-8777-888888888888","attempts":0}
JSON,
        'ready_at' => null,
        'first_attempt' => true,
        'display_name' => 'App\Jobs\SendInvoice',
    ],

    'released job: keys reordered and slashes escaped by cjson' => [
        'payload' => <<<'JSON'
{"attempts":2,"backoff":null,"createdAt":1700000000,"data":{"command":"O:20:\"App\\Jobs\\SendInvoice\":0:{}","commandName":"App\\Jobs\\SendInvoice"},"delay":null,"displayName":"App\\Jobs\\SendInvoice","failOnTimeout":false,"id":"55555555-6666-4777-8888-999999999999","job":"Illuminate\\Queue\\CallQueuedHandler@call","maxExceptions":null,"maxTries":3,"pushedAt":"1700000100.5","retryUntil":null,"silenced":false,"tags":["invoice:a\/b"],"timeout":null,"type":"job","uuid":"55555555-6666-4777-8888-999999999999"}
JSON,
        'ready_at' => 1700000100.5,
        'first_attempt' => false,
        'display_name' => 'App\Jobs\SendInvoice',
    ],

    'escaped quotes: key names inside a string value must not match' => [
        'payload' => <<<'JSON'
{"uuid":"66666666-7777-4888-8999-aaaaaaaaaaaa","displayName":"App\\Jobs\\Weird \"pushedAt\":1 \"attempts\":0 \"delay\":999","job":"Illuminate\\Queue\\CallQueuedHandler@call","data":{"commandName":"App\\Jobs\\Weird","command":"O:14:\"App\\Jobs\\Weird\":1:{s:8:\"pushedAt\";i:5;}"},"createdAt":1700000000,"delay":null,"id":"66666666-7777-4888-8999-aaaaaaaaaaaa","attempts":1,"type":"job","tags":[],"silenced":false,"pushedAt":"1700000200.5"}
JSON,
        'ready_at' => 1700000200.5,
        'first_attempt' => false,
        'display_name' => 'App\Jobs\Weird "pushedAt":1 "attempts":0 "delay":999',
    ],

    'unicode escapes in the display name' => [
        'payload' => <<<'JSON'
{"uuid":"77777777-8888-4999-8aaa-bbbbbbbbbbbb","displayName":"App\\Jobs\\Отчёт","job":"Illuminate\\Queue\\CallQueuedHandler@call","data":{"commandName":"App\\Jobs\\Отчёт","command":"O:19:\"App\\Jobs\\Отчёт\":0:{}"},"createdAt":1700000000,"delay":null,"id":"77777777-8888-4999-8aaa-bbbbbbbbbbbb","attempts":0,"type":"job","tags":[],"silenced":false,"pushedAt":"1700000300.5"}
JSON,
        'ready_at' => 1700000300.5,
        'first_attempt' => true,
        'display_name' => "App\\Jobs\\\u{041E}\u{0442}\u{0447}\u{0451}\u{0442}",
    ],

    'Horizon retry: attempts reset, fresh pushedAt beats the stale delay (C19)' => [
        'payload' => <<<'JSON'
{"uuid":"88888888-9999-4aaa-8bbb-cccccccccccc","displayName":"App\\Jobs\\SendReminder","job":"Illuminate\\Queue\\CallQueuedHandler@call","maxTries":null,"maxExceptions":null,"failOnTimeout":false,"backoff":null,"timeout":null,"retryUntil":null,"data":{"commandName":"App\\Jobs\\SendReminder","command":"O:21:\"App\\Jobs\\SendReminder\":0:{}"},"createdAt":1700000000,"delay":600,"id":"88888888-9999-4aaa-8bbb-cccccccccccc","attempts":0,"type":"job","tags":[],"silenced":false,"pushedAt":"1700005000.5","retry_of":"4f1d2e3c-5b6a-4789-9a0b-1c2d3e4f5a60"}
JSON,
        'ready_at' => 1700005000.5,
        'first_attempt' => true,
        'display_name' => 'App\Jobs\SendReminder',
    ],

    'queue:retry: attempts reset, fresh pushedAt' => [
        'payload' => <<<'JSON'
{"uuid":"99999999-aaaa-4bbb-8ccc-dddddddddddd","displayName":"App\\Jobs\\ImportFeed","job":"Illuminate\\Queue\\CallQueuedHandler@call","maxTries":3,"maxExceptions":null,"failOnTimeout":false,"backoff":null,"timeout":120,"retryUntil":null,"data":{"commandName":"App\\Jobs\\ImportFeed","command":"O:19:\"App\\Jobs\\ImportFeed\":0:{}"},"createdAt":1700000000,"delay":null,"id":"99999999-aaaa-4bbb-8ccc-dddddddddddd","attempts":0,"type":"job","tags":[],"silenced":false,"pushedAt":"1700009000.0"}
JSON,
        'ready_at' => 1700009000.0,
        'first_attempt' => true,
        'display_name' => 'App\Jobs\ImportFeed',
    ],

    'delay 0 with app clock skew: createdAt wins over an earlier pushedAt' => [
        'payload' => <<<'JSON'
{"uuid":"aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee","displayName":"App\\Jobs\\SendInvoice","job":"Illuminate\\Queue\\CallQueuedHandler@call","data":{"commandName":"App\\Jobs\\SendInvoice","command":"O:20:\"App\\Jobs\\SendInvoice\":0:{}"},"createdAt":1700000000,"delay":0,"id":"aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee","attempts":0,"pushedAt":"1699999999.9"}
JSON,
        'ready_at' => 1700000000.0,
        'first_attempt' => true,
        'display_name' => 'App\Jobs\SendInvoice',
    ],

    'string createdAt is not a timestamp: the delay is ignored' => [
        'payload' => <<<'JSON'
{"uuid":"bbbbbbbb-cccc-4ddd-8eee-ffffffffffff","displayName":"App\\Jobs\\SendInvoice","job":"Illuminate\\Queue\\CallQueuedHandler@call","data":{"commandName":"App\\Jobs\\SendInvoice","command":"O:20:\"App\\Jobs\\SendInvoice\":0:{}"},"createdAt":"1700000000","delay":60,"id":"bbbbbbbb-cccc-4ddd-8eee-ffffffffffff","attempts":0,"pushedAt":"1700000000.5"}
JSON,
        'ready_at' => 1700000000.5,
        'first_attempt' => true,
        'display_name' => 'App\Jobs\SendInvoice',
    ],
];
