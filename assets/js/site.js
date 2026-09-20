/* АЙРИС · сценарий сайта. Чистый JS, без библиотек. */
(() => {
'use strict';

/* ---------- утилиты ---------- */
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));
const clamp = (v, a, b) => (v < a ? a : v > b ? b : v);
const smooth = (p, a, b) => { const t = clamp((p - a) / (b - a), 0, 1); return t * t * (3 - 2 * t); };
const easeOut = t => 1 - Math.pow(1 - t, 3);
const easeIO = t => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2);
const lerp = (a, b, t) => a + (b - a) * t;
const wait = ms => new Promise(r => setTimeout(r, ms));
const mk = (tag, cls, text) => { const n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; };
const root = document.documentElement;
const body = document.body;
const CFG = (() => { try { return JSON.parse($('#cfg').textContent); } catch (e) { return {}; } })();
const BASE = CFG.base || '';
const store = kind => ({
  get(k) { try { return window[kind].getItem(k); } catch (e) { return null; } },
  set(k, v) { try { window[kind].setItem(k, v); } catch (e) { /* приватный режим */ } },
});
const LS = store('localStorage');
const SS = store('sessionStorage');

const mqReduce = matchMedia('(prefers-reduced-motion: reduce)');
const mqLandPhone = matchMedia('(orientation: landscape) and (pointer: coarse) and (max-height: 560px)');
const conn = navigator.connection || {};
const saveData = !!(conn.saveData || /(^|-)2g$/.test(conn.effectiveType || ''));
const staticWanted = () => mqReduce.matches || mqLandPhone.matches || saveData;

function goal(name) { try { if (window.ym) window.ym(CFG.metrika, 'reachGoal', name); } catch (e) { /* нет Метрики */ } }

/* ---------- разбивка текста на слова и буквы ---------- */
function rng(seed) { let s = seed >>> 0; return () => (s = (s * 1664525 + 1013904223) >>> 0) / 4294967296; }

function split(el, mode) {
  const full = el.textContent.replace(/\s+/g, ' ').trim();
  const sr = mk('span', 'sr-only', full);
  const vis = mk('span');
  vis.setAttribute('aria-hidden', 'true');
  const words = [];
  const addWord = content => {
    const w = mk('span', 'w');
    if (typeof content === 'string') {
      if (mode === 'chars') for (const ch of content) w.appendChild(mk('span', 'c', ch));
      else w.textContent = content;
    } else if (mode === 'chars') {
      const c = mk('span', 'c'); c.appendChild(content); w.appendChild(c);
    } else w.appendChild(content);
    vis.appendChild(w);
    words.push(w);
  };
  Array.from(el.childNodes).forEach(node => {
    if (node.nodeType === 3) {
      node.textContent.split(/(\s+)/).forEach(part => {
        if (!part) return;
        if (/^\s+$/.test(part)) vis.appendChild(document.createTextNode(' '));
        else addWord(part);
      });
    } else if (node.nodeType === 1) addWord(node.cloneNode(true));
  });
  el.textContent = '';
  el.append(sr, vis);
  return words;
}

function setupFx() {
  $$('.plate').forEach(p => {
    const t = $('.plate__title', p);
    if (t) {
      if (t.classList.contains('fx-blur')) {
        const full = t.textContent.trim();
        t.textContent = '';
        const sharp = mk('span', 'fx-sharp', full);
        const soft = mk('span', 'fx-soft', full);
        sharp.setAttribute('aria-hidden', 'true');
        soft.setAttribute('aria-hidden', 'true');
        t.append(mk('span', 'sr-only', full), sharp, soft);
      } else if (t.classList.contains('fx-cut')) {
        split(t, 'chars');
        const cs = $$('.c', t);
        cs.forEach((c, i) => c.style.setProperty('--th', (i / cs.length * 0.55).toFixed(3)));
      } else if (t.classList.contains('fx-door')) {
        const ws = split(t, 'words');
        const mid = (ws.length - 1) / 2;
        ws.forEach((w, i) => {
          w.style.setProperty('--jx', ((mid - i) * 30).toFixed(1) + 'px');
          w.style.setProperty('--th', (Math.abs(i - mid) / (mid || 1) * 0.16).toFixed(3));
        });
      } else {
        const ws = split(t, 'words');
        ws.forEach((w, i) => w.style.setProperty('--th', (i / Math.max(1, ws.length) * 0.42).toFixed(3)));
      }
    }
    $$('.fx-chips > *', p).forEach((c, i) => c.style.setProperty('--i', i));
  });
}

/* ================= ГЕРОЙ: видео и плашки ================= */
/* Ролик идёт остановками на любом экране: одно движение колёсика, свайп или стрелка
   проигрывают его до следующей плашки. Назад играет копия ролика задом наперёд,
   поэтому обратный ход такой же плавный: браузеры не умеют крутить видео назад. */

const hero = $('#hero');
const stage = $('#stage');
const frameEl = $('#frame');
const vFwd = $('#vFwd');
const vRev = $('#vRev');
let video = vFwd;             // какая из двух записей сейчас на экране
const amb = $('#amb');
const actx = amb ? amb.getContext('2d', { alpha: false }) : null;
const ui = $('#stageUi');
const osdCam = $('#osdCam');
const osdTc = $('#osdTc');
const osdClock = $('#osdClock');
const loadPill = $('#stageLoad');
const loadPillRing = $('#stageLoadRing');
const loadPillPct = $('#stageLoadPct');
const cue = $('#cue');

let FPS = 30;                 // у каждого ролика своя частота кадров
let DUR = 12.3;               // уточняется по ролику
let NF = 369;                 // кадров в ролике

// Плашки: сторона и высота на широком экране.
const PL = [
  { n: 1, side: 'L', vy: 0.52 },
  { n: 2, side: 'R', vy: 0.46 },
  { n: 3, side: 'L', vy: 0.5 },
  { n: 4, side: 'R', vy: 0.5 },
  { n: 5, side: 'L', vy: 0.44 },
  { n: 6, side: 'R', vy: 0.42 },
  { n: 7, side: 'C' },
].map(p => Object.assign(p, { el: $(`.plate[data-plate="${p.n}"]`), on: false, x: 0, y: 0, w: 0, h: 0, ov: 0 }));

// Остановки: секунда ролика и плашка на ней. -1 значит конец ролика. Вертикальный ролик для телефона
// и горизонтальный для компьютера скомпонованы по-разному, поэтому кадры остановок у них свои.
// fold: на узком экране плашка сворачивается до статусов, чтобы не закрывать оповещатель.
const STOPS_V = [
  { t: 0, n: 1 }, { t: 1.7, n: 2 }, { t: 3.15, n: 3 }, { t: 4.6, n: 4 },
  { t: 6.37, n: 4, fold: true }, { t: 8.4, n: 5 }, { t: 9.74, n: 6 }, { t: -1, n: 7 },
];
const STOPS_H = [
  { t: 0, n: 1 }, { t: 1.7, n: 2 }, { t: 3.17, n: 3 }, { t: 4.6, n: 4 },
  { t: 6.42, n: 4, fold: true }, { t: 8.4, n: 5 }, { t: 9.75, n: 6 }, { t: -1, n: 7 },
];

const CAMS = [[0, 'CAM 01 · ФАСАД'], [1.0, 'CAM 02 · СЕРВЕРНАЯ'], [2.37, 'CAM 03 · ВХОД'], [3.5, 'CAM 04 · КОРИДОР'],
  [4.75, 'CAM 04 · ТРЕВОГА'], [6.85, 'CAM 05 · ПУЛЬТ'], [9.35, 'CAM 06 · ТЕЛЕФОН'], [10.75, 'CAM 07 · ФАСАД']];
const ALARM = [4.75, 6.8];

/* ---------- загрузка: ролик сразу, запись задом наперёд следом ---------- */
const MOBILE_MQ = matchMedia('(max-width: 900px), (orientation: portrait) and (pointer: coarse)');
// ar: пропорции кадра; sign: где на кадре вывеска с логотипом (её закрывает последняя плашка)
const SOURCES = {
  // компьютер: ролик расширен до 16:9 (Higgsfield), плашки встают сбоку прямо на видео
  desktop: {
    fwd: { url: `${BASE}/assets/video/hero-d.mp4`, bytes: 6007402 },
    rev: { url: `${BASE}/assets/video/hero-d-rev.mp4`, bytes: 5422154 },
    ar: 16 / 9, fps: 24, sign: [0.49, 0.27], stops: STOPS_H,
    sides: { 1: 'L', 2: 'R', 3: 'R', 4: 'L', 5: 'L', 6: 'R' }, // там, где кадр пустой
  },
  mobile: {
    fwd: { url: `${BASE}/assets/video/hero-m.mp4`, bytes: 3715820 },
    rev: { url: `${BASE}/assets/video/hero-m-rev.mp4`, bytes: 3763781 },
    ar: 9 / 16, fps: 30, sign: [0.47, 0.275], stops: STOPS_V,
  },
};
let SRC = MOBILE_MQ.matches ? SOURCES.mobile : SOURCES.desktop;
let STOPS = SRC.stops;
FPS = SRC.fps;
const loadListeners = new Set();
let netFrac = 0, videoStarted = false, videoOk = false, revOk = false, gestureSeen = false;
let resolveVideo;
const videoReady = new Promise(r => { resolveVideo = r; });
const loadedFraction = () => (beatsOn ? netFrac : 1);
const notifyLoad = () => loadListeners.forEach(fn => fn());

