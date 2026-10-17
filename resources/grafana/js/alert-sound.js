// The panel hides itself and lifts the whole grid by its row: Grafana places single panels itself and would move
// them back, but leaves the margin of the whole grid alone
const item = context.element.closest('.react-grid-item');
const grid = item && item.parentElement;
if (grid && !item.style.visibility) {
  const tops = [...new Set([...grid.children].map((child) => Math.round(child.getBoundingClientRect().top)))]
    .sort((a, b) => a - b);
  item.style.visibility = 'hidden';
  grid.style.marginTop = -(tops.length > 1 ? tops[1] - tops[0] : item.offsetHeight + 8) + 'px';
}

// Firing rules: GRAFANA_ALERTS instances, one per queue for the per-queue rules
const frame = ((context.panelData && context.panelData.series) || [])[0];
const column = (name) => {
  const field = frame && frame.fields.find((item) => item.name === name);
  const values = field ? field.values : [];
  return (index) => (values.get ? values.get(index) : values[index]);
};
const length = frame ? (frame.length !== undefined ? frame.length : (frame.fields[0] ? frame.fields[0].values.length : 0)) : 0;
const [ruleOf, nameOf] = [column('grafana_rule_uid'), column('alertname')];
const rules = new Map();
for (let index = 0; index < length; index++) {
  rules.set(ruleOf(index), nameOf(index));
}
const firing = rules.size;
// The phrase is the titles of the firing rules. Speech synthesis reads "2x" as "two x", so the factor is spelled out
const sayable = (name) => name.replace(/\b2x\b/g, 'twice the').replace(/\b(\d+)x\b/g, '$1 times the');
const phrase = [...rules.values()].map((name) => sayable(name) + '.').join(' ');
// horizon_info{tts_url}: empty for the tone only, "browser" for the browser's own speech, or a Piper server URL
const ttsUrl = context.grafana.replaceVariables('${tts_url}');

// The audio context is created only when it is time to ring, or on a click: a browser does not start a context
// created before a user gesture and logs a warning. A click anywhere on the page unlocks it; a TV browser with the
// autoplay flag plays at once
const REPEAT_MS = 5 * 60 * 1000;
const state = (window.__horizonObservabilityAlertSound = window.__horizonObservabilityAlertSound || { firing: 0, lastPlayed: 0, audio: null });
const AudioContextClass = window.AudioContext || window.webkitAudioContext;
const audio = () => (state.audio = state.audio || (AudioContextClass ? new AudioContextClass() : null));
if (!state.unlocking) {
  document.addEventListener('pointerdown', () => {
    const ctx = audio();
    if (ctx && ctx.state !== 'running') {
      ctx.resume().catch(() => {});
    }
  });
  state.unlocking = true;
}
// High-priority alarm after IEC 60601-1-8 (intensive care monitors): a burst of 10 fast pulses, 3+2 / 3+2, as a
// square tone with harmonics as the standard asks. The browser synthesizes it, so it sounds even when speech fails.
// Returns the time it ends
const PULSES = [
  [0, 523], [0.19, 659], [0.38, 784], [0.75, 659], [0.94, 784],
  [1.5, 523], [1.69, 659], [1.88, 784], [2.25, 659], [2.44, 784],
];
const alarm = (ctx) => {
  const start = ctx.currentTime;
  const length = 0.12;
  PULSES.forEach(([at, freq]) => {
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = 'square';
    osc.frequency.value = freq;
    // Soft edges: switching a tone on or off at once clicks in the speakers
    gain.gain.setValueAtTime(0.0001, start + at);
    gain.gain.exponentialRampToValueAtTime(0.13, start + at + 0.02);
    gain.gain.setValueAtTime(0.13, start + at + length - 0.02);
    gain.gain.exponentialRampToValueAtTime(0.0001, start + at + length);
    osc.connect(gain).connect(ctx.destination);
    osc.start(start + at);
    osc.stop(start + at + length);
  });
  return start + PULSES[PULSES.length - 1][0] + length;
};
const live = String(context.grafana.timeRange.raw.to) === 'now';
// Speech after the alarm: "browser" speaks with the Web Speech API (en-US, no server needed); a URL is a Piper
// server that returns WAV for POST {url}/synthesize. A network or service error leaves the alarm
const speak = (ctx) => {
  if (!ttsUrl || ttsUrl.startsWith('$')) {
    return;
  }
  if (ttsUrl === 'browser') {
    if (!window.speechSynthesis || !window.SpeechSynthesisUtterance) {
      console.warn('Alert speech failed', 'this browser has no speech synthesis');
      return;
    }
    const utterance = new window.SpeechSynthesisUtterance(phrase);
    utterance.lang = 'en-US';
    setTimeout(() => window.speechSynthesis.speak(utterance), Math.max(0, (state.speechAfter || 0) - ctx.currentTime) * 1000);
    return;
  }
  fetch(ttsUrl + '/synthesize', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ text: phrase }),
  })
    .then((response) => (response.ok ? response.arrayBuffer() : Promise.reject(response.status)))
    .then((data) => ctx.decodeAudioData(data))
    .then((buffer) => {
      const source = ctx.createBufferSource();
      source.buffer = buffer;
      source.connect(ctx.destination);
      source.start(Math.max(ctx.currentTime, state.speechAfter || 0));
    })
    .catch((error) => console.warn('Alert speech failed', error));
};
const due = live && firing > 0 && (firing > state.firing || Date.now() - state.lastPlayed >= REPEAT_MS);
if (!due) {
  state.firing = live ? firing : 0;
} else {
  // Until the browser allows sound, the alarm waits for the next refresh
  const ctx = audio();
  if (ctx && ctx.state === 'running') {
    // The phrase follows the alarm at once
    state.speechAfter = alarm(ctx) + 0.3;
    speak(ctx);
    state.lastPlayed = Date.now();
    state.firing = firing;
  }
}
