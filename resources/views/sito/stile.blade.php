/* ==========================================================================
   SITO AZIENDALE - direzione grafica "Impatto" (decisione committente 19/09/2026)
   ==========================================================================

   A CHI PARLA. Al responsabile dell'ufficio tecnico o dell'ambiente di un
   Comune, di un'Unione, di una Provincia: ha un capitolato da scrivere e una
   responsabilita' sulla sicurezza degli alberi. Legge in fretta, spesso dal
   telefono, e cerca prove, non aggettivi. Ogni scelta qui sotto serve a quella
   persona; se non la aiuta, non entra.

   IDENTITA'. Un servizio tecnico, non un vivaio e non un gestionale: grandi
   titoli editoriali su una griglia rigorosa, forte contrasto, grandi campiture
   (verde bosco) alternate a molta carta, e i segni del rilievo come linguaggio
   grafico: linee cartografiche, coordinate, crocette di rilevamento, numeri
   identificativi, targhette botaniche, numerazione delle fasi (solo dove c'e'
   una sequenza vera). Impaginazione asimmetrica ma ordinata: il contenuto sta
   su 12 colonne e spesso parte dalla quarta.

   TAVOLOZZA. Variabili con i nomi condivisi con il futuro portale civico.
   Coppie verificate (WCAG, rapporto minimo 4,5:1 per il testo):
     ink su paper 16,4:1 - ink su ivory 14,9:1 - muted su paper 5,9:1 -
     muted su ivory 5,4:1 - leaf su paper 5,6:1 - leaf su ivory 5,1:1 -
     forest su paper 12,3:1 - white su forest 13,0:1 - ivory su forest 11,7:1 -
     accent su forest 8,9:1 - ink su accent 11,5:1 - accent su forest-dark 10,8:1.
   Il verde acido (accent) non fa mai testo su fondo chiaro: fa pulsanti (con
   il testo in ink), segni, filetti e occhielli sui fondi scuri.
   Il fuoco da tastiera e' un doppio anello: giallo (focus) e, fuori, ink sui
   fondi chiari o forest-dark sui fondi scuri, cosi' il segno contrasta sempre
   con quello che ha intorno.

   TIPOGRAFIA. Solo Inter, variabile, ospitato in casa (caratteri.blade.php).
   Pesi usati: 400, 500, 600, 700; corsivo per i nomi botanici. Corpo del
   testo mai sotto i 17px, riga di lettura entro ~66 caratteri (--misura),
   cifre tabellari ovunque. Scala fluida fra 375px e 1440px; ogni valore e'
   dichiarato prima in pixel secchi per i browser senza clamp():
     --t-etichetta   17          occhielli, etichette in maiuscoletto
     --t-corpo       17 -> 18,5  testo corrente
     --t-guida       19 -> 22    il paragrafo che accompagna un titolo
     --t-h3          21 -> 25    titoli dentro una sezione
     --t-h2          30 -> 46    titoli di sezione
     --t-h1          38 -> 72    titolo di pagina
     --t-affermazione 28 -> 48   le frasi messe in grande
     --t-cifra       44 -> 76    i numeri delle fasi

   MISURE DA TOCCARE. Tutto quello che si tocca e' alto almeno 44px. Niente
   esce dallo schermo a 320, 375, 768, 1024 e 1440px (SitoAziendaleTest e la
   verifica in Chromium). Nessuno script: il menu del telefono e' un
   details/summary, i passaggi di colore durano 150ms e solo per chi non ha
   chiesto di ridurre il movimento.
   ========================================================================== */