// Safari на айфоне разрешает проигрывать видео только после касания экрана, а в режиме энергосбережения
// не пускает даже беззвучное. Поэтому на первом касании коротко запускаем запись и сразу ставим на паузу:
// дальше браузер считает её разрешённой и свайпы работают.
function primeVideo(el) {
  if (!el || !gestureSeen || !el.currentSrc || el.dataset.primed) return;
  el.dataset.primed = '1';
  const t = el.currentTime, id = moveId;
  el.muted = true;
  const p = el.play();
  // если за это время начался переход к остановке, видео не трогаем
  const back = () => { if (id !== moveId) return; el.pause(); try { el.currentTime = t; } catch (e) { /* ещё грузится */ } };
  if (p && p.then) p.then(back).catch(() => { delete el.dataset.primed; });
  else back();
}
function onTouch() {
  gestureSeen = true;
  primeVideo(vFwd);
  primeVideo(vRev);
}
['touchstart', 'pointerdown', 'keydown'].forEach(ev => addEventListener(ev, onTouch, { passive: true }));

// Ролик подключается прямой ссылкой, а не скачивается в память: Safari на айфоне не проигрывает
// видео из памяти (blob), да и браузер сам докачивает нужные куски по мере надобности.
function attach(el, url, onProgress) {
  return new Promise((res, rej) => {
    let done = false, soon = 0;
    const clean = () => {
      clearTimeout(hard);
      clearTimeout(soon);
      el.removeEventListener('progress', tick);
      el.removeEventListener('loadeddata', tick);
      el.removeEventListener('canplay', ok);
      el.removeEventListener('error', fail);
    };
    function ok() { if (done) return; done = true; clean(); res(); }
    function fail() { if (done) return; done = true; clean(); rej(new Error('video')); }
    function tick() {
      if (onProgress && el.duration) {
        try {
          const b = el.buffered;
          onProgress(clamp(b.length ? b.end(b.length - 1) / el.duration : 0, 0, 1));
        } catch (e) { /* данных ещё нет */ }
      }
      // на мобильном интернете iOS не докачивает ролик заранее, поэтому длины кадра уже достаточно
      if (!soon && el.readyState >= 1) soon = setTimeout(() => (el.readyState >= 1 ? ok() : null), 3500);
      if (el.readyState >= 3) ok();
    }
    const hard = setTimeout(() => (el.readyState >= 1 ? ok() : fail()), 20000);
    el.addEventListener('progress', tick);
    el.addEventListener('loadeddata', tick);
    el.addEventListener('canplay', ok);
    el.addEventListener('error', fail);
    el.preload = 'auto';
    el.src = url;
    el.load();
  });
}

function startVideo() {
  if (videoStarted || !vFwd) return;
  videoStarted = true;
  SRC = MOBILE_MQ.matches ? SOURCES.mobile : SOURCES.desktop;
  STOPS = SRC.stops;
  FPS = SRC.fps;
  attach(vFwd, SRC.fwd.url, f => { netFrac = f; notifyLoad(); })
    .then(() => {
      DUR = vFwd.duration || DUR;
      NF = Math.max(2, Math.round(DUR * FPS));
      videoOk = true;
      netFrac = 1;
      stage.classList.add('video-ready');
      notifyLoad();
      resolveVideo();
      primeVideo(vFwd);
      if (beatsOn) jumpTo(stopTime(beatI));
      if (!SRC.rev || !vRev) return null;
      // запись задом наперёд подключается следом, чтобы не мешать основной занимать канал
      return wait(1500).then(() => attach(vRev, SRC.rev.url)).then(() => { revOk = true; primeVideo(vRev); cueRev(); });
    })
    .catch(() => {
      if (videoOk) return; // не загрузилась только запись задом наперёд: назад пойдёт пошаговой перемоткой
      stage.classList.add('video-failed');
      netFrac = 1;
      notifyLoad();
      resolveVideo();
    });
}

/* ---------- кадры и две записи ---------- */
const frameAt = t => clamp(Math.floor(t * FPS + 1e-4), 0, NF - 1);
const tOf = i => (i + 0.5) / FPS;                  // середина кадра, чтобы не попасть на стык
const mirror = t => tOf(NF - 1 - frameAt(t));      // тот же кадр в записи задом наперёд (и обратно)
const stopTime = i => (STOPS[i].t < 0 ? tOf(NF - 1) : tOf(frameAt(STOPS[i].t)));
// время прямого ролика, которое сейчас на экране
const shownT = () => (video === vRev ? mirror(vRev.currentTime) : vFwd.currentTime);

function show(el) {
  if (video === el) return;
  video = el;
  stage.classList.toggle('rev', el === vRev);
  drawAmb(true);
}

// Навести запись на время и дождаться, когда кадр готов к показу.
function cueTo(el, t) {
  return new Promise(res => {
    if (Math.abs(el.currentTime - t) < 0.5 / FPS && !el.seeking) { res(); return; }
    let done = false;
    const ok = () => {
      if (done) return;
      done = true;
      clearTimeout(timer);
      // кадр готов после seeked; сигнал о показе от закрытой записи может не прийти, поэтому есть запасной путь
      let fin = false;
      const end = () => { if (!fin) { fin = true; res(); } };
      if (el.requestVideoFrameCallback) el.requestVideoFrameCallback(end);
      requestAnimationFrame(() => requestAnimationFrame(end));
    };
    const timer = setTimeout(ok, 600);
    el.addEventListener('seeked', ok, { once: true });
    try { el.pause(); el.currentTime = t; } catch (e) { ok(); }
  });
}
// Запись задом наперёд заранее стоит на текущем кадре: ход назад начнётся без задержки.
function cueRev() {
  if (!revOk || video === vRev || moving) return;
  const t = mirror(vFwd.currentTime);
  if (Math.abs(vRev.currentTime - t) > 0.5 / FPS) { try { vRev.currentTime = t; } catch (e) { /* ещё грузится */ } }
}

// Мягкая подсветка по бокам (только на широком экране). Крошечный холст растягивается браузером,
// поэтому размытие получается даром, без тяжёлого CSS-фильтра на весь экран.
let ambAt = 0;
function drawAmb(force) {
  if (!flank || !actx || !video || video.readyState < 2) return;
  const now = performance.now();
  if (!force && now - ambAt < 120) return;
  ambAt = now;
  try {
    actx.drawImage(video, 0, 0, 8, 14);
    actx.fillStyle = 'rgba(4,6,12,.5)';
    actx.fillRect(0, 0, 8, 14);
  } catch (e) { /* кадр ещё не готов */ }
}

/* ---------- раскладка ---------- */
let W = 0, H = 0, UH = 0, flank = false, wide = false;
let box = { x: 0, y: 0, w: 0, h: 0 };
let map = { dw: 0, dh: 0, ox: 0, oy: 0 };
let heroTop = 0, heroEndY = 0;

function layout() {
  if (!stage) return;
  W = stage.clientWidth;
  H = stage.clientHeight;
  UH = ui.clientHeight;
  const fw = H * SRC.ar;
  flank = SRC.ar < 1 && W - fw >= 470;   // вертикальный кадр по центру, по бокам поля
  wide = SRC.ar > 1 && W >= 760;          // горизонтальный кадр на весь экран
  stage.classList.toggle('is-flank', flank);
  stage.classList.toggle('is-wide', wide);
  stage.classList.toggle('is-full', !flank && !wide);
  box = flank ? { x: Math.round((W - fw) / 2), y: 0, w: Math.round(fw), h: H } : { x: 0, y: 0, w: W, h: H };
  frameEl.style.left = box.x + 'px';
  frameEl.style.width = box.w + 'px';
  // кадр вписан по принципу cover: где на экране окажется каждая точка ролика
  const dw = Math.max(box.w, box.h * SRC.ar), dh = dw / SRC.ar;
  map = { dw, dh, ox: (box.w - dw) / 2, oy: (box.h - dh) / 2 };
  placePlates();
  const r = hero.getBoundingClientRect();
  heroTop = r.top + scrollY;
  heroEndY = heroTop + hero.offsetHeight - H * 0.5;
  drawAmb(true);
}

// Длинное слово («Видеонаблюдение») не должно вылезать за плашку: уменьшаем кегль, пока не влезет.
function fitTitle(el) {
  const t = $('.plate__title', el);
  if (!t) return;
  t.style.fontSize = '';
  let fs = parseFloat(getComputedStyle(t).fontSize);
  let guard = 0;
  while (t.scrollWidth > t.clientWidth + 1 && fs > 18 && guard++ < 40) {
    fs -= 1;
    t.style.fontSize = fs + 'px';
  }
}

