<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\ExceptionMode;
use DF\HorizonObservability\Http\ExceptionSummary;

/*
| The exception column of the failed-jobs list (Arch Q2.1, Q8.4, C4): the first line Horizon stores, shortened like
| a job name, in the three `exceptions` modes.
*/

const EXCEPTION_SUMMARY_QUERY = "Illuminate\\Database\\QueryException: SQLSTATE[HY000] [2002] Connection refused (Connection: mysql, SQL: select 1) in /app/vendor/laravel/framework/src/Illuminate/Database/Connection.php:825\nStack trace:\n#0 /app/vendor/laravel/framework/src/Illuminate/Database/Connection.php(779): Illuminate\\Database\\Connection->runQueryCallback()\n#1 {main}";

it('shortens the first line in full mode', function (string $stored, string $expected): void {
    expect(ExceptionSummary::summarize($stored, ExceptionMode::Full))->toBe($expected);
})->with([
    'query exception with its stack trace' => [EXCEPTION_SUMMARY_QUERY, 'QueryException: SQLSTATE[HY000] [2002] Connection refused (Connection: mysql, SQL: select 1)'],
    'global class' => ["RuntimeException: Boom in /app/app/Jobs/SendInvoice.php:31\nStack trace:\n#0 {main}", 'RuntimeException: Boom'],
    'empty message' => ["RuntimeException in /app/app/Jobs/SendInvoice.php:31\nStack trace:", 'RuntimeException'],
    'first line only (as job_details.lua returns it)' => ['App\Exceptions\PaymentDeclined: Card declined in /app/app/Exceptions/PaymentDeclined.php:12', 'PaymentDeclined: Card declined'],
    'a multiline message keeps its first line' => ["RuntimeException: first\nsecond in /app/x.php:3", 'RuntimeException: first'],
    'CRLF line ends' => ["RuntimeException: first\r\nStack trace:", 'RuntimeException: first'],
    'an "in" inside the message stays' => ['InvalidArgumentException: Value in column total is invalid in /app/app/Jobs/Import.php:40', 'InvalidArgumentException: Value in column total is invalid'],
    'a path inside the message stays' => ['RuntimeException: Cannot read /tmp/feed.csv in /app/app/Jobs/Import.php:40', 'RuntimeException: Cannot read /tmp/feed.csv'],
    'a location with spaces in the path' => ['RuntimeException: Boom in /srv/my app/app/Jobs/Import.php:40', 'RuntimeException: Boom'],
    'a Windows location' => ['App\Jobs\Failure: Boom in C:\www\app\Jobs\Failure.php:12', 'Failure: Boom'],
    'a phar location' => ['RuntimeException: Boom in phar:///app/tool.phar/src/Runner.php:7', 'RuntimeException: Boom'],
    'an eval location' => ["ParseError: syntax error in /app/x.php(3) : eval()'d code:1", 'ParseError: syntax error'],
    'a line without a location' => ['App\Jobs\Failure: Boom', 'Failure: Boom'],
    'a namespace in another script' => ['App\Задачи\Ошибка: Сбой оплаты in /app/app/Задачи/Ошибка.php:9', 'Ошибка: Сбой оплаты'],
    'a class named in the message keeps its namespace' => ['Illuminate\Queue\MaxAttemptsExceededException: App\Jobs\SendInvoice has been attempted too many times. in /app/vendor/laravel/framework/src/Illuminate/Queue/MaxAttemptsExceededException.php:24', 'MaxAttemptsExceededException: App\Jobs\SendInvoice has been attempted too many times.'],
]);

it('keeps only the short class in class mode', function (string $stored, string $expected): void {
    expect(ExceptionSummary::summarize($stored, ExceptionMode::ClassOnly))->toBe($expected);
})->with([
    'query exception' => [EXCEPTION_SUMMARY_QUERY, 'QueryException'],
    'empty message' => ["RuntimeException in /app/app/Jobs/SendInvoice.php:31\nStack trace:", 'RuntimeException'],
    'anonymous class' => ["App\\Exceptions\\ShouldntReport@anonymous\0/app/app/Jobs/Simulated.php:40\$1a: Simulated failure in /app/app/Jobs/Simulated.php:41", 'ShouldntReport@anonymous'],
]);

