# Confronto con GreenSpaces (R3GIS, Bolzano)

Verifica del 22/08/2026 sul documento commerciale *GreenSpaces_PA_IT.pdf* (luglio
2020, R3GIS S.r.l., Bolzano). Il documento ha quattro anni: quello che dichiara
oggi puo' essere di piu'.

**Come e' stata fatta.** Ognuna delle quattordici funzioni dichiarate dal
concorrente e' stata cercata nel nostro codice, e ogni risposta "ce l'abbiamo"
e' stata poi messa in dubbio da un secondo controllo indipendente, con l'obbligo
di abbassare il giudizio in caso di dubbio. Quattro giudizi sono stati corretti
al ribasso proprio da quel secondo passaggio.

**Esito:** 4 assenti, 8 parziali, 2 presenti.

Legenda dello stato: *presente* = un utente la usa oggi; *parziale* = il dato o
un pezzo del flusso c'e', ma non la funzione come la vende il concorrente;
*assente* = non c'e' traccia.

---

## Cosa conviene fare, in ordine

Rincorrerli su tutto e' il modo piu' veloce per non finire niente. Questa e' la
scaletta ragionata su quanto rende ogni cosa rispetto a quanto costa.

### Prima di tutto, e non e' codice: il MePA

L'ultima riga del loro documento dice che GreenSpaces e' acquistabile sul
**Mercato Elettronico della Pubblica Amministrazione**. Significa che un Comune
li compra con un ordine diretto, senza bandire una gara. Finche' non ci siamo
anche noi, il confronto tecnico spesso non avviene nemmeno: il funzionario
sceglie chi puo' comprare in mezz'ora.

Si risolve con pratiche e tempo, non con programmazione. E' la voce che rende
di piu' per euro speso.

### Poi, in ordine di convenienza

**1. Censire dall'interfaccia anche cio' che non e' un punto** (lavoro: poco).
Il catalogo copre gia' 387 tipi, di cui 313 di arredo urbano, ma mappa e app di
campo propongono solo gli oggetti a punto: siepi, prati, aiuole e pavimentazioni
oggi entrano solo importando un file. E' la differenza fra "gestiamo gli alberi"
e "gestiamo il verde", che nei bandi e' la differenza fra partecipare e no. Poco
lavoro, molta resa.

**2. Import di uno shapefile qualsiasi, con associazione delle colonne**
(lavoro: medio). Oggi importiamo solo file gia' nel formato ministeriale. Un
Comune che ha gia' un censimento fatto da un altro fornitore - cioe' proprio i
clienti di R3GIS - non lo possiamo caricare. E' la porta per subentrare a loro:
senza, ogni subentro costa un censimento da rifare.

**3. Aree gioco: campi della scheda attrezzo e lista di controllo delle
ispezioni** (lavoro: medio). Il censimento e le ispezioni si possono gia' fare
con gli strumenti generali; mancano i campi specifici (produttore, materiale,
fascia d'eta', altezza di caduta, sottofondo, data di installazione) e una lista
di controllo pronta. Serve perche' gli appalti "verde piu' aree gioco" viaggiano
insieme, e perche' li' si parla di sicurezza dei bambini.

**4. Costo calcolato dalla geometria** (lavoro: medio). Il programma gia' misura
superfici e lunghezze; il listino gia' c'e'. Manca la regola che unisce le due
cose: prezzo al metro quadrato per i metri quadrati veri del prato. Loro lo
vendono come funzione, e su un appalto a misura fa la differenza fra un
preventivo credibile e uno a occhio.

**5. Bilancio del verde di fine mandato come documento da consegnare**
(lavoro: medio). I numeri ci sono gia' nel cruscotto; manca la stampa. E' un
adempimento nominato dalla legge 10/2013: un documento che si scarica gia'
pronto e' un argomento di vendita, non solo una funzione.

**6. Sfondo cartografico per singolo committente, con supporto WMS**
(lavoro: medio). I capitolati chiedono spesso "ortofoto comunale" o "carta
tecnica regionale". Oggi lo sfondo e' uguale per tutti i clienti
dell'installazione.

**7. Intervalli di ricontrollo modificabili dall'interfaccia** (lavoro: poco).
La riprogrammazione automatica funziona gia'; i mesi per ciascuna classe si
cambiano solo dietro le quinte. Poche ore, e toglie una dipendenza da noi.

### Da valutare solo se serve davvero

**SAL, validazione del committente e pagamento** (lavoro: molto). Serve se si
lavora come appaltatore per un Comune che pretende Stati Avanzamento Lavori
formali. Prima di costruirlo, aspettare di avere quel committente.

**Diagramma di Gantt** (lavoro: medio). L'agenda settimanale copre l'uso
quotidiano. Il Gantt serve soprattutto perche' viene nominato nei capitolati.

**Lettura NFC per avvicinamento** (lavoro: medio). Il cartellino con QR copre
gia' l'uso pratico, e la lettura NFC da browser non funziona su iPhone. Da fare
solo se un committente la chiede per nome.

**Foglio Excel vero al posto del CSV** e **ruoli personalizzabili**: rifiniture.

---

## Quello che noi abbiamo e nel loro documento non c'e'

Da leggere prima di preoccuparsi troppo dell'elenco qui sopra. In quattordici
pagine del loro documento non compaiono:

- il **portale pubblico per i cittadini**, con mappa, scheda dell'albero,
  ricerca del cartellino e QR. Il loro sistema si rivolge solo all'interno:
  Comune, appaltatore, tecnici. Il cittadino non e' mai nominato;
- la **stima dell'anidride carbonica con il metodo dichiarato**;
- i **vincoli del territorio con il documento allegato** alla scheda;
- il **registro dei trattamenti fitosanitari**;
- lo **scadenzario dei patentini e dei certificati**;
- i **preventivi**;
- la **consegna CAM completa** come pacchetto con manifesto e fotografie.

Sono tutte cose gia' fatte e funzionanti. Su un bando che chiede trasparenza
verso i cittadini o adempimenti fitosanitari, sono punti che loro, con quel
documento in mano, non coprono.

---

## ASSENTI (4)

### Tag NFC per le ispezioni
**Stato:** assente (abbassato da *parziale* dal secondo controllo) — **lavoro per colmarlo:** medio

**Cosa abbiamo**

Abbiamo l'impianto dei tag fisici, ma la lettura funziona solo con QR e codici a barre.

In concreto:
- L'archivio dei tag (tabella asset_tags) prevede gia' i tipi nfc, qr, barcode, rfid e arbotag, con il ciclo di vita completo: attivo, a magazzino, sostituito, perso, danneggiato, ritirato, chi lo ha applicato e quando.
- Nell'app di campo c'e' la scheda "Scansiona": l'operatore inquadra un QR o un codice a barre con la fotocamera, oppure spara con il lettore a pistola, oppure digita il codice a mano; il programma apre la scheda dell'elemento. Se il tag non e' riconosciuto propone di associarlo a un elemento, e l'associazione funziona anche senza rete (finisce in coda e parte alla sincronizzazione).
- Il sistema non fa differenza fra i tipi di tag: se l'operatore sceglie "NFC" nella tendina e digita a mano il codice del tag, l'associazione viene registrata regolarmente come tag NFC. Manca solo la lettura per avvicinamento.
- La ricerca globale del gestionale trova un elemento incollando il codice esatto del tag, e la scheda dell'elemento mostra i tag associati.
- Per gli alberi stampiamo un cartellino A6 con il QR che porta alla pagina pubblica della pianta.
- Esiste un documento di progetto molto dettagliato (docs/ZEBRA-INTEGRATION.md) che ha gia' scelto la tecnologia (tag NTAG213/216, Web NFC su Android, QR ridondante accanto al tag, difese contro la clonazione): il lavoro di studio e' fatto, manca la realizzazione.

**Cosa manca**

Mancano le due cose che il concorrente vende come funzione.

1. La lettura per avvicinamento. Da nessuna parte nel programma si usa la funzione del browser che legge i tag NFC (si chiama NDEFReader): l'ho cercata sia nei sorgenti sia nel pacchetto gia' compilato e non c'e'. Quindi oggi avvicinando il telefono a un tag NFC il nostro programma non apre nessuna scheda. Al massimo, se sul tag e' scritto un indirizzo internet, e' il telefono da solo ad aprire la pagina pubblica dell'albero, ma non e' una funzione nostra e non entra nell'app di campo. Non c'e' neanche la scrittura dei tag: i cartellini che stampiamo hanno solo il QR.

2. La prova che l'ispezione e' stata fatta davvero sul posto. L'ispezione registrata dal campo porta con se' solo il modello di checklist, l'elemento o l'area e le risposte: nessun riferimento al tag letto e nessuna posizione GPS (la colonna per la posizione esiste nella tabella ma non viene mai riempita). Di conseguenza non c'e' la scritta "verificata sul posto" nel verbale, non c'e' filtro per distinguere le ispezioni verificate da quelle compilate a tavolino, e non ci sono le difese contro la copia del tag (controllo del numero di serie di fabbrica, contatore delle letture) descritte solo nel documento di progetto.

