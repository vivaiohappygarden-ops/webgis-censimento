/**
 * Compone il PDF del depliant dal file HTML, con Chromium (Playwright).
 *
 *   node docs/depliant/genera-pdf.mjs
 *
 * Scrive docs/depliant/depliant-commerciale.pdf e, con VERIFICA=1, anche una
 * immagine PNG per pagina in docs/depliant/anteprima/ per controllare a
 * occhio che niente sia tagliato. Se il contenuto di una pagina supera
 * l'altezza del foglio A4 il programma lo dice e termina con errore: il
 * foglio ha altezza fissa e quello che sborda non si stampa.
 *
 * Variabile facoltativa CHROME_PATH: eseguibile di Chromium, se non si vuole
 * quello scaricato da Playwright (npx playwright install chromium).
 */
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const cartella = fileURLToPath(new URL('./', import.meta.url));
const sorgente = 'file://' + cartella + 'depliant-commerciale.html';
const destinazione = cartella + 'depliant-commerciale.pdf';

const launch = { args: ['--no-sandbox'] };
if (process.env.CHROME_PATH) launch.executablePath = process.env.CHROME_PATH;

const browser = await chromium.launch(launch);
const page = await browser.newPage({ viewport: { width: 1000, height: 1400 } });
await page.goto(sorgente, { waitUntil: 'load' });
await page.evaluate(() => document.fonts.ready);
await page.emulateMedia({ media: 'print' });

// Ogni pagina ha altezza fissa: se il suo contenuto e' piu' alto, sborda e
// viene tagliato. Meglio saperlo subito che scoprirlo sulla stampa.
const sbordi = await page.evaluate(() => [...document.querySelectorAll('.pagina')].map((p, i) => ({
    pagina: i + 1,
    altezza: p.clientHeight,
    contenuto: p.scrollHeight,
})).filter((p) => p.contenuto > p.altezza + 1));

if (sbordi.length) {
    for (const s of sbordi) console.error(`Pagina ${s.pagina}: contenuto ${s.contenuto}px in un foglio da ${s.altezza}px, sborda di ${s.contenuto - s.altezza}px.`);
    await browser.close();
    process.exit(1);
}

await page.pdf({ path: destinazione, format: 'A4', printBackground: true, preferCSSPageSize: true, margin: { top: 0, right: 0, bottom: 0, left: 0 } });
console.log('scritto', destinazione);

if (process.env.VERIFICA) {
    const anteprima = cartella + 'anteprima/';
    mkdirSync(anteprima, { recursive: true });
    await page.emulateMedia({ media: 'screen' });
    const pagine = page.locator('.pagina');
    const n = await pagine.count();
    for (let i = 0; i < n; i++) {
        await pagine.nth(i).screenshot({ path: `${anteprima}pagina-${i + 1}.png` });
    }
    console.log(`anteprime: ${n} pagine in ${anteprima}`);
}

await browser.close();
