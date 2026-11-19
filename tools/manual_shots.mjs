// Fotos das telas do manual, tiradas da clinica FICTICIA local (nunca de rubia.med.br).
// Uso (Git Bash), com o banco e o servidor de demonstracao no ar:
//   source tools/env.sh; start_demo_db; APP_ENV=demo php tools/demo/seed.php
//   APP_ENV=demo php -S 127.0.0.1:8001 -t public tools/demo/router.php    (em outro terminal)
//   node tools/manual_shots.mjs [--base http://127.0.0.1:8001] [--only medica]
import { spawn, execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync, readFileSync, rmSync, mkdtempSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { tmpdir } from 'node:os';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
const { totp, viewport, groupByLogin } = require('./manual_shots_lib.js');
const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..');
const CHROME = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const PORT = 9333;
const MAX_HEIGHT = 1500;

const args = process.argv.slice(2);
const opt = (name, def) => { const i = args.indexOf(name); return i >= 0 ? args[i + 1] : def; };
const BASE = opt('--base', 'http://127.0.0.1:8001');
const ONLY = opt('--only', null);
if (!/^http:\/\/(127\.0\.0\.1|localhost)(:\d+)?$/.test(BASE)) {
  console.error('Recusado: as fotos so podem ser tiradas do servidor local de demonstracao.');
  process.exit(1);
}

const php = (a) => execFileSync('php', a, { cwd: ROOT, encoding: 'utf8' });
let list = JSON.parse(php(['tools/manual_shots_list.php']));
if (ONLY) { list = list.filter((s) => s.file.startsWith(ONLY + '/')); }
const logins = JSON.parse(readFileSync(join(ROOT, 'storage/demo/logins.json'), 'utf8'));
const [PASSWORD, SECRET] = JSON.parse(php(['-r', 'require "tools/demo/DemoSeed.php"; echo json_encode([Tools\\Demo\\DemoSeed::PASSWORD, Tools\\Demo\\DemoSeed::TOTP_SECRET]);']));

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function startChrome() {
  const profile = mkdtempSync(join(tmpdir(), 'rubia-shots-'));
  const proc = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, `--user-data-dir=${profile}`,
    '--no-first-run', '--no-default-browser-check', '--hide-scrollbars', '--lang=pt-BR', 'about:blank'], { stdio: 'ignore' });
  for (let i = 0; i < 50; i++) {
    try {
      const v = await (await fetch(`http://127.0.0.1:${PORT}/json/version`)).json();
      return { proc, profile, ws: v.webSocketDebuggerUrl };
    } catch { await sleep(200); }
  }
  throw new Error('Chrome nao respondeu.');
}

function cdp(wsUrl) {
  const ws = new WebSocket(wsUrl);
  let seq = 0;
  const pending = new Map();
  const listeners = [];
  ws.onmessage = (ev) => {
    const m = JSON.parse(ev.data);
    if (m.id && pending.has(m.id)) {
      const { ok, ko } = pending.get(m.id); pending.delete(m.id);
      if (m.error) { ko(new Error(m.error.message)); } else { ok(m.result); }
    } else if (m.method) { listeners.forEach((f) => f(m)); }
  };
  const send = (method, params = {}, sessionId) => new Promise((ok, ko) => {
    const id = ++seq; pending.set(id, { ok, ko });
    ws.send(JSON.stringify({ id, method, params, sessionId }));
  });
  const opened = new Promise((r) => { ws.onopen = r; });
  return { opened, send, on: (f) => listeners.push(f), close: () => ws.close() };
}

