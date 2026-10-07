# Convenzioni di progetto

WebGIS multi-tenant per la gestione del verde (censimento, catasto alberi/VTA, lavori, CAM).
Riferimenti: `PROPOSTA-ARCHITETTURA.md` (approvata 10/08/2026), `docs/GIS-DATA-MODEL.md`,
`docs/OFFLINE-SYNC.md`, `docs/ZEBRA-INTEGRATION.md`.

## Stile interfaccia (decisione committente 10/08/2026, agg. 11/08/2026)

- Stile **molto analitico, senza emoji**: nessuna emoji o icona pittografica in etichette,
  pulsanti, titoli, messaggi o placeholder dell'interfaccia.
- Stile **pulito e minimale**; carattere dell'interfaccia: **caratteri di sistema**
  (`system-ui, -apple-system, 'Segoe UI', Roboto, …`, decisione committente 15/08/2026
  che sostituisce Courier New), definiti in `resources/css/app.css`
  (`--font-sans`/`--font-mono` + `.maplibregl-map`): niente webfont esterni.
  Cifre tabellari (`font-variant-numeric: tabular-nums`) per tenere incolonnati i numeri.
- Le **stampe PDF** restano su **DejaVu Sans Mono** (font incorporato in dompdf): non
  seguono il foglio di stile dell'interfaccia.
- Ammessi solo simboli tipografici funzionali: frecce di navigazione (← →), "✕" per chiudere.
- Preferire testo sobrio, dati in evidenza, tabelle dense; l'informazione prevale sulla decorazione.
- Lingua dell'interfaccia e dei messaggi: italiano.
- **Lista di controllo UI/UX** (dal 16/09/2026, richiesta del committente): in
  `.claude/skills/ui-ux-pro-max/` c'e' la skill "UI/UX Pro Max" (banca dati di stili,
  tavolozze, caratteri e 99 regole di usabilita', con il suo cercatore
  `python3 .claude/skills/ui-ux-pro-max/scripts/search.py "<parole>" --domain ux`).
  Prima di consegnare un'interfaccia si passa la sua lista di controllo (contrasto 4,5:1,
  bersagli 44px, fuoco visibile, niente emoji come icone, passaggi di 150-300ms,
  `prefers-reduced-motion`, nessuno scorrimento orizzontale a 375/768/1024/1440).
  **Le decisioni del committente vincono sulla skill**: dove propone caratteri di Google
  o una tavolozza sua, restano i caratteri ospitati in casa e il colore dell'ente. La
  skill e' con licenza CC BY-NC 4.0 (uso non commerciale): e' uno strumento di lavoro,
  non finisce nel prodotto.
- Le pagine di gestione si usano anche dal telefono: sotto il punto di rottura `md` il menu
  laterale è a scomparsa (pulsante "Menu" nella barra in alto) e **niente deve uscire dallo
  schermo a 390 px**. Le tabelle larghe vanno in un contenitore `overflow-x-auto` (mai
  `overflow-hidden`, che le taglia), le barre di filtri usano `flex-wrap` e i campi larghi
  `w-full sm:w-auto`. L'app di campo (`/operatore`) resta un'interfaccia mobile a sé.

## Stack e vincoli

- Laravel 13 (PHP 8.4), Inertia + Vue 3, Tailwind v4, MapLibre GL v6
  (`import * as maplibregl`, worker registrato via `?worker&url` in `resources/js/app.js`).
- PostgreSQL 16 + PostGIS: geometrie SRID 4326, misure calcolate in EPSG 7791 da colonne
  GENERATED; logica critica (versioning, audit, coerenza geometria/tipo) nei trigger DB.
- Multi-tenant per riga: `tenant_id` ovunque, `TenantScope` (con guardia `Auth::hasUser()`,
  non rimuoverla: evita la ricorsione con SessionGuard) + trait `BelongsToTenant`.
- RBAC spatie/laravel-permission v8 con teams (`tenant_id`); ruoli di serie: amministratore,
  tecnico, operatore, cliente (portale), impresa (portale delle imprese), esecutore (campo,
  solo i lavori affidati).
- Aggiornamenti asset con optimistic locking (campo `version`, 409 in conflitto) dentro
  transazione con `lockForUpdate`.
- Catalogo Modello Dati v2.1: 387 codici in `database/seeders/data/catalogo_md_v21.csv`,
  installati da `CatalogInstaller`; i tipi CAM sono immutabili.
- Export CAM: `CamExporter` (GeoJSON + shapefile via ogr2ogr, riproiezione RDN2008
  EPSG 7791-7794, date GGMMAAAA).

## Portale pubblico dei committenti (dal 18/08/2026)

- Un portale per committente, acceso singolarmente (`clients.public_enabled`), raggiungibile
  dal sottodominio (`PORTAL_BASE_HOST`) o dal percorso di collaudo `/comune/{slug}`.
- Le rotte stanno in `routes/portale.php`, fuori dal gruppo `web`: **niente sessione, niente
  cookie, niente Inertia**. Il gestionale puo' vivere sullo stesso dominio dei portali
  (`gestionale.dominio.it` con i Comuni su `<comune>.dominio.it`): il suo nome e' escluso da
  `{comune}` con `PortalLabels::vincoloSottodominio()` ed e' fra gli slug riservati, altrimenti
  le rotte pubbliche coprirebbero l'intero programma di gestione. Il pacchetto JavaScript e' `resources/js/portale-mappa.js` e il
  service worker dell'app di campo ha ambito ristretto a `/operatore`.
- Senza utente autenticato `TenantScope` non filtra: **ogni query pubblica passa da
  `PortalQuery`**, che porta con se' le regole di pubblicabilita' (niente nascosti, abbattuti,
  validita' scaduta, aree previste o dismesse).
- Lo stato pubblico a quattro voci (sano, in cura, da potare, in verifica) e' scritto una volta
  sola in SQL in `PortalState::sql()`, perche' serve identico a scheda, mappa e riquadri.
- Niente esce in pubblico da solo: ordini di lavoro, perizie e vincoli hanno il proprio
  `is_public`; la stima CO2 si accende per committente.
- La stima CO2 applica il modello scritto in `config/co2.php` con i suoi riferimenti: il
  programma non inventa formule e la pagina dichiara sempre il metodo.

### Veste del portale (dal 14/09/2026)

- Registro **istituzionale e sobrio**, lo stesso del sito aziendale (decisione committente
  14/09/2026, sostituisce la veste editoriale "Sotto la chioma" del 29/08). Un portale
  civico deve somigliare a un atto dell'ente, non a una rivista. Il sistema e' documentato
  in testa a `resources/views/portale/layout.blade.php`: scala tipografica, spazi, misure.
- Un solo carattere, **Inter** tondo e corsivo, ospitato in `public/portale/font` (il
  corsivo serve ai nomi botanici e non si lascia disegnare al browser). Nessuna richiesta a
  terzi: e' quello che permette di scrivere nell'informativa che non ci sono cookie.
- Un solo accento: il **colore scelto dal Comune**. I fondi sono grigio chiarissimo e
  bianco; l'oro della veste precedente resta solo come anello del "sei qui" sulla mappa,
  dove deve distinguersi dai quattro colori di stato qualunque tinta abbia scelto l'ente.
  I quattro colori di stato non si ritingono mai.
- **Il testo non scende sotto i 17px**, bersagli alti almeno 44px, niente esce dallo
  schermo a 390px: il difetto da battere era "sul telefono si legge male".
- **Niente illustrazioni.** Restano solo i disegni che spiegano un dato: la tavola delle
  quote sulla home e' un disegno tecnico. Dove manca la fotografia di un elemento **non si
  disegna una pianta al suo posto**: prima erano fino a 560px da scorrere prima di leggere
  la specie, e un disegno che non e' quella pianta non e' un dato.
- L'ordine della home e' quello di chi arriva: **prima il campo del cartellino** (nel primo
  schermo, non sotto la piega), poi la mappa, poi i numeri, e solo dopo il racconto.
- I contrasti li garantisce `PortalPalette` con le sue guardie, non l'occhio: la tinta la
  sceglie il Comune, la leggibilita' no (`PortalPaletteTest`).
- **Veste mista (dal 23/09/2026)**, approvata dal committente su bozza: il nostro impianto
  piu' le idee del portale di Mentana. Testata bianca con stemma e nome (il colore dell'ente
  lo portano pulsanti e apertura). **Fotografia di copertina** facoltativa
  (`public_profile.cover_path`, caricata da Territorio, ricodificata in JPEG entro 1920 px e
  servita da `/copertina`; il nome del file porta l'ora del caricamento, cosi' la cache non
  mostra la foto vecchia): con la foto l'apertura ha il velo nero e il campo del cartellino
  in una scatola bianca; **senza foto resta l'apertura chiara, niente immagine di
  riempimento**. Sotto l'apertura i **quattro numeri grandi** (elementi, alberi, varieta' e la
  CO2 se accesa, altrimenti il conteggio successivo): quello che sale li' non si ripete
  nella fascia "come sta il verde". **Pie' di pagina a tre colonne** con i recapiti
  dell'ufficio (`address`, `contact_phone`, `contact_pec`, `opening_hours`): ogni riga esce
  solo se compilata, il telefono entra anche fra i modi di segnalare. Nella **scheda** prima
  la fotografia (4:3, con stato e data dello scatto sul bordo), poi cartellino, stato, nome,
  specie, misure su due colonne e **i due pulsanti subito dopo le misure** (erano in fondo,
  sotto la cronologia). Il pannello della mappa e' chiaro e porta alla scheda completa.
  Il raggio unico e' salito da 4 a 8 px. Le regole stanno in `PortaleVesteMistaTest`.
  **Schermi larghi** (osservazione del committente 23/09/2026, "si spreca tanto spazio
  laterale"): la pagina si allarga con lo schermo (`--misura` 1320 px da 1500 px, 1480 px da
  1800 px; le righe di testo restano sotto `--riga`) e la fila dei numeri ha tante colonne
  quanti sono i riquadri (`--tessere` scritto dalla pagina). Nell'apertura nome, testo,
  campo del cartellino e strade **restano a sinistra, nella parte velata**: spostare il
  campo a destra copriva la meta' visibile della fotografia (decisione committente
  23/09/2026), e la veduta del Comune viene prima del riempire lo schermo. La nota "il rilievo e' in corso" esce solo senza
  elementi: con pochi elementi i conteggi salgono tutti nei riquadri e la lista sotto resta
  vuota, ma il rilievo non e' in corso.
- **Patrimonio dimostrativo** (`php artisan demo:patrimonio`, dal 16/09/2026): riempie il
  Comune Demo con alberi, valutazioni e lavori verosimili per mostrare portale e gestionale
  a un Comune prima di avere i suoi dati. `App\Services\Demo\PatrimonioDimostrativo`
  scrive **solo nell'organizzazione con slug `demo`** (su ogni altra lancia un'eccezione,
  qualunque opzione gli si passi) e **non si somma** a un patrimonio gia' popolato
  (`SOGLIA_GIA_POPOLATO`). Anteprima ed esecuzione passano dallo stesso metodo (`$prova`),
  come le azioni multiple; seme fisso, cosi' due lanci danno lo stesso patrimonio. Ogni
  elemento generato porta in `notes` che e' dimostrativo. Prima serve `db:seed`, che crea
  l'organizzazione, il committente e il catalogo.

## Portale del Comune, area riservata (rifatto il 26/09/2026)

- `/portale` e' l'area riservata dell'ufficio tecnico del committente (ruolo `cliente`,
  permesso `portal.view`, `users.client_id`). Il committente l'ha trovata "molto spoglia"
  (quattro riquadri e un modulo): nella veste nuova apre `Pages/Nuovo/Portale.vue` con
  **sei schede** (`?scheda=`): Panoramica (frase con i conteggi veri, mappa, prossimi
  lavori, ultimi fatti, novita' degli ultimi 30 giorni, richieste aperte, ultimi documenti,
  aree, recapiti dello studio), **Mappa** (aree ed elementi con lo stato a quattro voci,
  ricerca a parole, scheda nel pannello), Patrimonio (elenco con filtri e anteprima),
  Lavori (in programma, fatti, dettaglio con elementi e foto), Segnalazioni (richieste
  proprie con il lavoro che ne e' nato, altre segnalazioni del territorio, modulo
  `?nuova=1`) e Documenti (perizie emesse e verbali di ispezione chiusi, in PDF).
  `?precedente=1` apre la pagina di prima (`Pages/Portale.vue`), che resta.
