<?php

declare(strict_types=1);

/*
 * src/Grafana is maintainer code shipped to applications: internal, final, and ASCII (the rendered glyphs are
 * written as \u{...} escapes, Arch Q5.7 item 7).
 */

arch('the renderer classes are final')
    ->expect('DF\HorizonObservability\Grafana')
    ->classes()
    ->toBeFinal();

it('marks every renderer file internal and keeps it ASCII', function (): void {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src/Grafana', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $code = (string) file_get_contents((string) $file);

        expect($code)->toContain('@internal')
            ->and(preg_match('/[^\x00-\x7F]/', $code))->toBe(0, $file.' contains non-ASCII characters');
    }
});

it('writes every package selector through Promql::selector()', function (): void {
    // metric() is the bare name; selector() is the one place WP8B adds the job matcher and the dedupe wrapper
    // (Arch Q5.6 steps 4 and 10). A bare name is needed only as text: the `__name__` regex of the Job variable
    // and the summary of the no-metrics rule.
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src/Grafana', FilesystemIterator::SKIP_DOTS));
    $names = [];

    foreach ($files as $file) {
        $count = preg_match_all('/->metric\(/', (string) file_get_contents((string) $file));

        if ($count > 0 && $file->getFilename() !== 'Promql.php') {
            $names[$file->getFilename()] = $count;
        }
    }

    ksort($names);

    expect($names)->toBe(['AlertRulesBuilder.php' => 1, 'Variables.php' => 1]);
});
