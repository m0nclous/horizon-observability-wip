<?php

declare(strict_types=1);

use DF\HorizonObservability\Attempts\JobClassLabel;

it('normalises display names', function (?string $displayName, string $expected): void {
    expect(JobClassLabel::normalize($displayName))->toBe($expected);
})->with([
    'class' => ['App\\Jobs\\SendInvoice', 'App\\Jobs\\SendInvoice'],
    'trimmed' => ["  App\\Jobs\\SendInvoice \n", 'App\\Jobs\\SendInvoice'],
    'closure line removed' => ['Closure (routes/web.php:42)', 'Closure (routes/web.php)'],
    'closure in a nested path' => ['Closure (app/Console/Kernel.php:120)', 'Closure (app/Console/Kernel.php)'],
    'closure without a line' => ['Closure (routes/web.php)', 'Closure (routes/web.php)'],
    'colon elsewhere kept' => ['App\\Jobs\\Report:42', 'App\\Jobs\\Report:42'],
    'listener wrapper kept' => ['App\\Listeners\\SendWelcome', 'App\\Listeners\\SendWelcome'],
    'empty' => ['', '(unknown)'],
    'whitespace' => ['   ', '(unknown)'],
    'null' => [null, '(unknown)'],
    'control characters only' => ["\x1f\x00", '(unknown)'],
    'control characters stripped' => ["App\\Jobs\\A\x1fB", 'App\\Jobs\\AB'],
]);

it('caps long names', function (): void {
    expect(strlen(JobClassLabel::normalize('App\\Jobs\\'.str_repeat('X', 300))))->toBe(200);
});