function placePlates() {
  PL.forEach(p => {
    const el = p.el;
    if (!el) return;
    let pw;
    p.ov = 0;
    if (p.side === 'C') pw = flank ? clamp(box.w * 0.94, 360, 540) : Math.min(W - 24, 520);
    else if (flank) {
      const avail = box.x - 36;
      pw = Math.min(440, avail - 36);
      if (pw < 330) { p.ov = Math.min(box.w * 0.2, 330 - pw); pw = Math.min(440, avail - 36 + p.ov); }
    } else if (wide) pw = clamp(W * 0.27, 340, 440);
    else pw = Math.min(W - 24, 560);
    el.style.setProperty('--pw', Math.round(pw) + 'px');
    fitTitle(el);
    p.w = pw;
    p.h = el.offsetHeight;
    let x, y;
    if (p.side === 'C') {
      // последняя плашка встаёт точно поверх вывески на фасаде и закрывает её целиком
      const cx = box.x + map.ox + SRC.sign[0] * map.dw;
      const cy = box.y + map.oy + SRC.sign[1] * map.dh;
      x = clamp(cx - pw / 2, 12, W - pw - 12);
      y = clamp(cy - p.h / 2, 76, Math.max(76, UH - p.h - 20));
      el.style.setProperty('--ox', Math.round(cx - x) + 'px');
      el.style.setProperty('--oy', Math.round(cy - y) + 'px');
    } else if (flank) {
      x = p.side === 'L' ? box.x - 36 + p.ov - pw : box.x + box.w + 36 - p.ov;
      y = clamp(UH * p.vy - p.h / 2, 84, Math.max(84, UH - p.h - 28));
    } else if (wide) {
      // горизонтальный кадр: плашка сбоку прямо на видео, со стороны, где кадр пустой
      const side = (SRC.sides && SRC.sides[p.n]) || p.side;
      const m = Math.max(28, W * 0.045);
      x = side === 'L' ? m : W - pw - m;
      y = clamp(UH * p.vy - p.h / 2, 84, Math.max(84, UH - p.h - 28));
    } else {
      // узкий экран: плашка прижата к низу через CSS, поэтому свёрнутая не повисает в воздухе
      x = (W - pw) / 2;
      y = 0;
    }
    x = clamp(x, 12, Math.max(12, W - pw - 12));
    p.x = x;
    p.y = y;
    el.style.setProperty('--x', Math.round(x) + 'px');
    el.style.setProperty('--y', Math.round(y) + 'px');
  });
}

