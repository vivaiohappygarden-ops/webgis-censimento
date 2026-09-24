# Depliant commerciale

Il depliant del programma, da stampare o da allegare a una email: otto pagine A4
con le funzioni utili a chi lo compra (Comuni, imprese del verde, studi
agronomici, gestori di patrimoni verdi). Non parla dei collegamenti con il
gestionale del vivaio: quelli servono a noi, non a chi acquista.

| File | Che cos'è |
| --- | --- |
| `depliant-commerciale.pdf` | Il depliant pronto |
| `depliant-commerciale.html` | La sorgente: testi e impaginazione, da modificare con un editor di testo |
| `img/` | Le sette schermate del Comune dimostrativo usate nelle pagine (lo script ne produce anche altre, che si possono scartare) |
| `genera-pdf.mjs` | Compone il PDF dalla sorgente |
| `schermate.mjs` | Rifà le schermate dal Comune dimostrativo |

## Prima di consegnarlo

In fondo all'ultima pagina c'è la chiusura con i recapiti, segnata nella
sorgente con il commento `RECAPITI`: i dati societari e la PEC sono quelli
verificati di `config/sito.php` (DAMA S.R.L.); telefono ed email non ci sono e
vanno scritti lì prima di rigenerare il PDF. Le voci lasciate vuote restano
come righe da completare a mano. Il nome del programma, "WebGIS Censimento", compare nel titolo, nelle
testate e nei piè di pagina: se cambia, si cerca e si sostituisce nella sorgente.

## Rigenerare il PDF

Servono Node e il pacchetto Playwright del progetto (`npm ci`), con il suo
Chromium (`npx playwright install chromium`, una volta sola).

```bash
node docs/depliant/genera-pdf.mjs
```

Con `VERIFICA=1` scrive anche un'immagine per pagina in `anteprima/` (cartella
ignorata da git), per controllare a occhio. Ogni pagina ha l'altezza fissa del
foglio: se un testo cresce troppo il comando si ferma e dice di quanto sborda,
invece di stampare una pagina tagliata.

## Rifare le schermate

Le schermate vengono dal Comune dimostrativo, con il programma in esecuzione:

```bash
php artisan migrate --seed
php artisan demo:patrimonio --si
npm run build && php artisan serve
node docs/depliant/schermate.mjs
```

Il portale del Comune Demo va acceso (pagina Territorio, o `public_enabled`
sul committente `DEMO` con indirizzo `demo`). Con `SEZIONI=gestionale` si
rifanno solo le pagine del gestionale (`campo`, `committente`, `portale` le
altre); `ALBERO` e `ALBERO_ID` scelgono l'elemento mostrato. Gli sfondi delle
mappe arrivano da server esterni: senza rete verso quei server le mappe escono
con il fondo grigio.

L'area riservata del committente non si fotografa con `php artisan serve`: il
server integrato di PHP risponde 404 da solo su `/portale`, perché in `public/`
esiste la cartella `portale/` dei caratteri. Con Caddy, come sul server, non
succede.
