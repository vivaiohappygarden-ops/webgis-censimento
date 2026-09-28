/**
 * Schermate del programma per il depliant, prese dal Comune dimostrativo con
 * la nuova interfaccia. Il nome "WebGIS Censimento" scritto nel menu viene
 * sostituito al volo con "ArborLab" solo nella schermata (nome commerciale dal
 * 28/09/2026): il codice del programma non cambia.
 *
 * Presupposti: server su BASE, db:seed + demo:patrimonio, portale del Comune
 * Demo acceso con indirizzo "demo". Sotto `php artisan serve` l'area riservata
 * (/portale) risponde 404 per la cartella public/portale: serve un server
 * vero o il server integrato con un instradatore che distingue file e cartelle.
 *
 *   ALBERO_ID=<uuid ALB-0002> ORDINE_ID=<uuid ODS-DEMO-0001> node docs/depliant/schermate.mjs
 *
 * SEZ sceglie i gruppi: g (gestionale), c (app di campo), p (area riservata),
 * t (portale pubblico dal telefono). CHROME_PATH come per genera-pdf.mjs.
 */
import { chromium } from 'playwright';
const BASE = process.env.BASE ?? 'http://127.0.0.1:8000';
const ALBERO_ID = process.env.ALBERO_ID ?? '';
const ORDINE_ID = process.env.ORDINE_ID ?? '';
const OUT = new URL('./img/', import.meta.url).pathname;
import { mkdirSync } from 'node:fs'; mkdirSync(OUT, { recursive: true });
const lancio = { args: ['--no-sandbox'] };
if (process.env.CHROME_PATH) lancio.executablePath = process.env.CHROME_PATH;
const b = await chromium.launch(lancio);
const att = (ms) => new Promise((r) => setTimeout(r, ms));
async function apri(p, u, ms = 2500) { await p.goto(BASE + u, { waitUntil: 'load' }); await p.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {}); await att(ms); await nome(p); }
async function nome(p) { await p.evaluate(() => { const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT); let n; while ((n = w.nextNode())) { if (n.nodeValue.includes('WebGIS Censimento')) n.nodeValue = n.nodeValue.replace('WebGIS Censimento', 'ArborLab'); if (n.nodeValue.includes('WebGIS Operatore')) n.nodeValue = n.nodeValue.replace('WebGIS Operatore', 'ArborLab Campo'); } }); }
async function accedi(p, email) { await p.goto(BASE + '/login'); await p.fill('#email', email); await p.fill('#password', 'password'); await Promise.all([p.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 20000 }), p.click('button[type=submit]')]); await p.waitForLoadState('networkidle').catch(() => {}); }
const opz = { viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2, locale: 'it-IT', timezoneId: 'Europe/Rome', geolocation: { latitude: 45.4663, longitude: 9.1926 }, permissions: ['geolocation'] };
const CLIP = { oggi: [0, 780], patrimonio: [0, 640], scheda: [0, 900], ordine: [0, 760], documenti: [0, 640], mappa: [0, 900], 'area-riservata': [0, 900], vta: [0, 760], gantt: [0, 620] };
const clip = (n) => CLIP[n] ? { x: 240, y: CLIP[n][0], width: 1200, height: CLIP[n][1] } : undefined;
const tel = { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, locale: 'it-IT', timezoneId: 'Europe/Rome' };
const sez = (process.env.SEZ ?? 'g,c,p,t').split(',');
if (sez.includes('g')) {
  const c = await b.newContext(opz); const p = await c.newPage(); await accedi(p, 'admin@demo.local');
  for (const [n, u, ms] of [['oggi','/oggi'],['patrimonio','/patrimonio',3500],['scheda','/censimento/' + ALBERO_ID,4000],['ordine','/lavori/' + ORDINE_ID,5000],['documenti','/documenti'],['mappa','/mappa',12000]]) { await apri(p, u, ms); await p.screenshot({ path: OUT + n + '.png', clip: clip(n) }); console.log(n); }
  await c.close();
}
if (sez.includes('c')) {
  const c = await b.newContext({ ...tel, geolocation: { latitude: 45.4663, longitude: 9.1926 }, permissions: ['geolocation'] }); const p = await c.newPage();
  await accedi(p, 'operatore@demo.local'); await apri(p, '/operatore', 4000); await p.screenshot({ path: OUT + 'operatore.png' });
  for (const [scheda, file] of [['Rilievo', 'operatore-rilievo'], ['Lavori', 'operatore-lavori']]) {
    await p.locator('nav button, nav a, button').filter({ hasText: new RegExp('^\\s*' + scheda + '\\s*$') }).last().click();
    await att(3000); await nome(p); await p.screenshot({ path: OUT + file + '.png' });
  }
  console.log('campo'); await c.close();
}
if (sez.includes('p')) { const c = await b.newContext(opz); const p = await c.newPage(); await accedi(p, 'cliente@demo.local'); await apri(p, '/portale', 4000); await p.screenshot({ path: OUT + 'area-riservata.png', clip: clip('area-riservata') }); console.log('area'); await c.close(); }
if (sez.includes('t')) {
    const t = await b.newContext(tel); const q = await t.newPage(); await apri(q, '/comune/demo', 5000); await q.screenshot({ path: OUT + 'portale-home-telefono.png' }); await apri(q, '/comune/demo/elemento/ALB-0002', 4000); await q.screenshot({ path: OUT + 'portale-elemento-telefono.png' }); console.log('portale'); await t.close();
}
await b.close();