/* ---------- экранные метки, тревога, статусы ---------- */
let osdLastT = 0, osdCamTxt = '', osdTcTxt = '', curT = 0, stAlarm = false;
const statusRows = $$('#status li[data-at]');
let tickEl = null; // ищем после разбивки заголовка на буквы
const pad = n => String(n).padStart(2, '0');
function fmtTc(sec, fps) {
  const ff = Math.floor((sec % 1) * fps);
  const s = Math.floor(sec) % 60;
  const m = Math.floor(sec / 60) % 60;
  const h = Math.floor(sec / 3600);
  return `${pad(h)}:${pad(m)}:${pad(s)}:${pad(ff)}`;
}
function updateOsd(force) {
  const now = performance.now();
  if (!force && now - osdLastT < 100) return;
  osdLastT = now;
  let cam = CAMS[0][1];
  for (const [at, name] of CAMS) if (curT >= at) cam = name;
  if (cam !== osdCamTxt) { osdCamTxt = cam; osdCam.textContent = cam; }
  const tc = fmtTc(curT, 30);
  if (tc !== osdTcTxt) { osdTcTxt = tc; osdTc.textContent = tc; }
}
function tickClock() {
  if (!osdClock) return;
  const d = new Date();
  osdClock.textContent = `${pad(d.getDate())}.${pad(d.getMonth() + 1)}.${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}
function paintState(t, force) {
  curT = t;
  statusRows.forEach(li => {
    const hot = t >= +li.dataset.at;
    if (hot !== li.classList.contains('is-hot')) {
      li.classList.toggle('is-hot', hot);
      $('b', li).textContent = hot ? li.dataset.on : (li.dataset.off || 'норма');
    }
    if (li.dataset.off) li.classList.toggle('is-idle', !hot);
  });
  const alarm = t >= ALARM[0] && t <= ALARM[1];
  if (alarm !== stAlarm) { stAlarm = alarm; stage.classList.toggle('is-alarm', alarm); }
  updateOsd(force);
}

/* ---------- остановки ---------- */
let beatI = 0, moveId = 0, moving = false, beatRaf = 0, tickRun = 0;
let beatsOn = false, heroVisible = true, introDone = false;

// «95 камер»: число отсчитывается, когда плашка появляется
function runTicker() {
  if (!tickEl) return;
  const run = ++tickRun, t0 = performance.now();
  if (mqReduce.matches) { tickEl.textContent = '95'; return; }
  const f = now => {
    if (run !== tickRun) return;
    const k = clamp((now - t0) / 1100, 0, 1);
    tickEl.textContent = Math.round(95 * easeOut(k));
    if (k < 1) requestAnimationFrame(f);
  };
  requestAnimationFrame(f);
}

function paintBeat(i) {
  const s = STOPS[i];
  PL.forEach(p => {
    if (!p.el) return;
    const on = p.n === s.n;
    if (on && !p.on && p.n === 5) runTicker();
    p.on = on;
    p.el.classList.toggle('is-on', on);
    p.el.classList.toggle('is-fold', on && !!s.fold);
    p.el.style.opacity = on ? 1 : 0;
    p.el.style.setProperty('--k', on ? 1 : 0);
  });
  if (cue) cue.classList.toggle('is-on', introDone && i === 0);
}
function stopBeatLoop() { if (beatRaf) { cancelAnimationFrame(beatRaf); beatRaf = 0; } }
function jumpTo(t) {
  moveId++;
  moving = false;
  try { vFwd.pause(); vFwd.currentTime = t; } catch (e) { /* ещё грузится */ }
  show(vFwd);
  paintState(t, true);
  cueRev();
}

// Проиграть запись el до времени toEl (в её собственном времени). toFwd переводит её время в время ролика.
function playEl(el, toEl, toFwd, id, landed) {
  const span = Math.max(0.05, toEl - el.currentTime);
  el.playbackRate = Math.min(2.5, Math.max(1, span / 0.85));
  const rate = el.playbackRate || 1; // браузер мог ограничить ускорение, ждём по настоящей скорости
  let fired = false;
  const check = () => { if (el.currentTime >= toEl - 0.012) finish(); };
  const timer = setTimeout(() => finish(), (span / rate) * 1000 + 900);
  function finish() {
    if (fired || id !== moveId) return;
    fired = true;
    clearTimeout(timer);
    stopBeatLoop();
    el.removeEventListener('timeupdate', check);
    el.pause();
    el.playbackRate = 1;
    try { el.currentTime = toEl; } catch (e) { /* ничего */ }
    landed();
  }
  el.addEventListener('timeupdate', check);
  const pr = el.play();
  if (pr && pr.catch) pr.catch(finish);
  if (el.requestVideoFrameCallback) {
    const frame = () => { if (fired || id !== moveId) return; if (el.currentTime >= toEl - 0.012) { finish(); return; } el.requestVideoFrameCallback(frame); };
    el.requestVideoFrameCallback(frame);
  }
  const watch = () => {
    if (fired || id !== moveId) return;
    paintState(toFwd(el.currentTime), false);
    drawAmb(false);
    if (el.currentTime >= toEl - 0.012) { finish(); return; }
    beatRaf = requestAnimationFrame(watch);
  };
  watch();
}

// Вперёд: играет прямая запись.
function moveFwd(to, id) {
  const from = shownT();
  const go = () => {
    if (id !== moveId) return;
    playEl(vFwd, to, t => t, id, () => {
      moving = false;
      paintState(to, true);
      drawAmb(true);
      cueRev();
    });
  };
  if (video === vFwd) { go(); return; }
  // на экране запись задом наперёд: прямую наводим на тот же кадр и незаметно подменяем
  try { vRev.pause(); } catch (e) { /* ничего */ }
  cueTo(vFwd, tOf(frameAt(from))).then(() => { if (id !== moveId) return; show(vFwd); go(); });
}

// Назад: играет запись задом наперёд, потом на остановке снова встаёт прямая.
function moveBack(to, id) {
  const from = shownT();
  const go = () => {
    if (id !== moveId) return;
    playEl(vRev, mirror(to), mirror, id, () => {
      paintState(to, true);
      cueTo(vFwd, to).then(() => {
        if (id !== moveId) return;
        moving = false;
        show(vFwd);
        drawAmb(true);
      });
    });
  };
  try { vFwd.pause(); } catch (e) { /* ничего */ }
  if (video === vRev) { go(); return; }
  cueTo(vRev, mirror(from)).then(() => { if (id !== moveId) return; show(vRev); go(); });
}

// Запасной ход назад, пока запись задом наперёд не загрузилась: короткая пошаговая перемотка.
function seekBack(to, id) {
  const el = vFwd;
  show(vFwd);
  el.pause();
  el.playbackRate = 1;
  const from = el.currentTime, STEPS = 9, PACE = 42;
  let k = 0, timer = null, lastAt = performance.now();
  const alive = () => id === moveId;
  const land = () => {
    if (!alive()) return;
    el.removeEventListener('seeked', next);
    clearTimeout(timer);
    moving = false;
    try { el.currentTime = to; } catch (e) { /* ничего */ }
    paintState(to, true);
  };
  const step = () => {
    if (!alive()) { el.removeEventListener('seeked', next); return; }
    k++;
    if (k >= STEPS) { land(); return; }
    const e = 1 - Math.pow(1 - k / STEPS, 3);
    lastAt = performance.now();
    timer = setTimeout(next, 260);
    const t = from + (to - from) * e;
    try { el.currentTime = t; } catch (err) { land(); }
    paintState(t, false);
  };
  function next() {
    if (!alive()) { el.removeEventListener('seeked', next); return; }
    clearTimeout(timer);
    const w = PACE - (performance.now() - lastAt);
    if (w > 4) timer = setTimeout(step, w); else step();
  }
  el.addEventListener('seeked', next);
  timer = setTimeout(next, 260);
  try { el.currentTime = from + (to - from) / STEPS; } catch (e) { land(); }
}

function goBeat(i) {
  if (i < 0 || i >= STOPS.length || i === beatI) return;
  const back = i < beatI;
  beatI = i;
  paintBeat(i);
  stopBeatLoop();
  const id = ++moveId;
  if (!videoOk) return;
  moving = true;
  const to = stopTime(i);
  if (!back) moveFwd(to, id);
  else if (revOk) moveBack(to, id);
  else seekBack(to, id);
}

// История кончилась: сами уводим страницу дальше, чтобы посетитель не упёрся в экран.
let leavingUntil = 0;
function leaveHero() {
  leavingUntil = performance.now() + 900;
  scrollTo({ top: hero.offsetTop + hero.offsetHeight, behavior: mqReduce.matches ? 'auto' : 'smooth' });
}
function step(down) {
  if (down && beatI >= STOPS.length - 1) { leaveHero(); return; }
  if (!down && beatI <= 0) return;
  goBeat(beatI + (down ? 1 : -1));
}

// Управляем роликом, только когда герой стоит ровно в экране и открытого меню нет.
const heroPinned = () => { const r = hero.getBoundingClientRect(); return r.top > -24 && r.top < 24; };
const canDrive = () => introDone && heroPinned() && !root.classList.contains('is-locked');

/* палец: один свайп = одна остановка */
let tY = 0, tActive = false, tDone = false;
function onTouchStart(e) {
  if (e.touches.length !== 1) return;
  tY = e.touches[0].clientY;
  tActive = canDrive();
  tDone = false;
}
function onTouchMove(e) {
  if (!tActive || e.touches.length !== 1) return;
  if (e.cancelable) e.preventDefault();
  if (tDone) return;
  const dy = tY - e.touches[0].clientY;
  if (Math.abs(dy) < 24) return;
  tDone = true;
  const up = dy > 0;
  if (up && beatI >= STOPS.length - 1) { tActive = false; leaveHero(); return; }
  step(up);
}
function onTouchEnd() { tActive = false; tDone = false; }

/* колёсико и тачпад: один жест = одна остановка. Жест кончается паузой в 160 мс или сменой направления,
   поэтому раскрученное колесо или инерция тачпада не проскакивают плашки. */
let wLast = 0, wDir = 0, wAcc = 0, wUsed = false, wCapture = false, wLockUntil = 0;
function onWheel(e) {
  if (!beatsOn || e.ctrlKey) return; // ctrl + колесо оставляем браузеру для масштаба
  const now = performance.now();
  const dy = e.deltaY * (e.deltaMode === 1 ? 40 : e.deltaMode === 2 ? innerHeight : 1);
  if (Math.abs(dy) < Math.abs(e.deltaX)) return; // горизонтальный жест не наш
  const dir = Math.sign(dy) || wDir;
  const gap = now - wLast;
  wLast = now;
  if (gap > 160 || dir !== wDir) {
    wDir = dir;
    wAcc = 0;
    wUsed = false;
    // жест, начатый при прокрутке страницы, доезжает сам и не листает ролик
    wCapture = canDrive() && !(dir < 0 && beatI <= 0);
  }
  if (!wCapture) return;
  e.preventDefault();
  if (wUsed || now < leavingUntil) return;
  wAcc += Math.abs(dy);
  if (wAcc < 12 || now < wLockUntil) return;
  wUsed = true;
  wLockUntil = now + 380;
  step(dir > 0);
}

/* клавиатура: стрелки, PageUp и PageDown, пробел */
function onKey(e) {
  if (!beatsOn || e.defaultPrevented || e.altKey || e.ctrlKey || e.metaKey) return;
  const k = e.key;
  const fwd = k === 'ArrowDown' || k === 'PageDown' || (k === ' ' && !e.shiftKey);
  const bwd = k === 'ArrowUp' || k === 'PageUp' || (k === ' ' && e.shiftKey);
  if (!fwd && !bwd) return;
  const tg = e.target;
  if (tg && tg.closest && tg.closest('input, textarea, select, [contenteditable]')) return;
  if (k === ' ' && tg && tg.closest && tg.closest('a, button')) return; // пробел нажимает кнопку
  if (!canDrive() || (bwd && beatI <= 0)) return;
  e.preventDefault();
  if (e.repeat || performance.now() < leavingUntil) return;
  step(fwd);
}

/* ---------- режимы: остановки или статичный герой ---------- */
function enableBeats() {
  if (beatsOn) return;
  beatsOn = true;
  hero.classList.add('is-beats');
  startVideo();
  layout();
  if (introDone) paintBeat(beatI);
  if (videoOk) jumpTo(stopTime(beatI));
  stage.addEventListener('touchstart', onTouchStart, { passive: true });
  stage.addEventListener('touchmove', onTouchMove, { passive: false });
  stage.addEventListener('touchend', onTouchEnd, { passive: true });
  addEventListener('wheel', onWheel, { passive: false });
  addEventListener('keydown', onKey);
  if (cue) $('span', cue).textContent = MOBILE_MQ.matches ? 'Свайп вверх' : 'Листайте';
}
function disableBeats() {
  if (!beatsOn) return;
  beatsOn = false;
  hero.classList.remove('is-beats');
  stopBeatLoop();
  moveId++;
  moving = false;
  [vFwd, vRev].forEach(v => { try { v.pause(); v.playbackRate = 1; } catch (e) { /* ничего */ } });
  stage.removeEventListener('touchstart', onTouchStart);
  stage.removeEventListener('touchmove', onTouchMove);
  stage.removeEventListener('touchend', onTouchEnd);
  removeEventListener('wheel', onWheel);
  removeEventListener('keydown', onKey);
}
function clearPlates() {
  PL.forEach(p => {
    if (!p.el) return;
    p.el.style.removeProperty('opacity');
    p.el.style.removeProperty('--k');
    p.el.classList.remove('is-on', 'is-fold');
    p.on = false;
  });
  stage.classList.remove('is-alarm');
  stAlarm = false;
}
function applyMode() {
  if (staticWanted()) {
    disableBeats();
    clearPlates();
    root.classList.add('is-static');
    layout();
    return;
  }
  root.classList.remove('is-static');
  enableBeats();
}

/* ---------- заставка ---------- */
function scramble(node, dur) {
  const final = node.dataset.text || node.textContent;
  node.dataset.text = final;
  const glyphs = 'АБВГДЕЖЗИКЛМНОПРСТУФХЦЧШЭЮЯ0123456789#%&*+=<>/';
  const t0 = performance.now();
  const step = now => {
    const t = clamp((now - t0) / dur, 0, 1);
    let out = '';
    for (let i = 0; i < final.length; i++) {
      const ch = final[i];
      if (ch === ' ') { out += ' '; continue; }
      const lockAt = (i / final.length) * 0.75 + 0.2;
      out += t >= lockAt ? ch : glyphs[(Math.random() * glyphs.length) | 0];
    }
    node.textContent = out;
    if (t < 1) requestAnimationFrame(step);
    else node.textContent = final;
  };
  requestAnimationFrame(step);
}

function openAperture(svg, dur) {
  const blades = $$('.blade', svg).map(b => ({ el: b, px: b.dataset.px, py: b.dataset.py }));
  const t0 = performance.now();
  return new Promise(res => {
    const step = now => {
      const t = clamp((now - t0) / dur, 0, 1);
      const ang = (-74 * easeIO(t)).toFixed(2);
      blades.forEach(b => b.el.setAttribute('transform', `rotate(${ang} ${b.px} ${b.py})`));
      svg.style.opacity = t > 0.8 ? (1 - (t - 0.8) / 0.2).toFixed(3) : 1;
      if (t < 1) requestAnimationFrame(step);
      else res();
    };
    requestAnimationFrame(step);
  });
}

async function runIntro() {
  const el = $('#intro');
  const finish = () => {
    if (el) el.remove();
    body.classList.remove('is-intro');
    root.classList.remove('is-locked');
    introDone = true;
    afterIntro();
  };
  if (!el) return finish();

  const hashTarget = location.hash && location.hash.length > 1 ? document.getElementById(location.hash.slice(1)) : null;
  if (hashTarget && location.hash !== '#top') {
    finish();
    requestAnimationFrame(() => hashTarget.scrollIntoView());
    return;
  }
  if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
  scrollTo(0, 0);
  root.classList.add('is-locked');

  const q = s => $(s, el);
  const A = (node, kf, o) => (node ? node.animate(kf, Object.assign({ fill: 'forwards', easing: 'cubic-bezier(.16,1,.3,1)' }, o)) : null);
  const t0 = performance.now();
  let skip;
  const skipP = new Promise(r => { skip = r; });
  const onSkip = () => { if (performance.now() - t0 > 450) skip(); };
  const evs = ['pointerdown', 'keydown', 'wheel', 'touchstart'];
  evs.forEach(ev => addEventListener(ev, onSkip, { passive: true }));
  el.addEventListener('touchmove', e => e.preventDefault(), { passive: false });

  if (mqReduce.matches) {
    A(q('.intro__sharp'), [{ opacity: 0 }, { opacity: 1 }], { duration: 250 });
    await Promise.race([wait(650), skipP]);
    evs.forEach(ev => removeEventListener(ev, onSkip));
    el.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 300, fill: 'forwards' });
    await wait(300);
    return finish();
  }

  const quick = SS.get('iris_intro') === '1';
  SS.set('iris_intro', '1');
  const sp = quick ? 0.5 : 1;

  // таймкод
  const tcEl = q('#introTc');
  let tcRun = true;
  const tcLoop = () => { if (!tcRun) return; tcEl.textContent = fmtTc((performance.now() - t0) / 1000, 25); setTimeout(tcLoop, 90); };
  tcLoop();

  A(q('.intro__line'), [{ transform: 'scaleX(0)', opacity: 0 }, { transform: 'scaleX(1)', opacity: 1, offset: 0.55 }, { transform: 'scaleX(1) scaleY(.3)', opacity: 0 }], { duration: 760 * sp, delay: 100 * sp, easing: 'cubic-bezier(.65,0,.35,1)' });
  $$('.intro__vf i', el).forEach((c, i) => A(c, [{ opacity: 0, transform: 'scale(1.5)' }, { opacity: 1, transform: 'none' }], { duration: 650 * sp, delay: (320 + i * 60) * sp }));
  $$('.intro__osd', el).forEach((o, i) => A(o, [{ opacity: 0 }, { opacity: 1 }], { duration: 500 * sp, delay: (440 + i * 70) * sp }));
  A(q('.intro__rings'), [{ opacity: 0, transform: 'scale(.84) rotate(-50deg)' }, { opacity: 1, transform: 'none' }], { duration: 1200 * sp, delay: 460 * sp });
  A(q('.ring--in'), [{ strokeDashoffset: 100 }, { strokeDashoffset: 0 }], { duration: 1300 * sp, delay: 620 * sp });
  A(q('.intro__soft'), [{ opacity: 0, transform: 'scale(1.18)' }, { opacity: 0.9, transform: 'scale(1.1)', offset: 0.45 }, { opacity: 0, transform: 'scale(1)' }], { duration: 1150 * sp, delay: 820 * sp });
  A(q('.intro__sharp'), [{ opacity: 0, transform: 'scale(1.12)' }, { opacity: 1, transform: 'scale(1)' }], { duration: 1050 * sp, delay: 1020 * sp });
  setTimeout(() => el.classList.add('is-glint'), 1550 * sp);
  setTimeout(() => { A(q('#introSub'), [{ opacity: 0 }, { opacity: 1 }], { duration: 300 }); scramble(q('#introSub'), 950 * sp); }, 1380 * sp);
  setTimeout(() => A(q('.intro__rings'), [{ transform: 'scale(1)' }, { transform: 'scale(.93)', offset: 0.35 }, { transform: 'scale(1)' }], { duration: 560, easing: 'cubic-bezier(.34,1.56,.64,1)', fill: 'none' }), 2150 * sp);
  setTimeout(() => el.classList.add('show-hint'), 1700);

  const ring = q('#introLoad');
  const pct = q('#introPct');
  const upd = () => { const p = Math.round(loadedFraction() * 100); ring.style.strokeDashoffset = 100 - p; pct.textContent = p; };
  loadListeners.add(upd);
  upd();

  const minT = quick ? 1300 : 2700;
  const maxT = quick ? 3000 : 4600;
  const ready = beatsOn ? videoReady : Promise.resolve();
  await Promise.race([Promise.all([ready, wait(minT)]), wait(maxT), skipP]);
  evs.forEach(ev => removeEventListener(ev, onSkip));
  loadListeners.delete(upd);
  tcRun = false;

  // выход: логотип улетает в шапку, диафрагма раскрывается
  const logo = q('#introLogoWrap');
  const navLogo = $('#navLogo');
  const r1 = logo.getBoundingClientRect();
  const r2 = navLogo.getBoundingClientRect();
  const dx = r2.left + r2.width / 2 - (r1.left + r1.width / 2);
  const dy = r2.top + r2.height / 2 - (r1.top + r1.height / 2);
  if (r1.width > 1 && r2.width > 1) {
    const sc = r2.width / r1.width;
    logo.animate([{ transform: 'none' }, { transform: `translate(${dx}px, ${dy}px) scale(${sc})` }], { duration: 950, easing: 'cubic-bezier(.65,0,.35,1)', fill: 'forwards' });
  }
  $$('.intro__osd, .intro__vf i, .intro__hint, #introSub', el).forEach(n => n.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 350, fill: 'forwards' }));
  A(q('.intro__rings'), [{ transform: 'scale(1)', opacity: 1 }, { transform: 'scale(2.6)', opacity: 0 }], { duration: 950 });
  setTimeout(() => body.classList.remove('is-intro'), 620);
  el.classList.add('is-opening');
  await wait(110);
  await openAperture(q('#aperture'), 1050);
  logo.animate([{ opacity: 1 }, { opacity: 0 }], { duration: 220, fill: 'forwards' });
  await wait(200);
  finish();
}

function afterIntro() {
  if (beatsOn) {
    paintBeat(beatI);
    const updPill = () => {
      const p = Math.round(loadedFraction() * 100);
      loadPillRing.style.strokeDashoffset = 100 - p;
      loadPillPct.textContent = p;
      loadPill.classList.toggle('is-on', p < 100);
      if (p >= 100) loadListeners.delete(updPill);
    };
    loadListeners.add(updPill);
    updPill();
  }
  // на телефоне плашка cookie ждёт конца ролика, на компьютере она сбоку и не мешает
  if (!MOBILE_MQ.matches) setTimeout(showCookie, 1400);
}

/* ---------- шапка, меню, мобильная панель ---------- */
const nav = $('#nav');
const mbar = $('#mbar');
function onPageScroll() {
  const y = scrollY;
  const pastHero = y > heroTop + hero.offsetHeight - 80;
  nav.classList.toggle('is-solid', pastHero);
  const contact = $('#contact');
  const nearForm = contact && contact.getBoundingClientRect().top < innerHeight * 0.6 && contact.getBoundingClientRect().bottom > 0;
  mbar.classList.toggle('is-on', introDone && y > heroEndY && !nearForm);
  if (introDone && beatsOn && y > heroEndY) showCookie();
}
addEventListener('scroll', onPageScroll, { passive: true });

const burger = $('#burger');
const menu = $('#menu');
function setMenu(open) {
  burger.setAttribute('aria-expanded', String(open));
  if (open) { menu.hidden = false; requestAnimationFrame(() => menu.classList.add('is-open')); root.classList.add('is-locked'); }
  else { menu.classList.remove('is-open'); root.classList.remove('is-locked'); setTimeout(() => { if (burger.getAttribute('aria-expanded') === 'false') menu.hidden = true; }, 400); }
}
burger.addEventListener('click', () => setMenu(burger.getAttribute('aria-expanded') !== 'true'));
$$('a', menu).forEach(a => a.addEventListener('click', () => setMenu(false)));
addEventListener('keydown', e => { if (e.key === 'Escape' && burger.getAttribute('aria-expanded') === 'true') { setMenu(false); burger.focus(); } });

// Якоря: короткий путь плавно, длинный (через весь ролик) сразу.
document.addEventListener('click', e => {
  const a = e.target.closest('a[href^="#"]');
  if (!a) return;
  const id = a.getAttribute('href').slice(1);
  const t = id === 'top' ? document.body : document.getElementById(id);
  if (!t) return;
  e.preventDefault();
  const y = id === 'top' ? 0 : t.getBoundingClientRect().top + scrollY - 76;
  const far = Math.abs(y - scrollY) > innerHeight * 4;
  scrollTo({ top: y, behavior: far || mqReduce.matches ? 'auto' : 'smooth' });
  if (id !== 'top') history.replaceState(null, '', '#' + id);
});

/* ---------- появление при прокрутке ---------- */
const revealIO = new IntersectionObserver(entries => {
  entries.forEach(en => {
    if (!en.isIntersecting) return;
    const el = en.target;
    el.classList.add('in');
    revealIO.unobserve(el);
    setTimeout(() => el.classList.add('settled'), 1600);
  });
}, { threshold: 0.14, rootMargin: '0px 0px -6% 0px' });
$$('[data-reveal]').forEach(el => revealIO.observe(el));

/* ---------- вступительная фраза: слова загораются ---------- */
const mani = $('#manifesto');
let maniWords = [];
let maniLit = -1;
if (mani) maniWords = split(mani, 'words');
function onManifesto() {
  if (!mani || !maniWords.length) return;
  const r = mani.getBoundingClientRect();
  if (r.bottom < 0 || r.top > innerHeight) return;
  const p = clamp((innerHeight * 0.82 - r.top) / (r.height + innerHeight * 0.3), 0, 1);
  const lit = mqReduce.matches ? maniWords.length : Math.round(p * maniWords.length);
  if (lit === maniLit) return;
  maniLit = lit;
  maniWords.forEach((w, i) => w.classList.toggle('on', i < lit));
}
addEventListener('scroll', onManifesto, { passive: true });

/* ---------- услуги: вкладки и свет за курсором ---------- */
const tabs = $$('.svc__tab');
const ind = $('.svc__ind');
function selectTab(tab, focus) {
  tabs.forEach(t => {
    const on = t === tab;
    t.setAttribute('aria-selected', String(on));
    t.tabIndex = on ? 0 : -1;
    const pan = document.getElementById(t.getAttribute('aria-controls'));
    if (on) { pan.hidden = false; pan.classList.remove('is-enter'); void pan.offsetWidth; pan.classList.add('is-enter'); }
    else pan.hidden = true;
  });
  if (ind) { ind.style.setProperty('--y', tab.offsetTop + 'px'); ind.style.setProperty('--h', tab.offsetHeight + 'px'); }
  if (focus) tab.focus();
}
tabs.forEach((t, i) => {
  t.addEventListener('click', () => selectTab(t));
  t.addEventListener('keydown', e => {
    const d = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1 }[e.key];
    if (d) { e.preventDefault(); selectTab(tabs[(i + d + tabs.length) % tabs.length], true); }
    if (e.key === 'Home') { e.preventDefault(); selectTab(tabs[0], true); }
    if (e.key === 'End') { e.preventDefault(); selectTab(tabs[tabs.length - 1], true); }
  });
});
if (tabs.length) selectTab(tabs[0]);

$$('.svc__card, .formcard').forEach(card => {
  const spot = $('.spot', card);
  if (!spot) return;
  let pend = null;
  card.addEventListener('pointermove', e => {
    const r = card.getBoundingClientRect();
    pend = [e.clientX - r.left, e.clientY - r.top];
    requestAnimationFrame(() => { if (!pend) return; spot.style.setProperty('--mx', pend[0] + 'px'); spot.style.setProperty('--my', pend[1] + 'px'); pend = null; });
  });
});

/* ---------- счётчики ---------- */
const countIO = new IntersectionObserver(entries => {
  entries.forEach(en => {
    if (!en.isIntersecting) return;
    countIO.unobserve(en.target);
    const el = en.target;
    const to = +el.dataset.to;
    const suf = el.dataset.suffix || '';
    if (mqReduce.matches) return;
    const t0 = performance.now();
    const step = now => {
      const t = clamp((now - t0) / 1500, 0, 1);
      el.textContent = Math.round(to * easeOut(t)) + suf;
      if (t < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  });
}, { threshold: 0.5 });
$$('.count').forEach(el => countIO.observe(el));

/* ---------- калькулятор ---------- */
const calcForm = $('#calcForm');
const P = CFG.prices;
const OBJ = { house: 'Жилой дом', flat: 'Магазин', office: 'Офис', warehouse: 'Склад или цех' };
const SYS = [['cctv', 'камеры'], ['access', 'СКУД'], ['fire', 'АПС'], ['soue', 'СОУЭ']];
// АПС и СОУЭ: цена «от» по типу объекта из админки, 0 значит «по смете»
const EXTRA = [['fire', 'Пожарная сигнализация (АПС)', 'АПС'], ['soue', 'Оповещение (СОУЭ)', 'СОУЭ']];
const PTS = ['2-4', '5-8', '9-16', '17+'];
const fmt = n => Math.round(n).toLocaleString('ru-RU').replace(/[  ]/g, ' ');
const touched = new Set();
let calcState = null;
const nf = $('#nf');
let nfStr = '';

function calcRead() {
  const fd = new FormData(calcForm);
  const sel = { cctv: !!fd.get('sys_cctv'), access: !!fd.get('sys_access'), fire: !!fd.get('sys_fire'), soue: !!fd.get('sys_soue') };
  return {
    obj: fd.get('obj'), sel, pts: +fd.get('pts'),
    night: sel.cctv && !!fd.get('night'), archive: sel.cctv && !!fd.get('archive'), remote: sel.cctv && !!fd.get('remote'),
  };
}
function calcPrice(c) {
  const any = SYS.some(([k]) => c.sel[k]);
  let cam = 0, acc = 0, disc = 0;
  if (c.sel.cctv) cam = +P.cctv[c.obj][c.pts];
  if (c.sel.access) acc = +P.access[c.pts];
  let sum = cam + acc;
  if (c.sel.cctv && c.sel.access) { const t = Math.round(sum * (100 - P.both_discount) / 100); disc = sum - t; sum = t; }
  const opt = [];
  if (c.night) { sum += +P.night; opt.push(['Цветное ночное видение', '+' + fmt(P.night) + ' ₽']); }
  if (c.archive) { sum += +P.archive; opt.push(['Архив больше 30 дней', '+' + fmt(P.archive) + ' ₽']); }
  const extra = [], est = [];
  EXTRA.forEach(([k, label, short]) => {
    if (!c.sel[k]) return;
    const v = +((P[k] || {})[c.obj] || 0);
    if (v > 0) { sum += v; extra.push([label, 'от ' + fmt(v) + ' ₽']); }
    else { est.push(short); extra.push([label, 'по смете']); }
  });
  return { any, sum, cam, acc, disc, opt, extra, est };
}
function renderNf(str) {
  if (!nf) return;
  if (str.length !== nfStr.length || !nf.querySelector('.nf__col')) {
    nf.textContent = '';
    for (const ch of str) {
      if (ch === ' ') { nf.appendChild(mk('span', 'nf__sp')); continue; }
      const d = mk('span', 'nf__d');
      const col = mk('span', 'nf__col');
      for (let k = 0; k < 10; k++) col.appendChild(mk('span', null, String(k)));
      col.style.setProperty('--d', nfStr ? 0 : ch);
      d.appendChild(col);
      nf.appendChild(d);
    }
    const first = !nfStr;
    nfStr = str;
    if (!first) requestAnimationFrame(() => requestAnimationFrame(() => setDigits(str)));
    return;
  }
  setDigits(str);
  nfStr = str;
}
function setDigits(str) {
  const cols = $$('.nf__col', nf);
  let j = 0;
  for (const ch of str) { if (ch === ' ') continue; if (cols[j]) cols[j].style.setProperty('--d', ch); j++; }
}
function calcUpdate() {
  if (!calcForm || !P) return;
  const c = calcRead();
  const r = calcPrice(c);
  calcState = r.any ? Object.assign({}, c, { price: r.sum, est: r.est }) : null;

  // без камер и СКУД число точек не нужно, без камер не нужны ночь и архив
  const pts = $('.step[data-step="pts"]', calcForm);
  if (pts) pts.classList.toggle('is-off', !(c.sel.cctv || c.sel.access));
  $$('[data-cam]', calcForm).forEach(l => {
    const off = !c.sel.cctv;
    l.classList.toggle('is-off', off);
    $('input', l).disabled = off;
  });
  $$('[data-sys]', calcForm).forEach(l => {
    const v = +((P[l.dataset.sys] || {})[c.obj] || 0);
    $('em', l).textContent = v > 0 ? 'от ' + fmt(v) + ' ₽' : 'по смете';
  });
  const hint = $('#calcHint');
  if (hint) hint.textContent = c.sel.cctv && c.sel.access
    ? `Скидка ${P.both_discount}% за камеры и СКУД вместе уже учтена`
    : `Камеры и СКУД вместе дешевле на ${P.both_discount}%`;

  const numEl = $('#priceNum');
  const estEl = $('#priceEst');
  if (!r.any) {
    numEl.hidden = true; estEl.hidden = false; estEl.textContent = 'Выберите систему';
    $('#priceSr').textContent = 'Выберите хотя бы одну систему';
  } else if (r.sum > 0) {
    numEl.hidden = false; estEl.hidden = true;
    renderNf(fmt(r.sum));
    $('#priceSr').textContent = 'от ' + fmt(r.sum) + ' ₽' + (r.est.length ? ', ' + r.est.join(' и ') + ' по смете' : '');
  } else {
    numEl.hidden = true; estEl.hidden = false; estEl.textContent = 'по смете';
    $('#priceSr').textContent = 'Цена по смете после осмотра';
  }

  const lines = [];
  if (r.cam) lines.push([`Камеры, ${PTS[c.pts]} шт.`, fmt(r.cam) + ' ₽']);
  if (r.acc) lines.push([`СКУД, ${PTS[c.pts]} ${c.pts === 3 ? 'дверей' : 'двери'}`, fmt(r.acc) + ' ₽']);
  if (r.disc) lines.push([`Скидка за камеры и СКУД, ${P.both_discount}%`, '−' + fmt(r.disc) + ' ₽']);
  r.opt.forEach(o => lines.push(o));
  r.extra.forEach(o => lines.push(o));
  if (c.remote) lines.push(['Просмотр со смартфона', 'бесплатно']);
  const ul = $('#calcLines');
  ul.textContent = '';
  lines.forEach(([a, b]) => { const li = mk('li'); li.append(mk('span', null, a), mk('b', null, b)); ul.appendChild(li); });

  const f = touched.size / 4;
  $('#calcRing').style.strokeDashoffset = 100 - f * 100;
  const iris = $('#calcIris');
  $('.r2', iris).style.transform = `scale(${(1.14 - 0.14 * f).toFixed(3)})`;
  $('.r3', iris).style.transform = `scale(${(1.3 - 0.3 * f).toFixed(3)})`;
  $('.c', iris).style.transform = `scale(${(0.55 + 0.45 * f).toFixed(3)})`;
}
if (calcForm) {
  calcForm.addEventListener('change', e => {
    const step = e.target.closest('.step');
    if (step) touched.add(step.dataset.step);
    calcUpdate();
  });
  calcUpdate();
  $('#calcGo').addEventListener('click', () => {
    attachCalc(calcState);
    goal('calc_done');
    const target = $('#contact');
    scrollTo({ top: target.getBoundingClientRect().top + scrollY - 76, behavior: mqReduce.matches ? 'auto' : 'smooth' });
    setTimeout(() => $('#fName').focus({ preventScroll: true }), 700);
  });
}

/* ---------- форма заявки ---------- */
const form = $('#leadForm');
let formCalc = null;
function calcText(c) {
  if (!c) return '';
  const sys = SYS.filter(([k]) => c.sel[k]).map(([, l]) => l);
  const parts = [OBJ[c.obj] + ': ' + sys.join(', ')];
  if (c.sel.cctv || c.sel.access) parts.push(PTS[c.pts] + (c.pts === 0 ? ' точки' : ' точек'));
  if (c.night) parts.push('цветное ночное видение');
  if (c.archive) parts.push('архив больше 30 дней');
  let t = parts.join(', ');
  if (c.price > 0) t += ': от ' + fmt(c.price) + ' ₽';
  if (c.est && c.est.length) t += c.price > 0 ? ' + ' + c.est.join(' и ') + ' по смете' : ', по смете';
  return t;
}
function attachCalc(c) {
  formCalc = c;
  const chip = $('#calcChip');
  chip.hidden = !c;
  if (c) {
    $('#calcChipText').textContent = calcText(c);
    const objMap = { house: 'house', flat: 'office', office: 'office', warehouse: 'warehouse' };
    const sel = $('#fObj');
    if (!sel.value) sel.value = objMap[c.obj] || '';
  }
}
$('#calcChipX') && $('#calcChipX').addEventListener('click', () => attachCalc(null));

const phoneIn = $('#fPhone');
function fmtPhone(v) {
  let d = v.replace(/\D/g, '');
  if (d.startsWith('7') || d.startsWith('8')) d = d.slice(1);
  d = d.slice(0, 10);
  if (!d) return '';
  let s = '+7 (' + d.slice(0, 3);
  if (d.length >= 3) s += ') ' + d.slice(3, 6);
  if (d.length >= 6) s += '-' + d.slice(6, 8);
  if (d.length >= 8) s += '-' + d.slice(8, 10);
  return s;
}
const phoneDigits = v => { let d = v.replace(/\D/g, ''); if (d.length === 11 && (d[0] === '7' || d[0] === '8')) d = d.slice(1); return d; };
if (phoneIn) {
  phoneIn.addEventListener('input', e => {
    if (e.inputType && e.inputType.startsWith('delete')) return;
    phoneIn.value = fmtPhone(phoneIn.value);
  });
  phoneIn.addEventListener('blur', () => { phoneIn.value = fmtPhone(phoneIn.value); });
}

function setErr(name, msg) {
  const map = { name: ['#fName', '#eName'], phone: ['#fPhone', '#ePhone'], consent: ['#fConsent', '#eConsent'] };
  const m = map[name];
  if (!m) return;
  const input = $(m[0]);
  const field = input.closest('.field');
  field.classList.toggle('is-bad', !!msg);
  input.setAttribute('aria-invalid', msg ? 'true' : 'false');
  $(m[1]).textContent = msg || '';
}
function validate() {
  const errs = {};
  const name = $('#fName').value.trim();
  if (name.length < 2 || name.length > 60) errs.name = 'Напишите, как к вам обращаться';
  if (phoneDigits(phoneIn.value).length !== 10) errs.phone = 'Проверьте номер: нужно 10 цифр после +7';
  if (!$('#fConsent').checked) errs.consent = 'Отметьте согласие, без него мы не сможем принять заявку';
  ['name', 'phone', 'consent'].forEach(k => setErr(k, errs[k]));
  return errs;
}
if (form) {
  $('#fName').addEventListener('input', () => setErr('name', ''));
  phoneIn.addEventListener('input', () => setErr('phone', ''));
  $('#fConsent').addEventListener('change', () => setErr('consent', ''));
}

function utm() {
  try {
    const u = new URLSearchParams(location.search);
    const o = {};
    ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'].forEach(k => { if (u.get(k)) o[k] = u.get(k).slice(0, 120); });
    if (Object.keys(o).length) SS.set('iris_utm', JSON.stringify(o));
    return JSON.parse(SS.get('iris_utm') || '{}');
  } catch (e) { return {}; }
}
const UTM = utm();

function leadText() {
  const obj = $('#fObj');
  const lines = ['Здравствуйте! Заявка с сайта АЙРИС.', 'Имя: ' + $('#fName').value.trim(), 'Телефон: ' + phoneIn.value.trim()];
  if (obj.value) lines.push('Объект: ' + obj.options[obj.selectedIndex].text);
  if (formCalc) lines.push('Расчёт: ' + calcText(formCalc));
  const cm = $('#fComment').value.trim();
  if (cm) lines.push('Комментарий: ' + cm);
  return lines.join('\n');
}
function showFail(msg, title) {
  const text = leadText();
  $('#failTitle').textContent = title || 'Не удалось отправить';
  $('#failText').textContent = msg || 'Отправьте ту же заявку в мессенджер, текст уже готов.';
  $('#failRetry').textContent = CFG.static ? 'Изменить заявку' : 'Попробовать ещё раз';
  $('#failWa').href = `https://wa.me/${CFG.wa}?text=${encodeURIComponent(text)}`;
  form.hidden = true;
  const box = $('#formFail');
  box.hidden = false;
  box.focus();
}
if (form) {
  $('#failTg').addEventListener('click', () => {
    try { navigator.clipboard.writeText(leadText()); $('#failTgNote').hidden = false; } catch (e) { /* без буфера обмена */ }
  });
  $('#failRetry').addEventListener('click', () => { $('#formFail').hidden = true; form.hidden = false; $('#fName').focus(); });

  form.addEventListener('submit', async e => {
    e.preventDefault();
    const errs = validate();
    const first = ['name', 'phone', 'consent'].find(k => errs[k]);
    if (first) { ({ name: $('#fName'), phone: phoneIn, consent: $('#fConsent') })[first].focus(); return; }
    // статическая копия без сервера: заявка уходит в мессенджер готовым текстом
    if (CFG.static) { showFail('Выберите мессенджер: текст заявки уже готов, останется нажать «Отправить».', 'Остался один шаг'); return; }
    const btn = $('#leadSubmit');
    const label = $('span', btn);
    btn.disabled = true;
    label.textContent = 'Отправляем…';
    const payload = {
      name: $('#fName').value.trim(),
      phone: phoneIn.value,
      object_type: $('#fObj').value,
      comment: $('#fComment').value.trim(),
      consent: $('#fConsent').checked,
      token: form.token.value,
      website: form.website.value,
      calc: formCalc ? { obj: formCalc.obj, sel: formCalc.sel, pts: formCalc.pts, night: formCalc.night, archive: formCalc.archive } : null,
      source: formCalc ? 'calc' : 'form',
      page: location.pathname + location.hash,
      utm: UTM,
    };
    const ac = new AbortController();
    const to = setTimeout(() => ac.abort(), 12000);
    try {
      const res = await fetch(`${BASE}/api/lead.php`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload), signal: ac.signal });
      clearTimeout(to);
      let data = null;
      try { data = await res.json(); } catch (x) { data = null; }
      if (res.ok && data && data.ok) {
        form.hidden = true;
        const ok = $('#formOk');
        ok.hidden = false;
        ok.focus();
        goal('lead_sent');
      } else if (data && data.error === 'validation' && data.fields) {
        Object.entries(data.fields).forEach(([k, v]) => setErr(k, v));
      } else {
        showFail(data && data.message ? data.message + ' Или отправьте заявку в мессенджер, текст уже готов.' : null);
      }
    } catch (x) {
      clearTimeout(to);
      showFail();
    } finally {
      btn.disabled = false;
      label.textContent = 'Отправить заявку';
    }
  });
}

