# Depliant commerciale di ArborLab

Il depliant del programma, da stampare o da allegare a una email: otto pagine A4
con le funzioni utili a chi lo compra (Comuni, imprese del verde, studi
agronomici, gestori di patrimoni verdi). Dal 28/09/2026 il programma si presenta
con il nome commerciale **ArborLab**, venduto da **DAMA S.R.L.**. Non parla dei
collegamenti con il gestionale del vivaio né della console della piattaforma:
servono a noi, non a chi acquista.

| File | Che cos'è |
| --- | --- |
| `depliant-commerciale.pdf` | Il depliant pronto |
| `depliant-commerciale.html` | La sorgente: testi e impaginazione, da modificare con un editor di testo |
| `img/` | Le schermate del Comune dimostrativo, con la nuova interfaccia |
| `genera-pdf.mjs` | Compone il PDF dalla sorgente |
| `schermate.mjs` | Rifà le schermate dal Comune dimostrativo |

L'offerta commerciale, da compilare caso per caso, sta a parte in `docs/offerta/`
(documento Word).

## Recapiti

La chiusura dell'ultima pagina (commento `RECAPITI` nella sorgente) e la
copertina portano i dati di DAMA S.R.L.: sede, partita IVA e codice fiscale, REA,
PEC da `config/sito.php`, telefono ed email dati dal committente il 24/09/2026. Il
capitale sociale non si stampa (sua decisione). Se cambiano, si correggono nella
sorgente e si rigenera il PDF.

## Rigenerare il PDF

Servono Node e il pacchetto Playwright del progetto (`npm ci`), con il suo
Chromium (`npx playwright install chromium`, una volta sola).

```bash
node docs/depliant/genera-pdf.mjs
```

Con `VERIFICA=1` scrive anche un'immagine per pagina in `anteprima/` (cartella
ignorata da git). Ogni pagina ha l'altezza fissa del foglio: se un testo cresce
troppo il comando si ferma e dice di quanto sborda, invece di stampare una
pagina tagliata.

## Rifare le schermate

```bash
php artisan migrate --seed
php artisan demo:patrimonio --si
npm run build
ALBERO_ID=<id di ALB-0002> ORDINE_ID=<id di ODS-DEMO-0001> node docs/depliant/schermate.mjs
```

Il portale del Comune Demo va acceso (committente `DEMO`, indirizzo `demo`).
Nel menu del programma c'è ancora scritto "WebGIS Censimento": lo script lo
sostituisce con "ArborLab" solo nella schermata. `SEZ` sceglie i gruppi (`g`
gestionale, `c` app di campo, `p` area riservata, `t` portale dal telefono).
Gli sfondi delle mappe arrivano da server esterni: senza rete verso quei server
le mappe escono con il fondo grigio.

L'area riservata del Comune (`/portale`) non si fotografa con `php artisan
serve`: il server integrato di PHP risponde 404 da solo, perché in `public/`
esiste la cartella `portale/` dei caratteri. Con Caddy, come sul server, non
succede; in locale serve un instradatore che passi al file server solo i file.