:root {
    color-scheme: light;

    --color-forest: #12382a;
    --color-forest-dark: #09241a;
    --color-leaf: #476f52;
    --color-accent: #c8e253;
    --color-ivory: #f4f1e8;
    --color-paper: #fbfaf6;
    --color-ink: #152019;
    --color-muted: #59645d;
    --color-line: #c9d0c9;
    --color-white: #ffffff;
    --color-focus: #f0c94d;

    /* Derivati, solo per i fondi scuri */
    --color-line-dark: rgba(244, 241, 232, 0.24);
    --color-ivory-dim: #cfd6cf;   /* testo secondario su forest: 8,7:1 */

    --t-etichetta: 17px;
    --t-corpo: 17px;
    --t-corpo: clamp(17px, 16.6px + 0.12vw, 18.5px);
    --t-guida: 19px;
    --t-guida: clamp(19px, 18px + 0.3vw, 22px);
    --t-h3: 21px;
    --t-h3: clamp(21px, 19.8px + 0.36vw, 25px);
    --t-h2: 30px;
    --t-h2: clamp(30px, 24.5px + 1.5vw, 46px);
    --t-h1: 38px;
    --t-h1: clamp(38px, 27px + 3.1vw, 72px);
    --t-affermazione: 28px;
    --t-affermazione: clamp(28px, 22px + 1.8vw, 48px);
    --t-cifra: 44px;
    --t-cifra: clamp(44px, 34px + 2.8vw, 76px);

    --s1: 8px; --s2: 12px; --s3: 20px; --s4: 32px; --s5: 48px; --s6: 72px; --s7: 112px;
    --sezione: 64px;
    --sezione: clamp(64px, 8vw, 120px);
    --colonna: 1240px;
    --misura: 50ch;   /* ~66 caratteri veri: "ch" misura lo zero, piu' largo della media */
    --raggio: 3px;

    font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif;
    font-variant-numeric: tabular-nums;
}

*, *::before, *::after { box-sizing: border-box; }

@media (prefers-reduced-motion: no-preference) {
    html { scroll-behavior: smooth; }
    a, summary, .invito { transition: background-color 150ms ease, border-color 150ms ease, color 150ms ease, text-decoration-color 150ms ease; }
}

body {
    margin: 0;
    background: var(--color-paper);
    color: var(--color-ink);
    font-size: var(--t-corpo);
    line-height: 1.6;
    -webkit-text-size-adjust: 100%;
    text-rendering: optimizeLegibility;
}

h1, h2, h3 { margin: 0; font-weight: 700; overflow-wrap: break-word; }
h1 { font-size: var(--t-h1); line-height: 1.02; letter-spacing: -0.035em; text-wrap: balance; }
h2 { font-size: var(--t-h2); line-height: 1.08; letter-spacing: -0.03em; text-wrap: balance; }
h3 { font-size: var(--t-h3); line-height: 1.25; letter-spacing: -0.012em; font-weight: 600; }
p, ul, ol, dl, dd, figure { margin: 0; }
ul, ol { padding: 0; list-style: none; }
img, svg { max-width: 100%; height: auto; }
strong { font-weight: 600; }
/* Le parole lunghe della materia (georeferenziazione, cartellinatura) a 320px
   vanno spezzate piuttosto che uscire dallo schermo */
@media (max-width: 399.98px) { h1, h2, h3, dt, dd, p, li { overflow-wrap: anywhere; } }

a { color: var(--color-forest); text-decoration-thickness: 1px; text-underline-offset: 3px; }
a:hover { text-decoration-thickness: 2px; }
.su-scuro { color: var(--color-ivory); }
.su-scuro a { color: var(--color-white); }
.su-scuro a:hover { color: var(--color-accent); }

/* Il segno del fuoco da tastiera non si toglie mai: doppio anello, cosi'
   contrasta sia sulla carta sia sul verde */
:where(a, button, summary, [tabindex]):focus-visible {
    outline: 3px solid var(--color-focus);
    outline-offset: 2px;
    box-shadow: 0 0 0 2px var(--color-ink);
    border-radius: 2px;
}
.su-scuro :where(a, button, summary):focus-visible { box-shadow: 0 0 0 2px var(--color-forest-dark); }

/* Salta al contenuto: primo elemento raggiungibile con il tabulatore */
.salta {
    position: absolute; left: -9999px; top: 8px; z-index: 100;
    background: var(--color-accent); color: var(--color-ink); padding: var(--s2) var(--s3);
    border: 2px solid var(--color-ink); font-weight: 600; text-decoration: none;
}
.salta:focus { left: 8px; }

.contenitore { width: 100%; max-width: var(--colonna); margin-inline: auto; padding-inline: 16px; }
@media (min-width: 600px) { .contenitore { padding-inline: var(--s4); } }

/* La griglia a 12 colonne: sotto i 900px tutto va in colonna, nell'ordine
   in cui si legge */
.griglia { display: grid; gap: var(--s4); }
@media (min-width: 900px) {
    .griglia { grid-template-columns: repeat(12, minmax(0, 1fr)); column-gap: var(--s3); }
    .c-1-4 { grid-column: 1 / 5; } .c-1-5 { grid-column: 1 / 6; } .c-1-6 { grid-column: 1 / 7; }
    .c-1-7 { grid-column: 1 / 8; } .c-1-8 { grid-column: 1 / 9; } .c-1-9 { grid-column: 1 / 10; }
    .c-4-13 { grid-column: 4 / 13; } .c-5-13 { grid-column: 5 / 13; } .c-6-13 { grid-column: 6 / 13; }
    .c-7-13 { grid-column: 7 / 13; } .c-8-13 { grid-column: 8 / 13; } .c-9-13 { grid-column: 9 / 13; }
    .c-1-13 { grid-column: 1 / 13; }
    .adesivo { position: sticky; top: var(--s3); }
}