- **Chi entra ha solo `portal.view`**: nessuna chiamata del gestionale gli risponde, quindi
  ogni dato passa da `PortaleComuneController` (riquadri `portal/tiles`, `portal/elementi`,
  `portal/foto`, `portal/lavori`, `portal/documenti` e i PDF) e dal riepilogo di
  `PortalController`. Il perimetro sta **una volta sola** in
  `App\Services\Portale\TerritorioCommittente` (aree del committente, regola degli ordini
  "coerenti", regola delle segnalazioni): la mappa, l'elenco, i lavori e il riepilogo
  devono mostrare lo stesso territorio. Mai un'area, un elemento, un lavoro o un
  documento altrui; niente prezzi; niente `notes` interne della scheda; il nome dell'area
  di un ordine esce solo se e' un'area del committente.
- I **documenti** sono le perizie **emesse** (`report_issued_at`) dei suoi alberi e i verbali
  delle ispezioni chiuse: una valutazione senza perizia emessa non e' un documento, e' lavoro
  in corso del tecnico. I PDF sono gli stessi che stampa il tecnico (`PeriziaController::pdf`,
  `PdfController::inspection` richiamati dopo il controllo di appartenenza).
- Le fotografie escono ridotte da `PublicPhotoCache` sotto `portal/foto/{id}`, solo se
  dell'elemento, di un lavoro coerente o di una richiesta del proprio portale.
