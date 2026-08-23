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
- RBAC spatie/laravel-permission v8 con teams (`tenant_id`); ruoli: amministratore,
  tecnico, operatore, cliente.
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
- La ricerca del portale pubblico (`PortalSearch`) **resta a corrispondenza
  esatta**: lì si cerca il numero dell'etichetta letto sul cartellino, non un
  nome.
- Gli accenti contano ancora: "citta" non trova "Città". Per toglierli servirebbe
  l'estensione `unaccent` di PostgreSQL, che va creata da superutente.

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

## Flusso di lavoro

- Direttiva committente 11/08/2026: **proseguire sempre** con il blocco successivo della roadmap
  senza chiedere conferma a ogni passaggio; chiedere solo per decisioni irreversibili o acquisti.
- Branch di sviluppo: `claude/aruba-hosting-specifics-atsiy4`; mai push altrove.
- Test: `php artisan test` (DB `webgis_test`); la suite deve restare verde prima del push.
- Verifica ogni blocco anche nel browser reale (Playwright/Chromium) oltre che con i test.
- Il committente non è tecnico: i resoconti si scrivono in italiano semplice, senza tecnicismi
  non spiegati e senza emoji.