it('gives nothing in none mode, without an exception and for an empty line', function (): void {
    expect(ExceptionSummary::summarize(EXCEPTION_SUMMARY_QUERY, ExceptionMode::None))->toBeNull()
        ->and(ExceptionSummary::summarize(null, ExceptionMode::Full))->toBeNull()
        ->and(ExceptionSummary::summarize(null, ExceptionMode::ClassOnly))->toBeNull()
        ->and(ExceptionSummary::summarize('', ExceptionMode::Full))->toBeNull()
        ->and(ExceptionSummary::summarize("\nStack trace:", ExceptionMode::Full))->toBeNull()
        ->and(ExceptionSummary::summarize('   ', ExceptionMode::ClassOnly))->toBeNull();
});

it('drops the NUL byte, file and counter of an anonymous class (@anonymous)', function (): void {
    $exception = new class('Simulated failure') extends RuntimeException {};

    expect($exception::class)->toContain("\0")
        ->and(ExceptionSummary::summarize((string) $exception, ExceptionMode::Full))->toBe('RuntimeException@anonymous: Simulated failure')
        ->and(ExceptionSummary::summarize("App\\Exceptions\\ShouldntReport@anonymous\0/app/app/Jobs/Simulated.php:40\$1a: Simulated failure in /app/app/Jobs/Simulated.php:41", ExceptionMode::Full))
        ->toBe('ShouldntReport@anonymous: Simulated failure');
});

it('drops the rest of an anonymous class name the byte cap cut', function (): void {
    $cut = "App\\Exceptions\\ShouldntReport@anonymous\0/app/".str_repeat('a', 1200).'/Simulated.php:40$1a: Simulated failure';

    expect(ExceptionSummary::summarize($cut, ExceptionMode::Full))->toBe('ShouldntReport@anonymous')
        ->and(ExceptionSummary::summarize($cut, ExceptionMode::ClassOnly))->toBe('ShouldntReport@anonymous');
});

it('summarises real exceptions, a previous exception first', function (): void {
    $previous = new LogicException('outer', 0, new RuntimeException('inner'));

    expect(ExceptionSummary::summarize((string) new RuntimeException('Boom'), ExceptionMode::Full))->toBe('RuntimeException: Boom')
        ->and(ExceptionSummary::summarize((string) new InvalidArgumentException, ExceptionMode::Full))->toBe('InvalidArgumentException')
        ->and(ExceptionSummary::summarize((string) $previous, ExceptionMode::Full))->toBe('RuntimeException: inner');
});

it('caps the line at 1000 bytes and drops a multibyte character the cap cut', function (): void {
    $message = 'RuntimeException: '.str_repeat('a', 981).'Ж tail in /app/x.php:3';
    $summary = ExceptionSummary::summarize($message, ExceptionMode::Full);

    expect(ExceptionSummary::MAX_BYTES)->toBe(1000)
        ->and(strlen('RuntimeException: '.str_repeat('a', 981)))->toBe(999)
        ->and($summary)->toBe('RuntimeException: '.str_repeat('a', 981))
        ->and(ExceptionSummary::summarize('RuntimeException: '.str_repeat('б', 2000), ExceptionMode::Full))
        ->toBe('RuntimeException: '.str_repeat('б', 491));
});

it('returns valid UTF-8 for broken bytes (mb_scrub)', function (): void {
    $summary = ExceptionSummary::summarize("RuntimeException: bad \xff\xfe byte and a cut \xd0", ExceptionMode::Full);

    expect($summary)->toBe('RuntimeException: bad ?? byte and a cut')
        ->and(mb_check_encoding((string) $summary, 'UTF-8'))->toBeTrue()
        ->and(json_encode($summary))->not->toBeFalse();
});
