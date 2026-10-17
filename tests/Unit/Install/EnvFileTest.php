<?php

declare(strict_types=1);

use DF\HorizonObservability\Install\EnvFile;

/*
| The .env editing of horizon-observability:install: one line, never a value that is already set.
*/

it('reads a value', function (string $contents, ?string $value): void {
    expect(EnvFile::value($contents, 'HORIZON_OBSERVABILITY_TOKEN'))->toBe($value);
})->with([
    'absent' => ["APP_NAME=Laravel\n", null],
    'empty' => ["HORIZON_OBSERVABILITY_TOKEN=\n", ''],
    'plain' => ["HORIZON_OBSERVABILITY_TOKEN=abc123\n", 'abc123'],
    'double quotes' => ["HORIZON_OBSERVABILITY_TOKEN=\"a b\"\n", 'a b'],
    'single quotes' => ["HORIZON_OBSERVABILITY_TOKEN='a#b'\n", 'a#b'],
    'empty quotes' => ["HORIZON_OBSERVABILITY_TOKEN=\"\"\n", ''],
    'comment' => ["HORIZON_OBSERVABILITY_TOKEN=abc # rotated\n", 'abc'],
    'export and spaces' => ["  export HORIZON_OBSERVABILITY_TOKEN = abc\n", 'abc'],
    'the last one wins' => ["HORIZON_OBSERVABILITY_TOKEN=a\nHORIZON_OBSERVABILITY_TOKEN=b\n", 'b'],
    'commented out' => ["# HORIZON_OBSERVABILITY_TOKEN=abc\n", null],
    'only a comment' => ["HORIZON_OBSERVABILITY_TOKEN= # set me\n", ''],
    'a longer key' => ["HORIZON_OBSERVABILITY_TOKEN_OLD=abc\n", null],
    'CRLF' => ["APP=1\r\nHORIZON_OBSERVABILITY_TOKEN=abc\r\n", 'abc'],
]);

it('appends a missing key and fills an empty one in place', function (string $contents, string $expected): void {
    expect(EnvFile::with($contents, 'HORIZON_OBSERVABILITY_TOKEN', 'new-token'))->toBe($expected);
})->with([
    'append' => ["APP_NAME=Laravel\n", "APP_NAME=Laravel\n\nHORIZON_OBSERVABILITY_TOKEN=new-token\n"],
    'append without a final newline' => ['APP_NAME=Laravel', "APP_NAME=Laravel\n\nHORIZON_OBSERVABILITY_TOKEN=new-token\n"],
    'empty file' => ['', "HORIZON_OBSERVABILITY_TOKEN=new-token\n"],
    'fill' => ["A=1\nHORIZON_OBSERVABILITY_TOKEN=\nB=2\n", "A=1\nHORIZON_OBSERVABILITY_TOKEN=new-token\nB=2\n"],
    'fill empty quotes' => ["HORIZON_OBSERVABILITY_TOKEN=\"\"\n", "HORIZON_OBSERVABILITY_TOKEN=new-token\n"],
    'keep a value' => ["HORIZON_OBSERVABILITY_TOKEN=old\n", "HORIZON_OBSERVABILITY_TOKEN=old\n"],
    'fill every empty definition' => ["T=1\nHORIZON_OBSERVABILITY_TOKEN=\nHORIZON_OBSERVABILITY_TOKEN=\n", "T=1\nHORIZON_OBSERVABILITY_TOKEN=new-token\nHORIZON_OBSERVABILITY_TOKEN=new-token\n"],
    'append after an empty value with a comment' => ["HORIZON_OBSERVABILITY_TOKEN= # set me\n", "HORIZON_OBSERVABILITY_TOKEN= # set me\n\nHORIZON_OBSERVABILITY_TOKEN=new-token\n"],
    'CRLF fill' => ["A=1\r\nHORIZON_OBSERVABILITY_TOKEN=\r\n", "A=1\r\nHORIZON_OBSERVABILITY_TOKEN=new-token\r\n"],
]);

it('writes the file only when it changes', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($path, "A=1\n");

    try {
        expect(EnvFile::write($path, 'HORIZON_OBSERVABILITY_TOKEN', 'x'))->toBeTrue()
            ->and(EnvFile::write($path, 'HORIZON_OBSERVABILITY_TOKEN', 'y'))->toBeFalse()
            ->and(file_get_contents($path))->toBe("A=1\n\nHORIZON_OBSERVABILITY_TOKEN=x\n");
    } finally {
        unlink($path);
    }

    expect(static fn (): bool => EnvFile::write('/nonexistent/.env', 'A', 'b'))->toThrow(RuntimeException::class, 'Cannot read /nonexistent/.env.');
});