/* ---------- отзывы: ленты крутятся, только когда видны ---------- */
const marqIO = new IntersectionObserver(entries => entries.forEach(en => en.target.classList.toggle('is-vis', en.isIntersecting)), { rootMargin: '100px' });
$$('.marquee').forEach(m => marqIO.observe(m));

/* ---------- лайтбокс фото ---------- */
const lb = $('#lb');
if (lb && lb.showModal) {
  $$('.work__btn').forEach(b => b.addEventListener('click', () => {
    $('#lbImg').src = b.dataset.src;
    $('#lbImg').alt = b.dataset.title + ': ' + b.dataset.sub;
    $('#lbCap').textContent = b.dataset.title + ' · ' + b.dataset.sub;
    lb.showModal();
  }));
  $('.lb__x', lb).addEventListener('click', () => lb.close());
  lb.addEventListener('click', e => { if (e.target === lb) lb.close(); });
}

/* ---------- cookie и Метрика ---------- */
function loadMetrika() {
  if (window.ym || !CFG.metrika) return;
  /* eslint-disable */
  (function (m, e, t, r, i, k, a) { m[i] = m[i] || function () { (m[i].a = m[i].a || []).push(arguments); }; m[i].l = 1 * new Date(); k = e.createElement(t); a = e.getElementsByTagName(t)[0]; k.async = 1; k.src = r; a.parentNode.insertBefore(k, a); })(window, document, 'script', 'https://mc.yandex.ru/metrika/tag.js', 'ym');
  /* eslint-enable */
  window.ym(CFG.metrika, 'init', { clickmap: true, trackLinks: true, accurateTrackBounce: true });
}
const cookie = $('#cookie');
const choice = LS.get('iris_cookie');
if (choice === 'yes') loadMetrika();
let cookieShown = false;
function showCookie() {
  if (cookieShown || !cookie || LS.get('iris_cookie')) return;
  cookieShown = true;
  cookie.hidden = false;
  requestAnimationFrame(() => cookie.classList.add('is-on'));
}
function closeCookie(v) {
  LS.set('iris_cookie', v);
  cookie.classList.remove('is-on');
  setTimeout(() => { cookie.hidden = true; }, 500);
  if (v === 'yes') loadMetrika();
}
if (cookie) {
  $('#cookieYes').addEventListener('click', () => closeCookie('yes'));
  $('#cookieNo').addEventListener('click', () => closeCookie('no'));
}

