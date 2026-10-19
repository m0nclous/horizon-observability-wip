<?php

declare(strict_types=1);

use DF\HorizonObservability\Install\EnvFile;
use Dotenv\Dotenv;
use Illuminate\Support\Env;

/*
| The .env editing of horizon-observability:install: one line, never a value that is already set. A line Laravel's
| env() reads as no value (null, (null), empty, (empty) in any case, bare or quoted: T14 QA round 3) is not set.
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

it('reads a value as Laravel\'s env() does', function (?string $literal, string|bool|null $expected): void {
    expect(EnvFile::resolve($literal))->toBe($expected);

    // The ground truth: Illuminate\Support\Env::get() on the same value in the environment.
    if ($literal !== null) {
        putenv('HO_ENVFILE_PROBE='.$literal);

        try {
            expect(Env::get('HO_ENVFILE_PROBE', 'default'))->toBe($expected);
        } finally {
            putenv('HO_ENVFILE_PROBE');
        }
    }
})->with([
    'absent' => [null, null],
    'null' => ['null', null],
    'NULL' => ['NULL', null],
    'Null' => ['Null', null],
    '(null)' => ['(null)', null],
    '(NULL)' => ['(NULL)', null],
    'empty' => ['empty', ''],
    'EMPTY' => ['EMPTY', ''],
    '(empty)' => ['(empty)', ''],
    '(Empty)' => ['(Empty)', ''],
    'nothing' => ['', ''],
    'true' => ['true', true],
    '(FALSE)' => ['(FALSE)', false],
    'a token' => ['nullable-token-0123456789', 'nullable-token-0123456789'],
    'null with a space' => ['null ', 'null '],
    'still quoted' => ['"null"', 'null'],
    'still single-quoted' => ["'abc'", 'abc'],
]);

it('treats a value Laravel reads as no value as unset, and fills it in place', function (string $contents, string $expected): void {
    expect(EnvFile::hasNoValue($contents, 'HORIZON_OBSERVABILITY_TOKEN'))->toBeTrue()
        ->and(EnvFile::with($contents, 'HORIZON_OBSERVABILITY_TOKEN', 'new-token'))->toBe($expected)
        // As the next start of the app reads it (phpdotenv, as Laravel loads .env).
        ->and(Dotenv::parse($expected)['HORIZON_OBSERVABILITY_TOKEN'])->toBe('new-token');
})->with([
    'null' => ["A=1\nHORIZON_OBSERVABILITY_TOKEN=null\nB=2\n", "A=1\nHORIZON_OBSERVABILITY_TOKEN=new-token\nB=2\n"],
    'NULL' => ["HORIZON_OBSERVABILITY_TOKEN=NULL\n", "HORIZON_OBSERVABILITY_TOKEN=new-token\n"],
    '(null)' => ["HORIZON_OBSERVABILITY_TOKEN=(null)\n", "HORIZON_OBSERVABILITY_TOKEN=new-token\n"],
    '(Null)' => ["HORIZON_OBSERVABILITY_TOKEN=(Null)\n", "HORIZON_OBSERVABILITY_TOKEN=new-token\n"],
    'empty' => ["HORIZON_OBSERVABILITY_TOKEN=empty\n", "HORIZON_OBSERVABILITY_TOKEN=new-token\n"],
    '(EMPTY)' => ["HORIZON_OBSERVABILITY_TOKEN=(EMPTY)\n", "HORIZON_OBSERVABILITY_TOKEN=new-token\n"],
    '"null"' => ["HORIZON_OBSERVABILITY_TOKEN=\"null\"\n", "HORIZON_OBSERVABILITY_TOKEN=new-token\n"],
    "'(empty)'" => ["HORIZON_OBSERVABILITY_TOKEN='(empty)'\n", "HORIZON_OBSERVABILITY_TOKEN=new-token\n"],
    'spaces and export' => ["  export HORIZON_OBSERVABILITY_TOKEN = null  \n", "  export HORIZON_OBSERVABILITY_TOKEN=new-token\n"],
    'CRLF' => ["A=1\r\nHORIZON_OBSERVABILITY_TOKEN=null\r\nB=2\r\n", "A=1\r\nHORIZON_OBSERVABILITY_TOKEN=new-token\r\nB=2\r\n"],
    'no final newline' => ['HORIZON_OBSERVABILITY_TOKEN=(null)', 'HORIZON_OBSERVABILITY_TOKEN=new-token'],
    // The last definition wins: a value before it is not read, and stays as it is.
    'after a value' => ["HORIZON_OBSERVABILITY_TOKEN=old-token-0123456789\nHORIZON_OBSERVABILITY_TOKEN=null\n", "HORIZON_OBSERVABILITY_TOKEN=old-token-0123456789\nHORIZON_OBSERVABILITY_TOKEN=new-token\n"],
    'with a comment: a later line' => ["HORIZON_OBSERVABILITY_TOKEN=null # set by install\n", "HORIZON_OBSERVABILITY_TOKEN=null # set by install\n\nHORIZON_OBSERVABILITY_TOKEN=new-token\n"],
]);

it('never changes a value Laravel reads as a value', function (string $contents): void {
    expect(EnvFile::hasNoValue($contents, 'HORIZON_OBSERVABILITY_TOKEN'))->toBeFalse()
        ->and(EnvFile::with($contents, 'HORIZON_OBSERVABILITY_TOKEN', 'new-token'))->toBe($contents);
})->with([
    'a token' => ["HORIZON_OBSERVABILITY_TOKEN=nullnullnullnull00\n"],
    'a token in quotes' => ["HORIZON_OBSERVABILITY_TOKEN=\"empty-token-0123456789\"\n"],
    'true' => ["HORIZON_OBSERVABILITY_TOKEN=true\n"],
    '(false)' => ["HORIZON_OBSERVABILITY_TOKEN=(false)\n"],
    'a value after null' => ["HORIZON_OBSERVABILITY_TOKEN=null\nHORIZON_OBSERVABILITY_TOKEN=later-token-0123456789\n"],
]);