Manca anche il collegamento pratico fra le due cose: oggi la scansione apre la scheda dell'elemento, ma non avvia l'ispezione; l'ispezione si comincia a parte scegliendo il modello e l'elemento da una tendina. E manca il "giro ispettivo" (lista di elementi da controllare uno dopo l'altro con avanzamento).

Ultimo dettaglio pratico: la lettura NFC da browser funziona solo su Android con Chrome; su iPhone non e' possibile, quindi il QR resterebbe comunque il ripiego, come del resto prevede gia' il nostro documento di progetto.

**Dove l'ho verificato**
- `database/migrations/2026_08_10_190004_create_assets_tables.php (tabella asset_tags: tag_type CHECK IN ('nfc','qr','barcode','rfid','arbotag'), uid, payload, status, attached_by/attached_at, indice unico su tenant+tipo+uid)`
- `app/Models/AssetTag.php (modello del tag fisico, relazione asset())`
- `database/migrations/2026_08_11_080000_add_asset_tags_change_trigger.php (i tag entrano nel flusso di sincronizzazione)`
- `app/Services/Sync/CommandApplier.php (metodo applyTagAssociate: accetta tag_type 'nfc', gestisce tag gia' in uso con esito TAG_IN_USE, scrive Audit::log('tag.associated'))`

**Secondo controllo:** Ho verificato uno per uno i file citati dal collega: i riferimenti sono esatti, nessuna prova e' inventata. Ma la conclusione "parziale, manca solo la lettura per avvicinamento" e' troppo generosa e va abbassata ad "assente", perche' della funzione descritta non manca un pezzo: mancano tutti e due i pezzi che la compongono.

COSA C'E' DAVVERO (confermato)
- La tabella asset_tags esiste con i tipi 'nfc','qr','barcode','rfid','arbotag' e il ciclo di vita completo (database/migrations/2026_08_10_190004_create_assets_tables.php, righe 73-92), con il modello App\Models\AssetTag e il trigger di sincronizzazione (2026_08_11_080000_add_asset_tags_change_trigger.php).
- Il comando di associazione dal campo accetta 'nfc' fra i tipi ammessi e gestisce il tag gia' in uso (CommandApplier::applyTagAssociate, righe 361-416).
- Nell'app di campo la scheda "Scansiona" legge con la fotocamera solo i formati ['qr_code','code_128','code_39','ean_13','data_matrix'] (Operatore.vue, righe 314-315), oppure si digita/spara il codice a mano.

PRIMO PUNTO: la lettura NFC non esiste in nessuna forma
La ricerca di NDEFReader su tutto il progetto (escludendo node_modules e vendor) trova occorrenze SOLO dentro d

### Diagramma di Gantt delle lavorazioni
**Stato:** assente (abbassato da *parziale* dal secondo controllo) — **lavoro per colmarlo:** medio

**Cosa abbiamo**

Abbiamo un'agenda settimanale dei lavori, non un vero diagramma di Gantt. Nella pagina Lavori c'e' il pulsante "Agenda": mostra una griglia con una riga per ogni squadra e sette colonne, una per ogni giorno della settimana. Un lavoro che dura piu' giorni non viene ripetuto giorno per giorno: viene disegnato come una fascia continua che attraversa i giorni, con codice e titolo scritti una volta sola all'inizio. In pratica e' gia' una barra sul calendario, ma limitata alla settimana. Ci sono anche: i colori diversi per ogni stato del lavoro (bozza, pianificato, assegnato, in corso, sospeso, completato, annullato), l'evidenziazione della colonna di oggi (intestazione verde con un trattino sotto), i pulsanti settimana precedente / Oggi / settimana successiva, l'elenco "Da pianificare" con il modulo per assegnare date, squadra e responsabile, e la possibilita' di annullare o ripristinare una singola giornata di un lavoro lungo. Sotto, il dato c'e' tutto: gli ordini hanno data di inizio e fine previste, la data di chiusura effettiva, e i rapportini dal campo (work_logs) registrano ora di inizio e di fine reali. L'API sa gia' restituire tutti gli ordini che ricadono in un intervallo di date qualsiasi, non solo di sette giorni.

**Cosa manca**

Manca il Gantt vero e proprio, cioe': 1) l'orizzonte lungo - si vede solo una settimana per volta, non c'e' modo di guardare il mese, il trimestre o l'anno con le barre proporzionali alla durata; 2) i colori configurabili - oggi i colori sono scritti dentro il programma e legati allo stato, l'utente non puo' sceglierli ne' colorare per squadra, priorita' o tipo di lavorazione; 3) la linea verticale della data odierna che attraversa tutto il diagramma - c'e' solo l'evidenziazione dell'intestazione della colonna di oggi; 4) il confronto "programmato contro realizzato" - le due barre affiancate (quanto era previsto e quanto e' stato davvero fatto) non esistono, anche se i dati per calcolarle ci sono gia'; 5) lo spostamento delle barre col trascinamento del mouse per ripianificare (oggi si ripianifica solo dalla finestra "Pianifica"); 6) i legami fra lavorazioni (questa dopo quella) e la percentuale di avanzamento sulla barra. Manca anche la stampa/esportazione del diagramma.

**Dove l'ho verificato**
- `resources/js/Components/WorkAgenda.vue (agenda settimanale: righe = squadre, colonne = 7 giorni; funzione ordersOn() disegna gli ordini di piu' giorni come fascia continua; CHIP_COLORS = colori fissi per stato; evidenziazione della colonna di oggi con trattino verde sotto l'intestazione)`
- `resources/js/Pages/Lavori.vue (selettore vista: Elenco, Agenda, Rendiconto, Qualita, Preventivi - nessuna voce Gantt/Calendario)`
- `app/Http/Controllers/Api/V1/WorkOrderController.php (righe 33-80: filtri 'from'/'to' che restituiscono gli ordini il cui periodo previsto interseca la finestra, e filtro 'unplanned'; riga 214 e seguenti: annullamento della singola giornata)`
- `database/migrations/2026_08_11_120000_create_work_tables.php (righe 110-111: colonne planned_start e planned_end sugli ordini di lavoro; righe 148-178: tabella work_logs con started_at/ended_at, cioe' l'eseguito reale dal campo)`

**Secondo controllo:** Abbasso lo stato da "parziale" ad "assente". Le prove indicate dal collega esistono e sono descritte con onesta', ma dimostrano l'agenda settimanale, non il Gantt: l'agenda e' una voce di programma gia' consegnata per conto suo (attivita' n. 16 "Fase 4 blocco 3: agenda programmazione lavori"), e la stessa riga 234 di PROPOSTA-ARCHITETTURA.md elenca "agenda, calendario, Gantt" come tre cose distinte. Contarla come mezzo Gantt significa contare due volte un lavoro gia' fatto.

Verifica punto per punto delle quattro cose che la funzione richiede.

1) "Vista a barre sul calendario": solo in parte, e piu' debole di come viene raccontata. In /home/user/webgis-censimento/resources/js/Components/WorkAgenda.vue la finestra e' fissa a sette giorni (riga 50, `Array.from({ length: 7 })`; `moveWeek` alle righe 182-185 si sposta solo di 7 in 7): non c'e' vista mensile, trimestrale o a periodo libero, che e' la ragione d'essere di un Gantt. Soprattutto, la "fascia continua" non e' una barra: `ordersOn()` (righe 130-148) viene richiamata dentro ogni singola cella e ricalcola da zero l'elenco degli ordini di quel giorno, quindi si disegnano sette pezzetti separati, non un oggetto solo. Tre consegue

### Costo calcolato dalla geometria
**Stato:** assente (abbassato da *parziale* dal secondo controllo) — **lavoro per colmarlo:** medio

**Cosa abbiamo**

Abbiamo le due meta' della funzione, ma non sono unite.

Da una parte il programma misura da solo le geometrie: appena si disegna un prato, una siepe o un'area, il database calcola e conserva superficie in metri quadrati, lunghezza in metri e perimetro (colonne computed_area_sqm, computed_length_m, computed_perimeter_m). Sono misure attendibili, calcolate nel sistema di coordinate metrico corretto, e si vedono nella scheda dell'elemento, nell'elenco del censimento e nei file esportati.

Dall'altra parte abbiamo tutta la macchina dei prezzi: listini con prezzo unitario per lavorazione e unita' di misura (per esempio sfalcio a metro quadrato), spese generali e costi della sicurezza, valorizzazione dell'ordine di lavoro (prezzo unitario per quantita'), rendicontazione per committente e periodo, preventivi con totale e IVA.

Quello che manca e' il ponte fra le due: la quantita' che viene moltiplicata per il prezzo e' sempre un numero scritto a mano. Nell'app di campo l'operatore digita la quantita' eseguita a fine lavoro (la casella si apre vuota, gli viene proposta solo l'unita' di misura). Nel preventivo l'utente scrive la quantita' riga per riga (parte da 1). Quando si collega un elemento a un ordine di lavoro non viene proposta nessuna misura: il campo della quantita' prevista resta vuoto e dall'interfaccia non e' nemmeno compilabile.

