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

it('writes every package selector through the Promql catalog builders', function (): void {
    // selector(), shifted() and over() take a Metrics\Metric case and add the job scope and the replica dedupe
    // (Arch Q5.6 steps 4 and 10). A bare name is needed only as text: the label_values() query of the job variable
    // and the summary of the no-metrics rule. No family is named by a string literal outside Promql.
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src/Grafana', FilesystemIterator::SKIP_DOTS));
    $names = [];
    $literals = [];

    foreach ($files as $file) {
        $code = (string) file_get_contents((string) $file);
        $count = preg_match_all('/->name\(/', $code);

        if ($count > 0 && $file->getFilename() !== 'Promql.php') {
            $names[$file->getFilename()] = $count;
        }

        if ($file->getFilename() !== 'Promql.php' && preg_match_all("/'(?:queue|horizon|exporter)_[a-z_]+'/", $code, $matches) > 0) {
            array_push($literals, ...array_diff($matches[0], ["'horizon_url'"]));
        }
    }

    ksort($names);

    expect($names)->toBe(['AlertRulesBuilder.php' => 2, 'Variables.php' => 1])
        ->and($literals)->toBe([]);
});
