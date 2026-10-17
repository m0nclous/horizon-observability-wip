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