/* ---------- Blocchi comuni --------------------------------------------- */

.sezione { padding-block: var(--sezione); }
.sezione-avorio { background: var(--color-ivory); }
.sezione-bianca { background: var(--color-white); }
.sezione-scura { background: var(--color-forest); }
.filo-sopra { border-top: 1px solid var(--color-line); }

/* L'occhiello porta il segno di rilevamento: un quadratino verde acido
   bordato di ink, lo stesso del marchio e dei numeri delle consegne */
.occhiello {
    display: inline-flex; align-items: center; gap: 10px;
    font-size: var(--t-etichetta); font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase;
    color: var(--color-leaf); margin-bottom: var(--s2);
}
.occhiello::before { content: ''; flex: none; width: 10px; height: 10px; background: var(--color-accent); border: 1.5px solid var(--color-ink); }
.su-scuro .occhiello { color: var(--color-accent); }
.su-scuro .occhiello::before { border-color: var(--color-ivory); }

.guida { font-size: var(--t-guida); line-height: 1.45; color: var(--color-muted); max-width: var(--misura); }
.griglia > .c-1-9 + .c-1-9 { margin-top: calc(-1 * var(--s2)); }
.su-scuro .guida { color: var(--color-ivory-dim); }
.prosa { max-width: var(--misura); }
.prosa > * + * { margin-top: var(--s3); }
.prosa h2 { margin-top: var(--s5); }
.prosa h3 { margin-top: var(--s4); }
.prosa ul li { position: relative; padding-left: var(--s3); }
.prosa ul li + li { margin-top: var(--s1); }
.prosa ul li::before { content: ''; position: absolute; left: 0; top: 0.68em; width: 8px; height: 8px; background: var(--color-accent); border: 1.5px solid var(--color-ink); }
.testa { max-width: 780px; }
.testa .guida { margin-top: var(--s3); }
.tenue { color: var(--color-muted); }

/* L'unica azione del sito: verde acido con il testo in ink (11,5:1). I
   selettori sono scritti con "a." apposta: devono vincere su ".su-scuro a" e
   su ".menu-pannello a", che colorano di bianco i collegamenti sui fondi
   scuri (bianco su verde acido sarebbe 1,45:1: successo alla prima prova). */
a.invito, .su-scuro a.invito, .menu-pannello a.invito {
    display: inline-flex; align-items: center; justify-content: center; gap: 12px;
    min-height: 52px; padding: 0 26px;
    background: var(--color-accent); color: var(--color-ink); font-weight: 700; font-size: var(--t-corpo);
    text-decoration: none; border: 2px solid var(--color-ink); border-radius: var(--raggio);
}
a.invito::after { content: '\2192'; font-weight: 500; }
a.invito:hover { background: var(--color-ink); color: var(--color-accent); }
.su-scuro a.invito, .menu-pannello a.invito { border-color: var(--color-accent); }
.su-scuro a.invito:hover, .menu-pannello a.invito:hover { background: var(--color-ivory); color: var(--color-ink); border-color: var(--color-ivory); }
a.invito-secondario { background: transparent; color: var(--color-ink); }
a.invito-secondario:hover { background: var(--color-ink); color: var(--color-white); }
.su-scuro a.invito-secondario { color: var(--color-ivory); border-color: var(--color-ivory); }
.su-scuro a.invito-secondario:hover { background: var(--color-ivory); color: var(--color-ink); }
.inviti { display: flex; flex-wrap: wrap; gap: var(--s2); margin-top: var(--s4); }

/* Il rimando in fondo a una voce: un bersaglio da toccare, non una parola */
.piu { display: inline-flex; align-items: center; min-height: 44px; font-weight: 600; text-decoration: none; color: var(--color-forest); }
.piu::after { content: '\2192'; margin-left: 8px; }
.piu:hover { text-decoration: underline; text-underline-offset: 5px; text-decoration-thickness: 2px; }

/* ---------- Testata ------------------------------------------------------ */