Quindi oggi il caso "sfalcio: prezzo al metro quadrato per i metri quadrati del poligono del prato" si puo' ottenere solo se una persona legge la superficie nella scheda dell'elemento e la ricopia a mano nel consuntivo o nel preventivo. Il risultato economico e' corretto, ma il passaggio non e' automatico e nulla garantisce che il numero ricopiato corrisponda davvero alla geometria (ne' che si aggiorni se il poligono viene ridisegnato).

**Cosa manca**

Manca il collegamento automatico fra misura della geometria e quantita' da valorizzare. In concreto:

1. Una regola che, data la lavorazione e la sua unita' di misura, sappia quale misura prendere: metri quadrati dalla superficie del poligono, metri dalla lunghezza della linea, "cadauno" per gli elementi puntuali come gli alberi.
2. La quantita' prevista proposta in automatico quando si aggiunge un elemento o un'area a un ordine di lavoro, con il totale dell'ordine (somma delle superfici o delle lunghezze di tutti gli elementi collegati).
3. La stessa proposta nella schermata di chiusura lavoro dell'app di campo, cosi' l'operatore conferma o corregge invece di digitare da zero, con l'indicazione che il numero viene dalla mappa.
4. Nei preventivi manca il legame stesso: le righe di preventivo non sono collegate a nessun elemento o area (la tabella estimate_items non ha il riferimento), quindi non c'e' proprio da dove prendere la misura. Servirebbe poter scegliere l'area o l'elemento sulla riga e riprendere la sua superficie.
5. Un importo previsto (preventivo di spesa dell'ordine, prezzo unitario per misura geometrica) accanto all'importo consuntivato che gia' esiste, per confrontare previsto e speso.
6. Il comportamento da tenere quando la geometria viene modificata dopo: se la quantita' presa dalla mappa si aggiorna o resta congelata al momento del lavoro (per un consuntivo gia' chiuso deve restare congelata).
7. La distinzione fra superficie lorda del poligono e superficie effettivamente lavorata (aiuole, vialetti, alberi dentro il prato): il concorrente probabilmente moltiplica il lordo, ma serve almeno poter correggere il valore proposto.

**Dove l'ho verificato**
- `database/migrations/2026_08_10_190004_create_assets_tables.php (righe 29-37: le colonne computed_area_sqm, computed_length_m, computed_perimeter_m sono calcolate in automatico dal database a partire dalla geometria, in metri quadrati e metri)`
- `database/migrations/2026_08_10_190002_create_clients_territory_tables.php (righe 109-112: stesso calcolo automatico di superficie e perimetro per le aree verdi)`
- `app/Services/Works/WorkOrderEconomics.php (righe 50-100, metodo consuntivo(): l'importo nasce da quantity dei consuntivi di campo moltiplicata per unit_price del listino; la geometria non compare mai)`
- `database/migrations/2026_08_11_120000_create_work_tables.php (righe 48-62 tabella price_list_items con unit e unit_price; righe 130-146 tabella work_order_assets con planned_quantity libera; righe 148-175 tabella work_logs con quantity e unit scritte dall'operatore)`

**Secondo controllo:** Le prove citate dal collega sono tutte esatte, ma la conclusione "parziale" e' troppo generosa: lo stato corretto e' "assente".

Verificato riga per riga. Le misure dalla geometria esistono davvero: in database/migrations/2026_08_10_190004_create_assets_tables.php (righe 29-37) le colonne computed_area_sqm, computed_length_m e computed_perimeter_m sono GENERATED ALWAYS ... STORED con ST_Area/ST_Length/ST_Perimeter su ST_Transform(geom, 7791); lo stesso per le aree in 2026_08_10_190002_create_clients_territory_tables.php (righe 108-112). Ed esiste tutta la macchina dei prezzi: app/Services/Works/WorkOrderEconomics.php (righe 49-105) somma la quantity dei work_logs per unita' di misura e la moltiplica per unit_price del listino; EstimateController usa quantity * unit_price alla riga 47 e nel metodo totals() (righe 377-379), con quantity validata come dato inviato dall'utente (righe 158-190).

La prova decisiva, che il collega non ha usato: cercando computed_area_sqm|computed_length_m|computed_perimeter_m in tutto il codice applicativo, compaiono SOLO nei cast dei modelli (app/Models/Asset.php, Area.php), in PortalController.php, in ExportController.php (CSV del censimento), in Mappa.

### Base cartografica del cliente
**Stato:** assente (abbassato da *parziale* dal secondo controllo) — **lavoro per colmarlo:** medio

**Cosa abbiamo**

Abbiamo lo sfondo cartografico, ma sempre uguale per tutti. Sul portale pubblico dei Comuni il visitatore puo' scegliere fra tre sfondi (Stradale, Satellite, Scura) con i pulsanti in alto a sinistra della mappa; gli indirizzi di questi tre sfondi sono scritti nella configurazione del server (file .env, voci PORTAL_TILES_*) e chi installa il programma puo' sostituirli con un altro fornitore senza toccare il codice. Uno sfondo lasciato senza indirizzo sparisce dal selettore, e ogni sfondo dichiara fin dove ha immagini. Nel programma di gestione (Mappa, scheda del Censimento) e nell'app di campo dell'operatore lo sfondo e' invece uno solo, OpenStreetMap, scritto fisso nel codice: non c'e' nemmeno il selettore. Lo stesso vale per la mappina che finisce nelle stampe PDF.

**Cosa manca**

Mancano proprio le due cose che chiede il concorrente. Primo: la scelta per singolo committente. Le impostazioni del Comune (nome, stemma, colore, testi, CO2) non prevedono nessuna voce cartografica, ne' esiste una tabella dei livelli di mappa: cambiando l'indirizzo dello sfondo cambia per tutti i clienti dell'installazione, quindi non si puo' dare al Comune A la sua ortofoto e al Comune B la sua. Secondo: il WMS. In tutto il programma non c'e' una riga che parli di WMS o WMTS; la mappa sa leggere solo i servizi a tessere numerate (il classico z/x/y). Un tecnico esperto potrebbe forse incollare nel file .env un indirizzo WMS costruito a mano, ma non e' previsto, non e' documentato, non e' testato e comunque varrebbe per tutti i committenti. Mancano inoltre: la pagina per inserire l'indirizzo del servizio dell'ente con nome, trasparenza e ordine dei livelli; la gestione dei sistemi di coordinate diversi dal 3857; il passaggio dal nostro server per i servizi che rifiutano le chiamate dirette dal browser o che vogliono utente e password; il selettore di sfondo nelle mappe del gestionale e dell'operatore; il database topografico e la mappa catastale come livelli sovrapponibili.

**Dove l'ho verificato**
- `config/portal.php (righe 36-91: i tre sfondi 'stradale', 'satellite', 'scura' con indirizzo preso dal file .env, PORTAL_TILES_*)`
- `app/Services/Portale/PortalExtent.php (metodo sfondi(): legge config('portal.basemaps') e scarta quelli senza indirizzo)`
- `app/Http/Controllers/Portale/MappaController.php (metodo mappa(): passa gli sfondi alla pagina pubblica)`
- `resources/views/portale/mappa.blade.php (righe 74-80: i pulsanti per cambiare sfondo sulla mappa pubblica)`

**Secondo controllo:** Ho aperto tutti i file citati dal collega: le sue prove sono esatte, ma la conclusione "parziale" e' troppo generosa e va abbassata ad "assente".

La funzione richiesta chiede due cose: (1) che lo sfondo della mappa sia scegliibile per singolo committente, e (2) che si sappiano leggere i servizi WMS degli enti (ortofoto comunale, carta tecnica regionale, database topografico). Nel codice non c'e' ne' l'una ne' l'altra. Quello che abbiamo e' semplicemente "una mappa con uno sfondo", cioe' il minimo indispensabile perche' una mappa esista, non la funzione in esame.

1) WMS: assente del tutto. Ho cercato le parole "wms", "wmts", "getmap" in tutto il programma (cartelle app, resources, config, routes, database, tests): zero risultati. Le uniche due righe che nominano WMS in tutto il progetto stanno in /home/user/webgis-censimento/PROPOSTA-ARCHITETTURA.md (riga 170, requisito P07; riga 175, requisito P12), cioe' sono elenchi di cose da fare, non cose fatte. Tutte le mappe del programma usano riquadri x/y/z (formato "tessere"), che e' una tecnologia diversa: /home/user/webgis-censimento/resources/js/portale-mappa.js (funzione stileCon, sorgente di tipo 'raster' con tessere), /home/user/w

## PARZIALI (8)

### Aree gioco e attrezzi ludici
**Stato:** parziale — **lavoro per colmarlo:** medio

**Cosa abbiamo**

Non esiste un modulo dedicato alle aree gioco, ma buona parte del lavoro si puo' gia' fare con gli strumenti generali del programma.

1) Censimento degli attrezzi. Il catalogo ministeriale gia' installato contiene i tipi giusti: "gioco singolo", "gioco complesso", "attrezzo percorso vita", "attrezzo sportivo", "attrezzo Agility Dog", "canestro basket", "porta calcio", "tavolo da ping-pong", oltre all'"area gioco" come superficie e alle "pavimentazioni antitrauma" (il sottofondo) come superfici a se'. Un gioco si posiziona sulla mappa o dall'app di campo, con codice di censimento, foto, note e targhetta NFC/QR.

2) Campi della scheda. Il programma permette all'amministratore di aggiungere campi propri a ciascun tipo di oggetto (pagina Catalogo, pannello "campi"): testo, numero con unita' di misura, data, elenco a scelta. Quei campi compaiono poi nella scheda dell'elemento e si compilano dall'ufficio. Quindi produttore, materiale, data di produzione, fascia d'eta', altezza di caduta, sottofondo e data di installazione si possono creare a mano, uno per uno, per ogni tipo di gioco.

3) Ispezioni periodiche di sicurezza. Questa parte c'e' ed e' completa: si creano modelli di controllo con il riferimento alla norma (per esempio UNI EN 1176-7:2020), la periodicita' in giorni e l'elenco delle domande; il controllo si esegue su un singolo attrezzo oppure sull'intera area, anche senza rete dal telefono; le risposte negative aprono automaticamente una non conformita'; c'e' lo scadenzario dei controlli in ritardo e la stampa del verbale in PDF. Nei dati dimostrativi c'e' gia' un modello "Controllo funzionale area giochi" a 90 giorni.

4) Giochi dismessi. Ogni elemento ha uno stato che comprende "Dismesso" e "Abbattuto/Rimosso", con data di fine validita' e motivo; l'elenco del censimento li nasconde di norma e li mostra con la casella "Mostra anche gli abbattuti" o filtrando per stato. Resta anche lo storico completo delle modifiche della scheda.

5) Lavori legati agli attrezzi. Gli ordini di lavoro e i rapportini si agganciano al singolo elemento censito, quindi un intervento su un gioco resta registrato su quel gioco.

**Cosa manca**

1) La scheda dell'attrezzo non esiste gia' pronta. Nessuno dei campi elencati dal concorrente (tipologia, materiale, produttore, data di produzione, fascia d'eta', altezza di caduta, sottofondo, data di installazione) e' preinstallato: l'installatore del catalogo non crea alcun campo personalizzato. Oggi bisognerebbe crearli a mano, uno per uno, e ripeterlo per ogni tipo di gioco e per ogni committente.

2) Manca del tutto la parte economica. Non c'e' traccia nel programma di "valore a nuovo", ammortamento o gestione a cespite: nessuna colonna, nessun calcolo, nessuna pagina. Un valore lo si potrebbe registrare come campo numerico, ma il programma non calcolerebbe nulla (ne' la quota annua, ne' il valore residuo, ne' un riepilogo per area o per committente).

3) L'operatore in campo non puo' compilare la scheda dell'attrezzo. L'app di campo scarica sul telefono i campi personalizzati ma non li mostra: crea l'elemento con area, tipo, codice, note e posizione. Le misure di campo previste sono solo quelle degli alberi. Quindi il rilievo dei dati del gioco si finisce comunque dall'ufficio.

