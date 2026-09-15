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
  censimento si dichiarano **una volta sola** nel controller: CSV e foglio partono da
  li', o al primo campo aggiunto divergono.
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

## Sito aziendale (dal 13/09/2026)

- Tre indirizzi sullo stesso dominio: il **sito che parla ai Comuni** sul dominio nudo
  (`SITO_BASE_HOST`, piu' il suo `www`), i **portali civici** sui sottodomini, il
  **gestionale** sul suo. Le rotte del sito stanno in `routes/sito.php`, gruppo di
  middleware `sito`: come i portali, **niente sessione e niente cookie** - e' quello che
  permette di scrivere in pie' di pagina che non c'e' niente da accettare, e
  `SitoAziendaleTest` lo verifica.
- Percorso di collaudo `/sito`, sempre attivo. I collegamenti interni passano da
  `App\Support\SitoUrl`: restano nella strada da cui si e' arrivati (dominio o `/sito`),
  come `PortalContext::url()` per i portali.
- **Il programma non inventa fatti sull'azienda.** Ragione sociale, partita IVA,
  recapiti, titolo di chi firma le perizie e referenze stanno in `config/sito.php`
  (chiavi `SITO_*`): quello che e' vuoto **non viene stampato**, mai un segnaposto.
  Finche' `SITO_BASE_HOST` non e' impostato il sito non e' pubblico: e' la leva con cui
  si decide quando aprirlo.
- Registro visivo **istituzionale e sobrio** (decisione committente 13/09/2026):
  fondo chiaro, un solo verde come accento, nessuna illustrazione. Dal 14/09/2026 e'
  lo stesso registro del portale civico, che ha seguito: stesso carattere, stessa
  scala, stesse misure. L'unica differenza voluta e' l'accento degli occhielli, qui
  ambra e nel portale il colore dell'ente - il portale non puo' fissarne uno, perche'
  ogni Comune sceglie il suo. Sistema di design documentato in testa a
  `resources/views/sito/layout.blade.php` (scala tipografica, spazi, colori con i
  contrasti gia' verificati). Corpo del testo mai sotto i 17px, riga entro 68 caratteri,
  bersagli da toccare alti almeno 44px: il difetto da battere era "sul telefono si legge
  male". Niente JavaScript, nessuna risorsa di terzi, caratteri gia' ospitati in casa.

## Flusso di lavoro

- Direttiva committente 11/08/2026: **proseguire sempre** con il blocco successivo della roadmap
  senza chiedere conferma a ogni passaggio; chiedere solo per decisioni irreversibili o acquisti.
- Branch di riferimento del progetto: `claude/aruba-hosting-specifics-atsiy4`. Quando la
  sessione ne assegna d'ufficio un altro (succede: il nome cambia a ogni sessione), si
  lavora su quello e **alla fine si allinea il ramo di riferimento** allo stesso punto,
  cosi' chi riprende non riparte da una storia vecchia. Mai push su rami diversi da
  questi due.
- Test: `php artisan test` (DB `webgis_test`); la suite deve restare verde prima del push.
- Verifica ogni blocco anche nel browser reale (Playwright/Chromium) oltre che con i test.
- Il committente non è tecnico: i resoconti si scrivono in italiano semplice, senza tecnicismi
  non spiegati e senza emoji.