- La mappa (`Components/Portale/MappaTerritorio.vue`) usa gli sfondi del portale pubblico
  piu' quelli propri del Comune (`SfondiCommittente`), riquadri MVT con due strati (`aree`,
  `elementi`) e i colori di `PortalState`; la scheda e' `Components/Portale/SchedaElemento.vue`
  (sola lettura; il modulo della richiesta e' `ModuloRichiesta.vue`). Prove:
  `PortaleComuneTest`; collaudo nel browser in `scratchpad/verifica-portale/` (in locale il
  server di sviluppo va lanciato con un instradatore che distingue file e cartelle,
  perche' `public/portale/` oscura la rotta `/portale` sotto `artisan serve`).

## Nuova interfaccia del gestionale (dal 26/09/2026)

- Il committente non era soddisfatto di come erano impostate le funzioni ("non mi piace come
  sono impostate le funzioni nel gestionale"). Su tre bozze ha scelto la **"A", per compiti**:
  sei voci nel menu (**Oggi, Patrimonio, Lavori, Documenti, Committenti, Impostazioni**), la
  mappa dentro Patrimonio, la scheda dell'albero che si legge prima di modificarla, i lavori
  con la loro pagina. **Niente riquadri di numeri** ("sembrano molto AI", 26/09/2026): i
  conteggi stanno in una frase e nei filtri della lista. Le bozze approvate stanno
  nell'artefatto Design https://claude.ai/artifact/T6rVDJkUPCKeMVwCspXAAP (pagine
  `tre-bozze` e `a-definitiva`, schermate AD-01...AD-11).
- **La veste precedente non si butta** (richiesta esplicita: "se poi non mi piace ripristiniamo
  quella vecchia"). La scelta e' **per utente**, `users.settings['interfaccia']` (`nuova` o
  `precedente`), con la predefinita in `config/interfaccia.php` (`INTERFACCIA_PREDEFINITA`,
  di serie `nuova`); la risolve `App\Support\Interfaccia`, arriva in pagina come prop
  condivisa `interfaccia.modo`, si cambia con `POST /interfaccia` dal pulsante in fondo al
  menu ("Torna all'interfaccia precedente" / "Prova la nuova interfaccia"). Il punto di
  partenza e' il commit `71f3356` (tag locale `interfaccia-precedente-2026-09`: il proxy git
  dell'ambiente rifiuta i tag con un 403, quindi sul remoto vale il numero del commit). Le pagine vecchie **non si
  cancellano** finche' il blocco nuovo non le sostituisce: nel menu nuovo ogni voce apre la
  prima delle sue pagine e, mentre ci si sta dentro, mostra le altre come sottovoci
  (`AppLayout.vue`, `sezioni`); le sottovoci spariranno man mano che i blocchi arrivano.
- **Oggi** (blocco 1): `Pages/Nuovo/Oggi.vue` legge `GET /api/v1/oggi` (`OggiController`):
  una sola lista in ordine di urgenza (ritardo, oggi, presto, programma; fra i ritardi prima
  il piu' vecchio) con il pulsante giusto su ogni riga, e accanto "Arrivato dal campo oggi"
  (da `sync_operations`), "Documenti da chiudere" (perizie emesse e non validate) e "Portali
  pubblici" (conteggi con le regole di `PortalQuery`, non una copia). Le definizioni delle
  scadenze stanno **una volta sola** in `App\Services\Oggi\CoseDaFare`, condiviso con il
  cruscotto della veste precedente (`DashboardController`): i numeri delle due pagine devono
  tornare (`OggiNuovoTest`). La frase in testa usa i **conteggi veri**, non le righe
  elencate (tetto `CoseDaFare::LIMIT` per sezione). Ogni sezione segue i permessi: lavori,
  ispezioni, segnalazioni, non conformita' e patentini con `works.view`; VTA, campo e
  documenti con `assets.view`; irrigazione con `areas.view`; portali con `clients.view`.
- **Patrimonio** (blocco 2): `/patrimonio` e' l'elenco con l'anteprima (`Pages/Nuovo/Patrimonio.vue`):
  ricerca a parole, filtri (committente, area, tipo, stato con l'archivio dentro), le scorciatoie
  "VTA scaduta", "Mai valutati", "Senza specie" con i loro numeri (`GET assets/riepilogo`),
  tabella con selezione e azioni in blocco (prova a vuoto e conferma, come prima), esportazioni
  in un menu, e a destra l'anteprima della riga scelta (foto, misure, cronologia, tre pulsanti).
  L'elenco chiede `dettagli=1` (specie, ultima VTA, ultimo lavoro chiuso, numero di foto:
  sottoquery per riga, solo se richieste) e `ordina=cartellino`. **I filtri stanno una volta
  sola in `App\Support\FiltriElementi`**, usati da elenco, riepilogo ed esportazioni CSV/Excel:
  prima l'esportazione teneva una copia del blocco dell'elenco. I filtri nuovi `vta`
  (`scaduta`, `in_scadenza`, `mai`, `valutato`) e `senza_specie` usano la definizione dello
  scadenzario (ultima valutazione per albero, alberi rimossi esclusi).
  **Cronologia**: `GET assets/{id}/cronologia` (`App\Services\Assets\CronologiaElemento`) mette in
  fila rilievo, modifiche della scheda, valutazioni VTA, lavori, segnalazioni, fotografie per
  **giorno di caricamento** (decisione committente 27/09/2026: dall'iPhone arrivano foto scattate
  settimane prima e la riga finiva nel giorno dello scatto; lo scatto, se e' un altro giorno,
  resta nel dettaglio "scattata il"), le foto eliminate (nel loro giorno, piu' la riga
  `foto_eliminata` nel giorno dell'eliminazione con l'autore dal registro `photo.deleted`) e
  abbattimento, dal piu' recente: la leggono l'anteprima e la scheda nuova. Il portale del
  Comune data le foto allo stesso modo. **Una foto eliminata resta visionabile solo dalla
  cronologia** (decisione committente 27/09/2026): l'eliminazione e' morbida e il file resta,
  le anteprime delle righe `foto` e `foto_eliminata` la portano con `eliminata`/`eliminata_il`
  (nella scheda bordo rosso, ingrandimento senza "Elimina"), `PhotoController::file` la serve
  con `withTrashed`; dalla scheda (`GET assets/{id}`), dal portale e dalle perizie **non
  validate** sparisce (scope di default), mentre una perizia **validata** la tiene
  (`PeriziaController::photos`, `withTrashed`): l'atto chiuso non cambia. Le altre
  schede di Patrimonio (Mappa `/mappa`, Alberi e VTA `/vta`, Irrigazione `/irrigazione`) sono
  ancora le pagine di prima con la testata `Components/Nuovo/TestataPatrimonio.vue` quando la
  veste e' nuova; la voce di menu Patrimonio non ha sottovoci (`schede: true`). L'importazione
  da file resta nella pagina precedente (`/censimento?importa=1`, dal menu "Altro") finche' non
  trova posto in Documenti. `/lavori?nuovo=1&elementi=a,b` apre il nuovo ordine e collega gli
  elementi appena creato. Prove: `PatrimonioTest`.
- **Scheda dell'elemento** (blocco 3): `/censimento/{id}` nella veste nuova apre
  `Pages/Nuovo/Scheda.vue` (`?precedente=1` apre quella di prima): **si legge prima di
  modificare**, una sezione per volta. Testata con cartellino, specie, etichette (stato, VTA,
  nascosto dal portale, pagina pubblica, versione) e i pulsanti Nuovo lavoro, Valuta VTA,
  Stampa, Altro; poi le carte Misure, Identita' e posizione (con vincoli e attributi del
  tipo), Stabilita' (VTA), Lavori e segnalazioni (dalla cronologia, con i campi espliciti
  `codice`, `stato_etichetta`, `periodo`, `squadra`, `origine`); a fianco fotografie (con
  ingrandimento ed eliminazione), mappa e cronologia. Le scritture passano dagli stessi
  moduli e dalle stesse chiamate di prima: `Components/Nuovo/ModuloAlbero.vue` (misure o
  identita', stesso `PATCH assets/{id}` con il blocco `tree` completo e la versione),
  `AssetEditPanel` per cartellino/stato/area/note/attributi, `TreeVtaPanel` con le prop
  `soloVta` e `nudo` per le valutazioni, `PlantingSitePanel`, `GestionalePanel`,
  abbattimento ed eliminazione da "Altro". **I dizionari di `config/agronomia.php` sono
  elenchi di voci** (il valore e' l'etichetta), non mappe: le tendine iterano l'elenco.
  Il dettaglio `GET assets/{id}` porta la catena area, localita', sede, committente per il
  percorso in testa. Prove: `SchedaNuovaTest`.
- **Lavori** (blocco 4): `/lavori` nella veste nuova apre `Pages/Nuovo/Lavori.vue` (`?precedente=1`
  apre la pagina di prima): l'elenco degli ordini con ricerca, filtri (stato con "aperti" di
  serie, committente, squadra, periodo), la scorciatoia "In ritardo" con il numero, la chiusura
  in blocco con prova a vuoto, le richieste delle imprese e a destra l'anteprima dell'ordine.
  Agenda, Gantt, qualita', preventivi, SAL, rendiconto e piani sono **gli stessi componenti di
  prima** montati dalla pagina nuova (`?vista=`); Segnalazioni e Ispezioni restano le pagine di
  prima con la testata `Components/Nuovo/TestataLavori.vue`. **Ogni ordine ha la sua pagina**
  `/lavori/{id}` (`Pages/Nuovo/Ordine.vue`, prima era un cassetto sopra l'elenco): testata con
  stato e passaggi ammessi (il passaggio "in avanti" e' il pulsante principale, annullamento e
  bozza in "Altro"), carte "Che cosa si fa" (con la modifica), "Elementi" (quantita' previste,
  riprendi dalla mappa, fatto/da fare e foto per elemento), "Consuntivo" (listino, economia),
  "Controlli qualita'"; a fianco la mappa degli elementi (verde fatto, arancione da fare), i
  documenti collegati e la cronologia. `GET work-orders/{id}/cronologia`
  (`App\Services\Works\CronologiaLavoro`) mette in fila creazione, cambi di stato **dal registro
  `audit_logs`**, consuntivi, foto, controlli, non conformita', richieste delle imprese e la
  fine prevista superata (fatto di oggi); porta `documenti` (preventivo d'origine, SAL,
  rendiconto) e `per_elemento` (fatti, ultimo, foto). L'elenco accetta `aperti=1` e
  `in_ritardo=1`; il dettaglio porta `geom_geojson` e `tree` degli elementi. I collegamenti agli
  ordini sono `/lavori/{id}`; il vecchio `?ordine=CODICE` viene risolto dalla pagina nuova.
  `Components/Nuovo/NuovoOrdine.vue` e' il modulo di creazione (`?nuovo=1&elementi=a,b`).
  Prove: `LavoriNuoviTest`.
- **Documenti, Committenti, Impostazioni** (blocchi 5, 6 e 7): tre sezioni che riuniscono le
  pagine di prima senza riscriverle. `/documenti` (`Pages/Nuovo/Documenti.vue`, `GET
  /api/v1/documenti` in `DocumentiController`) mette in un elenco solo perizie emesse, verbali
  di ispezione chiusi, preventivi, SAL ed esportazioni gia' fatte (dal registro `audit_logs`,
  azioni `export.*`), con schede per tipo, ricerca a parole, committente, anno e la
  scorciatoia "da validare"; a fianco "Da produrre" (bilancio arboreo, relazione annuale,
  registro fitosanitari: PDF a richiesta con i parametri dei loro endpoint) e una nota onesta:
  le marche temporali (dal 28/09/2026, sezione sotto) con lo stato onesto del servizio. Ogni sorgente esce solo
  a chi ha il permesso della sua pagina (perizie ed esportazioni con `assets.view`; verbali,
  preventivi e SAL con `works.view`). `/committenti` (`Pages/Nuovo/Committenti.vue`, `GET
  committenti/riepilogo` in `CommittentiController`) e' l'anagrafica con elementi, aree,
  lavori aperti e stato del portale per committente, con la scheda del committente scelto e le
  sue aree; le scritture restano in Territorio, che ora accetta `?cliente=ID`, `?scheda=`
  (sedi, portale, vincoli, carto) e `?nuovo=1`. `/impostazioni` (`Pages/Nuovo/Impostazioni.vue`)
  e' la casa delle regolazioni: voci che portano alle pagine che gia' fanno quel lavoro (le
  sezioni di Utenti hanno le ancore `#ruoli`, `#firma`, `#vta-intervalli`, `#squadre`,
  `#gestionale`) e la scelta dell'interfaccia. Le schede di ogni sezione stanno **una volta
  sola** in `resources/js/nuovo/sezioni.js` e le monta `Components/Nuovo/TestataSezione.vue`,
  anche sulle pagine di prima (Fitosanitari, Patentini, Statistiche, Territorio, Utenti,
  Catalogo, Listini) quando la veste e' nuova. Patrimonio accetta `?client_id=` e `?area_id=`,
  Lavori `?client_id=`. Prove: `SezioniNuoveTest`.
- **Casa dopo l'accesso**: nella veste nuova `HomeRoute` porta su `oggi` chiunque veda il
  censimento o i lavori (nella precedente Oggi resta il cruscotto dei lavori e si atterra
  sulla mappa). L'operatore di campo atterra sempre sull'app di campo.
- Le classi ricorrenti della veste nuova stanno in `resources/js/nuovo/stile.js` (pulsanti,
  etichette di stato, carte): bersagli da 44px sul telefono, 36-38px con il mouse, fuoco
  visibile. Collegamenti che aprono un modulo: `/lavori?nuovo=1` (nuovo ordine),
  `/segnalazioni?nuova=1` (nuova segnalazione), `/lavori?ordine=CODICE`,
  `/censimento/{id}?vta=1`.
- **Stato della veste nuova (26/09/2026)**: gli otto blocchi della scaletta sono fatti e
  pubblicati (Oggi; Patrimonio; Scheda; Lavori e Ordine; Documenti; Committenti; Impostazioni;
  telefono). Restano pagine di prima, montate dentro le sezioni nuove con la loro testata:
  Mappa, scadenzario VTA, Irrigazione, Segnalazioni, Ispezioni, Fitosanitari, Patentini,
  Statistiche, Territorio, Utenti, Catalogo, Listini, e i componenti di Agenda, Gantt,
  qualita', preventivi, SAL, rendiconto e piani. Si rifanno se e quando il committente lo
  chiede: la veste nuova e' un ordine diverso delle stesse funzioni, non una riscrittura.
  **Fatto il 28/09/2026**: le marche temporali (sezione "Marche temporali" sotto). Ogni pagina nuova si verifica sul Comune Demo in
  Chromium a 390, 768, 1024 e 1440 (copioni in `scratchpad/verifica-blocco*` della sessione).

## Le dodici modifiche del 04/10/2026 (elenco del committente, "MODIFICHE_ARBORLAB")

Stato per punto; i dettagli stanno nelle sezioni che seguono.
1. **Fatto**: la stampa della mappa e' un **PDF** (`POST exports/mappa.pdf`, `ExportController::mappaPdf`,
   `App\Services\Export\MappaPdf` su `ScrittorePdf`): il browser manda l'immagine della mappa com'e' a video
   (JPEG entro 2400 px, lo sfondo arriva da server esterni che il server non interroga), il titolo con
   committente e area, la scala a video, i metri coperti dalla larghezza (per la **scala grafica**), la
   rotazione (per la **freccia del nord**), le coordinate del centro, la legenda dei livelli accesi e
   l'attribuzione; il server compone il foglio (orizzontale o verticale secondo l'immagine) con
   l'intestazione dell'organizzazione e la nota sulla scala. Registro `export.mappa_pdf`, in Documenti
   come le altre esportazioni. `fetchPdf` (`resources/js/pdf.js`) accetta `method`, `body` e `apri`; la
   pagina apre la scheda del PDF dentro il clic e poi la indirizza al file. Le parti comuni delle stampe
   scritte a mano (intestazione, pie', JPEG da PNG) stanno in `App\Services\Pdf\PartiComuni`, usate da
   elenco e mappa. Prove: `MappaPdfTest`.
2. **Fatto**: dizionario delle specie. Tabella `tree_species` (voci di serie con `tenant_id` nullo da
   `database/seeders/data/specie.csv`, 242 voci con genere, famiglia, nome comune e sinonimi anche
   regionali, installate e riallineate dalla migrazione; voci proprie di ogni organizzazione). La logica
   sta in `App\Services\Botanica\DizionarioSpecie` (`installaDiSerie`, `cerca` a parole su `search_text`
   con in testa la voce esatta, poi parola intera, poi inizio di parola; `tutte`; `impara`). **Il
   dizionario impara**: una specie binomiale salvata in una scheda (gestionale o campo) che non conosce
   entra fra le voci dell'organizzazione; una parola sola ("aghifoglia") no. API `GET specie?q=` /
   `?tutte=1` (assets.view), `POST specie`, `DELETE specie/{id}` solo voci proprie (assets.update).
   In scheda `Components/CercaSpecie.vue` su Specie e Nome comune di `ModuloAlbero`: scelta una voce si
   compilano genere, famiglia e nome comune ("pino romano" -> Pinus pinea). L'app di campo scarica il
   dizionario nel bootstrap (tabella Dexie `specie`, v6), lo mette nelle proposte e compila da sola
   genere e nome botanico quando il nome scritto e' una voce conosciuta. Prove: `DizionarioSpecieTest`.
3. **Fatto**: "Invia al gestionale" e' una **funzione per organizzazione** (`App\Support\Funzioni`,
   `organizations.settings['funzioni']['gestionale_giardini']`), **spenta di serie**: la accende solo la
   console della piattaforma (`PUT piattaforma/organizzazioni/{id}/funzioni`,
   `ConsolePiattaforma::impostaFunzioni`, registro `piattaforma.funzioni` nel tenant del gestore, spunta
   nella scheda dell'organizzazione). Spenta, il middleware `funzione:gestionale_giardini`
   (`RichiediFunzione`, alias in `bootstrap/app.php`) chiude tutte le chiamate di `GestionaleController`
   con 403, il job `SendToGestionale` non spedisce, e le pagine non mostrano ne' la sezione in Utenti
   ne' "Invia al gestionale" nella scheda ne' la voce in Impostazioni (prop condivisa `funzioni`). La
   migrazione `funzioni_organizzazione` tiene accesa la funzione a chi aveva gia' un indirizzo del
   gestionale configurato. Una funzione nuova si aggiunge a `Funzioni::DI_SERIE` e si regola dalla stessa
   console. Prove: `FunzioniOrganizzazioneTest` (e `GestionaleTest` la accende prima di provare).
4. **Fatto**: le **zone**. Una zona (`zones`, `zone_client`, `zone_user`) e' un gruppo di committenti;
   un utente assegnato a una o piu' zone vede e tocca solo quello che sta sotto quei committenti (sedi,
   localita', aree, elementi, alberi, valutazioni, foto, documenti, lavori e le loro righe, consuntivi,
   controlli, non conformita', segnalazioni, trattamenti, ispezioni, irrigazione, piani, preventivi,
   contratti, SAL, vincoli); chi non ha zone e' della **sede centrale** e vede tutto; che cosa si puo'
   fare dentro lo decide il ruolo. **Il perimetro sta una volta sola in `App\Support\PerimetroZone`**
   (`clienti`, `limitato`, `zone`, `sqlAree`, `sqlClienti`, `sqlLavori`, letto una volta per richiesta
   per istanza di utente, `azzera()` dopo le assegnazioni): lo applica lo scope globale `ZonaScope`
   tramite il trait `NelPerimetroZona`, dove ogni modello dichiara come si lega al committente con la
   costante `PERIMETRO_ZONA` (`id`, `client_id`, `site_id`, `locality_id`, `area_id`, `asset_id`,
   `tree_id`, `lavoro`, `work_order_id`, `segnalazione`, `area_o_elemento`, `cliente_o_area`, le
   varianti `_o_nullo`); **le interrogazioni scritte in SQL** chiedono il frammento a `PerimetroZone`
   (tessere e livelli della mappa in `TileController::filtri`, scadenzario VTA e alberi mai valutati di
   `CoseDaFare`, i due generatori VTA, `CommittentiController::riepilogo`, `VtaDashboardController::index`):
   una query nuova sul territorio in SQL va scritta con lo stesso frammento, o un utente di zona
   vedrebbe gli altri. Le zone le gestisce **solo la sede centrale** (`ZoneController`, permesso
   `users.manage` piu' `PerimetroZone::autorizzaCentrale`; pagina `/zone`, `Pages/Zone.vue`, scheda
   "Zone" di Impostazioni che a chi e' di zona non compare); una zona con utenti non si elimina; il menu
   dice "Zona: ..." (prop `auth.user.zone`). Prove: `ZoneTest` (il tecnico del Nord non vede, non
   trova sulla mappa, non esporta, non scarica sul telefono e non scrive niente del Sud).
5. **Fatto**: bersagli della VTA anche dagli elementi censiti (`Components/CercaElemento.vue`, ricerca a
   parole su `GET assets?q=`; il bersaglio entra come riga "CARTELLINO · descrizione", i `targets`
   restano stringhe). **Proposti da soli (dal 07/10/2026**, richiesta "un albero che sta dentro a un
   parco giochi, quel parco giochi lo deve consigliare in automatico"): all'apertura del modulo la
   scheda VTA chiede `GET assets/{id}/bersagli-proposti` (`TreeAssessmentController::bersagliProposti`,
   `App\Services\Vta\BersagliProposti`, permesso `assets.view`) e mostra sotto il campo le **aree in
   cui l'albero sta** (poligono che lo contiene, oppure area della scheda) e gli **elementi censiti nel
   raggio di caduta**: il raggio e' l'altezza dell'albero (minimo 5 m, 15 m se l'altezza manca,
   `?raggio=` per cambiarlo dalla pagina). Fuori la vegetazione, l'archivio, le aree di gestione
   (325), le informazioni geodetiche (399) e i fattori ambientali salvo le infrastrutture (441),
   letti dal codice del catalogo; prima chi contiene l'albero, poi per distanza, tetto `LIMITE` con
   il conto di quanti restano. Le interrogazioni passano dai modelli, cosi' valgono organizzazione e
   zona. Ogni proposta e' un pulsante (44 px sul telefono), "Aggiungi tutti" le porta in una volta,
   il testo resta libero. Prove: `BersagliPropostiTest`.
6. **Fatto**: prescrizioni da un elenco predefinito con ricerca a parole (`config/agronomia.php`,
   chiave `prescrizioni_vta`, 38 formule d'uso) piu' il testo libero, che resta la prescrizione vera.
7. **Fatto**: la valutazione VTA porta la data entro cui fare gli interventi prescritti
   (`tree_assessments.prescriptions_due_on`, campo "Interventi prescritti da fare entro il" in
   `TreeVtaPanel`, visibile quando c'e' una prescrizione). `App\Services\Works\GeneratorePrescrizioniVta`
   (stesse regole di casa di `GeneratoreRicontrolliVta`: anteprima ed esecuzione dallo stesso metodo,
   riferimento all'**ultima** valutazione di ogni albero, un ordine per valutazione garantito dall'indice
   unico su `origin = 'vta_prescription'`, l'annullato copre) trasforma la prescrizione in un ordine
   "Prescrizione VTA - cartellino" pianificato alla data prescritta, con la lavorazione riconosciuta dal
   testo (il nome del listino piu' lungo che compare, poi la famiglia di parole: potatura/rimonda/
   riduzione, abbattimento, trattamento, consolidamento) o quella generica `PRE-VTA`, e la prescrizione
   come nota della riga dell'elemento. `GeneratorePrescrizioniVta::righe` e' l'unica lettura: la usano
   `GET vta/prescrizioni` (elenco con l'ordine nato o "senza ordine"), `POST vta/prescrizioni`
   (`assessment_ids` o `client_id`, `prova`; permesso `works.manage`), la sezione "Interventi prescritti
   dalle VTA" di `Vta.vue` (`?prescrizioni=1` ci porta), il pulsante "Crea l'ordine dalla prescrizione"
   nella carta Stabilita' della scheda nuova (poi mostra "Intervento in agenda: ODL-x") e le righe
   `prescrizione` di Oggi (`CoseDaFare::prescrizioniVta`: scadute, entro 30 giorni o senza data, famiglia
   lavori). Prove: `PrescrizioniVtaTest`.
8. **Fatto**: rilevatori abilitati (`RilevatoriController`, `organizations.settings['rilevatori']`,
   Utenti > Chi firma > "Rilevatori abilitati", permesso `users.manage` per scrivere; chiunque legge).
   La scheda VTA li propone in "Rilievo eseguito da" ("Io stesso" / elenco / "Altro" a mano) e la
   valutazione conserva in `tree_assessments.assessor_details` titolo, albo e partita IVA di quel
   giorno; la perizia li stampa accanto al nome (`PeriziaController::rilevatore`). Prove:
   `RilevatoriTest`.
9. **Fatto**: la scheda stampata (`GET assets/{id}/pdf`, `PdfController::asset`, sezioni in
   `PdfController::SEZIONI_SCHEDA`: posizione, dendro, vta, lavori, attributi, benefici, cronologia, foto)
   ha **Posizione** (coordinate WGS84 e piane nel sistema metrico dell'organizzazione, tipo di geometria
   con le misure calcolate, precisione GPS, vincoli) con la **planimetria**
   (`App\Services\Pdf\PlanimetriaElemento`, PNG disegnato con GD: l'elemento con la chioma a misura, i
   vicini con il cartellino, i confini delle aree tratteggiati, scala grafica e nord). **Lo sfondo
   stradale lo manda il browser** (richiesta del committente 04/10/2026 "non si puo' fare con lo sfondo
   stradale?"): stampando dalla scheda, `istantaneaMappa` (`resources/js/mappaIstantanea.js`) legge il
   canvas della mappa a fianco (`preserveDrawingBuffer`, `pixelRatio` 2,5 per la nitidezza) e manda in
   `POST assets/{id}/pdf` l'immagine con i confini geografici e l'attribuzione; il server proietta i
   dati censiti in Mercatore sferica (la proiezione della mappa a video) e li disegna sopra. Il server
   non interroga i server delle mappe: dipenderebbe da un servizio esterno a ogni stampa, e le regole
   d'uso di OpenStreetMap non lo gradiscono. Senza inquadratura (GET, collegamento diretto, mappa
   ruotata o inclinata, elemento fuori dall'inquadratura, immagine illeggibile) resta il disegno su
   fondo bianco nel sistema metrico dell'organizzazione, e la didascalia lo dice. Poi **tutte le
   valutazioni** VTA con rilevatore e prescrizioni, **lavori** con quello che si fa su quell'elemento e
   **segnalazioni**, **benefici ambientali** dichiarati stime, **cronologia** (`CronologiaElemento`, con
   il totale se tagliata). Le stesse sezioni stanno nei menu di stampa di `Nuovo/Scheda.vue` e
   `Censimento/Show.vue`. Il SRID nelle `ST_Transform` va legato come intero (`?::int`): legato come
   testo PostGIS lo legge come stringa proj. Prove: `SchedaPdfCompletaTest`.
10. **Fatto**: nella pagina dell'ordine la tabella Elementi ha la colonna "Che cosa si fa": lavorazione
    di riga (tendina del listino, vuota = quella dell'ordine) e note per la squadra, salvate con la
    riga (`PATCH work-orders/{id}/assets/{riga}` accetta `work_type_id` e `notes`; un elemento compare
    una volta per lavorazione, 422 altrimenti) e "+ altra lavorazione…" che aggiunge una seconda riga
    sullo stesso elemento (siepe A: potatura piu' concimazione). L'app di campo mostra lavorazione e
    note sotto ogni elemento dell'ordine (lo scarico le portava gia'). Prove: `LavorazioniPerElementoTest`.
11. **Fatto**: ogni intervento in Fitosanitari ha un **tipo** (`phyto_treatments.kind`:
    fitosanitario, diserbo, concimazione, biostimolante, altro; `PhytoTreatment::KINDS`) e la data del
    **prossimo intervento** (`next_due_on`, dopo la data dell'intervento). Nel registro dei trattamenti
    fitosanitari (PDF) entrano solo i tipi di `PhytoTreatment::NEL_REGISTRO` (fitosanitario e diserbo):
    concimazioni e altri prodotti restano negli elenchi e nelle scadenze. Le scadenze
    (`CoseDaFare::trattamenti`: scadute o entro 60 giorni, una scadenza vale finche' sulla stessa area ed
    elemento non si registra un intervento successivo dello stesso tipo) escono da `GET
    phyto-treatments/scadenze`, in testa alla pagina Fitosanitari ("Prossimi interventi", pulsante
    "Registra" che riapre il modulo con i dati dell'ultima volta, anche da `?ripeti=ID`) e come righe
    `trattamento` di Oggi (famiglia lavori). `GET phyto-treatments/{id}` legge il singolo intervento,
    l'elenco filtra per `kind`. Prove: `ScadenzeTrattamentiTest`.
12. **Fatto**: le pagine di prima montate nelle sezioni nuove hanno tutte lo stesso involucro
    (`mx-auto max-w-[1640px] p-4 md:p-6 lg:px-7`, lo stesso delle pagine nuove) e il titolo delle
    testate sta in una riga da 36 px (`min-h-9`): cambiando scheda il titolo non si sposta.

**Stati per tipo di elemento (07/10/2026)**, domanda del committente "i parchi e le attrezzature hanno
gli stessi stati delle alberature?": gli stati di `assets.status` sono gli stessi per tutto il
patrimonio, ma **"morto in piedi" e "ceppaia" valgono solo per la vegetazione** (tipo principale 1
del catalogo, seconda lettera del codice): `AssetStatus::SOLO_VEGETAZIONE`, `eVegetazione`,
`ammessoPer`, `motivoNonAmmesso` (un posto solo; gemello `statiProponibili` in
`resources/js/assetStatus.js`). La tendina di `AssetEditPanel` li toglie per arredi, giochi,
percorsi e impianti (restano attivo e dismesso, piu' l'abbattuto/rimosso dal suo flusso) e il
server rifiuta con 422 in `AssetController` (creazione e modifica) e nel sync di campo
(`CommandApplier`, creazione e `asset.change_status`). Le **aree** del territorio hanno da sempre i
loro stati (prevista, attiva, sospesa, dismessa). Prove: `StatiPerTipoTest`.

## Marche temporali (dal 28/09/2026)

- La marca temporale certifica che un documento esisteva cosi' com'e' a un istante certo:
  la rilascia una TSA accreditata (Aruba, InfoCert, Namirial...); ai clienti le vende DAMA a pacchetti. Il
  programma parla il **protocollo standard RFC 3161** sopra HTTPS con nome utente e password
  (`App\Support\Rfc3161`: richiesta e risposta scritte e lette **a mano in DER**, senza
  librerie; il gettone e' un CMS SignedData con dentro il TSTInfo). Nessun fornitore e' cablato:
  l'indirizzo di serie e' quello di Aruba (`config/marche.php`).
- **Credenziali solo per organizzazione** (decisione committente 28/09/2026: chi affitta la
  piattaforma a un'altra azienda, per esempio una ditta del verde, non deve vedersi consumare
  il proprio lotto, e ogni organizzazione compra e usa le sue marche). Si inseriscono da
  Documenti (permesso `users.manage`, `organizations.settings['marche']`, password cifrata con
  `Crypt`, scrittura sotto `lockForUpdate` come le altre impostazioni); **non esiste un ripiego
  su un account comune** nel `.env` (li' restano solo `MARCHE_URL` proposto nel modulo,
  `MARCHE_QUOTA_GIORNO` di serie, `MARCHE_CATENA` e `MARCHE_OPENSSL` per la verifica). Senza
  credenziali le marche di quell'organizzazione sono spente e la pagina lo dice, senza fingere.
  Solo indirizzi https (tranne il proprio computer).
- **Pacchetti dalla console** (stessa decisione): DAMA vende le marche a pacchetti, quindi la
  console della piattaforma assegna a ogni organizzazione il suo pacchetto
  (`settings['marche']['pacchetto']`, quante marche comprende; vuoto = nessun tetto) e, se
  serve, l'account con cui appone (`PUT piattaforma/organizzazioni/{id}/marche`, registro
  `piattaforma.marche` solo nel tenant del gestore). L'organizzazione **legge** il pacchetto
  ma non lo cambia (`MarcheController` ignora il campo); consumato il pacchetto la pagina
  Documenti rimanda all'assistenza e il servizio rifiuta ("esaurito (N su N)"). Il tetto
  giornaliero e il conteggio sono per organizzazione (`usateOggi`, `totale`), non per account.
  Tutte le scritture passano da `MarcheTemporali::salva` / `togliCredenziali` (lock, audit);
  togliere le credenziali non tocca il pacchetto. La console mostra marche apposte, pacchetto
  e account mascherato (`marche_pacchetto`, `marche_configurate`, `marche_utente`).
- **La logica sta una volta sola in `App\Services\Marche\MarcheTemporali`**: `configurazione`,
  `stato`, `applica`, `verifica`. `applica` produce il PDF **con lo stesso codice della stampa**
  (richiama i controller delle stampe con una richiesta interna e l'utente che chiede: perizia
  validata, verbale chiuso, registro fitosanitari, bilancio arboreo, relazione annuale), ne
  calcola l'impronta SHA-256, manda la richiesta con un nonce casuale, controlla che la
  risposta sia concessa, **con la stessa impronta e lo stesso nonce**, e conserva in
  `storage/app/private/marche/{tenant}/` il PDF esatto e il gettone `.tsr`
  (`marche_temporali`: titolo, impronta, istante certificato `generato_il`, seriale, TSA,
  politica, servizio, `account`). **Il PDF marcato che si scarica e' la copia conservata**, non
  una ristampa: una ristampa puo' avere byte diversi e la marca vale solo per quei byte. Una
  risposta rifiutata, incoerente o non leggibile non lascia niente (ne' riga ne' file) e il
  messaggio riporta lo stato e i motivi scritti dalla TSA.
- **Chi puo' stampare un documento puo' marcarlo** (`PERMESSI`: perizie e bilancio con
  `assets.view`; verbali, registro fitosanitari e relazione con `works.view`): i controller
  delle stampe richiamati direttamente non passano dal loro middleware, quindi il permesso lo
  controllano `MarcheController` e il servizio. Si marca **solo** una perizia validata (prima
  protocollo e impronta possono cambiare) e un verbale chiuso.
- **Quota giornaliera per account** (`quota_giorno`, 10 di serie, 0 = senza tetto), contata su
  tutte le organizzazioni che condividono lo stesso account (`account` = impronta di url e
  utente): e' il lotto che si consuma, non la pagina.
- **Verifica** (`GET documenti/marche/{id}/verifica`): ricalcola l'impronta del PDF conservato,
  controlla che il gettone parli di quell'impronta e, se sul server c'e' la catena dei
  certificati della TSA (`MARCHE_CATENA`, file PEM), verifica anche la firma con
  `openssl ts -verify` (il `openssl_cms_verify` di PHP non accetta lo scopo "marcatura
  temporale"). Senza catena lo dice: la firma si controlla fuori dal programma con il `.tsr`.
- **Pagina Documenti**: carta "Marche temporali" (`#marche`, stato del servizio, credenziali
  dell'organizzazione per chi gestisce gli utenti), pulsante "Marca temporale" sulle righe
  marcabili, poi "PDF marcato", "Gettone .tsr" e "Verifica"; "Genera e marca" / "Stampa e
  marca" nei documenti da produrre: il registro marcato diventa un documento (`tipo` `marca`,
  scheda "Registri marcati"); scorciatoia "Con marca temporale" (`stato=marcati`). Impostazioni
  ha la voce che porta qui. Registro: `marca.applicata`, `marche.configurazione`.
- **Prove**: `MarcheTemporaliTest` usa una **TSA vera creata da openssl**
  (`Tests\Support\TsaDiProva`: autorita', certificato con scopo timeStamping, risposte
  firmate) dietro `Http::fake`, piu' una risposta registrata in `tests/Fixtures/marche/`; le
  finte HTTP di Laravel si accodano e la prima che corrisponde vince, quindi una per prova (o
  `Http::fakeSequence`). Chi vuole la TSA di prova anche in locale la lancia con
  `php -S 127.0.0.1:8099 scratchpad/verifica-marche/tsa-server.php` (credenziali demo/demo).

## Mappa del gestionale (rifatta il 28/09/2026, spunti da GreenSpaces)

- Il committente ha mostrato la mappa di GisClient (GreenSpaces) con l'albero dei livelli,
  le siepi e le recinzioni come linee rosse etichettate, i numeri accanto agli alberi e la
  barra con scala e coordinate: "una schermata cosi' noi non ce l'abbiamo". La nostra mappa
  (`Pages/Mappa.vue`, MapLibre dentro la pagina) ora ha un **pannello a tre schede**:
  Livelli (vista, albero dei livelli, altri livelli, sfondo), Strumenti (misura, stampa,
  disegna area, nuovo elemento, elementi per un lavoro), Legenda. Sul telefono il pannello
  parte chiuso (pulsante "Pannello") e occupa tutta la larghezza.
- **Albero dei livelli**: i quattro tipi principali del Modello Dati con i sottotipi
  **presenti davvero** e i loro numeri, da `GET tiles/livelli` (`TileController::livelli`,
  stessi filtri delle tessere: il metodo `filtri()` e' uno solo per tutte e due). Il filtro
  di categoria si legge dal codice del tipo nella tessera: posizione 2 il tipo principale,
  posizioni 2-4 il sottotipo (`filtroLivelli()`), quindi accendere e spegnere non richiede
  nuove tessere. I nomi dei sottotipi vengono dal catalogo ("PIANTA" diventa "Pianta").
- **Linee per famiglia** (`FAMIGLIE_LINEE`): siepi, filari e cigli verde scuro; recinzioni,
  reti e cancelli rosse; muri, cordoli, canaline con il loro colore; percorsi e piste
  **tratteggiati** (livello a parte, perche' il tratteggio non e' un dato). Sotto ogni linea un
  **bordo bianco**: sull'ortofoto una siepe verde sul prato sparirebbe. L'archivio, quando
  lo si mostra, resta sbiadito (`opacitaStato`).
- **Etichette** con il numero del cartellino accanto ai punti (dallo zoom 16,5) e lungo le
  linee (cartellino o tipo): i **glifi sono ospitati in casa** in `public/mappa/font/`
  (DejaVu Sans, generati con fontnik dai caratteri di sistema, intervalli latini), lo stile
  dichiara `glyphs` sul nostro dominio e nessun carattere arriva da server esterni. La
  spunta si ricorda (`webgis:etichette-mappa`).
- **Lavori aperti**: la tessera porta `lavoro_aperto` (EXISTS su ordini programmati,
  assegnati, in corso o sospesi) e il livello disegna un anello arancione; la scheda
  dell'elemento lo dice. **Segnalazioni aperte** (`works.view`): punti rossi da `GET issues`
  con `geom_geojson` (posizione della segnalazione o centro dell'elemento segnalato, cast
  `array` nel modello perche' arriva come testo JSON), scheda al clic.
- **Coordinate e scala** sotto la mappa come nella barra di stato di un GIS: WGS84 e
  coordinate piane nel sistema metrico dell'organizzazione (`metric_srid`, ora fra le prop
  condivise), calcolate in pagina da `resources/js/proiezione.js` (trasversa di Mercatore,
  serie di Kruger, verificata al centimetro su una lettura di un altro GIS in
  `tests/js/proiezione.test.mjs`). **Misura** di distanze e superfici a punti, piane nello
  stesso sistema della banca dati. **Stampa**: `preserveDrawingBuffer` e una pagina a parte
  con immagine, data, scala a video, coordinate del centro, legenda e attribuzione.
- Collaudo nel browser in `scratchpad/verifica-mappa/` (prepara il Comune Demo con siepe,
  recinzione, percorso, ordine aperto e segnalazione; `pulizia.php` li toglie).

## Intestazione per organizzazione (dal 28/09/2026)

- **Principio** (committente 28/09/2026): un'organizzazione affittata sulla piattaforma e' del
  cliente in tutto. Il suo tecnico, la sua intestazione, i suoi documenti, le sue email: tutto a
  nome suo, niente della piattaforma o di DAMA nelle sue pagine e nei suoi fogli. Nel codice del
  prodotto non ci sono nomi fissi (restano solo nel sito aziendale, nel depliant e nell'offerta,
  che sono di DAMA).
- **Intestazione dei documenti**: ragione sociale (`organizations.name`), partita IVA
  (`vat_number`) e `organizations.branding` (`indirizzo`, `comune`, `telefono`, `email`, `pec`,
  `sito`, `codice_fiscale`, `logo_path`), regolati da Utenti > "Intestazione e firma dei
  documenti" (`IntestazioneController`, permesso `users.manage`, scrittura sotto
  `lockForUpdate`). Il logo si ricodifica in PNG entro 600 px (`ImageDerivative::png`) in
  `intestazioni/{tenant}/logo-<ora>.png` e si serve solo alla propria organizzazione
  (`GET intestazione/logo`). **`App\Services\Pdf\Intestazione::per($tenantId)`** compone nome,
  righe (sede, recapiti, dati fiscali: solo quello che c'e') e il logo come data URI; il partial
  `pdf.partials.intestazione` sta **in cima a ogni PDF** (perizia, verbale, registro
  fitosanitari, bilancio, relazione, preventivo, SAL, scheda localita', scheda elemento). Il
  "chi firma" del professionista resta a parte (`settings['professionista']`).
- Le email del riepilogo partono con il **nome dell'organizzazione** come mittente
  (l'indirizzo resta quello del server). Il nome nel menu si rilegge al salvataggio
  (`router.reload({ only: ['auth'] })`). Prove: `IntestazioneTest`.

## Depliant commerciale (dal 17/09/2026)

- In `docs/depliant/` c'e' il depliant del programma (otto pagine A4): sorgente
  `depliant-commerciale.html`, PDF composto da `genera-pdf.mjs` (Chromium via
  Playwright), schermate del Comune dimostrativo rifatte da `schermate.mjs`.
  Stesso registro del sito aziendale: Inter ospitato in casa, un solo verde, niente
  illustrazioni ne' emoji, niente superlativi, fatti e non aggettivi. Dal 23/09/2026
  la veste e' piu' curata (richiesta del committente): copertina con fascia scura,
  fasce chiare alternate, schermate incorniciate come schermo e telefono, un richiamo
  per pagina, chiusura su fondo scuro. Resta un documento, non una rivista.
- **Non descrive i collegamenti con il gestionale del vivaio** (invio al gestionale
  giardini WordPress): sono funzioni nostre, non del prodotto che si vende. Parla a
  Comuni, imprese del verde, studi agronomici e gestori di patrimoni verdi.
- Ogni pagina ha l'altezza fissa del foglio: `genera-pdf.mjs` si ferma se il
  contenuto sborda, invece di stampare una pagina tagliata. I recapiti in ultima
  pagina si compilano nella sorgente (commento `RECAPITI`) prima di consegnarlo.
- **Nome commerciale ArborLab, venditore DAMA S.R.L.** (decisione committente
  28/09/2026). Depliant rifatto sulla nuova interfaccia (schermate di Oggi,
  Patrimonio, scheda, ordine, Documenti, mappa a livelli, app di campo, area
  riservata, portale): nel menu del programma resta "WebGIS Censimento" e
  `schermate.mjs` lo sostituisce solo nella schermata. Dati di DAMA in copertina e
  in chiusura, **senza capitale sociale** (decisione committente 24/09/2026).
  Avvertenza data al committente prima della scelta: "ArborLab" e' gia' il nome di
  uno studio di arboricoltura di Livorno; la verifica del marchio e' sua.
- **Offerta commerciale** in `docs/offerta/offerta-arborlab.docx`, in Word perche'
  il committente la compila caso per caso: campi fra parentesi quadre evidenziati in
  giallo, testo in `genera-docx.cjs` (pacchetto npm `docx`, fuori dalle dipendenze).
  Stesse regole del depliant: solo funzioni operative e gestionali, niente console
  della piattaforma, niente vivaio. **Le marche temporali le vende DAMA S.R.L.**, a
  pacchetti insieme al programma (decisione committente 28/09/2026: "da noi si
  comprano"): mai scrivere che si comprano "dal fornitore" in depliant, offerta o
  pagine del programma; nell'offerta sono una riga della tabella dei prezzi, non fra
  gli esclusi. In Documenti, a marche spente, il messaggio rimanda all'assistenza.
- **Tono dei testi commerciali** (osservazione del committente 28/09/2026: "i testi
  devono essere naturali e meno AI"): frasi semplici da brochure italiana, rivolte al
  lettore ("potete", "vi mostriamo"). Niente slogan e aforismi ("il dato resta di chi lo
  ha pagato", "ogni albero ha una storia, non una riga"), niente frasi a effetto nei pie'
  di pagina (li' solo "ArborLab · DAMA S.R.L."), pochi due punti e punti e virgola,
  niente elenchi di sostantivi accatastati, niente commenti sul proprio stile ("niente
  riquadri decorativi"). CAM si scioglie in "Criteri Ambientali Minimi".

## Ricerca (dal 23/08/2026)

- Tutti i campi di ricerca usano `App\Support\RicercaTestuale`: il testo si spezza
  in parole (massimo 6), ognuna diventa `%parola%`, le parole vanno in **AND** e i
  campi in **OR**. "rossi mario" trova "Mario Rossi"; ogni parola in più
  restringe. I jolly `%` e `_` sono schermati: chi cerca "50%" cerca quello.
- L'elemento censito ha lo scope `Asset::cercaTesto()`, l'unico posto in cui si
  decide dove si cerca: codice, note, specie/genere/nome comune dell'albero, tipo
  di catalogo, area, località, sede e committente. Lo usano l'elenco del
  censimento, l'export CSV (deve esportare quello che si vede) e la ricerca
  rapida: se si aggiunge un campo va aggiunto lì, non nei controller.
- Lato interfaccia la stessa logica sta in `resources/js/ricerca.js`
  (`corrisponde()`), per gli elenchi filtrati in pagina (Catalogo, committenti in
  Territorio).
- La scelta del committente (filtri di pagina e moduli di creazione) passa da
  `Components/ScegliCommittente.vue`: campo di ricerca a parole su nome, codice,
  partita IVA e codice fiscale, con tendina filtrata. Niente `<select>` semplici
  per i committenti nel gestionale; l'app operatore tiene la tendina di sistema
  (sul telefono è più comoda) e il portale pubblico resta a corrispondenza esatta.
- La ricerca del portale pubblico (`PortalSearch`) **resta a corrispondenza
  esatta**: lì si cerca il numero dell'etichetta letto sul cartellino, non un
  nome.
- Gli accenti non contano più nel gestionale (dal 01/09/2026): "citta" trova
  "Città". Lato server le condizioni diventano `senza_accenti(campo) ILIKE
  senza_accenti(?)`: la funzione è l'involucro IMMUTABLE (quindi indicizzabile)
  dell'estensione `unaccent`, creato con i suoi indici a trigrammi dalla
  migrazione `ricerca_senza_accenti`. L'estensione la crea il **deploy** da
  superutente (`update.sh` e `provision.sh`); la migrazione la tenta comunque e,
  senza permessi, avvisa nel log e prosegue. Senza funzione la ricerca degrada
  da sola al comportamento vecchio (accenti distinti): l'esito del controllo sta
  in `RicercaTestuale::databaseSenzaAccenti()`, una volta per processo. In
  pagina `ricerca.js` toglie i diacritici (NFD) da tutte e due le parti. La
  ricerca del portale pubblico resta a corrispondenza esatta, accenti compresi.

## Elenchi, mappa e scheda (dal 25/08/2026)

- **Viste salvate**: i filtri con un nome stanno in `saved_filters` (jsonb `filtri`),
  API in `VisteController`; le pagine ammesse sono la mappa `PAGINE`
  (pagina → permesso: censimento → assets.view, lavori/segnalazioni → works.view):
  una pagina nuova va aggiunta lì **con il suo permesso** (senza, il ruolo cliente
  leggerebbe le viste dello staff) e monta `Components/VisteSalvate.vue` con `:filtri`
  (computed dei filtri correnti) ed evento `@applica`; la prop `:auto` spegne
  l'applicazione della predefinita quando il componente si rimonta (Lavori, cambio
  scheda interna). "Una sola predefinita per utente e pagina" la garantisce l'indice
  parziale unico sul DB, non il codice; la predefinita è personale (quella di un
  collega condivisa non vale per gli altri); le viste condivise si applicano ma si
  modificano solo le proprie.
- **Chiome sulla mappa**: il tile MVT porta `chioma_m` (join su `trees.crown_diameter_m`);
  il cerchio in metri veri usa `interpolate exponential base 2` su due stop con
  `pixel = metri × 2^zoom / (78271.517 × cos(lat))` — con base 2 la formula è esatta a
  ogni zoom, non un'approssimazione fra gli stop.
- **Azioni multiple con pre-conteggio**: `AzioniMultiple` accetta `bool $prova`; con
  `prova=1` conta fatti/saltati senza scrivere. Il conteggio d'anteprima e l'esecuzione
  passano **dallo stesso metodo**: mai duplicare la logica dei saltati in un percorso
  separato, o anteprima ed esito divergeranno.
- **Storico della scheda**: il trigger `fn_trg_assets_version_snapshot` fotografa in
  `asset_versions` anche `albero` e `posto`. **Ordine delle scritture obbligato** in
  **ogni** percorso che tocca la specializzazione (`AssetController::update` e
  `CommandApplier::applyMeasures` del sync): prima si prepara la specializzazione
  (fill, senza save), poi si salva/incrementa `assets` (la fotografia scatta lì e deve
  riprendere i valori vecchi, e il bump grezzo imposta anche `updated_by`/`updated_at`),
  e solo dopo si salvano albero e posto. Invertirlo fa mentire lo storico (la modifica
  scivola nella revisione precedente, con autore e data sbagliati). Etichette e formati
  vivono solo in `App\Services\Assets\StoriaScheda`; `normalizza()` confronta le date
  riportate alla stessa forma ma **solo** se la stringa è tutta una data, tiene i
  codici con zero iniziale come testo e salta le colonne nate dopo la fotografia;
  endpoint `GET assets/{id}/versioni` (lettura con `sharedLock` sulla riga).

## Documenti stampati

- Export CAM: `CamExporter` (vedi sopra). Le stampe PDF passano tutte da
  `PdfRenderer` (dompdf, DejaVu Sans Mono, nessuna risorsa esterna).
- Sopra la firma di ogni documento firmabile (perizia, bilancio arboreo, verbale
  di ispezione, registro fitosanitari) c'è la riga "Luogo, data"
  prodotta da `App\Services\Pdf\LuogoFirma::riga()`. Il luogo sta una volta
  sola in `organizations.settings['professionista']['luogo']` e si cambia dalla
  pagina Utenti; senza luogo impostato resta la sola data.
- La data è quella **propria del documento**, mai l'orologio letto al momento
  della stampa quando il documento una data ce l'ha già: perizia
  `report_issued_at` (la stessa di testata e piè di pagina), verbale
  `completed_at` (la stessa del corpo e del nome del file). Il **preventivo non
  porta la riga** (decisione committente 23/08/2026): la data dell'offerta è già
  in testata e sotto si firma per accettazione, non per attestazione.
  Bilancio arboreo e registro fitosanitari non hanno
  una data propria: lì è la data di stampa, letta **una volta sola** e passata
  alla vista (`stampatoIl`), o a cavallo della mezzanotte "stampato il" e la
  firma uscirebbero con due giorni diversi.
- Regola generale: **su uno stesso foglio non devono comparire due date
  diverse**. È il motivo per cui la firma riusa la data già stampata altrove
  invece di `now()`.
- Le viste dei PDF ricevono `luogoData` dal controller: aggiungendo una stampa
  firmabile va passato anche lì, altrimenti il modello esplode in produzione
  (ogni vista ha un solo punto di render, elencati in `LuogoFirmaTest`).
- `PeriziaController::updateSettings` scrive `organizations.settings` **sotto
  `lockForUpdate` dentro una transazione**: nella stessa colonna vive
  `perizia_last_number`, il contatore dei protocolli. Salvando su una copia
  letta prima si riscriverebbe tutto l'insieme e un numero appena assegnato
  potrebbe sparire.
- **Documentazione fotografica della perizia**: entrano *tutte* le foto
  dell'elemento (tetto `PeriziaController::MASSIMO_FOTO`, oltre il quale si
  tengono le più vicine al sopralluogo e il documento dichiara il totale).
  Niente esclusioni silenziose: una foto successiva al sopralluogo si stampa
  con la didascalia che lo dice, una illeggibile finisce nel conteggio della
  nota. La versione precedente ne mostrava quattro e scartava in silenzio le
  altre.
- Una perizia **validata** congela anche le fotografie: entrano solo quelle
  caricate prima di `validated_at` (confronto su `created_at`: la domanda è se
  la foto fosse già negli atti alla firma). Le successive non entrano e la nota
  le conta. Senza questo, ristampare un atto chiuso dopo aver aggiunto una foto
  darebbe un documento diverso a parità di impronta SHA-256.
- `ImageDerivative` ha soglie (`BYTE_MASSIMI`, `PIXEL_MASSIMI`) che vanno tenute
  sopra il limite di caricamento delle foto: erano 12 Mpx e una normale foto da
  telefono (4032 x 3024 = 12,19 Mpx) veniva scartata senza avviso, sparendo da
  perizie, schede e portale pubblico.
- `PhotoController` ricava `taken_at` dagli EXIF quando il client non la manda:
  il momento del caricamento non è la data dello scatto, e quella data finisce
  stampata sotto la fotografia nella perizia.
- **Copia d'archivio delle foto (dal 26/09/2026)**: le foto caricate dal computer
  arrivavano intere (anche 5-15 MB) e cosi' restavano, dieci volte lo spazio di quelle
  ridotte dall'app di campo. `ImageDerivative::perArchivio()` le riduce al caricamento
  (`LATO_ARCHIVIO` 2000 px, JPEG `QUALITA_ARCHIVIO` 82); un JPEG gia' dritto, entro il
  lato massimo e sotto `BYTE_ARCHIVIO` resta com'e' (le foto dell'app di campo non si
  toccano); se la riduzione non riesce resta l'originale, un caricamento non si perde.
  Gli EXIF si leggono **prima** della riduzione (data di scatto e posizione finiscono
  nelle colonne), e `size_bytes`/`hash_sha256` sono quelli del file salvato davvero.
  `ImageDerivative` applica l'**orientamento EXIF** prima di buttare gli EXIF: prima le
  copie ricodificate (richieste del portale, derivate pubbliche) uscivano sdraiate.
  Per l'archivio gia' pieno c'e' `php artisan foto:riduci-archivio` (anteprima di serie,
  `--esegui` per scrivere): stessa regola, ma **non tocca le foto negli atti di una perizia
  validata** (una perizia validata ristampata deve dare lo stesso foglio); scrive il file
  nuovo, aggiorna la riga e solo dopo elimina il vecchio. Prove: `FotoRidotteTest`.
- Nei test le stampe si controllano con `Tests\Support\RaccoglitorePdf`, che
  prende il posto di `PdfRenderer`, tiene i dati passati alla vista e compone
  davvero il Blade: si vede il testo del documento senza riaprire un PDF.

## Robustezza delle richieste (dal 23/08/2026)

- `resources/js/bootstrap.js` ripete da solo le richieste respinte per motivi
  passeggeri (429, 502, 503, 504, rete assente): tre tentativi ad attese
  crescenti, rispettando `Retry-After`. Le scritture si ripetono **solo** su 429
  (respinte prima di essere eseguite); mai su 502/504, che potrebbero averle già
  applicate. Con `navigator.onLine === false` non si ritenta: l'app di campo ha
  la sua coda.
- Su 401/419 si torna alla pagina di accesso **una volta sola** per sessione del
  browser (`webgis:rientro-accesso` in `sessionStorage`): senza quel freno, se le
  chiamate ai dati non fossero riconosciute mentre la sessione web è ancora
  valida, il browser rimbalzerebbe all'infinito fra `/login` e la home.
  `/operatore` è escluso: avvisa da sé e non va sbalzato fuori in cantiere.
- **Nessun caricamento deve fallire in silenzio**: un elenco vuoto per errore e
  un elenco vuoto perché non ci sono dati si somigliano troppo. Si usa
  `usaCaricamento()` (`resources/js/caricamento.js`) con `<AvvisoErrore>`, oppure
  `avvisoCaricamento(err)` (`resources/js/avvisi.js`) su un banner esistente. Il
  messaggio riporta sempre il numero dell'errore: è l'unico modo per capire da
  una schermata inviata dal committente che cosa è successo.
- I riquadri della mappa hanno un tetto di richieste separato (`throttle:tiles`,
  1200/min): spostarsi sulla mappa non deve consumare il credito delle altre
  pagine (`api`, 600/min).
- Sul server i processi PHP si dimensionano con `deploy/php-fpm-config.sh` (i
  cinque di serie non bastano); `deploy/diagnostica.sh` stampa in italiano lo
  stato del server quando qualcosa "a volte non funziona".

## Dal rilievo al lavoro (dal 12/09/2026)

- **Ricontrollo VTA in agenda**: `GeneratoreRicontrolliVta` crea ordini con `origin`
  `vta_recheck` e `origin_id` della **valutazione** (non dell'albero): l'indice unico
  parziale sul DB e' la garanzia contro i doppioni, anche con due lanci insieme. Un
  ordine annullato copre comunque la sua valutazione; spostare la data in agenda non
  rigenera nulla. Il riferimento e' sempre l'**ultima** valutazione dell'albero.
  Anteprima ed esecuzione dallo stesso metodo (`$prova`), come per piani e azioni
  multiple. Il cruscotto Oggi conta anche quelli **senza ordine**.
- **Benefici ambientali**: la CO2 sta in `config/co2.php`, ossigeno/polveri/pioggia in
  `config/benefici.php`, tutti e due con i loro riferimenti e l'avvertenza di
  verificarli prima di pubblicarli. In pubblico hanno **due interruttori distinti**
  (`show_co2`, `show_benefici`), tutti e due spenti di suo: stime nuove non escono
  appoggiandosi al consenso dato per un altro numero (regola "niente esce in pubblico
  da solo"). Nella scheda del gestionale si vedono sempre: il tecnico deve poterle
  controllare prima di accenderle. Le **voci** (etichetta, valore, unita', euro) si
  compongono una volta sola in `ServiziEcosistemici`: le stesse righe escono su scheda,
  portale e relazione annuale. Niente energia risparmiata: dipende dagli edifici, dato
  che non abbiamo. Euro spenti senza prezzo **e** fonte dichiarati.
- **Modifica multipla di specie e misure** (`AzioniMultiple::modificaAlberi`): si
  scrivono solo i campi scelti, di serie **solo dove il campo e' vuoto**, e l'anteprima
  conta le schede che cambierebbero davvero (un valore gia' uguale non e' una modifica).
  Vale l'**ordine di scrittura** delle specializzazioni: fill dell'albero senza save,
  bump della versione di `assets` (li' scatta la fotografia), poi save dell'albero.
- **Corredo aree gioco** (`ModelloAreeGioco`): campi della scheda attrezzo e tre liste
  EN 1176 si installano con un gesto e sono **idempotenti**; quello che c'e' si dichiara
  "presente" e non si tocca, cosi' gli adattamenti del tecnico restano. Le liste non
  sono il testo della norma (protetto) e il programma lo dichiara.
- **Gantt**: la matematica sta nel modulo puro `resources/js/lavori/gantt.js` con le sue
  prove (`node --test tests/js/gantt.test.mjs`); i dati sono quelli dell'agenda (stessa
  API e stessa regola: senza fine prevista il lavoro occupa il solo giorno di inizio).
- **Esportazioni**: `App\Services\Export\FoglioXlsx` scrive un .xlsx vero senza
  librerie (zip di XML, righe su file temporaneo). Colonne e valori dell'export del
  censimento si dichiarano **una volta sola** nel controller: CSV, foglio e PDF partono da
  li', o al primo campo aggiunto divergono. **L'elenco filtrato in PDF** (dal 01/10/2026,
  `GET exports/assets.pdf`, `App\Services\Export\ElencoPdf`, A4 orizzontale): intestazione
  dell'organizzazione, filtri scritti in chiaro (`filtriInChiaro`), le sole colonne segnate
  `pdf` in `colonneAssets()` con i valori di `rigaAsset()`, ordine dell'elenco (cartellino),
  tetto `config('esportazioni.pdf_righe_massime')` (20.000) dichiarato nel documento, registro
  `export.assets_pdf` che Documenti elenca come le altre. **Non passa da dompdf**: dompdf costa
  quasi mezzo megabyte per riga di tabella (500 righe superano i 256 MB del server), quindi
  il PDF lo scrive `App\Services\Pdf\ScrittorePdf` (pagine, testo, linee, rettangoli, JPEG,
  xref: tutto a mano) con `CarattereTrueType` che legge i DejaVu Sans Mono di dompdf (cmap,
  hmtx, descrittore) e li incorpora con la mappa ToUnicode: stesso carattere delle altre
  stampe, ventimila righe in pochi secondi. Nei test `Tests\Support\LettorePdf` rilegge il
  testo di questi PDF (oggetti, flussi, ToUnicode), come `RaccoglitorePdf` per dompdf.
- **Ruoli su misura**: i cinque di serie non si rinominano ne' si eliminano, i loro
  permessi si cambiano tranne quelli dell'`amministratore`; i permessi dei portali non
  si mescolano con quelli interni. Il modello `Role` viene dal pacchetto dei permessi e
  **non ha TenantScope**: ogni query dei ruoli filtra a mano su `tenant_id`.
  I nomi dei permessi si spiegano in italiano in `App\Support\Permessi`.
- **Schermata operativa**: `/operatore` si apre sulla home a quattro blocchi ed e' la
  pagina di atterraggio di chi sta in campo (`HomeRoute`: censisce e non gestisce ne'
  lavori ne' utenti - guarda i permessi, non il nome del ruolo, perche' i ruoli ora si
  inventano). La VTA si compila nel gestionale: senza rete l'app lo dice e apre la
  scheda dell'albero, dove misure e foto vanno offline.
- **Ruolo esecutore (dal 27/09/2026)**: chi lavora per una ditta esterna (caso "il Comune
  affida il giardinaggio a una ditta") rendiconta dal campo i soli lavori affidati alla sua
  squadra, senza poter toccare il censimento. Permesso `works.execute` (gruppo Lavori), ruolo
  di serie `esecutore` = `['works.execute']`, creato anche nelle organizzazioni esistenti dalla
  migrazione `ruolo_esecutore` (l'amministratore riceve il permesso). Le porte di campo sono i
  gate `app-campo` (`assets.create` o `works.execute`, rotta `/operatore`) e `sync-campo`
  (`assets.view` o `works.execute`, `SyncController`); `HomeRoute` lo manda sull'app di campo.
  **Il perimetro sta una volta sola in `App\Support\Esecuzione`**: ordini visibili dal campo
  (`visibleInField`: assegnati a lui o alla sua squadra), loro elementi, loro aree. Senza
  `assets.view` la sincronizzazione entra in "modo esecutore": lo scarico porta solo quel
  perimetro (elementi senza `notes`, niente modelli di ispezione ne' committenti) e il delta
  rimanda ogni volta tutto il perimetro cancellando dal telefono cio' che ne e' uscito (un
  ordine appena affidato porta elementi e aree che nel registro dei cambiamenti non compaiono).
  I comandi `work_order.transition`, `work_log.add` e `issue.create` accettano `works.view` o
  `works.execute`; `inspection.complete` resta a `works.view`. Le foto: `PhotoController` le
  accetta da chi ha `works.execute` solo su un elemento di un suo ordine e con `work_order_id`
  (l'app di campo lo manda gia' per le foto del lavoro), e gli fa rivedere solo quelle degli
  elementi dei suoi ordini. Nell'app di campo `canCensire` (`assets.create`) accende rilievo,
  scansione e schede; l'esecutore vede "I miei lavori", la mappa dei loro elementi e la
  sincronizzazione. Prove: `EsecutoreTest`.
- **Rilievo completo dal campo (dal 23/09/2026)**: il modulo "Nuovo rilievo" porta specie e
  misure dentro `asset.create` (blocco `tree`, stesse regole di `asset.update_measures`, stato
  vegetativo dal dizionario `config/agronomia.php`): una sola revisione, niente storico "da
  vuoto a pieno". Dopo "Registra elemento" si apre la scheda del nuovo elemento per foto e
  cartellino. **Area nata in campo**: comando `area.create` (permesso `areas.create`; con un
  committente nuovo anche `clients.manage`, controllato nell'applier). Con un committente
  esistente l'area finisce in una localita' **nuova con il nome dell'area** sotto la sua prima
  sede (mai sotto una localita' che si chiama in un altro modo); il committente nuovo nasce con
  prefisso etichette, sede e localita'. Il perimetro "attorno alla posizione"
  (`poligonoAttorno` in `resources/js/geometria.js`) nasce `planned` con la nota che lo dice:
  non esce sul portale finche' l'ufficio non lo ridisegna. Sul telefono le aree in coda sono
  `dirty` come gli elementi (sopravvivono al ri-scarico, il pull le rimanda per id, lo scarto
  le toglie e conta gli elementi orfani); lo scarico porta anche l'elenco dei committenti a
  chi puo' aprire aree. Prove: `CampoAreeTest`, `tests/js/geometria.test.mjs`.

## Sicurezza dell'accesso (dal 27/09/2026)

- **Verifica in due passaggi** (TOTP, RFC 6238 su SHA1, sei cifre ogni 30 s): codici delle
  app di autenticazione comuni, senza dipendenze (`App\Support\Totp`, con i vettori della RFC
  in `DueFattoriTest`). La logica sta **una volta sola** in `App\Services\Sicurezza\DueFattori`:
  `avvia` (segreto cifrato in `users.mfa_secret`, cast `encrypted`), `conferma` (primo codice,
  otto codici di recupero conservati solo come impronta HMAC della chiave dell'app, gettoni API
  precedenti eliminati), `verifica` (finestra di un passo; `mfa_last_step` rifiuta un codice gia'
  speso; il codice di recupero si consuma), `spegni`. Il segreto e i codici sono `hidden`.
- **Accesso web**: `WebAuthController::login` con la verifica accesa non autentica: mette in
  sessione `due_fattori` (utente, ricordami, scadenza 10 min, tentativi, pagina di ritorno) e
  rimanda a `/login/codice` (`Auth/Codice.vue`); cinque codici sbagliati o la scadenza fanno
  ricominciare dalla password. Chi entra con un codice di recupero atterra su
  `/sicurezza?recupero=1`. Registro: `auth.mfa_failed`, `auth.mfa_locked`, `auth.login` con
  `payload.due_fattori`, `mfa.enabled/disabled/recovery_codes/recovery_used`, `user.mfa_reset`,
  `sicurezza.due_fattori`, `auth.password_changed` (`Audit::logPer` scrive a nome di chi non e'
  ancora autenticato). L'**accesso con gettone** (`auth/login`) vuole il campo `codice`.
- **Regola dell'organizzazione** `organizations.settings['sicurezza']['due_fattori']`
  (`nessuno`, `amministratori`, `tutti`; `SicurezzaController`, permesso `users.manage`,
  scrittura sotto `lockForUpdate` come le altre impostazioni). Chi e' obbligato e non l'ha
  accesa trova aperte solo `/sicurezza`, l'uscita e le chiamate `profilo/*`, `sicurezza/*`,
  `auth/*` (`RichiediDueFattori`, in coda ai gruppi `web` e `api`, dopo `SetPermissionsTeam`);
  il ruolo si legge dalle tabelle (`DueFattori::eAmministratore`) perche' al login il contesto
  del pacchetto dei permessi non e' impostato. Con la regola accesa la verifica non si spegne
  da soli; l'amministratore la **azzera** a chi ha perso il telefono
  (`POST users/{id}/reset-due-fattori`, gettoni e "ricordami" decadono).
- **Pagina "Il mio accesso"** (`/sicurezza`, `Pages/Sicurezza.vue`, in fondo al menu e fra le
  Impostazioni): attivazione con QR (`bacon-qr-code`, SVG in data URI) e chiave a mano, codici
  di recupero mostrati una volta, nuovi codici, disattivazione, **cambio della propria password**
  (min 10 caratteri; le altre sessioni decadono, quella corrente aggiorna `password_hash_web`) e,
  per chi gestisce gli utenti, la regola con il conteggio degli utenti scoperti. Ogni operazione
  delicata richiede la password (`ProfiloController`, `throttle:10,1`). Prove: `DueFattoriTest`.

## Console della piattaforma (dal 27/09/2026)

- `/piattaforma` (`Pages/Piattaforma.vue`, `PiattaformaController`, servizio
  `App\Services\Piattaforma\ConsolePiattaforma`) e' la console di chi affitta il programma:
  tutte le organizzazioni con i numeri per fatturare (utenti attivi, elementi, alberi, foto e
  spazio, aree, committenti, portali accesi, lavori, ultimo accesso), la creazione di una nuova
  organizzazione, la sospensione, le note della piattaforma e l'accesso di assistenza.
- La qualifica e' `users.is_platform_manager`, data **solo dal terminale**
  (`php artisan piattaforma:gestore <email> [--organizzazione=slug] [--togli]`): gate
  `piattaforma`, non un permesso dei ruoli. La console vuole la **verifica in due passaggi
  attiva** (la pagina lo spiega, le chiamate rispondono 403). Il gestore vede la voce
  "Piattaforma" nel menu (prop condivisa `auth.user.piattaforma`).
- **Creare un'organizzazione** passa da `App\Services\Tenancy\CreatoreOrganizzazione`, la stessa
  procedura di `tenant:create` (ruoli, catalogo MD v2.1, amministratore con password
  provvisoria mostrata una volta): il contesto dei permessi si sposta sulla nuova organizzazione
  solo per assegnare il ruolo e poi torna com'era, perche' il gestore sta in un'altra.
- **Sospensione** = `organizations.is_active = false` piu' `settings['piattaforma']['sospensione']`
  (dal, motivo, da): il login dice "organizzazione sospesa" solo a chi ha la password giusta
  (`ConsolePiattaforma::organizzazioneSospesaPer`), i gettoni API vengono eliminati,
  `EnsureUserIsActive` chiude le sessioni aperte alla richiesta successiva, i portali pubblici e
  i feed del calendario erano gia' spenti dal controllo su `is_active`. Non si sospende la propria
  organizzazione.
- **Assistenza**: il gestore entra in un'altra organizzazione **come utente proprio**
  "Assistenza piattaforma" (`assistenza+<slug>@piattaforma.invalid`, dominio riservato RFC 2606,
  password casuale mai comunicata), amministratore, acceso per `ORE_ASSISTENZA` (8) e poi scaduto
  da solo (`assistenzaScaduta`, controllata da `EnsureUserIsActive`). **Non lascia traccia
  nell'organizzazione assistita** (decisione committente 27/09/2026): niente righe nel suo
  registro (inizio, fine, ne' `auth.logout` se si esce con "Esci", che chiude l'assistenza),
  l'utente non compare nella sua pagina Utenti (`UserAdminController::index`) e non conta come
  amministratore (`guardLastAdministrator`), ne' nelle regole della verifica in due passaggi.
  Le modifiche fatte durante l'assistenza restano a nome "Assistenza piattaforma", come ogni
  modifica resta a nome di chi la fa: lo storico non mente. La sessione ricorda il gestore
  (`session('assistenza')`), il layout mostra la fascia con "Termina e torna alla console"
  (`AssistenzaController`, `InvalidateStaleSessions::ricorda` allinea l'hash quando la sessione
  cambia utente). Registro, **solo nel tenant del gestore**: `piattaforma.organizzazione_creata`,
  `piattaforma.sospesa/riattivata`, `piattaforma.assistenza_inizio/fine`. Prove: `PiattaformaTest`.

## Sito aziendale (dal 13/09/2026, ridisegnato il 19/09/2026)

- Tre indirizzi sullo stesso dominio: il **sito che parla ai Comuni** sul dominio nudo
  (`SITO_BASE_HOST`; il `www` rinvia al nudo con un 301), i **portali civici** sui
  sottodomini, il **gestionale** sul suo. Le rotte del sito stanno in `routes/sito.php`,
  gruppo di middleware `sito`: come i portali, **niente sessione e niente cookie** - e'
  quello che permette di scrivere in pie' di pagina "Nessun cookie, niente da accettare",
  e `SitoAziendaleTest` lo verifica insieme a: nessuno `<script>`, nessun `<form>`,
  nessun `<iframe>`, nessuna risorsa da altri domini.
- **Otto pagine**, indirizzi puliti: `/`, `/censimento`, `/stabilita-vta`,
  `/portale-cittadini`, `/conformita-cam`, `/chi-siamo`, `/contatti`, `/privacy` (i vecchi
  `/stabilita`, `/portale`, `/conformita` rinviano). Titolo, descrizione, etichetta di menu e
  briciola di ogni pagina stanno in `SitoController::PAGINE`, in un posto solo. Le prime
  sette nel menu, la privacy solo nel pie'.
- Percorso di collaudo `/sito`, sempre attivo e **sempre `noindex, nofollow`**, senza
  canonical. Finche' `SITO_BASE_HOST` e' vuoto la radice del dominio non pubblica niente
  (nessun ripiego sul dominio dei portali, ne' in `config/sito.php` ne' in
  `deploy/caddy-config.sh`): e' la leva con cui si decide quando aprire il sito, e la
  accende `deploy/set-sito-domain.sh <dominio>` (controlla i record DNS `@` e `www`, chiede
  i dati). Con il dominio acceso: canonical sul dominio nudo, Open Graph con immagine
  locale, dati strutturati prudenti (Organization, WebSite, BreadcrumbList: niente
  valutazioni, niente numeri), `robots.txt` dinamico (ha preso il posto del file statico:
  sugli altri nomi non vieta niente) e `sitemap.xml`. I collegamenti interni passano da
  `App\Support\SitoUrl` (mai `/sito` scritto a mano); le risorse statiche stanno in
  `public/sito-risorse/` - non `public/sito/`, perche' una cartella con quel nome
  coprirebbe il percorso di collaudo.
- **Il programma non inventa fatti sull'azienda.** Tutti i dati modificabili stanno in
  `config/sito.php` (chiavi `SITO_*`, di serie i dati societari verificati di DAMA S.R.L.
  del 19/09/2026 e la sola PEC; il capitale e' "sottoscritto", non "versato") e le viste li
  leggono **solo** attraverso `App\Support\SitoDati`: quello che e' vuoto, nullo, di soli
  spazi o elenco vuoto **non viene stampato** - niente etichette vuote, trattini, "da
  definire". `SitoDati::problemi()` e' la validazione del file (la prova la vuole vuota).
  Anni di esperienza, alberi censiti, Comuni serviti, referenze, professionisti, portali
  realizzati: compaiono solo se compilati. Senza firmatario la pagina VTA usa la formula
  prudente ("il professionista incaricato, secondo la natura dell'attivita' e le competenze
  richieste"); la conformita' non si dichiara mai automatica o assoluta e non si citano
  norme non verificate; i benefici ambientali del portale sono dichiarati stime.
- Direzione grafica **"Impatto"** (decisione committente 19/09/2026, sostituisce il registro
  sobrio del 13/09): grandi titoli editoriali su griglia a 12 colonne, forte contrasto,
  grandi campiture verde bosco alternate a molta carta, impaginazione asimmetrica ma
  ordinata, e i **segni del rilievo** come linguaggio grafico (mappa stilizzata, targhetta
  con numero del cartellino e cronologia, crocette di rilievo, coordinate, numerazione solo
  delle sequenze vere). Nessuna fotografia finche' non ce ne sono di vere: nessun
  segnaposto. La tavolozza sta in `resources/views/sito/stile.blade.php` come variabili
  `--color-*` (forest, forest-dark, leaf, accent, ivory, paper, ink, muted, line, white,
  focus), pensate per essere condivise con il futuro portale civico; le coppie di contrasto
  verificate sono elencate in testa al file. Il verde acido (`accent`) non fa mai testo su
  fondo chiaro: fa il pulsante (testo in ink), i segni e gli occhielli sui fondi scuri.
  Il fuoco da tastiera e' un doppio anello (giallo + ink o forest-dark).
- **Niente JavaScript, nemmeno per il menu**: sul telefono e' un `details/summary`, dai
  1000px la testata ha due righe (marchio e pulsante, poi le sette voci in linea). Solo
  Inter variabile ospitato in casa (`caratteri.blade.php`, pesi dichiarati 100-900 perche'
  sono quelli veri), corpo del testo mai sotto i 17px, bersagli alti almeno 44px, un solo
  `h1`, briciole su tutte le pagine tranne la home, "Salta al contenuto". La verifica in
  Chromium (`scratchpad/verifica-sito.mjs` della sessione del 19/09) controlla a 320, 375,
  768, 1024 e 1440px: nessuno scorrimento laterale, nessun testo sotto i 17px, nessun
  bersaglio sotto i 44px, contrasti, nessuna richiesta esterna, nessun cookie, axe-core
  senza violazioni, menu funzionante con JavaScript disattivato.

## Flusso di lavoro

- Direttiva committente 11/08/2026: **proseguire sempre** con il blocco successivo della roadmap
  senza chiedere conferma a ogni passaggio; chiedere solo per decisioni irreversibili o acquisti.
- Branch di riferimento del progetto: `claude/aruba-hosting-specifics-atsiy4`. Quando la
  sessione ne assegna d'ufficio un altro (succede: il nome cambia a ogni sessione), si
  lavora su quello e **alla fine si allinea il ramo di riferimento** allo stesso punto,
  cosi' chi riprende non riparte da una storia vecchia. Mai push su rami diversi da
  questi due.
- Test: `php artisan test` (DB `webgis_test`); la suite deve restare verde prima del push.
  **Dal 20/09/2026 il push e' una pubblicazione**: se sul server e' acceso l'aggiornamento
  automatico (`deploy/abilita-aggiornamento-automatico.sh`, timer di sistema ogni cinque
  minuti che lancia `update.sh` quando il ramo seguito avanza), quello che si spinge sul ramo
  di riferimento va in produzione da solo entro cinque minuti. Solo avanzamenti in linea
  retta: una storia divergente ferma l'aggiornamento e lo scrive nel registro.
  **Sotto systemd HOME manca** (scoperto il 27/09/2026: il timer era acceso da giorni, il
  registro vuoto e il server vecchio): senza HOME git non trova la configurazione di root e
  rifiuta la cartella di www-data. L'unita' imposta `HOME=/root`, gli script esportano HOME
  e dichiarano `safe.directory` per variabile d'ambiente, ogni controllo che fallisce prima
  dell'aggiornamento (git, fetch) finisce nel registro, `--stato` dice se l'ultimo controllo
  e' fallito e mostra il diario, e `update.sh` rinfresca le unita' del timer quando esistono.
  Prove: `AggiornamentoAutomaticoTest` (compreso il lancio senza HOME).
- **Salvataggi del server** (`deploy/backup.sh`, rifatto il 26/09/2026): banca dati con
  `pg_dump` verificato da `pg_restore --list`, file in **istantanee incrementali** con
  `rsync --link-dest` (una copia piu' le sole novita'; prima erano 14 archivi interi al
  giorno, con 36 GB di foto 500 GB), 14 giorni conservati, istantanee giudicate dal nome
  (rsync conserva le date dei file), controllo dello spazio prima di partire, copia fuori
  dal server da `/etc/webgis-backup.conf` (`BACKUP_REMOTO` rsync via SSH, oppure
  `BACKUP_RCLONE`). `webgis-backup stato` lo legge `diagnostica.sh`. Lo script vive in
  `/usr/local/bin/webgis-backup`: **`update.sh` lo reinstalla** a ogni aggiornamento (e
  installa rsync), altrimenti i server gia' in piedi terrebbero la versione vecchia.
  Prove: `SalvataggiTest` (lancia davvero lo script su cartelle temporanee).
- Verifica ogni blocco anche nel browser reale (Playwright/Chromium) oltre che con i test.
- **Prove d'uso** (dal 04/10/2026, richiesta del committente "puoi fare delle prove pratiche di
  utilizzo?", riferite al gestionale e all'app di campo, non al portale pubblico): si percorre
  nel browser un flusso intero come farebbe l'ufficio, sul Comune Demo in locale (copioni in
  `scratchpad/prova-uso/` della sessione, con il diario `diario.txt` e le schermate). Il primo
  giro, "dalla segnalazione al lavoro chiuso" (segnalazione -> Genera l'ordine -> Modifica con
  squadra e date -> Assegna -> app di campo: Avvia, Completa con consuntivo -> ordine completato
  in ufficio -> segnalazione risolta -> Oggi), ha trovato e fatto correggere: il pulsante
  "Valuta VTA" in testa alla scheda nuova non apriva il modulo (`document` dentro un'espressione
  del template: Vue risolve i nomi sul componente, quindi i gestori che toccano il DOM vanno in
  una funzione dello script); dopo "Registra valutazione" testata, carta e cronologia non si
  aggiornavano (`TreeVtaPanel` ora emette `saved` anche per valutazioni e validazioni, e
  `apriValutazione` e' osservata, non solo letta al montaggio); dal pannello della segnalazione
  il codice dell'ordine non era un collegamento e, a lavoro completato dal campo, nulla diceva
  che restava da chiudere la segnalazione (ora collegamento con lo stato, avviso nel pannello,
  riga di Oggi "lavoro ODL-x completato: da chiudere" con il pulsante "Chiudi", da
  `CoseDaFare::issues()` che porta `work_order`); le scorciatoie di Patrimonio e Lavori e "Salva
  vista" erano sotto i 44 px sul telefono. Un flusso che passa e' un fatto, non un'impressione:
  le prove d'uso si ripetono dopo ogni blocco che tocca il flusso.
- Il committente non è tecnico: i resoconti si scrivono in italiano semplice, senza tecnicismi
  non spiegati e senza emoji.
