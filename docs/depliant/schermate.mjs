/**
 * Schermate del programma per il depliant, prese dal Comune dimostrativo.
 *
 * Presupposti: il server risponde su BASE (php artisan serve), il database
 * ha db:seed + demo:patrimonio, e il portale del Comune Demo e' acceso con
 * indirizzo "demo". Le immagini finiscono in docs/depliant/img.
 *
 *   node docs/depliant/schermate.mjs
 *
 * Variabili facoltative: BASE (http://127.0.0.1:8000), CHROME_PATH (eseguibile
 * di Chromium, se non si vuole quello scaricato da Playwright), ALBERO (codice
 * dell'elemento da mostrare nel portale, di serie ALB-0002), ALBERO_ID (il suo
 * identificativo, per la scheda del gestionale), SEZIONI (quali gruppi
 * rifare, separati da virgola: gestionale, campo, committente, portale).
 *
 * Nota per "php artisan serve": il server integrato di PHP risponde 404 da
 * solo su /portale, perche' in public/ esiste la cartella portale/ dei
 * caratteri. Per l'area del committente serve un server vero (Caddy) o il
 * server integrato con un router che passi al file server solo i file.
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';

const BASE = process.env.BASE ?? 'http://127.0.0.1:8000';
const OUT = new URL('./img/', import.meta.url).pathname;
const ALBERO = process.env.ALBERO ?? 'ALB-0002';
const ALBERO_ID = process.env.ALBERO_ID ?? '';
mkdirSync(OUT, { recursive: true });

const launch = { args: ['--no-sandbox'] };
if (process.env.CHROME_PATH) launch.executablePath = process.env.CHROME_PATH;
if (process.env.HTTPS_PROXY) launch.proxy = { server: process.env.HTTPS_PROXY, bypass: 'localhost,127.0.0.1' };

const browser = await chromium.launch(launch);

const attesa = (ms) => new Promise((r) => setTimeout(r, ms));

async function apri(page, url, ms = 1500) {
    await page.goto(BASE + url, { waitUntil: 'load' });
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
    await attesa(ms);
}

async function accedi(page, email) {
    await page.goto(BASE + '/login', { waitUntil: 'load' });
    await page.fill('#email', email);
    await page.fill('#password', 'password');
    await Promise.all([
        page.waitForURL((u) => !u.pathname.endsWith('/login'), { timeout: 20000 }),
        page.click('button[type=submit]'),
    ]);
    await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
}

const SEZIONI = (process.env.SEZIONI ?? 'gestionale,campo,committente,portale').split(',').map((s) => s.trim());

async function sezione(nome, fn) {
    if (! SEZIONI.includes(nome)) return;
    try { await fn(); } catch (err) { console.error('sezione ' + nome + ' non riuscita:', err.message.split('\n')[0]); }
}

// Ritagli in pixel CSS (finestra 1440x900, barra laterale larga 224): nel
// depliant alcune schermate stanno accanto al testo e la barra laterale,
// uguale in tutte, sarebbe solo spazio sprecato.
const RITAGLI = {
    mappa: { x: 224, y: 0, width: 1216, height: 900 },
    vta: { x: 224, y: 0, width: 1216, height: 760 },
    gantt: { x: 224, y: 95, width: 1216, height: 520 },
};

async function scatta(page, nome) {
    await page.screenshot({ path: OUT + nome + '.png', clip: RITAGLI[nome] });
    console.log('scattata', nome);
}

// ---- Gestionale, dal computer -------------------------------------------
await sezione('gestionale', async () => {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2, ignoreHTTPSErrors: true, locale: 'it-IT', timezoneId: 'Europe/Rome' });
    const page = await ctx.newPage();
    await accedi(page, 'admin@demo.local');

    await apri(page, '/oggi'); await scatta(page, 'oggi');
    await apri(page, '/mappa', 6000); await scatta(page, 'mappa');
    await apri(page, '/censimento', 2500); await scatta(page, 'censimento');
    if (ALBERO_ID) { await apri(page, '/censimento/' + ALBERO_ID, 4000); await scatta(page, 'scheda'); }
    await apri(page, '/vta', 2500); await scatta(page, 'vta');
    await apri(page, '/lavori', 2500); await scatta(page, 'lavori');
    for (const scheda of ['Agenda', 'Gantt', 'Rendiconto']) {
        const tab = page.locator('button, a').filter({ hasText: new RegExp('^\\s*' + scheda + '\\s*$') }).first();
        if (await tab.count()) { await tab.click(); await attesa(2500); await scatta(page, scheda.toLowerCase()); }
    }
    await apri(page, '/ispezioni', 2000); await scatta(page, 'ispezioni');
    await apri(page, '/segnalazioni', 2000); await scatta(page, 'segnalazioni');
    await apri(page, '/statistiche', 3500); await scatta(page, 'statistiche');
    await apri(page, '/territorio', 2500); await scatta(page, 'territorio');
    await apri(page, '/irrigazione', 2000); await scatta(page, 'irrigazione');
    await apri(page, '/fitosanitari', 2000); await scatta(page, 'fitosanitari');
    await apri(page, '/utenti', 2000); await scatta(page, 'utenti');
    await ctx.close();
});

// ---- App di campo, dal telefono -----------------------------------------
await sezione('campo', async () => {
    const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, ignoreHTTPSErrors: true, locale: 'it-IT', timezoneId: 'Europe/Rome' });
    const page = await ctx.newPage();
    await accedi(page, 'operatore@demo.local');
    await apri(page, '/operatore', 4000); await scatta(page, 'operatore');
    await ctx.close();
});

// ---- Area riservata del committente --------------------------------------
await sezione('committente', async () => {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2, ignoreHTTPSErrors: true, locale: 'it-IT', timezoneId: 'Europe/Rome' });
    const page = await ctx.newPage();
    await accedi(page, 'cliente@demo.local');
    await apri(page, '/portale', 3000); await scatta(page, 'portale-committente');
    await ctx.close();
});

// ---- Portale pubblico del Comune -----------------------------------------
await sezione('portale', async () => {
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2, ignoreHTTPSErrors: true, locale: 'it-IT', timezoneId: 'Europe/Rome' });
    const page = await ctx.newPage();
    await apri(page, '/comune/demo', 6000); await scatta(page, 'portale-home');
    await apri(page, '/comune/demo/mappa', 6000); await scatta(page, 'portale-mappa');
    await apri(page, '/comune/demo/elemento/' + ALBERO, 4000); await scatta(page, 'portale-elemento');
    await ctx.close();

    const tel = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, ignoreHTTPSErrors: true, locale: 'it-IT', timezoneId: 'Europe/Rome' });
    const p = await tel.newPage();
    await apri(p, '/comune/demo', 6000); await scatta(p, 'portale-home-telefono');
    await apri(p, '/comune/demo/elemento/' + ALBERO, 4000); await scatta(p, 'portale-elemento-telefono');
    await tel.close();
});

await browser.close();
console.log('fatto');
