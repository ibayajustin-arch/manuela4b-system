// Temporary measurement harness: launches headless Chrome with CDP, measures the
// mobile layout of a real page, prints overflow culprits, then exits.
const { spawn } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9333;
const URL = process.argv[2] || 'http://127.0.0.1/Manuela/admin/_preview.php?page=dashboard';
const WIDTH = Number(process.argv[3] || 390);
const HEIGHT = Number(process.argv[4] || 1700);
const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'cdp-measure-'));

const chrome = spawn(CHROME, [
  '--headless=new', '--disable-gpu', '--hide-scrollbars', '--no-first-run',
  `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`,
  `--window-size=${WIDTH},${HEIGHT}`, URL
], { stdio: 'ignore' });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function getTarget() {
  for (let i = 0; i < 60; i++) {
    try {
      const res = await fetch(`http://127.0.0.1:${PORT}/json`);
      const list = await res.json();
      const page = list.find((t) => t.type === 'page' && t.webSocketDebuggerUrl);
      if (page) return page;
    } catch (e) { /* not up yet */ }
    await sleep(250);
  }
  throw new Error('Chrome did not expose a page target');
}

(async () => {
  const target = await getTarget();
  const ws = new WebSocket(target.webSocketDebuggerUrl);
  let id = 0;
  const pending = new Map();
  ws.addEventListener('message', (ev) => {
    const msg = JSON.parse(ev.data);
    if (msg.id && pending.has(msg.id)) { pending.get(msg.id)(msg); pending.delete(msg.id); }
  });
  const send = (method, params = {}) => new Promise((resolve, reject) => {
    const mid = ++id;
    pending.set(mid, (m) => (m.error ? reject(new Error(JSON.stringify(m.error))) : resolve(m.result)));
    ws.send(JSON.stringify({ id: mid, method, params }));
  });
  await new Promise((resolve) => ws.addEventListener('open', resolve, { once: true }));
  await send('Page.enable');
  await send('Emulation.setDeviceMetricsOverride', {
    width: WIDTH, height: HEIGHT, deviceScaleFactor: 1, mobile: true,
    screenWidth: WIDTH, screenHeight: HEIGHT
  });
  await send('Page.navigate', { url: URL });
  await sleep(3500);
  const expr = `JSON.stringify({
    scrollW: document.documentElement.scrollWidth,
    clientW: document.documentElement.clientWidth,
    bodyW: Math.round(document.body.getBoundingClientRect().width),
    main: (() => { const e = document.querySelector('.main-content'); if (!e) return null; const r = e.getBoundingClientRect(); const s = getComputedStyle(e); return { w: Math.round(r.width), left: Math.round(r.left), padL: s.paddingLeft, padR: s.paddingRight }; })(),
    wide: Array.from(document.querySelectorAll('body *')).filter((e) => {
      const r = e.getBoundingClientRect();
      return r.width > document.documentElement.clientWidth + 1 || r.right > document.documentElement.clientWidth + 1;
    }).slice(0, 12).map((e) => ({ tag: e.tagName, cls: (e.className || '').toString().slice(0, 70), w: Math.round(e.getBoundingClientRect().width), right: Math.round(e.getBoundingClientRect().right) }))
  })`;
  const out = await send('Runtime.evaluate', { expression: expr, returnByValue: true });
  console.log(out.result.value);
  const shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
  const file = `C:\\xampp\\htdocs\\Manuela\\.tmp\\${process.argv[5] || 'measure.png'}`;
  fs.writeFileSync(file, Buffer.from(shot.data, 'base64'));
  ws.close();
  chrome.kill();
  process.exit(0);
})().catch((e) => { console.error('ERR', e.message); chrome.kill(); process.exit(1); });