4) Non si riesce a estrarre facilmente l'elenco dei giochi. Nella pagina Censimento si filtra per committente, area e stato, ma non per tipo di oggetto (la funzione c'e' nell'API, non nell'interfaccia) e la ricerca a testo cerca solo nel codice e nelle note. Un elenco "tutti gli attrezzi ludici del Comune" non si ottiene con un clic.

5) La scheda dell'elemento non mostra la sua storia di controlli e lavori: ci sono foto, attributi, targhette, vincoli e abbattimento, ma non l'elenco delle ispezioni fatte ne' degli interventi eseguiti su quell'attrezzo. Bisogna cercarli dalle pagine Ispezioni e Lavori.

6) Non c'e' il legame fra l'area gioco e i giochi che contiene (nessun "padre-figlio"): l'area e' un poligono come gli altri, e il collegamento e' solo geografico.

7) Non c'e' nessun modello di ispezione EN 1176 preinstallato per la vendita: quello esistente sta nei dati dimostrativi, non nell'installazione di un nuovo committente. Mancano anche i controlli tipici della norma (verifica dell'area di caduta rispetto all'altezza dichiarata, spazi liberi di sicurezza).

8) Non esiste una voce di menu ne' una pagina "Aree gioco": la funzione, cosi' come la presenta il concorrente, non e' riconoscibile da un utente che apre il programma.

**Dove l'ho verificato**
- `database/seeders/data/catalogo_md_v21.csv (righe P214250 "gioco singolo", P214251 "gioco complesso", P214264 "attrezzo percorso vita", P214288 "attrezzo sportivo", P214298 "attrezzo Agility Dog", P214268 "canestro basket", P214290 "tavolo da ping-pong", S327552 "area gioco", S242001/002/013/015/054 "pavimentazione antitrauma")`
- `database/migrations/2026_08_10_190004_create_assets_tables.php (tabella assets, colonna libera attributes jsonb)`
- `database/migrations/2026_08_10_190003_create_catalog_tables.php (tabella custom_fields: campi aggiuntivi per tipo di oggetto, con tipo testo/numero/data/elenco e unita' di misura)`
- `app/Services/Catalog/AttributeValidator.php (accetta solo gli attributi definiti come campi personalizzati, gli altri li rifiuta)`

**Secondo controllo:** Confermo "parziale" ma la descrizione del collega e' troppo generosa: delle quattro parti della funzione ne esiste davvero una sola. VERIFICATO E VERO: i codici di catalogo ci sono tutti (catalogo_md_v21.csv righe 221,222,232,236,237,254,256,264,370,81-86); custom_fields esiste con tipi testo/numero/data/elenco e unita' (2026_08_10_190003_create_catalog_tables.php) e il pannello "campi" c'e' (Catalogo.vue 159-217); CatalogInstaller non crea alcun campo preimpostato; le ispezioni sono reali e complete come motore (InspectionRunner genera le NC dai KO, esecuzione offline in Operatore.vue 44-92 e sync.js 314-337, PDF in PdfController + views/pdf/inspection.blade.php, modello demo EN 1176-7 a 90 giorni in DatabaseSeeder 258-283); i lavori si agganciano al singolo elemento (work_order_assets.asset_id, work_logs.asset_id, ricerca in Lavori.vue 238-258); stati Dismesso/Abbattuto, valid_to e removal_reason ci sono. QUATTRO ERRORI NELL'AFFERMAZIONE: (1) "storico completo delle modifiche della scheda" e' falso lato utente: asset_versions esiste nel DB e c'e' app/Models/AssetVersion.php con la relazione in Asset.php:66, ma non esiste nessuna rotta in routes/api.php ne' alcuna pagina che lo mo

### SAL, validazione della committenza e pagamento
**Stato:** parziale — **lavoro per colmarlo:** molto

**Cosa abbiamo**

Dei quattro passaggi del concorrente ne abbiamo due, e uno solo per meta'.

1) Chiusura del lavoro: c'e' ed e' completa. Ogni ordine di lavoro segue gli stati bozza, pianificato, assegnato, in corso, sospeso, completato. Quando si mette su "completato" il programma registra data e ora esatte e da quel momento l'ordine non si puo' piu' modificare: la storia resta com'era.

2) Elenco dei lavori di un periodo con gli importi: c'e', nella scheda "Rendiconto" della pagina Lavori. Si sceglie il mese (o un periodo qualsiasi) e, se serve, un committente; esce l'elenco dei lavori chiusi in quel periodo con ore, quantita', importo calcolato dal listino, subtotale per ogni committente e totale generale. Il rendiconto dichiara anche i lavori che non riesce a valorizzare e spiega il motivo (manca il listino, manca la voce, manca la quantita'). Si puo' scaricare in foglio di calcolo (CSV, con il punto e virgola come vuole Excel italiano).

3) Un controllo sul lavoro fatto esiste, ma e' interno: il verbale di qualita' con esito positivo o negativo, che in caso negativo apre una non conformita' e puo' generare un ordine correttivo. Lo compila il nostro tecnico, non il committente.

In pratica: sappiamo dire con precisione quanto e' stato fatto e quanto vale, ma ci fermiamo un attimo prima del documento formale.

**Cosa manca**

1) La validazione del committente: non esiste. Il portale del committente e' di sola lettura e per scelta non mostra nessun dato economico; l'unica cosa che il committente puo' fare e' inviare una segnalazione. Non c'e' un pulsante per approvare un lavoro o un periodo di lavori, non c'e' uno stato "validato dal committente", non c'e' la data ne' il nome di chi ha approvato. Nel programma esistono due colonne pensate per la firma dell'operatore e del cliente sul consuntivo di campo, ma sono vuote: nessuna parte del programma le riempie.

2) Il SAL come documento vero: non esiste. Oggi il rendiconto e' un calcolo che si rifa' ogni volta che si apre la pagina. Manca il documento salvato: un numero progressivo, il periodo bloccato, l'elenco dei lavori congelato con gli importi di quel momento (se poi cambia il listino, il documento non deve cambiare), uno stato che avanza (bozza, inviato al committente, validato), la stampa in PDF da firmare e allegare.

3) Il pagamento: non esiste. Non c'e' nessun posto dove registrare che il SAL e' stato pagato: ne' importo liquidato, ne' data, ne' numero di fattura, ne' saldo residuo. Esiste la tabella dei contratti con l'importo, il CIG e il CUP, ma e' completamente inutilizzata: nessuna pagina e nessun comando la gestiscono. Esiste anche la possibilita' di allegare un documento di tipo "fattura", ma solo come file appeso a un elemento, senza alcun collegamento con i lavori o con gli importi.

Da notare che questo passaggio era gia' previsto nel documento di architettura approvato (requisito W12 e tabella "statements"), quindi non e' una svista ma un blocco non ancora affrontato.

**Dove l'ho verificato**
- `app/Models/WorkOrder.php — costante TRANSITIONS: gli stati sono bozza, pianificato, assegnato, in corso, sospeso, completato, annullato. Dopo 'completed' non c'e' nessuno stato successivo (nessun 'validato', 'rendicontato' o 'pagato')`
- `app/Http/Controllers/Api/V1/WorkOrderController.php — metodo transition(): quando lo stato diventa 'completed' scrive completed_at; il metodo update() blocca ogni modifica successiva (ordine completato immutabile). Richiede il permesso works.manage, cioe' personale interno`
- `app/Http/Controllers/Api/V1/WorkReportController.php — metodo lavori(): riepilogo dei lavori completati in un periodo (from/to) e per committente, con ore uomo, quantita', importi da listino, subtotali per cliente e totale generale. E' il calcolo piu' vicino a un SAL, ma viene ricalcolato ogni volta e non salvato`
- `resources/js/Components/WorkReport.vue — la scheda 'Rendiconto' a video: scelta del mese, filtro per committente, tabella con importi, funzione exportCsv() per il foglio di calcolo. Nessun pulsante per creare un documento, protocollarlo, farlo approvare o registrare un incasso`

**Secondo controllo:** Verifica fatta file per file: l'affermazione regge, lo stato "parziale" e' corretto, ma con due correzioni in peggio (non in meglio).

CONFERMATO
1) Chiusura del lavoro. app/Models/WorkOrder.php, costante TRANSITIONS: 'completed' => [] e 'cancelled' => [], nessuno stato oltre la chiusura (niente validato/rendicontato/pagato). app/Http/Controllers/Api/V1/WorkOrderController.php, transition() riga 201 scrive completed_at = now(); update() rifiuta le modifiche a un ordine completato o annullato. Serve il permesso works.manage: personale interno.
2) Elenco dei lavori di un periodo con importi. app/Http/Controllers/Api/V1/WorkReportController.php, metodo lavori(): lavori con status 'completed', filtro from/to (fuso Europe/Rome) e client_id opzionale, ore uomo, quantita', importo da listino via app/Services/Works/WorkOrderEconomics.php, subtotali per cliente e totale generale. E' calcolato a ogni chiamata e non viene salvato da nessuna parte. resources/js/Components/WorkReport.vue: scheda con date "Dal/Al", filtro cliente, pulsanti mese, exportCsv() con separatore ';' e BOM per Excel italiano. Nessun pulsante per creare, protocollare, far approvare o incassare. resources/js/Pages/Lavori.

### Adempimenti della legge 10/2013
**Stato:** parziale — **lavoro per colmarlo:** medio

**Cosa abbiamo**

Tre dei quattro punti ci sono gia' e si usano dall'interfaccia.

1) Catasto degli alberi: e' il cuore del programma. Ogni pianta ha la sua scheda con posizione sulla mappa, codice, specie, misure, data di messa a dimora e data di abbattimento, piu' la storia delle valutazioni di stabilita'. Si consulta dalle pagine Censimento e Mappa, si stampa in PDF e si esporta.

2) Piante monumentali: sulla scheda dell'albero c'e' la casella "Monumentale" con il campo per il riferimento dell'elenco nazionale (per esempio AMI/03/000123) e una casella separata "Tutelato" con il suo riferimento. Nella pagina Scadenzario VTA c'e' l'elenco "Alberi monumentali, tutelati e dedicati" che li raccoglie tutti; il conteggio compare anche nella pagina Statistiche. La dicitura "Albero monumentale" viene mostrata al cittadino sia sulla pagina raggiunta dal codice QR sia sulla scheda del portale pubblico del Comune, e ai vincoli si puo' allegare il provvedimento in PDF.

