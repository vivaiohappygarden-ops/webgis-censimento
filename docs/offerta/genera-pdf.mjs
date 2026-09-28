/**
 * Compone il PDF dell'offerta commerciale dal file HTML, con Chromium (Playwright).
 *
 *   node docs/offerta/genera-pdf.mjs
 *
 * Il documento scorre su piu' pagine; testata e pie' di pagina (con "pagina n di m")
 * li aggiunge Chromium. Variabile facoltativa CHROME_PATH come per il depliant.
 */
import { chromium } from 'playwright';
import { fileURLToPath } from 'node:url';

const cartella = fileURLToPath(new URL('./', import.meta.url));
const launch = { args: ['--no-sandbox'] };
if (process.env.CHROME_PATH) launch.executablePath = process.env.CHROME_PATH;

const browser = await chromium.launch(launch);
const page = await browser.newPage();
await page.goto('file://' + cartella + 'offerta-arborlab.html', { waitUntil: 'load' });
await page.evaluate(() => document.fonts.ready);
await page.emulateMedia({ media: 'print' });

const stile = 'font-family: Inter, Arial, sans-serif; font-size: 7.5pt; color: #566158; width: 100%; padding: 0 18mm;';
await page.pdf({
    path: cartella + 'offerta-arborlab.pdf',
    format: 'A4',
    printBackground: true,
    preferCSSPageSize: true,
    displayHeaderFooter: true,
    headerTemplate: `<div style="${stile} display:flex; justify-content:space-between; margin-top: 8mm;"><span style="font-weight:600; color:#16211c;">ArborLab, offerta commerciale</span><span>DAMA S.R.L.</span></div>`,
    footerTemplate: `<div style="${stile} display:flex; justify-content:space-between; margin-bottom: 7mm;"><span>DAMA S.R.L. · Via Crescenzio 58, 00193 Roma · P. IVA 17947161000 · info@damagroup.it</span><span>Pagina <span class="pageNumber"></span> di <span class="totalPages"></span></span></div>`,
});
console.log('scritto', cartella + 'offerta-arborlab.pdf');
await browser.close();