// цели Метрики по звонкам и мессенджерам
document.addEventListener('click', e => {
  const a = e.target.closest('a[href]');
  if (!a) return;
  const h = a.getAttribute('href');
  if (h.startsWith('tel:')) goal('call_click');
  else if (h.includes('wa.me')) goal('wa_click');
  else if (h.includes('t.me')) goal('tg_click');
});

/* ---------- фон под роликом: символы плывут вниз, лучи идут вверх ----------
   Перенос Particle Drift с 21st.dev на чистый холст. Работает, только когда под роликом видна страница:
   пока ролик на весь экран, холст спит и не мешает видео. */
(() => {
  const cv = $('#drift');
  if (!cv || !cv.getContext) return;
  const ctx = cv.getContext('2d');
  const CH = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ@#$%&*()'.split('');
  const pick = () => CH[(Math.random() * CH.length) | 0];
  const mouse = { x: -1000, y: -1000 };
  let w = 0, h = 0, nodes = [], beams = [], raf = 0, last = 0, below = false;

  function size() {
    w = innerWidth;
    h = innerHeight;
    const dpr = Math.min(2, devicePixelRatio || 1);
    cv.width = Math.round(w * dpr);
    cv.height = Math.round(h * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }
  function seed() {
    // на маленьком экране частиц меньше, на большом чуть больше
    const k = clamp((w * h) / (1440 * 900), 0.45, 1.3);
    nodes = Array.from({ length: Math.round(90 * k) }, () => ({ x: Math.random() * w, y: Math.random() * h, vy: Math.random() * 0.4 + 0.1, ch: pick() }));
    beams = Array.from({ length: Math.round(25 * k) }, () => ({ x: Math.random() * w, y: Math.random() * h, len: Math.random() * 100 + 50, sp: Math.random() * 6 + 3, op: Math.random() * 0.5 + 0.3 }));
  }
  function draw(k) {
    ctx.clearRect(0, 0, w, h);
    // лучи снизу вверх
    ctx.lineWidth = 1.5;
    for (const b of beams) {
      b.y -= b.sp * k;
      if (b.y + b.len < 0) { b.y = h + 100; b.x = Math.random() * w; }
      const g = ctx.createLinearGradient(b.x, b.y, b.x, b.y + b.len);
      g.addColorStop(0, `rgba(96,165,250,${b.op})`);
      g.addColorStop(1, 'rgba(96,165,250,0)');
      ctx.strokeStyle = g;
      ctx.beginPath();
      ctx.moveTo(b.x, b.y);
      ctx.lineTo(b.x, b.y + b.len);
      ctx.stroke();
    }
    // тонкие линии между соседними символами
    ctx.lineWidth = 0.5;
    for (let i = 0; i < nodes.length; i++) {
      const a = nodes[i];
      for (let j = i + 1; j < nodes.length; j++) {
        const b = nodes[j];
        const d = Math.hypot(a.x - b.x, a.y - b.y);
        if (d < 120) {
          ctx.strokeStyle = `rgba(156,163,175,${0.15 * (1 - d / 120)})`;
          ctx.beginPath();
          ctx.moveTo(a.x, a.y);
          ctx.lineTo(b.x, b.y);
          ctx.stroke();
        }
      }
    }
    // символы: медленно плывут вниз, у курсора загораются и тянутся к нему
    ctx.font = '12px "JetBrains Mono", monospace';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    for (const n of nodes) {
      n.y += n.vy * k;
      if (n.y > h + 20) { n.y = -20; n.x = Math.random() * w; }
      const d = Math.hypot(mouse.x - n.x, mouse.y - n.y);
      if (d < 180 || Math.random() > 0.98) n.ch = pick();
      if (d < 180) {
        ctx.strokeStyle = `rgba(96,165,250,${0.5 * (1 - d / 180)})`;
        ctx.beginPath();
        ctx.moveTo(n.x, n.y);
        ctx.lineTo(mouse.x, mouse.y);
        ctx.stroke();
      }
      ctx.fillStyle = d < 180 ? '#60A5FA' : 'rgba(156,163,175,0.4)';
      ctx.fillText(n.ch, n.x, n.y);
    }
  }
  function loop(now) {
    // скорость по времени, а не по кадрам: на экране 144 Гц всё движется так же, как на 60 Гц
    const k = Math.min(3, (now - (last || now)) / 16.667);
    last = now;
    draw(k);
    raf = requestAnimationFrame(loop);
  }
  function update() {
    const run = below && !document.hidden && !mqReduce.matches;
    cv.classList.toggle('is-on', below);
    if (run && !raf) { last = 0; raf = requestAnimationFrame(loop); }
    if (!run && raf) { cancelAnimationFrame(raf); raf = 0; }
    // без движения: один неподвижный кадр
    if (below && mqReduce.matches) draw(0);
  }

  size();
  seed();
  // под роликом видна страница, когда герой занимает меньше 95% экрана
  const heroEl = $('#hero');
  if (heroEl) {
    new IntersectionObserver(en => { below = en[0].intersectionRatio < 0.95; update(); },
      { threshold: [0, 0.95, 1] }).observe(heroEl);
  } else { below = true; }
  addEventListener('pointermove', e => { mouse.x = e.clientX; mouse.y = e.clientY; }, { passive: true });
  document.addEventListener('pointerleave', () => { mouse.x = mouse.y = -1000; });
  let rt = 0;
  addEventListener('resize', () => { clearTimeout(rt); rt = setTimeout(() => { const ow = w; size(); if (Math.abs(w - ow) > 80) seed(); if (!raf && below) draw(0); }, 150); });
  document.addEventListener('visibilitychange', update);
  if (mqReduce.addEventListener) mqReduce.addEventListener('change', update);
  update();
})();

/* ---------- пауза анимаций на скрытой вкладке ---------- */
document.addEventListener('visibilitychange', () => body.classList.toggle('paused', document.hidden));

/* ---------- запуск ---------- */
setupFx();
tickEl = $('.plate[data-plate="5"] .tick');

if (stage) {
  const heroIO = new IntersectionObserver(entries => {
    entries.forEach(en => {
      heroVisible = en.isIntersecting;
    });
  });
  heroIO.observe(stage);
  setInterval(() => { if (heroVisible) tickClock(); }, 1000);
  tickClock();
}

applyMode();
[mqReduce, mqLandPhone, MOBILE_MQ].forEach(m => (m.addEventListener ? m.addEventListener('change', applyMode) : m.addListener(applyMode)));

let rsz = 0, lastW = innerWidth, lastH = innerHeight;
addEventListener('resize', () => {
  clearTimeout(rsz);
  rsz = setTimeout(() => {
    if (innerWidth === lastW && Math.abs(innerHeight - lastH) < 120) return;
    lastW = innerWidth; lastH = innerHeight;
    layout();
    if (tabs.length) selectTab(tabs.find(t => t.getAttribute('aria-selected') === 'true') || tabs[0]);
  }, 150);
});
if (document.fonts && document.fonts.ready) document.fonts.ready.then(layout);

runIntro();
onPageScroll();
onManifesto();
})();