3) Albero dedicato: sulla scheda c'e' la casella "Dedicato" con tre campi (a chi e' dedicato, occasione, data). Nei nostri test l'esempio e' proprio una nuova nata. La dedica compare nell'elenco degli alberi tutelati, nella scheda albero in PDF e sulla pagina pubblica dell'albero, quindi ai genitori si puo' gia' consegnare il collegamento o il codice QR della "loro" pianta.

4) Bilancio del verde: c'e' il riquadro "Bilancio arboreo (L. 10/2013)" nella pagina Scadenzario VTA. Si scelgono due date qualsiasi (quindi anche l'inizio e la fine del mandato) e il programma calcola quanti alberi c'erano all'inizio, quanti ne sono stati piantati, quanti abbattuti, quanti ce ne sono alla fine, la variazione, il dettaglio specie per specie e quante sostituzioni sono state completate sui posti liberati da un abbattimento.

**Cosa manca**

Mancano soprattutto i documenti da consegnare e alcuni dettagli anagrafici.

1) Albero dedicato ai nuovi nati e agli adottati: la dedica oggi e' un testo libero (nome, occasione, data). Non ci sono i dati veri e propri del bambino e della famiglia (data di nascita o di adozione, genitori, indirizzo, numero di protocollo), non c'e' un elenco dedicato dei nuovi nati con relativa pagina di gestione, non c'e' modo di caricare in blocco l'elenco che arriva dall'anagrafe e soprattutto non esiste l'attestato di dedica da stampare e consegnare ai genitori: la voce e' scritta nella nostra proposta di architettura (A10) ma non e' mai stata realizzata. Anche il cartellino con il codice QR non riporta la dedica, e il portale pubblico del Comune non la mostra.

2) Bilancio di fine mandato: il calcolo c'e' ma resta solo a video. Non si puo' stampare in PDF ne' scaricare in Excel, quindi non produce il documento che il sindaco deve pubblicare. Inoltre il calcolo abbraccia tutti gli alberi dell'impresa: non si puo' ancora filtrare per singolo Comune, e chi gestisce piu' Comuni non riesce a tirare fuori il bilancio di uno solo.

3) Piante monumentali: manca un filtro rapido nel censimento e nella mappa per vedere solo monumentali, tutelate o dedicate (oggi si passa dalla pagina Scadenzario VTA), e l'esportazione in Excel del censimento non ha le colonne relative. Non esiste una procedura guidata specifica per la tutela (richiesta di abbattimento, comunicazione all'ente competente, controlli piu' frequenti): i vincoli con documento allegato ci sono, ma vanno collegati a mano.

**Dove l'ho verificato**
- `database/migrations/2026_08_10_190006_create_trees_tables.php (tabella trees: is_monumental, monumental_ref, is_protected, protection_ref, is_dedicated, dedicated_to, planted_on, removed_on)`
- `app/Models/Tree.php (righe 23-46: i campi sono scrivibili, dedicated_to letto come elenco di dati)`
- `app/Http/Controllers/Api/V1/VtaDashboardController.php (metodo tutelati(), riga 74: "Alberi monumentali, soggetti a tutela o dedicati (L. 10/2013)")`
- `app/Http/Controllers/Api/V1/TreeBalanceController.php (calcolo del bilancio arboreo tra due date: consistenza iniziale e finale, piantati, abbattuti, variazione, dettaglio per specie, posti liberi e sostituzioni)`

**Secondo controllo:** Ho aperto tutti i file indicati e ho anche eseguito i due test citati (php artisan test --filter='TreeBalanceTest|ProtectedTreesTest': 8 test, 49 verifiche, tutti verdi). Non sono riuscito a smentire il collega: le prove reggono e lo stato "parziale" resta corretto. Correggo pero' la descrizione, perche' in tre punti il collega dice piu' di quanto il programma faccia davvero.

CIO' CHE HO CONFERMATO
- Catasto alberi: confermato. La tabella trees (migrazione 2026_08_10_190006) ha is_monumental, monumental_ref, is_protected, protection_ref, is_dedicated, dedicated_to (jsonb), planted_on, removed_on; il modello app/Models/Tree.php li rende scrivibili (righe 19-47) e legge dedicated_to come elenco di dati. Pagine /censimento, /censimento/{id} e /vta esistono in routes/web.php (righe 65-79), la stampa PDF in resources/views/pdf/asset.blade.php e l'export CSV in ExportController.
- Monumentali e tutelati: confermato. Caselle e riferimenti in TreeVtaPanel.vue (righe 405-421, con l'esempio AMI/03/000123), elenco in VtaDashboardController::tutelati() (riga 74 e seguenti), rotte api.php righe 83-85, tabella in Vta.vue righe 334-370, conteggio in StatsController righe 73-91, dicitura "Albero 

### Impianti di irrigazione
**Stato:** parziale — **lavoro per colmarlo:** medio

**Cosa abbiamo**

Esiste un modulo Irrigazione vero e usabile: dal menu si apre la pagina /irrigazione, si crea un impianto per area e si compila la sua scheda (nome, tipo di impianto - aspersione, ala gocciolante, subirrigazione, idranti, misto -, fonte idrica - acquedotto, pozzo, cisterna, corso d'acqua, altro -, stato di esercizio, apertura e chiusura della stagione, note). I settori irrigati ci sono e si gestiscono bene: elenco ordinato con nome, descrizione, portata in litri al minuto, minuti per ciclo e cicli a settimana, con salvataggio protetto dalle modifiche contemporanee di altri utenti. Dalla scheda si crea con un pulsante un ordine di lavoro di manutenzione in bozza, che finisce nella pagina Lavori e in agenda. In piu' del concorrente abbiamo le letture del contatore con il calcolo del consumo tra una lettura e l'altra e il confronto con il consumo stimato dal programma dei settori, e il promemoria nel cruscotto Oggi degli impianti da invernare o da riaprire. Sul versante censimento il catalogo prevede gia' i singoli oggetti dell'impianto (centralina, irrigatore, valvola, adduttore, allaccio, pozzetto, idrante, area impianto di irrigazione): si possono disegnare sulla mappa, fotografare e descrivere con campi personalizzati liberi definiti dall'amministratore.

**Cosa manca**

1) Centralina: c'e' un solo campo di testo libero "Centralina (marca e modello)". Mancano i campi distinti richiesti dal concorrente: marca separata dal modello, alloggiamento, tipo di controllo, alimentazione, presenza di pompa. Mancano anche piu' centraline per impianto. 2) Erogatori e pozzetti non esistono come parti dell'impianto: si possono censire come elementi sulla mappa, ma non sono collegati ne' all'impianto ne' al settore, quindi non si sa quanti irrigatori o pozzetti servono un settore. 3) I settori non hanno geometria: non si disegna sulla mappa l'area irrigata da ciascun settore. 4) Lavori programmati: si genera l'ordine di manutenzione, ma nella scheda dell'impianto non compare l'elenco dei lavori fatti o programmati su quell'impianto (il collegamento resta salvato nell'ordine ma non e' interrogabile da nessuna pagina), e non c'e' un piano di manutenzione ricorrente. 5) Documenti e immagini: sull'impianto di irrigazione non si allega nulla. Le foto si caricano solo sugli elementi censiti e i documenti solo su vincoli e analisi strumentali. 6) L'irrigazione non e' presente nell'app di campo /operatore, quindi non si lavora sull'impianto dal telefono in cantiere.

**Dove l'ho verificato**
- `database/migrations/2026_08_13_220000_create_irrigation_tables.php (tabelle irrigation_systems e irrigation_sectors: nome, tipo, fonte idrica, controller_model, stato, stagione, note; settori con portata l/min, minuti per ciclo, cicli a settimana)`
- `database/migrations/2026_08_13_230000_create_irrigation_meter_readings.php (letture del contatore idrico, una al giorno per impianto)`
- `app/Models/IrrigationSystem.php (costanti TYPES, WATER_SOURCES, STATUSES; relazione sectors)`
- `app/Models/IrrigationSector.php`

