// Панель прячется, а вся сетка поднимается на её строку: отдельные панели Grafana расставляет сама и вернула бы
// на место, а отступ сетки целиком не трогает
const item = context.element.closest('.react-grid-item');
const grid = item && item.parentElement;
if (grid && !item.style.visibility) {
  const tops = [...new Set([...grid.children].map((child) => Math.round(child.getBoundingClientRect().top)))]
    .sort((a, b) => a - b);
  item.style.visibility = 'hidden';
  grid.style.marginTop = -(tops.length > 1 ? tops[1] - tops[0] : item.offsetHeight + 8) + 'px';
}

// Сработавшие правила: экземпляры GRAFANA_ALERTS, у правил по очередям — по экземпляру на очередь
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
// Фраза — названия сработавших правил. «2x» синтез читает как «two x», поэтому множитель — словами
const sayable = (name) => name.replace(/\b2x\b/g, 'twice the').replace(/\b(\d+)x\b/g, '$1 times the');
const phrase = [...rules.values()].map((name) => sayable(name) + '.').join(' ');
const ttsUrl = context.grafana.replaceVariables('${tts_url}');

// Контекст звука создаётся, только когда пора звенеть или по клику: созданный до клика пользователя браузер
// не запускает и пишет об этом в консоль. Включается он кликом где угодно на странице, а на телевизоре с флагом
// автозапуска работает сразу
const REPEAT_MS = 5 * 60 * 1000;
const state = (window.__horizonAlertSound = window.__horizonAlertSound || { firing: 0, lastPlayed: 0, audio: null });
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
// Сигнал тревоги высокого приоритета по мотивам IEC 60601-1-8 (мониторы в реанимации): пачка из 10 быстрых
// импульсов 3+2 / 3+2, тон square — с гармониками, как требует стандарт. Синтезирует сам браузер, поэтому звучит
// и тогда, когда речь не получилась. Возвращает время, когда он закончится
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
    // Плавные края: резкое включение и выключение щёлкает в динамиках
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
// Речь: WAV от Piper, проигрывается после сигнала тревоги. Ошибка сети или сервиса — остаётся сигнал
const speak = (ctx) => {
  if (!ttsUrl || ttsUrl.startsWith('$')) {
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
  // Пока браузер звук не разрешил, сигнал ждёт следующего обновления
  const ctx = audio();
  if (ctx && ctx.state === 'running') {
    // Фраза — сразу после сигнала тревоги
    state.speechAfter = alarm(ctx) + 0.3;
    speak(ctx);
    state.lastPlayed = Date.now();
    state.firing = firing;
  }
}