async function page(c, contextId) {
  const { targetId } = await c.send('Target.createTarget', { url: 'about:blank', browserContextId: contextId });
  const { sessionId } = await c.send('Target.attachToTarget', { targetId, flatten: true });
  const s = (m, p) => c.send(m, p, sessionId);
  await s('Page.enable'); await s('Runtime.enable');
  const evalJs = async (expr) => (await s('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true })).result.value;
  const waitReady = async () => {
    for (let i = 0; i < 100; i++) {
      await sleep(150);
      try { if (await evalJs('document.readyState') === 'complete') { break; } } catch { /* navegando */ }
    }
    await sleep(500);
  };
  const go = async (url) => { await s('Page.navigate', { url: BASE + url }); await waitReady(); };
  return { s, evalJs, go, waitReady, targetId };
}

async function login(p, as) {
  const user = logins[as];
  await p.go('/login');
  await p.evalJs(`(() => { const f = document.querySelector('form');
    f.querySelector('[name=email]').value = ${JSON.stringify(user)};
    f.querySelector('[name=senha]').value = ${JSON.stringify(PASSWORD)}; f.submit(); })()`);
  await p.waitReady();
  if ((await p.evalJs('location.pathname')) === '/2fa') {
    await p.evalJs(`(() => { const f = document.querySelector('form');
      f.querySelector('[name=codigo]').value = ${JSON.stringify(totp(SECRET, Date.now() / 1000))}; f.submit(); })()`);
    await p.waitReady();
  }
  const path = await p.evalJs('location.pathname');
  if (path === '/login' || path === '/2fa') { throw new Error('Nao consegui entrar como ' + as); }
}

async function shoot(p, shot) {
  const vp = viewport(shot.width);
  await p.s('Emulation.setDeviceMetricsOverride', { width: vp.width, height: vp.height, deviceScaleFactor: 1, mobile: shot.width === 'mobile' });
  await p.go(shot.url);
  if (await p.evalJs('document.querySelectorAll("canvas").length') > 0) { await sleep(1200); } // Chart.js
  const h = Math.min(MAX_HEIGHT, Math.max(vp.height, await p.evalJs('document.documentElement.scrollHeight')));
  const { data } = await p.s('Page.captureScreenshot', { format: 'jpeg', quality: 62, captureBeyondViewport: true,
    clip: { x: 0, y: 0, width: vp.width, height: h, scale: 1 } });
  const out = join(ROOT, 'public/assets/manual', shot.file);
  mkdirSync(dirname(out), { recursive: true });
  writeFileSync(out, Buffer.from(data, 'base64'));
  // Copia reduzida para o PDF (tools/manual_pdf.php), fora de public/ e fora do pacote.
  const small = await p.s('Page.captureScreenshot', { format: 'jpeg', quality: 55, captureBeyondViewport: true,
    clip: { x: 0, y: 0, width: vp.width, height: h, scale: shot.width === 'mobile' ? 0.8 : 0.55 } });
  const outSmall = join(ROOT, 'storage/demo/pdf-img', shot.file);
  mkdirSync(dirname(outSmall), { recursive: true });
  writeFileSync(outSmall, Buffer.from(small.data, 'base64'));
  const final = await p.evalJs('location.pathname + location.search');
  const warn = final !== shot.url ? `  (atenção: abriu ${final})` : '';
  console.log(`ok ${shot.file}${warn}`);
}

const chrome = await startChrome();
const c = cdp(chrome.ws);
await c.opened;
let failed = 0;
try {
  for (const [as, shots] of groupByLogin(list)) {
    const { browserContextId } = await c.send('Target.createBrowserContext');
    const p = await page(c, browserContextId);
    try {
      if (as !== null) { await login(p, as); }
      for (const shot of shots) {
        try { await shoot(p, shot); } catch (e) { failed++; console.error(`ERRO ${shot.file}: ${e.message}`); }
      }
    } catch (e) {
      failed += shots.length; console.error(`ERRO login ${as}: ${e.message}`);
    } finally {
      await c.send('Target.disposeBrowserContext', { browserContextId });
    }
  }
} finally {
  c.close();
  chrome.proc.kill();
  await sleep(500);
  try { rmSync(chrome.profile, { recursive: true, force: true }); } catch { /* Chrome ainda soltando arquivos */ }
}
console.log(`${list.length - failed} fotos, ${failed} erros.`);
process.exit(failed ? 1 : 0);
