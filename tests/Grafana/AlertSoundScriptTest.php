<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\Resources;
use Symfony\Component\Process\Process;

/*
 * Arch Q5.4: the alert sound script of panel #23 (resources/grafana/js/alert-sound.js), run in Node with a fake
 * Grafana context and fake browser APIs: the tone always, then the firing rule titles spoken by the browser
 * (tts_url "browser", en-US) or by a Piper server (an http(s) URL), or not at all without tts_url.
 */

pest()->group('grafana');

/**
 * @return array{globals: list<string>, oscillators: int, speak: list<array{text: string, lang: string}>, fetch: list<array{url: string, body: string}>}
 */
function runAlertSound(string $ttsUrl, string $to = 'now'): array
{
    $harness = <<<'HARNESS'
        const [script, ttsUrl, to] = [require('fs').readFileSync(process.argv[2], 'utf8'), process.argv[3], process.argv[4]];
        const calls = { oscillators: 0, speak: [], fetch: [] };
        const node = () => ({ connect: () => node(), start() {}, stop() {}, type: '', frequency: { value: 0 },
          gain: { setValueAtTime() {}, exponentialRampToValueAtTime() {} } });
        global.window = {
          AudioContext: class {
            constructor() { this.state = 'running'; this.currentTime = 0; this.destination = {}; }
            createOscillator() { calls.oscillators++; return node(); }
            createGain() { return node(); }
            resume() { return Promise.resolve(); }
            decodeAudioData() { return Promise.resolve({}); }
            createBufferSource() { return node(); }
          },
          speechSynthesis: { speak: (utterance) => calls.speak.push({ text: utterance.text, lang: utterance.lang }) },
          SpeechSynthesisUtterance: class { constructor(text) { this.text = text; this.lang = ''; } },
        };
        global.document = { addEventListener() {} };
        global.fetch = (url, init) => { calls.fetch.push({ url, body: init.body }); return Promise.resolve({ ok: false, status: 503 }); };
        global.setTimeout = (callback) => callback();
        console.warn = () => {};
        const rules = ['horizon-observability-long-wait', 'horizon-observability-long-wait', 'horizon-observability-down'];
        const names = ['Queue wait over 2x limit', 'Queue wait over 2x limit', 'Horizon is down'];
        const context = {
          element: { closest: () => null },
          panelData: { series: [{ length: 3, fields: [{ name: 'grafana_rule_uid', values: rules }, { name: 'alertname', values: names }] }] },
          grafana: { replaceVariables: (text) => (text === '${tts_url}' ? ttsUrl : text), timeRange: { raw: { to } } },
        };
        new Function('context', script)(context);
        setImmediate(() => console.log(JSON.stringify({ globals: Object.keys(window), ...calls })));
        HARNESS;

    $script = tempnam(sys_get_temp_dir(), 'ho-alert-sound-');
    file_put_contents($script, Resources::alertSoundScript());

    try {
        $process = new Process(['node', '-', $script, $ttsUrl, $to]);
        $process->setInput($harness)->mustRun();
    } finally {
        unlink($script);
    }

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    if ((new Process(['node', '--version']))->run() !== 0) {
        $this->markTestSkipped('Node is not installed.');
    }
});

it('rings and speaks the firing rules with the browser speech in en-US', function (): void {
    $run = runAlertSound('browser');

    expect($run['oscillators'])->toBe(10)
        ->and($run['speak'])->toBe([['text' => 'Queue wait over twice the limit. Horizon is down.', 'lang' => 'en-US']])
        ->and($run['fetch'])->toBe([])
        ->and($run['globals'])->toContain('__horizonObservabilityAlertSound');
});

it('asks a Piper server for the phrase', function (): void {
    $run = runAlertSound('http://tts.internal:5000');

    expect($run['oscillators'])->toBe(10)
        ->and($run['speak'])->toBe([])
        ->and($run['fetch'])->toBe([[
            'url' => 'http://tts.internal:5000/synthesize',
            'body' => '{"text":"Queue wait over twice the limit. Horizon is down."}',
        ]]);
});

it('rings without speech when no speech service is exported', function (string $ttsUrl): void {
    $run = runAlertSound($ttsUrl);

    expect($run['oscillators'])->toBe(10)
        ->and($run['speak'])->toBe([])
        ->and($run['fetch'])->toBe([]);
})->with(['empty' => '', 'unresolved variable' => '${tts_url}']);

it('stays silent on a past period', function (): void {
    $run = runAlertSound('browser', to: '1700000000000');

    expect($run['oscillators'])->toBe(0)
        ->and($run['speak'])->toBe([]);
});

it('is English ASCII with the renamed global', function (): void {
    $script = Resources::alertSoundScript();

    expect(preg_match('/[^\x00-\x7F]/', $script))->toBe(0)
        ->and($script)->toContain('window.__horizonObservabilityAlertSound')
        ->not->toContain('__horizonAlertSound')
        ->not->toEndWith("\n");
});