.testata { position: relative; z-index: 20; background: var(--color-paper); border-bottom: 1px solid var(--color-line); }
.testata-riga { display: flex; align-items: center; justify-content: space-between; gap: var(--s3); min-height: 76px; padding-block: 8px; }

.marchio { display: inline-flex; align-items: center; gap: 12px; text-decoration: none; color: var(--color-ink); min-height: 44px; }
.marchio-segno { flex: none; width: 16px; height: 16px; background: var(--color-accent); border: 2px solid var(--color-ink); }
.marchio-nome { display: block; font-weight: 700; font-size: 19px; letter-spacing: -0.015em; line-height: 1.1; }
.marchio-riga { display: block; font-size: var(--t-etichetta); color: var(--color-muted); margin-top: 2px; font-weight: 400; }
@media (max-width: 599.98px) { .marchio-riga { display: none; } .marchio-nome { font-size: 18px; } }

/* Dai 1000px la testata ha due righe: marchio e pulsante sopra, le sette
   voci del menu sotto, in linea e senza andare a capo. Sotto i 1000px resta
   il blocco details/summary qui sotto. */
.menu-desktop { display: none; border-top: 1px solid var(--color-line); }
a.testata-azione { display: none; }
@media (min-width: 1000px) {
    .menu-desktop { display: block; }
    .menu-desktop ul { display: flex; flex-wrap: wrap; gap: 0 4px; margin-left: -14px; }
    .menu-desktop a {
        display: inline-flex; align-items: center; min-height: 52px; padding: 0 14px;
        font-size: 17px; font-weight: 500; color: var(--color-ink); text-decoration: none; white-space: nowrap;
        border-top: 3px solid transparent; border-bottom: 3px solid transparent;
    }
    .menu-desktop a:hover { border-bottom-color: var(--color-line); }
    .menu-desktop a[aria-current="page"] { border-bottom-color: var(--color-accent); font-weight: 600; }
    a.testata-azione { display: inline-flex; min-height: 44px; padding: 0 16px; }
    .menu-mobile { display: none; }
}

/* Menu a scomparsa senza script: <details>/<summary>. Sotto i 1200px e'
   l'unico menu presente nell'albero di accessibilita' (l'altro e' display:none) */
.menu-mobile { margin-left: auto; }
.menu-mobile summary {
    list-style: none; display: inline-flex; align-items: center; gap: 8px;
    min-height: 44px; padding: 0 16px; border: 2px solid var(--color-ink); border-radius: var(--raggio);
    font-weight: 600; font-size: 17px; cursor: pointer; user-select: none; color: var(--color-ink); background: var(--color-paper);
}
.menu-mobile summary::-webkit-details-marker { display: none; }
.menu-mobile summary::after { content: '\2193'; font-weight: 500; }
.menu-mobile[open] summary::after { content: '\2191'; }
.menu-mobile[open] summary { background: var(--color-ink); color: var(--color-paper); }
.menu-pannello {
    position: absolute; left: 0; right: 0; top: 100%; z-index: 30;
    background: var(--color-forest-dark); border-top: 1px solid var(--color-line-dark);
    box-shadow: 0 24px 40px rgba(9, 36, 26, 0.35);
}
.menu-pannello ul { max-width: var(--colonna); margin-inline: auto; padding: var(--s2) 16px var(--s3); }
@media (min-width: 600px) { .menu-pannello ul { padding-inline: var(--s4); } }
.menu-pannello li + li { border-top: 1px solid var(--color-line-dark); }
.menu-pannello a { display: flex; align-items: center; min-height: 52px; color: var(--color-white); text-decoration: none; font-size: 18px; font-weight: 500; }
.menu-pannello a:hover { color: var(--color-accent); }
.menu-pannello a[aria-current="page"] { color: var(--color-accent); box-shadow: inset 3px 0 0 var(--color-accent); padding-left: 14px; }
.menu-pannello li.azione { border-top: 0; padding-top: var(--s3); }
.menu-pannello a.invito { width: 100%; }

/* ---------- Percorso (briciole) ---------------------------------------- */

.percorso { background: var(--color-paper); border-bottom: 1px solid var(--color-line); }
.percorso ol { display: flex; flex-wrap: wrap; align-items: center; gap: 0 10px; min-height: 48px; font-size: 17px; }
.percorso li { display: inline-flex; align-items: center; gap: 10px; }
.percorso li + li::before { content: '/'; color: var(--color-muted); }
.percorso a { display: inline-flex; align-items: center; min-height: 44px; color: var(--color-forest); }
.percorso [aria-current="page"] { color: var(--color-muted); }