**Secondo controllo:** Ho aperto tutti i file citati. Le prove esistono davvero (nessuna e' inventata), ma il racconto che le accompagna promette molto piu' di quello che il codice fa. Lo stato "parziale" resta corretto, pero' e' un "parziale" basso: delle sei voci chieste dal concorrente ne copriamo bene una sola.

Cosa ho verificato che c'e' davvero
- La pagina /irrigazione esiste (routes/web.php righe 107-108, permesso areas.view) con voce di menu (resources/js/Layouts/AppLayout.vue) e scheda impianto completa di nome, tipo (aspersione, ala gocciolante, subirrigazione, idranti, misto), fonte idrica, stato, apertura/chiusura stagione e note: confermato in database/migrations/2026_08_13_220000_create_irrigation_tables.php e in resources/js/Pages/Irrigazione.vue (680 righe, etichette alle righe 12-30).
- I settori ci sono e sono fatti bene: nome, descrizione, portata l/min, minuti per ciclo, cicli a settimana, ordinamento, sostituzione integrale con blocco pessimistico e controllo di versione (metodo syncSectors in app/Http/Controllers/Api/V1/IrrigationController.php).
- Letture del contatore e consumo confrontato con la stima dei settori: confermato (metodo readings, migrazione 2026_08_13_230000, una le

### Arredo urbano e tutti gli elementi del verde
**Stato:** parziale — **lavoro per colmarlo:** poco

**Cosa abbiamo**

Il nostro programma non è fatto solo per gli alberi: l'albero è uno dei 387 tipi di oggetto del catalogo, e la parte più grossa del catalogo (313 codici su 387) è proprio "Arredo urbano". Ci sono panchine (25 varianti per materiale, a punto e a linea), cestini e cassonetti (5), pavimentazioni (28) e pavimentazioni antitrauma (6), manufatti d'arredo compresi i giochi (50), recinzioni (11), cancelli (7), muri (4), cordoli (10), fontanelle (4), idranti (2), pozzetti (9), scale e rampe (13), canaline di scolo, elementi di irrigazione e tutti gli impianti sportivi. Sul verde non arboreo ci sono prati, aiuole (comprese quelle pensili e i muri verdi), siepi, filari, cespugli e macchie. Tutti questi elementi usano lo stesso identico impianto degli alberi: una sola tabella "assets" che accetta punti, linee e superfici, con metri e metri quadrati calcolati in automatico dal database (una siepe restituisce i metri lineari, un prato i metri quadrati e il perimetro). Da qui in avanti funziona tutto per qualsiasi elemento: l'elenco del censimento con filtri e ricerca, la scheda del singolo elemento con foto, documenti, cronologia versioni ed etichette fisiche (QR/NFC), la modifica della scheda, la mappa che disegna anche le superfici e le linee colorandole per categoria, gli ordini di lavoro, le ispezioni su modello (nella dimostrazione c'è già il controllo funzionale delle aree gioco secondo la norma UNI EN 1176-7), le segnalazioni, le statistiche per tipologia, l'esportazione e l'importazione CAM di tutti i livelli e il portale pubblico, che apre correttamente anche la scheda di un'aiuola o di una siepe.

**Cosa manca**

Manca il modo di censire dall'interfaccia gli elementi che non sono un punto. Sia la mappa del gestionale sia l'app di campo propongono solo i tipi "a punto": va benissimo per panchine, cestini, fontanelle, giochi e idranti, ma una siepe, un prato, un'aiuola o una pavimentazione oggi possono entrare soltanto importando un file (GeoJSON o consegna CAM) o passando dalle interfacce di programmazione. Non c'è uno strumento per disegnare una linea o un'area sulla mappa e salvarla come elemento, e non c'è nemmeno il modo di correggere il contorno di un elemento già censito (il server lo permetterebbe, ma nessuna pagina invia la geometria). Manca poi una dotazione di partenza di campi descrittivi per l'arredo: il catalogo si installa senza campi, e il programma rifiuta gli attributi non previsti, quindi finché l'amministratore non li crea a mano tipo per tipo dalla pagina Catalogo, una panchina non ha campi come materiale, stato di conservazione, anno di posa o produttore. Infine l'elenco stati della scheda è pensato sull'albero (attivo, morto in piedi, ceppaia, dismesso, abbattuto): per un manufatto restano utilizzabili solo "attivo" e "dismesso", e non esiste una scala di condizione (buono/mediocre/scadente) tipica dell'arredo. Restano naturalmente riservati agli alberi i moduli specialistici (VTA, stima CO2, bilancio arboreo), ma questo è corretto.

**Dove l'ho verificato**
- `database/seeders/data/catalogo_md_v21.csv (387 codici: 313 di "Arredo urbano", 43 "Vegetazione", 22 "Fruizione e gestione", 9 "Fattori ambientali"; es. P219012 panchina in legno, P224282 cestino raccolta differenziata, L103107 siepe, S102103 aiuola erbacee annuali, S205xxx pavimentazione, S327552 area gioco)`
- `app/Services/Catalog/CatalogInstaller.php (costante MAIN_TYPE_NAMES con '2' => 'Arredo urbano'; metodo install() installa tutti i 387 codici per ogni committente)`
- `database/migrations/2026_08_10_190004_create_assets_tables.php (tabella assets con colonna geom di tipo generico geometry(Geometry,4326) e colonne calcolate computed_area_sqm, computed_length_m, computed_perimeter_m)`
- `app/Models/Asset.php (modello unico per qualsiasi elemento, collegato a objectType)`

**Secondo controllo:** Confermato "parziale", ma nella parte bassa della fascia: le prove del collega reggono tutte, la descrizione però promette più di quanto le pagine facciano.

VERIFICATO E VERO. catalogo_md_v21.csv: 387 codici esatti, 313 Arredo urbano + 43 Vegetazione + 22 Fruizione + 9 Fattori; ricontati anche i sottotipi (panchine 25, cestini 5, pavimentazioni 28, antitrauma 6, manufatti 50, recinzioni 11, cancelli 7, muri 4, cordoli 10, fontanelle 4, idranti 2, pozzetti 9, scale/rampe 13) e tutti i codici di esempio esistono. Tabella unica assets con geometry(Geometry,4326) e le tre colonne GENERATED (area/lunghezza/perimetro in EPSG 7791). Asset, CatalogObjectType::allowedGeometryTypes(), AssetController, StoreAssetRequest: completamente generici. CamExporter::LAYERS copre i 10 livelli; esistono i test sulla panchina P214256 in P2, sull'area gioco S327552 in S3 e test_la_scheda_si_apre_anche_su_aiuole_e_siepi. Portale con ST_PointOnSurface, mattonelle, ordini di lavoro e statistiche per tipologia: generici.

GAP CONFERMATI, DUE NON DICHIARATI DAL COLLEGA.
1) Nessuna pagina disegna linee o superfici: Mappa.vue:382 e Operatore.vue:725 filtrano entrambe allowed_geometry === 'P', e la creazione da 

### Import di dati da shapefile
**Stato:** parziale — **lavoro per colmarlo:** medio

**Cosa abbiamo**