/* ---------- Apertura della home ---------------------------------------- */

.apertura { position: relative; isolation: isolate; overflow: hidden; background: var(--color-forest); }
.apertura-sfondo { position: absolute; inset: 0; z-index: -1; width: 100%; height: 100%; pointer-events: none; }
.apertura-griglia { display: grid; gap: var(--s5); padding-block: var(--s5); }
@media (min-width: 1000px) {
    .apertura-griglia { grid-template-columns: minmax(0, 6fr) minmax(0, 6fr); column-gap: var(--s6); align-items: center; padding-block: var(--s6) calc(var(--s6) + 40px); }
}
.apertura h1 { color: var(--color-white); max-width: 13ch; }
.apertura .guida { margin-top: var(--s4); max-width: 44ch; }
.apertura .inviti { margin-top: var(--s5); }

/* La tavola: la mappa stilizzata con la targhetta dell'albero sopra. Sul
   telefono stanno una sotto l'altra (la targhetta monta di 24px sulla mappa);
   dai 1000px stanno nella stessa cella di una griglia, cosi' la tavola e'
   alta quanto la piu' alta delle due e niente viene tagliato dalla sezione,
   e la targhetta sporge a sinistra nello spazio fra le colonne. */
.tavola { position: relative; }
.mappa { display: block; width: 100%; height: auto; border: 1px solid var(--color-line-dark); background: var(--color-forest-dark); }
.mappa-chiara { border-color: var(--color-line); background: var(--color-white); }
.tavola .targhetta { margin: -24px 16px 0; }
@media (min-width: 1000px) {
    .tavola { display: grid; grid-template-columns: minmax(0, 1fr); }
    .tavola .mappa { grid-area: 1 / 1; align-self: start; }
    .tavola .targhetta { grid-area: 1 / 1; align-self: end; justify-self: start; width: min(340px, 82%); margin: 64px 0 -40px -56px; }
}

/* La targhetta: la scheda di un albero come si legge sul cartellino */
.targhetta {
    position: relative; background: var(--color-paper); color: var(--color-ink);
    border: 1.5px solid var(--color-ink); border-radius: var(--raggio);
    padding: 30px 20px 20px; box-shadow: 0 24px 48px rgba(9, 36, 26, 0.32);
}
.targhetta::before { content: ''; position: absolute; top: -1.5px; left: -1.5px; width: 22px; height: 22px; background: var(--color-accent); border: 1.5px solid var(--color-ink); }
.targhetta-etichetta { font-size: var(--t-etichetta); text-transform: uppercase; letter-spacing: 0.08em; color: var(--color-muted); font-weight: 600; }
.targhetta-numero { font-size: 44px; font-weight: 700; line-height: 1; letter-spacing: -0.03em; margin-top: 4px; }
.targhetta-specie { margin-top: var(--s2); font-size: 17px; }
.targhetta-specie em { font-weight: 500; }
.targhetta-qr { position: absolute; top: 20px; right: 20px; width: 56px; height: 56px; }
.targhetta-dati { margin-top: var(--s3); border-top: 1px solid var(--color-line); padding-top: var(--s2); display: grid; gap: 6px; font-size: 17px; }
.targhetta-dati div { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.targhetta-dati dt { color: var(--color-muted); }
.targhetta-dati dd { font-weight: 600; }
.cronologia { margin-top: var(--s3); border-top: 1px solid var(--color-line); padding-top: var(--s2); display: grid; gap: 8px; font-size: 17px; }
.cronologia li { position: relative; display: grid; grid-template-columns: 14px auto minmax(0, 1fr); gap: 10px; align-items: baseline; }
.cronologia li::before { content: ''; width: 11px; height: 11px; border: 1.5px solid var(--color-ink); background: var(--color-accent); transform: translateY(1px); }
.cronologia li.previsto::before { background: var(--color-paper); }
.cronologia li:not(:last-child)::after { content: ''; position: absolute; left: 5px; top: 18px; bottom: -10px; width: 1px; background: var(--color-line); }
.cronologia-data { color: var(--color-muted); white-space: nowrap; }
.targhetta-nota { margin-top: var(--s2); font-size: 17px; color: var(--color-muted); text-align: right; }

/* Il cartiglio in fondo all'apertura: quattro fatti tecnici */
.cartiglio { border-top: 1px solid var(--color-line-dark); }
.cartiglio dl { display: grid; grid-template-columns: 1fr; }
.cartiglio div { padding: var(--s3) 0; border-top: 1px solid var(--color-line-dark); }
.cartiglio div:first-child { border-top: 0; }
.cartiglio dt { font-size: var(--t-etichetta); text-transform: uppercase; letter-spacing: 0.08em; color: var(--color-accent); font-weight: 600; }
.cartiglio dd { margin-top: 4px; color: var(--color-ivory); font-size: 17px; line-height: 1.45; }
@media (min-width: 600px) {
    .cartiglio dl { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .cartiglio div:nth-child(-n+2) { border-top: 0; }
    .cartiglio div:nth-child(even) { border-left: 1px solid var(--color-line-dark); padding-left: var(--s3); }
    .cartiglio div:nth-child(odd) { padding-right: var(--s3); }
}
@media (min-width: 1000px) {
    .cartiglio dl { grid-template-columns: repeat(4, minmax(0, 1fr)); }
    .cartiglio div { border-top: 0; border-left: 1px solid var(--color-line-dark); padding-inline: var(--s3); }
    .cartiglio div:first-child { border-left: 0; padding-left: 0; }
}

/* ---------- Il messaggio centrale --------------------------------------- */

.affermazione { font-size: var(--t-affermazione); font-weight: 600; line-height: 1.12; letter-spacing: -0.028em; max-width: 26ch; text-wrap: balance; }
.affermazione::before { content: ''; display: block; width: 72px; height: 8px; background: var(--color-accent); border: 1.5px solid var(--color-ink); margin-bottom: var(--s4); }
.su-scuro .affermazione::before { border-color: var(--color-ivory); }

/* ---------- I tre principi: colonne sfalsate ----------------------------- */

.principi { display: grid; gap: var(--s5) var(--s4); }
@media (min-width: 900px) {
    .principi { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    .principio:nth-child(2) { margin-top: var(--s6); }
    .principio:nth-child(3) { margin-top: calc(var(--s6) * 2); }
}
.principio { border-top: 2px solid var(--color-ink); padding-top: var(--s3); }
.principio h3 { font-size: 24px; font-size: clamp(24px, 20px + 1vw, 32px); font-weight: 700; letter-spacing: -0.025em; line-height: 1.1; }
.principio p { margin-top: var(--s3); color: var(--color-muted); }

/* ---------- Il registro delle consegne: righe, non card ----------------- */

.registro { border-top: 2px solid var(--color-ink); margin-top: var(--s5); }
.voce { display: grid; grid-template-columns: 52px minmax(0, 1fr); column-gap: var(--s3); row-gap: var(--s2); padding-block: var(--s4); border-bottom: 1px solid var(--color-line); align-items: start; }
.voce-indice {
    display: flex; align-items: center; justify-content: center; width: 48px; height: 48px;
    background: var(--color-accent); border: 1.5px solid var(--color-ink); color: var(--color-ink);
    font-weight: 700; font-size: 20px;
}
.voce h3 { font-size: 22px; font-size: clamp(22px, 19px + 0.8vw, 28px); font-weight: 700; letter-spacing: -0.02em; padding-top: 8px; }
.voce p { color: var(--color-muted); max-width: var(--misura); }
.voce .piu { padding-top: 4px; }
@media (max-width: 899.98px) { .voce p, .voce .piu { grid-column: 2; } }
@media (min-width: 900px) { .voce { grid-template-columns: 72px minmax(0, 4fr) minmax(0, 6fr) auto; column-gap: var(--s4); } }

/* ---------- Le fasi numerate: il numero e' un dato ---------------------- */

.fasi { border-top: 2px solid var(--color-ink); margin-top: var(--s5); }
.fase { display: grid; grid-template-columns: 72px minmax(0, 1fr); column-gap: var(--s3); padding-block: var(--s4); border-bottom: 1px solid var(--color-line); }
.fase-numero { font-size: var(--t-cifra); font-weight: 700; line-height: 0.9; letter-spacing: -0.045em; color: var(--color-forest); }
.fase h3 { font-size: 22px; font-size: clamp(22px, 19px + 0.8vw, 28px); font-weight: 700; letter-spacing: -0.02em; padding-top: 6px; }
.fase p { color: var(--color-muted); max-width: var(--misura); }
@media (max-width: 899.98px) { .fase p { grid-column: 2; margin-top: var(--s2); } }
@media (min-width: 900px) { .fase { grid-template-columns: 140px minmax(0, 4fr) minmax(0, 7fr); column-gap: var(--s4); } }

/* ---------- La scheda dei campi: crocette di rilievo -------------------- */

.campi { display: grid; gap: 0 var(--s4); border-top: 1px solid var(--color-line); }
@media (min-width: 600px) { .campi { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (min-width: 1000px) { .campi { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
.campi li { display: flex; align-items: baseline; gap: 12px; min-height: 44px; padding-block: 10px; border-bottom: 1px solid var(--color-line); }
.campi li::before {
    content: ''; flex: none; width: 11px; height: 11px; transform: translateY(1px);
    background: linear-gradient(var(--color-ink), var(--color-ink)) center / 11px 1.5px no-repeat,
                linear-gradient(var(--color-ink), var(--color-ink)) center / 1.5px 11px no-repeat;
}

/* ---------- La tavola delle quote ---------------------------------------- */

.quote { display: grid; gap: var(--s4); align-items: start; }
@media (min-width: 900px) { .quote { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--s6); } }
.quote-disegno { background: var(--color-white); border: 1px solid var(--color-line); padding: var(--s3); }
.quote-disegno svg { display: block; width: 100%; height: auto; }
.legenda { display: grid; }
.legenda li { display: grid; grid-template-columns: 36px 1fr; gap: 12px; align-items: baseline; padding-block: 10px; border-bottom: 1px solid var(--color-line); }
.legenda .n { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 50%; background: var(--color-ink); color: var(--color-accent); font-weight: 700; font-size: 16px; transform: translateY(6px); }

/* ---------- Le tappe collegate (sequenza) -------------------------------- */

.tappe { position: relative; display: grid; gap: var(--s4); margin-top: var(--s5); counter-reset: tappa; }
.tappa { position: relative; counter-increment: tappa; }
.tappa-segno {
    position: relative; z-index: 1; display: flex; align-items: center; justify-content: center;
    width: 44px; height: 44px; background: var(--color-accent); border: 1.5px solid var(--color-ink);
    font-weight: 700; font-size: 18px;
}
.tappa-segno::before { content: counter(tappa); }
.tappa h3 { margin-top: var(--s3); }
.tappa p { margin-top: var(--s1); color: var(--color-muted); max-width: 30ch; }
@media (max-width: 899.98px) {
    .tappa { display: grid; grid-template-columns: 44px 1fr; column-gap: var(--s3); }
    .tappa-segno { grid-row: 1 / span 3; }
    .tappa h3 { margin-top: 8px; grid-column: 2; }
    .tappa p { grid-column: 2; max-width: none; }
    .tappa:not(:last-child)::before { content: ''; position: absolute; left: 21px; top: 44px; bottom: calc(-1 * var(--s4)); width: 2px; background: var(--color-line); }
}
@media (min-width: 900px) {
    .tappe { grid-template-columns: repeat(var(--tappe, 5), minmax(0, 1fr)); gap: var(--s3); }
    .tappe::before { content: ''; position: absolute; left: 22px; right: 22px; top: 21px; height: 2px; background: var(--color-line); }
}

/* ---------- Definizioni (parole che non sono sinonimi) ------------------ */

.definizioni { border-top: 2px solid var(--color-ink); }
.definizioni > div { display: grid; gap: 4px var(--s4); padding-block: var(--s3); border-bottom: 1px solid var(--color-line); }
@media (min-width: 900px) { .definizioni > div { grid-template-columns: minmax(0, 4fr) minmax(0, 8fr); } }
.definizioni dt { font-weight: 700; font-size: var(--t-h3); letter-spacing: -0.012em; }
.definizioni dd { color: var(--color-muted); max-width: var(--misura); }

/* ---------- La banda scura con la frase in grande ----------------------- */

.banda { position: relative; isolation: isolate; overflow: hidden; background: var(--color-forest); }
.banda-frase { font-size: 40px; font-size: clamp(40px, 28px + 3vw, 84px); font-weight: 700; letter-spacing: -0.04em; line-height: 0.98; color: var(--color-white); max-width: 14ch; text-wrap: balance; }
.banda-frase .punto { color: var(--color-accent); }
.banda .guida { margin-top: var(--s4); }

/* ---------- Dati in due colonne (Chi siamo) ------------------------------ */

.dati { border-top: 2px solid var(--color-ink); }
.dati > div { display: grid; gap: 2px var(--s4); padding-block: var(--s2); border-bottom: 1px solid var(--color-line); }
@media (min-width: 600px) { .dati > div { grid-template-columns: minmax(0, 5fr) minmax(0, 7fr); } }
.dati dt { font-size: var(--t-etichetta); text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-muted); font-weight: 600; align-self: center; }
.dati dd { font-size: 17px; font-weight: 500; overflow-wrap: anywhere; }

/* ---------- Recapiti ------------------------------------------------------ */

.recapiti { display: grid; gap: var(--s3); }
.recapito { border-top: 2px solid var(--color-ink); padding-top: var(--s2); }
.recapito dt { font-size: var(--t-etichetta); text-transform: uppercase; letter-spacing: 0.06em; color: var(--color-muted); font-weight: 600; }
.recapito dd { font-size: 20px; font-size: clamp(20px, 18px + 0.6vw, 26px); font-weight: 600; overflow-wrap: anywhere; margin-top: 2px; line-height: 1.3; }
.recapito dd a { display: inline-flex; align-items: center; min-height: 44px; }

/* ---------- Avvisi e note ------------------------------------------------- */

.avviso { border: 1.5px solid var(--color-ink); border-left: 10px solid var(--color-accent); background: var(--color-white); padding: var(--s3) var(--s4); max-width: calc(var(--misura) + 8ch); }
.avviso p { font-weight: 500; }
.nota { border-left: 3px solid var(--color-line); padding-left: var(--s3); color: var(--color-muted); max-width: var(--misura); }
.elenco-semplice li { position: relative; padding-left: var(--s3); }
.elenco-semplice li + li { margin-top: var(--s1); }
.elenco-semplice li::before { content: ''; position: absolute; left: 0; top: 0.68em; width: 8px; height: 8px; background: var(--color-accent); border: 1.5px solid var(--color-ink); }

/* ---------- Chiusura: la stessa e unica azione ---------------------------- */

.chiusura { background: var(--color-ivory); border-top: 1px solid var(--color-line); }
.chiusura .contenitore { display: grid; gap: var(--s4); align-items: center; }
@media (min-width: 900px) { .chiusura .contenitore { grid-template-columns: minmax(0, 7fr) auto; gap: var(--s6); } }
.chiusura h2 { max-width: 20ch; }
.chiusura .guida { margin-top: var(--s3); }

/* ---------- Piede --------------------------------------------------------- */

.piede { background: var(--color-forest-dark); color: var(--color-ivory); padding-block: var(--s6) var(--s5); }
.piede-griglia { display: grid; gap: var(--s5) var(--s4); }
@media (min-width: 760px) { .piede-griglia { grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr) minmax(0, 1fr); } }
.piede h2 { font-size: var(--t-etichetta); text-transform: uppercase; letter-spacing: 0.08em; color: var(--color-accent); margin-bottom: var(--s2); font-weight: 600; }
.piede-marchio { font-size: 22px; font-weight: 700; letter-spacing: -0.02em; color: var(--color-white); }
.piede-riga { color: var(--color-ivory-dim); margin-top: 4px; }
.piede-dati { margin-top: var(--s3); display: grid; gap: 2px; font-size: 17px; color: var(--color-ivory); }
.piede-voci a { display: inline-flex; min-height: 44px; align-items: center; color: var(--color-white); text-decoration: none; font-size: 17px; }
.piede-voci a:hover { text-decoration: underline; text-underline-offset: 4px; color: var(--color-accent); }
.piede-voci .tenue { color: var(--color-ivory-dim); }
.piede-legale { margin-top: var(--s5); padding-top: var(--s3); border-top: 1px solid var(--color-line-dark); display: flex; flex-wrap: wrap; gap: var(--s2) var(--s4); align-items: center; justify-content: space-between; font-size: 17px; color: var(--color-ivory-dim); }
.piede-legale p { max-width: 60ch; }
.piede-legale strong { color: var(--color-ivory); font-weight: 600; }
.piede-legale a { display: inline-flex; min-height: 44px; align-items: center; color: var(--color-white); }

@media print {
    .testata, .percorso, .chiusura, .invito, .salta { display: none !important; }
    body { background: #fff; color: #000; }
    .apertura, .banda, .piede, .sezione-scura { background: #fff !important; color: #000 !important; }
    .su-scuro, .su-scuro a, .apertura h1, .banda-frase { color: #000 !important; }
}

/* Varianti a colonna singola: l'elenco delle funzioni del portale accanto
   alla mappa e i tre passaggi della pagina Contatti */
.campi-1 { grid-template-columns: 1fr !important; }
.registro-compatto .voce { grid-template-columns: 52px minmax(0, 1fr) !important; column-gap: var(--s3) !important; }
.registro-compatto .voce p { grid-column: 2; }