Il caricamento di un censimento da shapefile esiste ed e' usabile da un utente normale: nella pagina Censimento c'e' il pulsante di import, si sceglie il file, l'area di destinazione, si fa prima l'analisi a vuoto (mostra quanti elementi sono importabili e l'elenco degli scarti) e solo dopo si conferma l'import vero, che e' tutto-o-niente. Il file va caricato come .zip contenente lo shapefile completo (.shp, .shx, .dbf e obbligatoriamente il .prj); il programma lo converte e lo riproietta da solo in coordinate WGS84 usando GDAL, che e' gia' installato sul server. Vengono riempiti geometria, codice censimento, stato, note, date e la scheda albero (genere, specie, varieta', altezza, diametro tronco, diametro chioma), e i campi standard del Modello Dati mancanti vengono creati al volo. C'e' anche la protezione contro archivi zip gonfiati, contro i doppioni nel file e contro codici gia' presenti in banca dati. Tutto questo e' coperto da test, compreso un test che esporta uno shapefile e lo reimporta.

**Cosa manca**

Manca l'import di uno shapefile QUALSIASI. Il canale shapefile funziona solo se le colonne del file hanno esattamente i nomi del formato ministeriale CAM (Modello Dati v2.1): CODICE, OBJ_ID, GENERE, SPECIE, H_m e cosi' via. Se un Comune ci consegna il censimento fatto con un altro programma, con colonne chiamate per esempio ID_PIANTA, SPECIE_ALB o ALTEZZA, e senza la colonna CODICE con i codici del nostro catalogo, tutte le righe vengono scartate. Manca cioe' il passaggio che nei concorrenti fa la differenza: leggere le colonne del file e far scegliere all'utente a quale nostro campo corrisponde ciascuna colonna (con la possibilita' di salvare la corrispondenza per riusarla). Manca anche: la scelta di un tipo di elemento predefinito quando il file non ha il codice (esiste nel codice per il GeoJSON ma non e' esposta nell'interfaccia, e non esiste affatto per lo shapefile); l'anteprima delle prime righe prima di importare; l'aggiornamento di elementi gia' esistenti (oggi un codice gia' presente viene solo scartato, non aggiornato); il tetto di 5.000 elementi per volta, basso per un censimento comunale intero; e gli altri formati diffusi (GeoPackage, KML, CSV con coordinate, DXF).

**Dove l'ho verificato**
- `app/Services/Import/CamImporter.php (metodo toGeoJson: scompatta lo .zip, accetta solo .shp/.shx/.dbf/.prj/.cpg, pretende il .prj e converte con ogr2ogr in WGS84; metodo run: legge i campi CODICE, OBJ_ID, GENERE, SPECIE, VARIETA, H_m, LARG_m, DIAM_TRONC, DIAM_CHIOM, STATO, DATA_RIL, DATA_INI, DATA_FINE, NOTE)`
- `app/Services/Import/CamImporter.php riga 215: se CODICE e' vuoto o non e' nel catalogo la riga viene scartata ("CODICE ... assente o non presente nel catalogo")`
- `app/Services/Import/CamImporter.php riga 29: costante MAX_FEATURES = 5000 (tetto di elementi per singolo import)`
- `app/Http/Controllers/Api/V1/ImportController.php (metodo cam: accetta un file fino a 50 MB e chiama CamImporter; metodo geojson: fa json_decode del file, quindi non accetta zip/shapefile)`

**Secondo controllo:** Confermo "parziale", ma la descrizione del collega e' piu' generosa del codice. Cosa esiste davvero (verificato): CamImporter::toGeoJson (app/Services/Import/CamImporter.php righe 54-175) scompatta lo .zip, accetta solo shp/shx/dbf/prj/cpg con basename() (protezione zip-slip), pretende il .prj (righe 136-140), blocca gli archivi che superano 200 MB da estratti (righe 91-99) e converte con ogr2ogr -t_srs EPSG:4326; run() legge CODICE, OBJ_ID, GENERE, SPECIE, VARIETA, H_m, LARG_m, DIAM_TRONC, DIAM_CHIOM, STATO, DATA_RIL, DATA_INI, DATA_FINE, NOTE, PT; import transazionale con dry-run, doppioni nel file (riga 254) e gia' in banca dati (righe 326-343) intercettati; MAX_FEATURES=5000 alla riga 29. Confermati anche ImportController::cam (50 MB), routes/api.php 57-58, CamExporter::ogr2ogrAvailable() righe 93-105, gdal-bin in deploy/provision.sh, e il test roundtrip shapefile reale in tests/Feature/CamImportTest.php:126.

Il punto che decide la domanda posta (shapefile generici o solo formato nostro/CAM): SOLO CAM. CamImporter.php riga 215 scarta ogni riga il cui CODICE non sia gia' nel catalogo del tenant; non c'e' mappatura colonne ne' tipo predefinito su quel percorso. L'unica scappatoi

### Esportazioni e stampe
**Stato:** parziale — **lavoro per colmarlo:** medio

**Cosa abbiamo**

Buona parte c'e' gia' ed e' usabile con un clic dalle pagine del programma.

ESPORTAZIONI DI DATI
- Shapefile (SHP): c'e' davvero. Dalla pagina Censimento si sceglie il livello (alberi, siepi, aree, arredi, ecc.) e si scarica uno zip con lo shapefile riproiettato nel sistema di coordinate italiano. Serve che sul server sia installato GDAL: se manca, il programma lo dice con un messaggio chiaro e propone il formato alternativo.
- GeoJSON: stesso pulsante, formato alternativo.
- Consegna completa: un unico zip con tutti i livelli insieme, le foto collegate e un foglio riepilogativo dei conteggi.
- Elenco per Excel: il pulsante "Esporta CSV" nel Censimento scarica l'elenco degli elementi rispettando i filtri che si stanno guardando a video (committente, area, localita', tipo, stato, ricerca). E' curato per l'Excel italiano: punto e virgola, accenti corretti, decimali con la virgola, 19 colonne fra cui specie, altezza, diametro, superficie, data di abbattimento.
- Rendiconto lavori: la pagina dei lavori ha il suo "Esporta CSV" con ore, quantita' e importi per periodo e committente.

STAMPE PDF (tutte con un pulsante nella relativa pagina)
- Scheda dell'elemento censito: vale per qualsiasi oggetto a catalogo, quindi anche per un gioco (il catalogo ha "gioco singolo" e "gioco complesso") e ne stampa tipo, collocazione, stato, misure, foto e i campi personalizzati di quel tipo.
- Verbale di ispezione con la checklist compilata e le non conformita'.
- Perizia di stabilita' dell'albero, completa di mappa, foto e analisi strumentali.
- Preventivo con intestazione, voci e totali.
- Registro dei trattamenti fitosanitari.
- Cartellino con codice QR da applicare in campo.

**Cosa manca**

Quattro cose, di cui due pesano.

1. Il file XLS/XLSX vero non c'e'. Esportiamo in CSV, che l'Excel apre correttamente, ma non e' un foglio di calcolo vero e proprio: niente colonne gia' larghe, niente intestazioni in grassetto, niente piu' fogli in un solo file, niente formule o totali. Nel progetto non c'e' nessuna libreria per scrivere file Excel.

2. Il Gantt non esiste. Nella pagina dei lavori c'e' un'agenda settimanale a griglia (squadre in riga, giorni in colonna, con la linea del giorno corrente), utile ma diversa: non ci sono le barre che attraversano il tempo da data inizio a data fine, non c'e' il confronto fra programmato e realizzato e non si vedono piu' settimane o mesi insieme. Era previsto fra le funzioni obbligatorie ma non e' stato realizzato.

3. La scheda di localita' in PDF non c'e'. Le localita' si gestiscono (pagina Territorio) ma non si stampano: manca la stampa riepilogativa di una localita' con il suo elenco di elementi, le quantita' e le superfici.

4. La reportistica non si stampa. Il rendiconto lavori esce solo in CSV e la pagina Statistiche non ha alcun pulsante di esportazione o stampa: niente PDF dei report, niente CSV dei numeri statistici. Manca anche la stampa in PDF del singolo ordine di lavoro.

Nota minore: lo shapefile si esporta per livello del tracciato CAM, non della selezione filtrata a video (per quella c'e' solo il CSV).

**Dove l'ho verificato**
- `app/Http/Controllers/Api/V1/ExportController.php (metodi cam, camDelivery, assetsCsv)`
- `app/Services/Export/CamExporter.php (toShapefileZip, shapefileParts, ogr2ogrAvailable, LAYERS P1/L1/S1/P2/L2/S2/P3/L3/S3/S4)`
- `app/Services/Export/CamDeliveryBuilder.php (zip multi-layer con foto e manifest.json)`
- `app/Services/Pdf/PdfRenderer.php (motore di stampa dompdf)`

**Secondo controllo:** Stato "parziale" confermato: le prove reggono tutte, ma la descrizione va ridimensionata. VERIFICATO PRESENTE: export Shapefile reale (CamExporter::toShapefileZip/shapefileParts/ogr2ogrAvailable, LAYERS P1/L1/S1/P2/L2/S2/P3/L3/S3/S4, riproiezione EPSG del tenant, messaggio chiaro se manca GDAL con ripiego su GeoJSON); GeoJSON; consegna completa multi-layer con cartella FOTO/, manifest.json e LEGGIMI.txt (CamDeliveryBuilder); CSV del censimento (routes/api.php riga 71) con punto e virgola, BOM UTF-8, decimali con virgola e 19 colonne (intestazione contata); CSV del rendiconto lavori (WorkReport.vue righe 99-126); sei stampe PDF via dompdf tutte cablate a un pulsante: scheda elemento censito, verbale di ispezione, perizia di stabilita', preventivo, registro fitosanitari, cartellino QR. Route 69-71, 80, 117, 129, 142-143 esistono alle righe esatte indicate. I 38 test citati passano (345 asserzioni) e GDAL 3.8.4 e' installato, quindi anche i test shapefile hanno girato davvero. VERIFICATO ASSENTE: (1) nessun export XLS/XLSX - composer.json non ha PhpSpreadsheet/Maatwebsite ne' package.json alcuna libreria JS; c'e' solo CSV, che Excel apre ma non e' un file XLS; (2) nessun Gantt - la pa

### Utenti, ruoli e sola visualizzazione
**Stato:** parziale — **lavoro per colmarlo:** medio

**Cosa abbiamo**

C'e' un impianto di utenti e ruoli funzionante e gia' usabile. L'amministratore ha la pagina "Utenti" dove crea un account, sceglie il ruolo, riceve una password provvisoria da consegnare, modifica il ruolo, disattiva o riattiva la persona (la storia resta) e genera una nuova password. Non esiste alcun limite al numero di utenti: nessun conteggio o licenza nel codice. I ruoli disponibili sono quattro: amministratore, tecnico, operatore e cliente. Dietro c'e' un vero sistema di permessi (16 permessi tipo "vedi elementi", "modifica aree", "gestisci lavori") che governa sia le voci di menu sia le chiamate al server, ed e' separato per organizzazione. Il profilo di sola visualizzazione esiste ed e' quello del committente: l'utente "cliente" viene agganciato a un nominativo dell'anagrafica e dal suo portale vede soltanto le aree, gli elementi, i lavori completati e le segnalazioni del proprio territorio, senza dati economici e senza poter modificare nulla; se prova a leggere il censimento generale riceve un rifiuto (verificato dai test). Esistono anche i gruppi di lavoro (le squadre, con capo e membri) e servono davvero a limitare cosa si vede: nell'app di campo l'operatore riceve solo gli ordini assegnati a lui o alla sua squadra.

**Cosa manca**

Mancano tre cose rispetto al concorrente. Primo: i ruoli sono fissi e non modificabili. Non c'e' nessuna schermata (ne' funzione lato server) per creare un ruolo nuovo o per decidere permesso per permesso cosa puo' fare, quindi non si possono ritagliare profili come "appaltatore", "collaboratore" o "professionista esterno": bisogna far rientrare tutti in uno dei quattro ruoli esistenti. Manca anche un profilo interno di sola lettura: tecnico e operatore possono comunque scrivere, e l'unico profilo che guarda e basta e' il cliente, che pero' entra solo dal portale e non vede il gestionale. Secondo: la limitazione "alle proprie aree di competenza" vale solo per il cliente (tutto il territorio del suo committente, non un sottoinsieme scelto). Per il personale interno non esiste: un tecnico o un operatore vede tutto il censimento dell'organizzazione, e il filtro per committente o area e' solo un filtro di ricerca che l'utente puo' togliere quando vuole. Terzo: le squadre non hanno una pagina di gestione. Si possono leggere e assegnare a un ordine di lavoro, ma per crearle o cambiarne i membri bisogna passare dalle chiamate tecniche; inoltre il ruolo del membro dentro la squadra (capo, operatore, autista, esterno) e' previsto nella base dati ma non viene mai impostato. Le squadre, infine, non portano permessi: incidono solo su quali lavori arrivano sul telefono.

**Dove l'ho verificato**
- `database/migrations/0001_01_01_000000_create_users_table.php (tabella users: user_type internal/client_portal/public/api, is_active, nessun tetto al numero di utenti)`
- `database/migrations/2026_08_10_190001_create_permission_tables.php (tabelle roles, permissions, role_has_permissions, model_has_roles con tenant_id)`
- `app/Services/Tenancy/TenantProvisioner.php (costanti PERMISSIONS con 16 permessi e ROLES con i 4 ruoli: amministratore, tecnico, operatore, cliente)`
- `app/Http/Controllers/Api/V1/UserAdminController.php (metodi index, store, update, resetPassword; middleware can:users.manage; Rule::in(array_keys(TenantProvisioner::ROLES)) limita la scelta ai 4 ruoli fissi; guardLastAdministrator)`

**Secondo controllo:** Ho provato a smentire il collega ma lo stato "parziale" regge: non si puo' alzare (mancano pezzi veri) e non si puo' abbassare ad "assente" (l'impianto c'e', funziona ed e' coperto da test: `php artisan test --filter="UserAdminTest|RbacTest|ClientPortalTest|PortalRequestTest"` -> 22 test, 146 asserzioni, tutti verdi). La sua descrizione pero' e' piu' generosa di quanto il codice permetta, e su tre punti va corretta.

CIO' CHE HO CONFERMATO
- Utenti illimitati: nessun conteggio, tetto o licenza. Cercato in tutto app/, config/, database/: l'unica occorrenza di "licen" e' un commento in /home/user/webgis-censimento/config/portal.php sulle mappe di sfondo. Vero.
- Pagina Utenti completa: /home/user/webgis-censimento/resources/js/Pages/Utenti.vue crea l'account, mostra la password provvisoria una sola volta (riga 90-98), modifica ruolo, attiva/disattiva (riga 139), "Nuova password" (riga 155, 328). Rotta /utenti protetta da can:users.manage (routes/web.php riga 113).
- 16 permessi contati uno a uno in /home/user/webgis-censimento/app/Services/Tenancy/TenantProvisioner.php righe 16-24, ruoli per tenant (colonna tenant_id nelle tabelle roles/model_has_roles). Vero.
- Guardie serie in User

## GIA' PRESENTI (2)

### Riprogrammazione automatica dei ricontrolli
**Stato:** presente — **lavoro per colmarlo:** poco

**Cosa abbiamo**

La funzione c'e' e funziona gia' oggi. Quando il tecnico salva una scheda VTA e lascia vuoto il campo "Prossimo controllo", il programma calcola da solo la data della prossima verifica sommando alla data del sopralluogo un numero di mesi che dipende dalla classe di propensione al cedimento: classe A 60 mesi (5 anni), B 36 mesi (3 anni), C 24 mesi (2 anni), C/D 12 mesi (1 anno); la classe D non riceve nessuna data perche' l'albero e' da abbattere. Il campo nella scheda dice esplicitamente "vuoto = automatico dalla classe", quindi il comportamento e' chiaro a chi compila.

Se il tecnico preferisce, puo' scrivere una data a mano e quella resta: il calcolo automatico non la sovrascrive mai. Se in un secondo momento si corregge la scheda e si peggiora la classe (per esempio da C a C/D), svuotando il campo la scadenza si accorcia da sola; una correzione che non tocca il campo lascia la data com'e'. Il programma rifiuta inoltre una data di ricontrollo precedente o uguale al giorno del sopralluogo, spiegandone il motivo in italiano.

Le date cosi' prodotte non restano ferme sulla scheda: alimentano il cruscotto VTA (pagina "VTA" con gli elenchi "Ricontrolli scaduti" e "In scadenza nei prossimi 30 giorni", ognuno con il pulsante "Valuta" per aprire subito la nuova scheda), la email riepilogativa giornaliera (ricontrolli scaduti o dovuti entro 30 giorni), la perizia e la scheda albero in PDF ("Prossima verifica entro") e, se il committente lo pubblica, la cronologia sul portale pubblico. Gli alberi abbattuti o usciti dal censimento sono esclusi dagli elenchi. Tutto questo e' coperto da test automatici.

Gli intervalli sono gia' predisposti per essere diversi da un committente all'altro: se nelle impostazioni dell'organizzazione e' presente la voce "vta_recheck_months", i suoi valori sostituiscono quelli predefiniti classe per classe.

**Cosa manca**

Manca la schermata per cambiare gli intervalli. Oggi i mesi per ogni classe sono scritti nel programma e la possibilita' di personalizzarli per singolo committente esiste solo "dietro le quinte": il programma legge la voce vta_recheck_months nelle impostazioni dell'organizzazione, ma nessuna pagina dell'interfaccia permette di scriverla (le pagine Utenti e Territorio salvano altre impostazioni, non questa). In pratica, per passare per esempio la classe B da 36 a 24 mesi serve oggi un intervento tecnico sul database.

Altre due mancanze minori: la scadenza proposta non si vede a video mentre si compila (compare solo dopo il salvataggio, quindi non e' una vera "proposta da confermare"); e i ricontrolli VTA non compaiono nel cruscotto "Oggi" insieme alle altre scadenze (controlli ricorrenti, segnalazioni, patentini), dove sarebbero comodi. Infine il programma propone la data ma non crea da solo un ordine di lavoro o un appuntamento in agenda per eseguirla.

**Dove l'ho verificato**
- `app/Models/TreeAssessment.php — costante DEFAULT_RECHECK_MONTHS (riga 18): A=60 mesi, B=36, C=24, C/D=12, D=nessun ricontrollo`
- `app/Http/Controllers/Api/V1/TreeAssessmentController.php — metodo store(), righe 62-68: se il tecnico lascia vuota la data del prossimo controllo, la calcola sommando i mesi della classe alla data del sopralluogo`
- `app/Http/Controllers/Api/V1/TreeAssessmentController.php — metodo update(), righe 124-134: correggendo la classe e svuotando il campo, la scadenza viene ricalcolata (una classe peggiorata accorcia la data)`
- `app/Http/Controllers/Api/V1/TreeAssessmentController.php — metodo recheckMonths(), righe 241-249: i mesi predefiniti possono essere sostituiti per singolo committente con la voce 'vta_recheck_months' nelle impostazioni dell'organizzazione`

**Secondo controllo:** Verifica confermata: la funzione c'e' davvero e ho controllato ogni prova, eseguendo il programma e non solo leggendo il codice.

COSA HO TROVATO NEL CODICE
- app/Models/TreeAssessment.php, riga 18: gli intervalli sono scritti esattamente come dichiarato (A=60 mesi, B=36, C=24, C/D=12, D nessun ricontrollo).
- app/Http/Controllers/Api/V1/TreeAssessmentController.php, righe 63-69 (nuova scheda) e 128-135 (correzione): se il campo "Prossimo controllo" e' vuoto, la data viene calcolata sommando i mesi della classe alla data del sopralluogo; se il tecnico scrive una data a mano, non viene mai sovrascritta.
- Righe 144-149 e riga 206: la data precedente o uguale al sopralluogo viene rifiutata.
- Righe 241-249: gli intervalli si possono cambiare committente per committente con la voce vta_recheck_months.
- resources/js/Components/TreeVtaPanel.vue, riga 529: l'etichetta dice davvero "Prossimo controllo (vuoto = automatico dalla classe)".
- Colonna next_check_due e indice per lo scadenzario presenti nella migrazione.
- La data alimenta davvero il cruscotto VTA (VtaDashboardController + Vta.vue con i due elenchi e il pulsante "Valuta"), la email delle 6:30 (DailyDigest riga 113, programmata

### Analisi strumentali sugli alberi
**Stato:** presente — **lavoro per colmarlo:** poco

**Cosa abbiamo**

La funzione c'e' gia' ed e' utilizzabile davvero da un utente, dall'inizio alla fine.

Nella scheda di un albero, sotto ogni valutazione di stabilita' (VTA), c'e' il pulsante "Analisi strumentali" con il numero di analisi gia' registrate. Aprendolo si vede la tabella delle analisi e, per chi ha il permesso di modifica, il pulsante "+ Aggiungi analisi".

Per ogni analisi si registra:
- lo strumento usato, scelto da un elenco: Resistografo, Tomografo sonico, Tomografo elettrico, Prova di trazione, Dendrodensimetro, Altro strumento (ci sono quindi entrambi gli strumenti citati dal concorrente);
- il modello dello strumento, la data della misura e l'altezza sul tronco a cui e' stata fatta;
- le misure lette sullo strumento, come coppie libere "voce / valore" (per esempio "residuo sano" = "62%"), con la possibilita' di aggiungerne quante se ne vogliono;
- le note con l'esito a parole;
- il referto in PDF, caricato dal modulo stesso (fino a 20 MB), che diventa un documento scaricabile collegato all'albero.

Le analisi finiscono anche nella perizia di stabilita' in PDF, in un capitolo dedicato "Analisi strumentali" con strumento, data, altezza di misura, tabella delle misure ed esito; se una valutazione ha analisi collegate, il testo della perizia dice da solo che l'indagine visiva e' stata "integrata da indagini strumentali".

Tutto e' protetto dai permessi (vedere, modificare, cancellare) e separato per committente, ed e' coperto da test automatici che verificano anche il caso in cui un altro committente prova a scaricare un referto non suo.

**Cosa manca**

Restano tre limiti piccoli, nessuno dei quali impedisce di usare la funzione.

1. Un'analisi gia' registrata non si puo' correggere: non esiste una funzione di modifica. Per cambiare un dato sbagliato bisognerebbe cancellare l'analisi e reinserirla.

2. La cancellazione funziona nel programma dietro le quinte (ed e' testata), ma nella pagina non c'e' il pulsante per farla: chi usa il programma dal browser non ha modo di eliminare un'analisi sbagliata.

3. Come referto si accetta solo il PDF. I tomografi sonici spesso producono un'immagine della sezione del tronco (PNG o JPG): oggi andrebbe prima convertita in PDF. Inoltre si puo' allegare un solo file per analisi, non piu' documenti.

Da segnalare, ma probabilmente voluto: le analisi strumentali non si registrano dall'app di campo dell'operatore (si inseriscono dall'ufficio) e non compaiono nel portale pubblico dei Comuni, dove esce solo il fatto che e' stata fatta una "valutazione strumentale di stabilita'".

**Dove l'ho verificato**
- `database/migrations/2026_08_10_190006_create_trees_tables.php (righe 75-90: tabella instrumental_analyses, legata a tree_assessments, con instrument_type fra resistograph, sonic_tomograph, electric_tomograph, pull_test, dendro_densimeter, other; colonne instrument_model, measured_at, measurement_height_cm, measures jsonb, document_id, notes)`
- `app/Models/InstrumentalAnalysis.php (relazioni assessment() verso TreeAssessment e document() verso Document)`
- `app/Models/TreeAssessment.php (relazione instrumentalAnalyses())`
- `app/Http/Controllers/Api/V1/InstrumentalAnalysisController.php (metodi index, store con allegato PDF, destroy che cancella anche il referto)`

**Secondo controllo:** Ho controllato tutte le prove indicate e ho provato a smentirle: reggono. La tabella instrumental_analyses esiste davvero (migrazione, righe 75-90) e prevede sia il tomografo sonico sia il dendrodensimetro, oltre a resistografo, tomografo elettrico, prova di trazione e altro. Il modello, le relazioni con la valutazione e con il documento, le tre rotte API e i permessi (vedere / modificare / cancellare) sono al loro posto, con il caricamento del referto limitato ai soli PDF fino a 20 MB.

Nella scheda dell'albero il pannello c'e' ed e' completo: pulsante "Analisi strumentali" con il numero di analisi gia' registrate, tabella delle analisi visibile a tutti, e pulsante "+ Aggiungi analisi" solo per chi puo' modificare. Il modulo permette di scegliere lo strumento, scrivere modello, data e altezza di misura, aggiungere quante coppie voce/valore si vogliono, scrivere le note e allegare il referto.

Ho verificato apposta i due punti in cui una funzione simile di solito si rompe. Primo: il collegamento per scaricare il referto usa un indirizzo calcolato che nella lettura dei dati sembrava mancante, ma il programma lo aggiunge sempre a partire dal codice del documento, quindi il collegamen
