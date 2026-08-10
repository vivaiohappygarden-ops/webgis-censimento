# PROPOSTA-ARCHITETTURA

**Piattaforma WebGIS professionale per la gestione del verde**
Analisi dei requisiti e proposta tecnica — versione 0.2 del 10/08/2026

> **Stato: IN ATTESA DI APPROVAZIONE.** Come richiesto dal prompt (§109), questo documento è l'unico
> deliverable di questa fase. Nessun codice applicativo è stato scritto. Lo sviluppo parte solo dopo
> approvazione esplicita di questa proposta.
>
> **Aggiornamento 10/08/2026 — decisioni ricevute dal committente**: hosting = piano economico Aruba
> (§7.2); dispositivi di campo = terminali Zebra + smartphone, con barcode/QR/NFC (§12); clienti PA
> attesi entro 6-12 mesi → export CAM anticipato alla Fase 2 (§15). Approvazione dell'architettura:
> in attesa — richiesta una spiegazione non tecnica, aggiunta come §0.

---

## Indice

0. [In parole semplici](#0-in-parole-semplici)
1. [Documenti analizzati](#1-documenti-analizzati)
2. [Riepilogo ragionato dei requisiti](#2-riepilogo-ragionato-dei-requisiti)
3. [Matrice dei requisiti](#3-matrice-dei-requisiti)
4. [Funzioni aggiuntive consigliate](#4-funzioni-aggiuntive-consigliate)
5. [Confronto tra architetture tecnologiche](#5-confronto-tra-architetture-tecnologiche)
6. [Architettura consigliata](#6-architettura-consigliata)
7. [Hosting: vincolo Aruba e cosa acquistare](#7-hosting-vincolo-aruba-e-cosa-acquistare)
8. [Schema logico preliminare dei moduli](#8-schema-logico-preliminare-dei-moduli)
9. [Modello ER preliminare](#9-modello-er-preliminare)
10. [Strategia GIS](#10-strategia-gis)
11. [Strategia offline](#11-strategia-offline)
12. [Strategia Zebra barcode/NFC/RFID](#12-strategia-zebra-barcodenfcrfid)
13. [Strategia sicurezza](#13-strategia-sicurezza)
14. [Strategia multi-tenant](#14-strategia-multi-tenant)
15. [Roadmap di sviluppo per fasi](#15-roadmap-di-sviluppo-per-fasi)
16. [Rischi tecnici e mitigazioni](#16-rischi-tecnici-e-mitigazioni)
17. [Domande indispensabili prima di iniziare](#17-domande-indispensabili-prima-di-iniziare)

---

## 0. In parole semplici

Sintesi per chi non mastica i tecnicismi.

**Cosa costruiamo.** Tre pezzi che lavorano insieme:

1. **Il gestionale in ufficio** — un sito web riservato dove vedi su mappa tutto ciò che gestisci
   (alberi, prati, siepi, giochi, impianti di irrigazione), apri le schede, programmi i lavori delle
   squadre e stampi report e computi.
2. **L'app per il campo** — sul telefono o sul palmare Zebra: l'operatore vede i suoi lavori,
   inquadra il QR o avvicina il tag NFC sulla pianta e gli si apre la scheda giusta, scatta le foto,
   chiude l'intervento. Funziona anche **senza segnale**: salva tutto sul dispositivo e sincronizza
   da sola appena torna la linea.
3. **L'archivio centrale** — il database geografico: ogni oggetto ha posizione, foto, storia,
   controlli e costi. È il "catasto del verde" da cui nasce tutto il resto.

**Le tecnologie scelte, tradotte.**

- *PostgreSQL + PostGIS* = l'archivio. Database professionale gratuito; PostGIS gli insegna a
  lavorare con le mappe (es. calcola da solo i m² di un prato dal disegno del poligono).
- *Laravel* = il telaio del gestionale: fondamenta prefabbricate e collaudate da milioni di siti
  (accessi, permessi, sicurezza già pronti), su cui costruiamo solo le stanze che servono a noi.
- *PWA* = l'app di campo: si installa dal browser senza passare dagli store e sa lavorare offline.
- *Docker* = scatole preconfezionate: il software si installa identico sul tuo PC, su Aruba o su
  qualunque altro fornitore. Zero dipendenza da un fornitore specifico.
- *Object Storage S3* = l'armadio delle foto e dei documenti: paghi solo lo spazio usato, centesimi
  al GB.
- *MapLibre* = la mappa interattiva (stile Google Maps, ma open source e gratuita).

**Perché non WordPress**: è fatto per siti e blog, non per un gestionale con mappa, milioni di
oggetti e app offline — lo useremmo contro la sua natura, con più costi e più rischi.

**Quanto costa l'infrastruttura** (piano economico scelto): 0 € durante lo sviluppo → ~3-4 €/mese
quando mettiamo online la demo → ~10-20 €/mese al debutto col primo utilizzo reale → ~40-70 €/mese
solo quando arriveranno clienti/PA che richiedono garanzie di servizio elevate.

**Come procediamo**: a fasi (§15), e ogni fase consegna qualcosa di usabile: prima il censimento su
mappa, poi alberi e controlli di stabilità con l'export per i Comuni, poi l'app di campo offline,
poi lavori e rendicontazione. Ogni fase termina con una demo e con la tua approvazione.

---

## 1. Documenti analizzati

Tutti i PDF sono stati letti integralmente. Sigle usate nella matrice dei requisiti:

| Sigla | Documento | Pagine | Natura |
|---|---|---|---|
| **PR** | Prompt per Claude Code — Piattaforma WebGIS gestione del verde | 38 (109 sezioni) | Specifica funzionale committente |
| **BR1** | Brochure "GreenSpaces — La soluzione digitale più avanzata per la gestione del verde" (R3GIS) | 12 | Brochure prodotto di riferimento |
| **BR2** | "Gestione efficiente del verde — Cura e manutenzione del verde urbano e stradale" (GreenSpaces/R3GIS) | 18 | Brochure tecnica di prodotto (schede, moduli, versioni) |
| **L10** | R3GIS — "Legge 10/2013, Norme per lo sviluppo degli spazi verdi urbani" | 3 | Nota normativa (catasto alberi, bilancio arboreo, piante dedicate) |
| **MD** | "Modello dati per il censimento del verde urbano" v2.1 — 24/09/2020, Politecnico di Milano + R3GIS | 83 | **Standard nazionale di riferimento** (CAM DM 63 10/03/2020): catalogo oggetti, tracciati record, vincoli topologici, specifiche di rilievo |

Note importanti emerse dalla lettura:

- **MD è il documento vincolante per il modello dati di censimento**: definisce la codifica `TXYYZZZ`
  (geometria S/L/P + Tipo Principale + Tipo Secondario + Attributo), le 4 macro-categorie
  (1 Vegetazione, 2 Arredo Urbano, 3 Fruizione e Gestione, 4 Fattori Ambientali), ~300 codici oggetto,
  i campi obbligatori comuni (CODE_ISTAT, ZONA, AREA, OBJ_ID, TP, TS, CODICE, DATA_INI, DATA_FINE,
  DATA_AGG…), i vincoli topologici (copertura completa, no sovrapposizioni, no multipart) e il sistema
  di riferimento ufficiale **ETRF2000/RDN2008** (EPSG 6706, 7791–7794).
- **BR1/BR2 descrivono il modello funzionale di GreenSpaces** (censimento, VTA/VSA, aree gioco, lavori,
  SAL, irrigazione, app mobile offline, moduli METEO/BENEFITS/WATER/WORKS/GREEN CITY, versioni
  Standard/TREES/PLAY/Enterprise). Vanno usati **solo come riferimento funzionale**: nessuna copia di
  codice, grafica o database (PR §108).
- **L10** aggiunge tre obblighi di legge per i Comuni >15.000 abitanti: catasto alberi, **piante
  dedicate ai nuovi nati** (con stampa scheda per i genitori), **bilancio arboreo di fine mandato**
  (confronto tra due date).

---

## 2. Riepilogo ragionato dei requisiti

Il sistema richiesto è un **gestionale geografico** (non una mappa accessoria a un gestionale): un
database geografico unico del patrimonio verde, multi-tenant e multi-cliente, con quattro anime che
devono restare integrate:

1. **Censimento e catasto (il cuore).** Ogni elemento (albero, siepe, prato, arredo, gioco, componente
   irrigua, fattore ambientale) è un oggetto geografico tipizzato da un **catalogo configurabile** a
   3 livelli (Tipo Principale → Tipo Secondario → Attributo) compatibile con il Modello Dati v2.1/CAM.
   Identità a tre livelli separati: **UUID interno immutabile ≠ codice censimento ≠ codice
   cartellino/tag (NFC/QR/barcode)**. Storicizzazione sempre (DATA_INI/DATA_FINE, soft delete, stato
   ABBATTUTO, versioning schede, timeline per albero, mappa "alla data X").

2. **Controlli e sicurezza.** VTA/VSA professionale con difetti per parte della pianta, classe di
   propensione al cedimento, analisi strumentali allegabili, ricontrolli automatici a scadenza;
   ispezioni con checklist configurabili (aree gioco in primis) che a esito KO generano non conformità;
   scadenzario e dashboard delle criticità. Le decisioni professionali restano ai tecnici (l'AI, futura,
   solo suggerisce).

3. **Lavori ed economia.** Workflow ODL completo (12 stati configurabili), programmazione
   agenda/calendario/Gantt/mappa, squadre/mezzi/materiali, consuntivazione dal campo con
   inizio/fine intervento georeferenziati, foto prima/durante/dopo, firma; listini per
   cliente/contratto/anno; calcolo automatico delle quantità dalle geometrie (m² dal poligono, m dalla
   linea); capitolati, computi, preventivi, SAL, SLA, non conformità e segnalazioni.

4. **Campo e hardware.** PWA operatore con pulsanti grandi (I MIEI LAVORI / MAPPA / SCANSIONA / NFC /
   SEGNALA / FOTO / INIZIA / CHIUDI), **offline-first completo** (cartografia scaricata, censimento,
   checklist, foto, code di sync con indicatore ONLINE/OFFLINE), integrazione **Zebra**
   (DataWedge per barcode, NFC/RFID, stampa etichette ZPL), modalità operative rapide: "Scansiona e
   lavora", "Giro ispettivo", "Censimento massivo", "Associazione massiva tag".

Trasversali: RBAC granulare fino al livello record/azione, multitenancy con isolamento sicuro, audit
log immutabile, API-first versionate con OpenAPI, import/export GIS (GeoJSON, Shapefile, GeoPackage,
KML, CSV, XLSX — e **shapefile conformi CAM/MD** in entrata e uscita), portale cliente e portale
pubblico opzionale, reportistica PDF templated, backup con RPO/RTO dichiarati, GDPR, predisposizione
IoT/meteo/servizi ecosistemici con motore modulare (nessuna formula inventata).

**Divergenza gestita**: il MD impone per lo scambio dati lo shapefile 2D in RDN2008 con tracciati
record fissi; la piattaforma internamente userà un modello più ricco (PostGIS, UUID, versioning) e
tratterà il formato CAM come **profilo di import/export certificato**, non come schema interno.

---

## 3. Matrice dei requisiti

Legenda — **Tipo**: E = esplicito nei documenti; I = implicito (necessario ma non dichiarato);
M = miglioramento proposto da noi. **Priorità**: MUST (MVP), SHOULD (subito dopo), COULD (evoluzione).
Le fonti citano documento e pagina/sezione.

### 3.1 Piattaforma e GIS di base

| # | Requisito | Fonte | Modulo | Tipo | Priorità | Note |
|---|---|---|---|---|---|---|
| P01 | Database geografico unico del patrimonio; cartografia come strumento primario | PR §3; BR1 p.4; BR2 p.3 | Core GIS | E | MUST | |
| P02 | Geometrie native POINT/LINESTRING/POLYGON/MULTIPOLYGON per tutte le tipologie elencate | PR §4; MD p.6-7 | Core GIS | E | MUST | PostGIS |
| P03 | GIS Validation Engine: duplicati, sovrapposizioni, invalide, fuori perimetro, buchi, non connesse, senza coordinate; dashboard errori | PR §5; MD p.11, 19-20 | Core GIS | E | MUST | Regole MD: mutua esclusione e copertura completa dentro le aree di gestione |
| P04 | Vincolo MD: vegetazione/arredo solo dentro perimetri di Fruizione e Gestione; prati "bucati" in corrispondenza di manufatti; no multipart | MD p.11 | Core GIS | E | MUST | Configurabile per profilo cliente |
| P05 | CRS: EPSG:4326/3857 + sistemi italiani; ufficiale censimento ETRF2000/RDN2008 (EPSG 6706, 7791-7794); trasformazioni | PR §45; MD p.18, 56 | Core GIS | E | MUST | Storage 4326, misure su proiettato, export RDN2008 |
| P06 | Calcolo automatico di aree/lunghezze/perimetri dalla geometria | PR §14; MD p.11 | Core GIS | E | MUST | Campi derivati, mai editabili a mano |
| P07 | Mappa professionale multi-layer (12+ layer tematici), mappa base OSM/ortofoto/WMS/WMTS/cartografia cliente/catastale | PR §46, §49; BR2 p.16-17 | Mappa | E | MUST | |
| P08 | Mappe tematiche configurabili (stato, specie, rischio, VTA, scadenza, cliente, squadra…) con colori configurabili | PR §47; BR2 p.6 | Mappa | E | MUST | Cromie per classe di propensione al cedimento (BR2) |
| P09 | Selezione spaziale (click, rettangolo, poligono, raggio, lasso) con azioni massive (crea lavoro, esporta, assegna, stampa…) | PR §48, §86 | Mappa | E | MUST | |
| P10 | Layer temporale: stato del patrimonio a una data | PR §84; MD p.20 (DATA_INI/DATA_FINE) | Mappa | E | SHOULD | Deriva dal versioning |
| P11 | Vector tiles / clustering / indici spaziali per milioni di oggetti | PR §75-76 | Core GIS | E | MUST | ST_AsMVT + tile server |
| P12 | Import/export GIS: GeoJSON, Shapefile, GeoPackage, CSV, KML, XLSX; valutare GML/WFS/WMS/WMTS | PR §43; BR2 p.17-18 | Import/Export | E | MUST | Export XLS e SHP già in GreenSpaces |
| P13 | **Profilo import/export conforme CAM/Modello Dati v2.1** (shapefile per macro-categoria, tracciati record, codifica TXYYZZZ) | BR2 p.4-5; MD tutto | Import/Export | E | MUST | Requisito di legge per clienti PA |
| P14 | Wizard import 7 step con rilevamento CRS, mapping colonne, validazione, report errori; mai import silenzioso | PR §44 | Import/Export | E | MUST | |
| P15 | Interoperabilità via standard aperti e API documentate | BR2 p.4; PR §65 | API | E | MUST | |

### 3.2 Catalogo oggetti e censimento

| # | Requisito | Fonte | Modulo | Tipo | Priorità | Note |
|---|---|---|---|---|---|---|
| C01 | Catalogo a 3 livelli TP→TS→ATT con geometria ammessa e codice; 4 macro-categorie iniziali | PR §6; MD p.6-9 | Catalog Manager | E | MUST | Seed = catalogo MD v2.1 completo (~300 codici, TS 01-99) |
| C02 | Catalog Manager amministrabile: categorie, tipologie, attributi, liste valori, UdM, campi obbligatori, regole validazione — niente hardcoding | PR §6; BR1 p.5; BR2 p.5-6 | Catalog Manager | E | MUST | |
| C03 | Configuratore schede: campi custom (testo, numero, select, multi, checkbox, data, foto, documento, geometria, firma) per tipo oggetto | PR §103; BR1 p.5 | Catalog Manager | E | MUST | |
| C04 | Censimento multi-modalità: manuale, da mappa, GPS, import, copia, multiplo, mobile, offline, NFC, barcode/QR | PR §7 | Censimento | E | MUST | |
| C05 | UUID interno ≠ codice censimento ≠ codice cartellino/tag | PR §7 | Censimento | E | MUST | |
| C06 | Gerarchia Cliente→Contratto→Sede→Località→Area→Sottoarea→Elemento; multi-cliente e multi-sede | PR §8; BR2 p.17 (Enterprise multi-dominio) | Clienti | E | MUST | Livelli attivabili per tenant |
| C07 | Scheda località con codice, nome, superfici, classificazione (anche ISTAT), zona, stato, impresa, date, statistiche, oggetti | BR2 p.10 | Censimento | E | MUST | |
| C08 | Modalità censimento massivo: flusso + ALBERO → GPS → foto → specie → DBH → tag → salva → prossimo, tocchi minimi | PR §102 | Mobile | E | MUST | |
| C09 | Modalità di rilievo per codice (punto medio al suolo, spezzata, mediana con estremi) e foto di riferimento da catalogo | MD Allegato 1 p.34-83 | Censimento | E | SHOULD | Testo guida mostrato al rilevatore |
| C10 | Aree speciali: gestione (fittizia/limite/in attesa di censimento), assegnazione temporanea (cantiere, sponsor, concessione, inaccessibile), funzionali (gioco, cani, orti, sport, irrigazione, sgombero neve…) | MD p.32, 81-83 | Censimento | E | MUST | |
| C11 | Fattori ambientali: aree quarantena/focolai fitosanitari (anoplophora, cancro colorato), interferenze infrastrutturali (cavi tramviari), punti analisi terreno, **sinistri georiferiti** (4 tipologie) | MD p.32-33, 83; L10 p.2 | Censimento | E | SHOULD | I sinistri collegano al contenzioso/assicurazioni |
| C12 | Verde stradale: grafo tratte in gestione con progressiva chilometrica e larghezza pertinenza | MD p.15, 40 | Censimento | E | SHOULD | Per appalti su strade |
| C13 | Accessi/cancelli con attributo chiusura oraria e civico | MD p.29, 54 | Censimento | E | COULD | Utile per gestione aperture parchi |

### 3.3 Alberi, VTA, vegetazione

| # | Requisito | Fonte | Modulo | Tipo | Priorità | Note |
|---|---|---|---|---|---|---|
| A01 | Scheda albero completa: identificazione (UUID, codice, cartellino, NFC/RFID/QR/barcode), tassonomia (genere, specie, cultivar, famiglia, nome comune), dendrometria (H, circ., DBH, Ø chioma, inserzione, età: modalità/data/stima), posizione (coord., quota, precisione, modalità rilievo), stato (vivo/morto/ceppaia/rimosso/trapiantato/nuovo impianto/posto libero), condizioni, foto tipizzate | PR §9; BR1 p.6; BR2 p.5-7; MD p.13 | Alberi | E | MUST | Include flag: monumentale, particolare valore, tutela, interferenze, tutore, consolidamenti (BR2 p.7) |
| A02 | Timeline/storico per pianta, versioning, mai sovrascrivere | PR §10; BR2 p.6-7 | Alberi | E | MUST | |
| A03 | Modulo VTA/VSA: data, rilevatore, tipo verifica, difetti per parte (radici, colletto, fusto, branche, castello, chioma, patogeni), bersagli, classe propensione cedimento, prescrizioni, ricontrolli con frequenza da classe | PR §11; BR2 p.5-8 | VTA | E | MUST | Scheda configurabile per cliente |
| A04 | Analisi strumentali: tomografia, resistograph/dendrodensimetro, pulling test, inclinometro, altre; allegati PDF/immagini/misure/raw | PR §11; BR2 p.6 | VTA | E | MUST | |
| A05 | Metodo di analisi rischio opzionale (in GreenSpaces: "Aretè") — da noi: framework pluggable di metodi di valutazione | BR2 p.6 | VTA | E/M | SHOULD | Nessuna formula proprietaria copiata |
| A06 | Scadenzario controlli con generazione automatica di ricontrolli/alert/interventi; dashboard VTA scadute/30 giorni/alta priorità | PR §12; BR2 p.6 | VTA | E | MUST | |
| A07 | Workflow albero: analisi visiva → strumentale → prescrizioni → approvazione classe → nuovo controllo | BR2 p.6-7 | VTA | E | MUST | |
| A08 | Abbattimenti e piantumazioni; modulo "posti liberi" (siti di impianto vuoti) | BR2 p.6; PR §9 (posto libero) | Alberi | E | MUST | |
| A09 | **Bilancio arboreo tra due date** (iniziali, abbattuti, nuovi, sostituzioni, finali, variazione) | PR §53; L10 p.1-2; BR2 p.18 | Report | E | MUST | Obbligo di legge fine mandato |
| A10 | **Piante dedicate ai nuovi nati** con generalità e stampa scheda per i genitori | L10 p.1-2; BR2 p.18 (alberi dedicati) | Alberi | E | SHOULD | Obbligo L.10/2013 art.2 |
| A11 | Alberi monumentali: individuazione e gestione (raccordo Corpo Forestale) | L10 p.2; BR2 p.7 | Alberi | E | SHOULD | Flag + attributi dedicati |
| A12 | Arbusti, siepi, aiuole, tappezzanti, vasi/fioriere: schede con specie multiple con date inserimento/rimozione, tag NFC su fioriere, densità, composizione, manutenzione | PR §13; BR2 p.8 | Vegetazione | E | MUST | |
| A13 | Prati: superficie da poligono, tipologia (16 tipi MD), miscuglio, sfalci (frequenza/altezza), irrigazione, infestanti, costi | PR §14; MD p.21 | Vegetazione | E | MUST | |
| A14 | Fitopatie e parassiti: monitoraggio diffusione per pianificare difesa | L10 p.2; MD p.32 | Vegetazione | E | SHOULD | Collegato ad aree focolaio C11 |

### 3.4 Arredo urbano, aree gioco, ispezioni

| # | Requisito | Fonte | Modulo | Tipo | Priorità | Note |
|---|---|---|---|---|---|---|
| U01 | Arredo urbano completo (panchine, cestini, tavoli, fontanelle, recinzioni, pavimentazioni, cordoli, giochi, sport, illuminazione…) con posizione, stato, produttore, modello, matricola, installazione, ispezioni, foto, documenti, interventi, NC | PR §15; MD TS 04-42 | Arredo | E | MUST | Catalogo MD come seed delle tipologie/materiali |
| U02 | Aree gioco: area + singoli attrezzi (gioco semplice/complesso), pavimentazioni antitrauma, recinzioni; scheda attrezzo con tipologia, materiale, produttore, codice prodotto, fascia d'età, altezza caduta, sottofondo, installatore, valore a nuovo/ammortamento, manuali | PR §16; BR2 p.8-9; MD p.23, 41, 80-81 | Aree gioco | E | MUST | Norma EN 1176 come riferimento ispezioni |
| U03 | Storico giochi dismessi con ispezioni e interventi | BR2 p.9 | Aree gioco | E | MUST | Soft delete + stato |
| U04 | Ispezioni con template configurabili (visivo ordinario, operativo, annuale, altro), checklist dinamiche con condizioni, KO→NC automatica, foto/allegato obbligatori, firma, GPS, data/ora/operatore | PR §17, §104; BR1 p.5 | Ispezioni | E | MUST | |
| U05 | Ispezione via tag NFC sull'attrezzo (lettura → registrazione) | BR2 p.9; PR §38 | Ispezioni | E | MUST | |
| U06 | Giro ispettivo: lista elementi, percorso, per ciascuno NFC/barcode+checklist+foto+OK/KO, avanzamento X/X, report automatico | PR §101 | Ispezioni | E | MUST | |

### 3.5 Lavori, economia, qualità

| # | Requisito | Fonte | Modulo | Tipo | Priorità | Note |
|---|---|---|---|---|---|---|
| W01 | Workflow lavori a 12 stati configurabili (Richiesta→…→Chiusa) | PR §22 | Lavori | E | MUST | |
| W02 | ODL completo (cliente, contratto, sito, oggetti, lavorazione, quantità+UdM, priorità, squadra, operatori, mezzi, attrezzature, materiali, date, durata, allegati, rischi, DPI, stato) | PR §23 | Lavori | E | MUST | |
| W03 | Programmazione: agenda, calendario, Gantt (colori configurabili, linea "oggi", realizzato vs programmato), lista, mappa; drag&drop; filtri | PR §24; BR2 p.10 | Lavori | E | MUST | |
| W04 | Assegnazione condizionata dall'esito di attività precedenti | BR1 p.6 | Lavori | E | SHOULD | Regole evento→azione |
| W05 | Ottimizzazione geografica dei giri (distanza, priorità, scadenza, squadra, mezzi, meteo, competenze); valutare OSRM/GraphHopper/Valhalla | PR §25; BR1 p.9 | Lavori | E | COULD | Fase evolutiva |
| W06 | Squadre: responsabile, componenti, qualifiche, patentini, mezzi, attrezzature, disponibilità | PR §26 | Squadre | E | MUST | |
| W07 | Consuntivazione dal campo: INIZIA (operatore+timestamp+GPS) / CHIUDI (ore uomo, quantità, mezzi, materiali, foto prima/dopo, note, firma) | PR §27, §82; BR2 p.13 | Mobile | E | MUST | |
| W08 | Catalogo lavorazioni con codice, categoria, UdM, prezzo, costo, durata standard, squadra standard, periodicità; listini per cliente/appalto/anno/contratto | PR §28; BR2 p.11 | Listini | E | MUST | |
| W09 | Calcolo automatico costi su base geometrica (prezzo/m² × m² del poligono) | BR2 p.11; PR §85 | Listini | E | MUST | |
| W10 | Manutenzione ordinaria: cicli e generazione automatica interventi | PR §29 | Lavori | E | MUST | |
| W11 | Manutenzione straordinaria: richiesta→sopralluogo→preventivo→autorizzazione→programmazione→esecuzione→consuntivo | PR §30 | Lavori | E | MUST | |
| W12 | Rendicontazione: chiusura→validazione committenza→**SAL**→pagamento; previsto vs consuntivato, ore, costi, ricavi, margine | PR §78; BR2 p.11 | Rendicontazione | E | MUST | |
| W13 | Non conformità: origini multiple, severità 4 livelli, workflow 6 stati, tempi di risoluzione in base a gravità | PR §31; BR2 p.11 | Qualità | E | MUST | |
| W14 | Segnalazioni (distinte da NC): testo, foto, posizione, categoria, gravità, autore; trasformabili in intervento/NC/sopralluogo | PR §32; BR2 p.11 | Qualità | E | MUST | |
| W15 | SLA su segnalazioni e lavori (presa in carico, intervento, chiusura) con alert | PR §79 | Qualità | E | SHOULD | |
| W16 | Modulo capitolati: dal censimento calcola quantità, aggrega lavorazioni, applica prezzi, genera computo/capitolato/base preventivo | PR §85; L10 p.3 | Preventivazione | E | SHOULD | |
| W17 | Preventivazione da selezione su mappa | PR §86 | Preventivazione | E | SHOULD | |

### 3.6 Irrigazione, meteo, ambiente

| # | Requisito | Fonte | Modulo | Tipo | Priorità | Note |
|---|---|---|---|---|---|---|
| I01 | Irrigazione: gerarchia Impianto→Centralina→Linea→Settore→Elettrovalvola→Tubazione→Erogatori; tutti i componenti (punto acqua, contatore, pompa, filtro, fertilizzatore, sensore, pozzetto, ala gocciolante, irrigatori, ugelli); mappa | PR §18; BR2 p.12; MD p.31, 55 | Irrigazione | E | MUST | Scheda centralina BR2 p.12 come riferimento campi |
| I02 | Gestione idrica per settore: area, portata, pressione, precipitazione, durata, frequenza, consumi teorico/reale, storico | PR §19 | Irrigazione | E | SHOULD | |
| I03 | Modulo meteo: API provider, archivio T/pioggia/vento/umidità/ET0/radiazione/allerte | PR §20; BR1 p.8; BR2 p.13-14 | Meteo | E | SHOULD | |
| I04 | Water management: fabbisogno idrico indicativo (specie, dimensione, terreno, esposizione, ET0, pioggia, Kc) con suggerimenti; bilancio idrico che considera irrigazioni precedenti | PR §21; BR2 p.14-15 | Water | E | COULD | Motore a modelli pluggable |
| I05 | Servizi ecosistemici: motore modulare per modelli validati (CO2, PM, benefici termici, intercettazione pioggia, ombreggiamento); nessuna formula inventata | PR §54; BR1 p.8; BR2 p.14; L10 p.2 | Benefits | E | COULD | Integrazione i-Tree o modelli accademici su licenza |
| I06 | IoT: entità device/device_type/measurement/measurement_type per sensori, contatori, centraline, dendrometri | PR §67 | IoT | E | COULD | Solo predisposizione schema |

### 3.7 Mobile, offline, Zebra

| # | Requisito | Fonte | Modulo | Tipo | Priorità | Note |
|---|---|---|---|---|---|---|
| M01 | App operatore dedicata (non backoffice responsive): pulsanti grandi I MIEI LAVORI / MAPPA / SCANSIONA / NFC / SEGNALA / FOTO / INIZIA / CHIUDI; ottimizzata smartphone/tablet/rugged Zebra | PR §33; BR1 p.7; BR2 p.12-13 | Mobile | E | MUST | |
| M02 | Offline completo: lavori, cartografia scaricata, censimento, schede, foto, NFC, barcode, checklist, apertura/chiusura lavori; sync automatica al ritorno; indicatore ONLINE / OFFLINE — X da sincronizzare | PR §34; BR1 p.7; BR2 p.12 | Offline | E | MUST | IndexedDB + service worker + conflict resolution |
| M03 | Dashboard operatore: OGGI / PROSSIMO / AVVIA | PR §72 | Mobile | E | MUST | |
| M04 | Scansiona e lavora: scan → elemento + ultimo intervento + lavoro assegnato + azioni INIZIA/FOTO/REGISTRA/CHIUDI | PR §100, §36 | Mobile | E | MUST | |
| M05 | Integrazione Zebra: palmari rugged, lettori barcode, RFID/NFC; Hardware Abstraction Layer; verifica preventiva modalità di integrazione | PR §35 | Zebra | E | MUST | Documento ZEBRA-INTEGRATION.md in Fase 0 |
| M06 | Barcode: QR, Code128, Code39, DataMatrix, EAN; scan → apre scheda | PR §36 | Zebra | E | MUST | |
| M07 | DataWedge: profili, intent output/keystroke, eventuale wrapper WebView; proporre approccio più affidabile | PR §37 | Zebra | E | MUST | |
| M08 | NFC: lettura UID/payload → scheda; TAG NON ASSOCIATO → associa/crea/annulla | PR §38; BR1 p.4; BR2 p.8-9 | Zebra | E | MUST | |
| M09 | Scrittura NFC: solo identificativo/URL/checksum, dati nel DB | PR §39 | Zebra | E | SHOULD | |
| M10 | Sicurezza tag: anti-clonazione/sostituzione/riscrittura; tag ≠ autenticazione utente | PR §40 | Zebra | E | SHOULD | |
| M11 | Stampa etichette Zebra: barcode/QR/DataMatrix/alfanumerico, ZPL diretto | PR §41 | Zebra | E | SHOULD | |
| M12 | Associazione massiva tag: area→elemento→scan→conferma→prossimo, per migliaia di oggetti | PR §42 | Zebra | E | MUST | |
| M13 | Decisione PWA vs app Android: web desktop + PWA + eventuale wrapper Android per Zebra se il browser compromette lettore/NFC/RFID/background sync/fotocamera/GPS | PR §99 | Mobile | E | MUST | Proposta in §12 |

### 3.8 Portali, report, piattaforma

| # | Requisito | Fonte | Modulo | Tipo | Priorità | Note |
|---|---|---|---|---|---|---|
| R01 | Documenti su ogni elemento (PDF, foto, DOCX, XLSX, certificazioni, manuali); no URL pubblici, access control, signed URL | PR §50; BR2 p.9 | Documenti | E | MUST | |
| R02 | Foto: compressione, thumbnail, originale, EXIF, data, operatore, GPS, categoria, PRIMA/DURANTE/DOPO | PR §51 | Documenti | E | MUST | |
| R03 | Dashboard per ruolo: operatore (§72), responsabile (§73), direzione (§74) | PR §72-74 | Report | E | MUST | |
| R04 | Report: consistenza patrimonio, lavorazioni, VTA, abbattimenti/piantumazioni, costi, ore, materiali, stato interventi; statistiche località/alberi/oggetti | PR §52; BR2 p.10, 17-18 | Report | E | MUST | |
| R05 | Generatore PDF con template configurabili (logo, cliente, periodo, foto, mappe, firme, statistiche); stampa mappa e schede | PR §81; BR2 p.17 | Report | E | MUST | |
| R06 | Esportazioni Excel/CSV/PDF/GIS di ogni report | PR §80 | Report | E | MUST | |
| R07 | Portale cliente: patrimonio, mappa, lavori, foto, documenti, report, segnalazioni, consuntivi; permessi granulari | PR §55; BR2 p.17 | Portali | E | SHOULD | |
| R08 | Portale pubblico opzionale (alberi, specie, parchi, giochi, info ambientali; nessun dato interno); QR/NFC pubblico su albero con pagina specie/anno/benefici/curiosità | PR §56, §83; BR1 p.10; BR2 p.15; L10 p.2-3 | Portali | E | COULD | Rif. rimini.lifeurbangreen.eu |
| R09 | Ricerca globale unica (albero, ODL, tag, documento, segnalazione) + command palette Ctrl+K | PR §69-70 | UX | E | MUST | |
| R10 | UX: progressive disclosure, wizard, tab, drawer, quick actions, filtri salvabili; niente schermate sovraccariche | PR §71; BR1 p.2 | UX | E | MUST | |
| R11 | Notifiche in-app/email/push (+ SMS/WhatsApp via provider); centro notifiche | PR §68 | Piattaforma | E | SHOULD | |
| R12 | Webhook (lavoro creato/concluso, segnalazione, NC, VTA in scadenza, oggetto modificato) | PR §66 | API | E | SHOULD | |
| R13 | API-first versionate /api/v1 con OpenAPI/Swagger | PR §65 | API | E | MUST | |
| R14 | RBAC granulare (11+ ruoli esempio; permessi a livello modulo/cliente/contratto/sito/record/azione) server-side | PR §57, §62; BR2 p.3, 17 | Sicurezza | E | MUST | |
| R15 | Multitenancy con isolamento (utenti, clienti, listini, cataloghi, configurazioni, documenti per organizzazione) | PR §58; BR2 p.17 (Enterprise) | Piattaforma | E | MUST | |
| R16 | Audit log immutabile (login, CRUD, export, ruoli, associazioni NFC, dati critici) | PR §59 | Sicurezza | E | MUST | |
| R17 | Versioning schede sensibili con confronto campo per campo | PR §60 | Piattaforma | E | MUST | |
| R18 | Soft delete; albero abbattuto = stato ABBATTUTO con storico | PR §61; MD p.20 | Piattaforma | E | MUST | |
| R19 | Sicurezza by design (hashing, MFA, CSRF/XSS/SQLi, rate limit, upload validation, MIME, antivirus hook, signed URL, encryption at rest/in transit, CSP, security headers) | PR §62 | Sicurezza | E | MUST | |
| R20 | GDPR: minimizzazione, retention, export, cancellazione, log accessi, consenso | PR §63 | Sicurezza | E | MUST | |
| R21 | Backup DB+documenti+config, automatico, retention, restore test, off-site; RPO/RTO dichiarati | PR §64; BR1 p.11 (backup incluso nel SaaS) | Ops | E | MUST | Proposta: RPO 24h (1h per DB con WAL), RTO 4h |
| R22 | Configurazione per cliente: schede, campi, workflow, listini, permessi diversi | PR §105; BR1 p.5; BR2 p.17 | Piattaforma | E | MUST | |
| R23 | Tracciabilità completa (le 10 domande §106) | PR §106 | Piattaforma | E | MUST | |
| R24 | No vendor lock-in: dati sempre esportabili in formati standard; il cliente resta proprietario | PR §107 | Piattaforma | E | MUST | |
| R25 | Proprietà intellettuale: nome/codice/UI/architettura autonomi, nessuna copia da GreenSpaces | PR §108 | Progetto | E | MUST | |
| R26 | Test (unit, integration, API, GIS, permission, security, E2E) + security test in CI (SAST, dependency/secret/container scan) | PR §90-91 | Ops | E | MUST | |
| R27 | Ambienti DEV/STAGING/PRODUCTION; CI/CD con rollback; log centralizzati; /health con monitor DB/Redis/storage/queue/spazio | PR §92-95 | Ops | E | MUST | |
| R28 | Documentazione: README, ARCHITECTURE, DATABASE, API, SECURITY, DEPLOYMENT, OFFLINE-SYNC, ZEBRA-INTEGRATION, GIS-DATA-MODEL, TESTING | PR §96, §98 | Progetto | E | MUST | |
| R29 | Predisposizione AI assistant (ricerca semantica, sintesi, report, specie da foto come suggerimento); mai diagnosi autonome | PR §87-88 | AI | E | COULD | |
| R30 | Detection anomalie su storico (consumi, lavorazioni ripetute, NC, costi fuori standard) | PR §89 | AI | E | COULD | |

*(Requisiti impliciti chiave, tipo I, integrati nel progetto: gestione code/job asincroni per import e report pesanti; localizzazione i18n — GreenSpaces gestisce nomi bilingue it/de; numerazione progressiva per area (PT/OBJ_ID del MD); merge fotografico EXIF→GPS; gestione fusi orari e timestamp UTC; migrazione dati da eventuali sistemi esistenti.)*

---

## 4. Funzioni aggiuntive consigliate

Migliorie (tipo M) non presenti nei documenti, proposte per superare GreenSpaces sul piano operativo:

| # | Proposta | Perché |
|---|---|---|
| X01 | **Registro pubblico segnalazioni cittadino con QR di area** (invio senza account, con anti-spam) | Chiude il cerchio con il portale pubblico; oggi le segnalazioni cittadine arrivano per telefono/PEC |
| X02 | **Diff visivo del censimento tra import successivi** (cosa è cambiato rispetto alla consegna precedente del rilevatore) | Collaudo dei rilievi esterni: il MD impone il 95% entro tolleranza, il sistema può verificarlo |
| X03 | **Motore di collaudo rilievi** (controlli automatici MD: nomenclatura file, poligoni chiusi, copertura, tolleranze sigma/2sigma) | Trasforma le specifiche di rilievo MD p.17-20 in validazioni eseguibili |
| X04 | **Cost intelligence**: margine per ODL in tempo reale (listino vs consuntivo) con alert su commesse in perdita | I dati ci sono già (W08-W12), manca solo la vista |
| X05 | **Snapshot "gemello dell'appalto"**: freeze del censimento a inizio contratto per contestazioni | Le quantità contrattuali restano verificabili anche dopo aggiornamenti |
| X06 | **Modalità squadra** sul mobile: un caposquadra consuntiva per tutti i componenti | Riduce i dispositivi necessari in campo |
| X07 | **Pacchetti cartografici offline per area** con stima MB prima del download | Rende governabile l'offline su territori grandi |
| X08 | **Field-level permissions sul portale cliente** (es. cliente vede lo stato VTA ma non le note interne del tecnico) | Richiesto implicitamente da §55/§56 |
| X09 | **API pubblica read-only open data** (GeoJSON/WFS) attivabile per tenant PA | Trasparenza L.10/2013 a costo marginale |
| X10 | **Firma digitale dei report VTA** (hash + timestamp, eventualmente PAdES) | Valore probatorio delle perizie |

---

## 5. Confronto tra architetture tecnologiche

### Soluzione A — Applicazione web dedicata a servizi separati

Frontend React/Next.js SPA + backend API NestJS (o Django/DRF) + PostgreSQL/PostGIS + Redis + object
storage S3 + PWA operatore separata + Docker.

| Criterio | Valutazione |
|---|---|
| Vantaggi | Massima flessibilità; separazione netta API/frontend; scaling indipendente; ecosistema JS/TS unico se NestJS |
| Svantaggi | **Due (o tre) codebase** da mantenere; duplicazione validazioni; più DevOps; team piccolo penalizzato |
| Sicurezza | Ottima ma da assemblare (auth, RBAC, CSRF su SPA, refresh token…) |
| Scalabilità | Eccellente (orizzontale per servizio) |
| GIS | Ottimo con PostGIS; serve comunque tile server o ST_AsMVT custom |
| Mobile/Offline | PWA dedicata comunque necessaria; nessun vantaggio specifico |
| Zebra | Identico alle altre (livello browser/Android) |
| Manutenzione | Più costosa: 2 deploy, 2 dipendenze, 2 supply chain |
| Backup | Standard (pg_dump/WAL + S3) |
| Costi infra | Medi: più container, più RAM; su VPS Aruba comunque fattibile |
| Difficoltà sviluppo | Alta (coordinamento API/tipi/contratti) |

### Soluzione B — Monolite modulare Laravel full-stack ★ CONSIGLIATA

Laravel 12 + PostgreSQL 16/PostGIS 3.4 + Redis + Inertia.js/Vue 3 per il backoffice + **PWA operatore
Vue 3 dedicata** (stessa repo, bundle separato, offline-first) + MapLibre GL JS + vector tiles da
PostGIS + storage S3-compatible + Docker Compose.

| Criterio | Valutazione |
|---|---|
| Vantaggi | **Una sola codebase e un solo deploy**; ecosistema maturo per ogni requisito del prompt: auth+MFA (Fortify), RBAC (spatie/permission), code (Horizon), audit (owen-it/auditing), versioning (custom su event sourcing leggero), media (spatie/medialibrary), PDF (browsershot/dompdf), Excel (laravel-excel), OpenAPI (scribe/l5-swagger), multitenancy (stancl/tenancy o scoping nativo); GDAL/ogr2ogr invocabile per import/export SHP/GPKG; time-to-market più rapido |
| Svantaggi | PHP meno "di moda"; realtime nativo più limitato (risolto con Reverb/WebSocket o polling); l'app operatore resta comunque una SPA JS da curare |
| Sicurezza | Molto buona out-of-the-box (CSRF, hashing argon2id, rate limiting, signed URL, policy server-side) |
| Scalabilità | Verticale + repliche orizzontali dietro LB quando servirà; PostGIS è il collo di bottiglia reale e vale per tutte le soluzioni; adeguata ai target §75 |
| GIS | PostGIS pieno; ST_AsMVT via controller dedicato o martin come sidecar; laravel-magellan per i tipi geometrici |
| Mobile/Offline | PWA Vue dedicata con Dexie/IndexedDB + service worker (Workbox), identica alla Soluzione A |
| Zebra | Identica alle altre; HAL JS condiviso nella PWA |
| Manutenzione | **La più semplice**: un framework, un linguaggio lato server, aggiornamenti LTS prevedibili |
| Backup | Standard + spatie/backup per orchestrare dump→S3 |
| Costi infra | **I più bassi**: gira bene su un VPS 4 vCPU/8GB |
| Difficoltà sviluppo | Media; il rischio si concentra dove è inevitabile (offline sync, GIS), non nell'infrastruttura |

### Soluzione C — WordPress (plugin o headless)

| Criterio | Valutazione |
|---|---|
| Vantaggi | Familiarità; hosting economico; ecosistema plugin |
| Svantaggi | **Inadeguata**: WordPress è un CMS su MySQL — niente PostGIS (le query spaziali serie non esistono su MySQL a questo livello), niente vincoli topologici, RBAC record-level estraneo al modello, offline-first e sync estranei al runtime, multitenancy fragile, performance su milioni di geometrie irraggiungibili; ogni modulo (VTA, ODL, listini) andrebbe costruito contro il grano della piattaforma |
| Sicurezza | Superficie d'attacco più ampia del settore; hardening perenne |
| Scalabilità/GIS/Offline/Zebra | Insufficienti per i requisiti §4, §5, §34, §75 |
| Verdetto | **Da escludere.** Non ci sono ragioni tecniche concrete (condizione posta dallo stesso prompt §2). WordPress può semmai restare per il sito vetrina aziendale, separato dalla piattaforma |

### ARCHITETTURA CONSIGLIATA

**Soluzione B — monolite modulare Laravel full-stack con PWA operatore dedicata.**

Motivazione tecnica: (1) il 90% dei requisiti è gestionale-transazionale, terreno ideale di un
framework full-stack maturo; (2) la complessità irriducibile del progetto sta in PostGIS, offline-sync
e Zebra — che sono identiche in tutte le soluzioni — quindi conviene azzerare la complessità
*accidentale* (microservizi, doppie codebase); (3) team piccolo e hosting Aruba a costi contenuti:
un solo artefatto Docker Compose si installa su un Cloud Server e si migra senza lock-in; (4) tutti i
"mattoni rischiosi" (RBAC, audit, code, media, PDF, Excel, OpenAPI, backup) hanno pacchetti Laravel
consolidati, riducendo il codice da scrivere e da testare. L'API resta **API-first** (/api/v1 con
OpenAPI): la PWA operatore consuma le stesse API pubbliche, garantendo che il giorno in cui servisse
un frontend separato o un'app nativa non si riparta da zero.

---

## 6. Architettura consigliata

```
                    ┌───────────────────────────────────────────────┐
                    │                UTENTI / DEVICE                │
                    │ Desktop backoffice │ Smartphone/Tablet │ Zebra │
                    └─────────┬──────────────────┬────────────┬─────┘
                              │ HTTPS            │            │ DataWedge/NFC
                    ┌─────────▼──────────────────▼────────────▼─────┐
                    │   Caddy/Nginx (TLS, HTTP/2, security headers)  │
                    └─────────┬──────────────────┬──────────────────┘
                              │                  │
              ┌───────────────▼───┐   ┌──────────▼─────────────┐
              │ Laravel (Octane)  │   │  PWA Operatore (Vue 3) │
              │ Backoffice Inertia│   │  service worker+Dexie  │
              │ API /api/v1       │◄──┤  offline queue → sync  │
              │ Tiles ST_AsMVT    │   └────────────────────────┘
              └──┬────┬────┬─────┬┘
                 │    │    │     │
       ┌─────────▼┐ ┌─▼──────┐ ┌▼──────────────┐ ┌───────────────────┐
       │PostgreSQL│ │ Redis  │ │ Object Storage │ │ Worker Horizon    │
       │16+PostGIS│ │cache/  │ │ S3 (Aruba)     │ │ import GIS, PDF,  │
       │  3.4     │ │queue   │ │ foto/documenti │ │ report, sync, mail│
       └──────────┘ └────────┘ └────────────────┘ └───────────────────┘
```

Scelte chiave:

- **Backend**: Laravel 12 (PHP 8.4) con Octane (FrankenPHP) per throughput; moduli applicativi come
  bounded context in `app/Modules/*` (Censimento, Alberi, VTA, Lavori, Irrigazione, Qualità, Portali…).
- **DB**: PostgreSQL 16 + PostGIS 3.4; SRID interno 4326, colonne generate per aree/lunghezze calcolate
  su proiezione metrica (EPSG:7791/32632); estensioni: `postgis`, `postgis_topology` (validazioni),
  `pg_trgm` (ricerca globale), `uuid-ossp`.
- **Mappa**: MapLibre GL JS (backoffice e PWA) con vector tiles servite da endpoint Laravel
  `ST_AsMVT` cache-ate su Redis (opzione sidecar `martin` se servisse più throughput).
- **PWA operatore**: Vue 3 + Vite + Workbox; Dexie (IndexedDB) per dataset offline; coda comandi con
  idempotency key; tile raster/vector pre-scaricate per area.
- **File**: object storage S3-compatible (bucket per tenant/ambiente), signed URL a scadenza,
  thumbnail via job asincroni.
- **Import/export GIS**: GDAL/ogr2ogr in container worker (SHP/GPKG/KML/GeoJSON, riproiezioni
  RDN2008↔4326), profilo CAM/MD certificato.
- **Ricerca**: Postgres full-text + trigram su vista materializzata "searchables" (albero, ODL, tag,
  documento, segnalazione).
- **Notifiche**: mail (SMTP Aruba o provider transazionale), Web Push (VAPID), canale in-app; webhook
  firmati HMAC.
- **CI/CD**: GitHub Actions (test, SAST, dependency/secret scan, build immagini, deploy via SSH a
  staging/prod con rollback su tag).

---

## 7. Hosting: vincolo Aruba e cosa acquistare

Verifica effettuata sull'offerta Aruba attuale (agosto 2026, prezzi indicativi +IVA, da confermare sul
listino [cloud.it/prezzi](https://www.cloud.it/prezzi)).

### 7.1 Cosa NON va bene

- **Hosting condiviso Linux Aruba (hosting.aruba.it)** — quello tipicamente incluso con i domini — è
  **inutilizzabile** per questa piattaforma: offre solo PHP+MySQL/MariaDB via FTP e pannello, **niente
  PostgreSQL/PostGIS, niente SSH/root, niente Docker, niente Redis**. Se oggi avete solo questo, va
  affiancato (non sostituito: può continuare a servire il sito vetrina) da servizi cloud Aruba.
- **Jelastic Cloud (PaaS Aruba)**: in evidente fase di de-enfasi nel catalogo 2026; sconsigliato come
  base per un progetto nuovo.
- **DBaaS PostgreSQL Aruba**: esiste, ma il **supporto dell'estensione PostGIS non è documentato**
  (e il piano Shared con utente "lite-admin" quasi certamente non consente `CREATE EXTENSION`).
  Da usare solo previa conferma scritta del supporto Aruba; fino ad allora PostgreSQL self-managed
  in container.

### 7.2 Cosa acquistare — piano economico (scelto dal committente il 10/08/2026)

Acquisti scaglionati: si compra solo quando la fase lo richiede.

| Quando | Servizio Aruba | Taglio | Costo indicativo |
|---|---|---|---|
| Oggi (Fasi 0-2) | **Nessun acquisto**: sviluppo in Docker sul PC locale | — | 0 € |
| Demo/staging (da Fase 3) | **Cloud VPS** (linea OpenStack) | 1-2 vCPU / 2 GB, Ubuntu 24.04 LTS | ~3–4 €/mese |
| Go-live pilota | **Cloud VPS** (linea OpenStack) | 2-4 vCPU / 4-8 GB / 60-80 GB SSD — Docker Compose completo (app+worker+PostgreSQL/PostGIS+Redis) | ~6–15 €/mese |
| Foto/documenti | **Cloud Object Storage** (S3-compatible confermato) | bucket separati prod/staging, pay-per-use ~0,011 €/GB/mese | ~1–3 €/mese fino a ~200 GB |
| Backup off-site | Bucket Object Storage **in data center diverso** da quello di produzione (dump `pg_dump` giornalieri cifrati + volumi) | 50–100 GB | ~1–2 €/mese |
| DNS/SSL/email | Dominio e DNS già su Aruba; certificati **Let's Encrypt** (gratis); SMTP Aruba del dominio | — | 0 € |

**Totale al go-live del pilota: ~10–20 €/mese +IVA.**

Trade-off accettato: il VPS di produzione pilota non ha storage ridondato né SLA 99,95%.
Mitigazione: backup off-site giornalieri **testati** (restore di prova periodico) — in caso di guasto
si ripristina su un VPS nuovo in poche ore (RPO 24h, 1h per il DB con archiviazione WAL; RTO ~4h).

**Percorso di upgrade** (quando arrivano clienti paganti o PA con SLA contrattuali): produzione
migrata via snapshot su **Cloud Server PRO** 4 vCPU / 8-16 GB (~40–70 €/mese, storage ridondato,
SLA 99,95%, scalabile a caldo) e backup VM aggiuntivo con Cloud Backup 50/100 (6,99–12,99 €/mese).
Il piano "standard" resta quindi il traguardo, non il punto di partenza.

Note:

- **Managed Kubernetes Aruba** (control plane gratuito) è tecnicamente valido ma sovradimensionato in
  complessità per 2–3 ambienti: da riconsiderare solo con più istanze/tenant di grandi dimensioni.
- La crescita è lineare: il Cloud Server PRO si scala a caldo (CPU/RAM/disco); quando i tenant
  aumentano si può separare il DB su una macchina dedicata o sul DBaaS (se PostGIS confermato).
- Requisito §107 (no lock-in) rispettato: tutto è Docker + Postgres + S3 standard, migrabile da Aruba
  a qualunque provider senza modifiche applicative.
- Tutti i data center proposti sono in Italia (rilevante per clienti PA e GDPR).

**Azione richiesta a te**: nessun acquisto immediato. Il primo acquisto sarà il VPS di staging
(~3-4 €/mese) all'inizio della Fase 3, quando servirà la demo online dell'app di campo — te lo
segnalerò io al momento giusto. Resta utile sapere quale abbonamento Aruba hai già attivo (hosting
condiviso? dominio? PEC?).

---

## 8. Schema logico preliminare dei moduli

```
CORE
 ├─ Identity & Access (utenti, ruoli, permessi, MFA, sessioni, API token)
 ├─ Tenancy (organizzazioni, configurazioni, branding)
 ├─ Clienti & Contratti (clienti, contratti/appalti, sedi, listini collegati)
 ├─ Territorio (località, aree, sottoaree, perimetri di gestione)
 ├─ Catalog Manager (TP/TS/ATT, schede-tipo, campi custom, regole validazione, seed MD v2.1)
 └─ Audit & Versioning (audit log immutabile, versioni schede, timeline)

GIS
 ├─ Geometrie & CRS (storage, trasformazioni, misure derivate)
 ├─ Validation Engine (topologia, regole MD, dashboard errori)
 ├─ Tiles & Mappa (MVT, layer, stili tematici, mappa temporale)
 └─ Import/Export (wizard, GDAL, profilo CAM/MD, collaudo rilievi)

PATRIMONIO
 ├─ Censimento (asset generico + specializzazioni)
 ├─ Alberi (scheda, stati, dedicati, monumentali, posti liberi, bilancio arboreo)
 ├─ VTA/VSA (schede, difetti, classi, strumentali, scadenzario)
 ├─ Vegetazione (arbusti, siepi, aiuole, prati, vasi/fioriere)
 ├─ Arredo & Aree gioco (schede, ispezioni EN1176, dismissioni)
 ├─ Irrigazione (gerarchia impianti, componenti, settori, gestione idrica)
 └─ Fattori ambientali (fitopatologie, interferenze, sinistri, analisi terreno)

OPERATIONS
 ├─ Lavori (ODL, workflow, programmazione, Gantt, cicli ordinari, straordinaria)
 ├─ Squadre & Risorse (squadre, operatori, mezzi, attrezzature, materiali)
 ├─ Consuntivazione (start/stop campo, ore, quantità, firma)
 ├─ Listini & Economia (lavorazioni, prezzi, computi, capitolati, preventivi, SAL, margini)
 ├─ Qualità (segnalazioni, non conformità, SLA)
 └─ Ispezioni (template, checklist dinamiche, giri ispettivi)

CANALI
 ├─ Backoffice web (Inertia)
 ├─ PWA Operatore (offline-first, HAL Zebra: DataWedge/NFC/camera)
 ├─ Portale Cliente
 ├─ Portale Pubblico + QR/NFC pubblico (opzionale)
 └─ API pubblica /api/v1 + webhook

SERVIZI
 ├─ Documenti & Foto (S3, signed URL, EXIF, thumbnail)
 ├─ Report & PDF (template, export Excel/CSV/GIS)
 ├─ Notifiche (in-app, email, push; centro notifiche)
 ├─ Ricerca globale & Command palette
 ├─ Meteo / Water / Benefits (motori modulari, futuri)
 ├─ IoT (device/measurement, futuro)
 └─ AI (predisposizione API interne, futuro)
```

---

## 9. Modello ER preliminare

Impostazione: **un'entità geografica generica (`assets`) con specializzazioni per dominio**, catalogo
configurabile, storicizzazione ovunque. Tutte le tabelle hanno `id UUID PK`, `tenant_id`,
`created_at/updated_at`, soft delete dove sensato; le schede sensibili hanno tabella `*_versions`.

```
TENANCY / ACCESSO
organizations ─< users ─< user_roles >─ roles ─< role_permissions >─ permissions
organizations ─< api_tokens, webhooks, settings

CLIENTI / TERRITORIO
organizations ─< clients ─< contracts ─< contract_price_lists
clients ─< sites ─< localities ─< areas (self-ref parent_id per sottoaree)
areas: geom MULTIPOLYGON, tipo (gestione/funzionale/temporanea), stato, gestore

CATALOGO
catalog_main_types (TP) ─< catalog_sub_types (TS) ─< catalog_object_types (codice TXYYZZZ,
   geometria ammessa S/L/P, modalità rilievo, foto riferimento, flag CAM)
catalog_object_types ─< custom_fields (tipo campo, obbligatorio, regole, lista valori)
work_types (lavorazioni: codice, UdM, durata std, periodicità) ─< price_list_items >─ price_lists

PATRIMONIO (cuore)
assets: id UUID, tenant_id, area_id, object_type_id, census_code, geom GEOMETRY,
        status, data_ini, data_fine, attributes JSONB (campi custom), computed_measures,
        created_by, survey_method, gps_accuracy
assets ─< asset_versions (versioning campo per campo)
assets ─< asset_tags (tag fisici: tipo NFC/QR/BARCODE/RFID/ARBOTAG, uid, payload, stato,
                      associato_da/il — storicizzato)
assets ─< photos (categoria PRIMA/DURANTE/DOPO/organo, EXIF, gps, operatore)
assets ─< documents (tipo, s3_key, hash, ACL)
trees (asset_id PK/FK): tassonomia (genus, species, cultivar, family, common_name),
        dendrometria (height, dbh, trunk_circ, crown_diam, crown_insertion, età…),
        flag monumentale/dedicato/tutela/tutore/consolidamenti; dedicated_to (dati nato, L.10/2013)
tree_assessments (VTA/VSA): tree_id, date, assessor_id, type, defects JSONB per organo,
        targets, failure_class, prescriptions, next_check_due, esito, versioned
instrumental_analyses: assessment_id, tipo strumento, misure, allegati
planting_sites ("posti liberi"): asset_id, stato, specie prevista
irrigation_systems ─< irrigation_controllers ─< irrigation_sectors ─< irrigation_components
        (tipo: valvola/irrigatore/pozzetto/pompa/sensore…, asset_id FK per la geometria)
environmental_factors (asset_id FK): tipo (quarantena/focolaio/interferenza/sinistro/analisi)

ISPEZIONI / QUALITÀ
inspection_templates ─< checklist_items (condizioni, KO→NC, obblighi foto/allegato)
inspections: template_id, asset_id/area_id, operatore, gps, firma, risposte JSONB, esito
inspection_rounds (giri ispettivi) ─< round_items (sequenza, esito OK/KO)
issues (segnalazioni): reporter, canale, geom, foto, categoria, gravità, stato, sla_due
non_conformities: origine, severità, workflow, tempi risoluzione, asset/lavoro collegato

LAVORI / ECONOMIA
work_orders (ODL): client/contract/site/area, work_type, stato (configurabile), priorità,
        planned_date, durata, team_id, note, rischi, dpi JSONB
work_order_assets >─ assets (N:M con quantità per oggetto)
work_logs (consuntivi): started_at+gps, ended_at, ore uomo, quantità, mezzi, materiali,
        foto, firma operatore/cliente
teams ─< team_members; vehicles; equipment; materials
maintenance_plans (cicli ordinari) → genera work_orders
statements (SAL): periodo, lavori inclusi, validazione, pagamento
estimates/boq (computi, capitolati, preventivi da selezione spaziale)

PIATTAFORMA
audit_logs (append-only), notifications, saved_filters, report_templates,
devices (IoT: device_type) ─< measurements (measurement_type),
sync_operations (coda offline: idempotency_key, payload, stato, conflitti),
weather_observations (futuro), ecosystem_models (futuro, motore pluggable)
```

Scelte da validare insieme (v. domande §17): attributi custom in JSONB con indice GIN (flessibilità
del Catalog Manager senza migrazioni per tenant); geometria unica su `assets` con vincolo di
coerenza col tipo catalogo; partizionamento per tenant su tabelle calde quando i volumi lo
richiederanno.

---

## 10. Strategia GIS

1. **Storage**: SRID 4326; colonne `computed_area_sqm` / `computed_length_m` generate con cast a
   proiezione metrica italiana (EPSG:7791 di default, configurabile per tenant). Indici GIST ovunque;
   `ST_Subdivide` per i poligoni enormi.
2. **Rendering**: vector tiles MVT generate da PostGIS (`ST_AsMVT`) con cache Redis e invalidazione
   per bbox al salvataggio; MapLibre GL sul client con stili tematici data-driven (colori
   configurabili per tenant, requisito §47). Cluster lato tile per i punti ad alto zoom-out.
3. **Validation Engine**: job asincroni che eseguono le regole (invalide `ST_IsValid`, duplicate,
   sovrapposizioni `ST_Overlaps` tra layer mutuamente esclusivi, buchi di copertura
   `ST_Difference` sul perimetro di gestione, fuori perimetro `NOT ST_Within`, multipart, senza
   coordinate) e scrivono su `gis_validation_issues` → dashboard errori con zoom-to-error e fix
   guidati. Le regole MD (mutua esclusione vegetazione/arredo, copertura completa, prati bucati)
   sono profili attivabili per cliente.
4. **CRS e conformità**: trasformazioni PROJ complete; import che rileva il CRS (prj/gpkg) e
   riproietta; **export CAM/MD**: shapefile per macro-categoria con tracciati record esatti
   (ID_ZRIL, CODE_ISTAT, ZONA, AREA, OBJ_ID, TP, TS, CODICE, DATA_INI, DATA_FINE, DATA_AGG,
   MODIF_DA, NOTE, FOTO + campi specifici P1/L1/S1…S4) in RDN2008 (EPSG 7791-7794 per fuso).
5. **Mappa temporale**: le geometrie e gli stati sono storicizzati (DATA_INI/DATA_FINE +
   versioning) → query "as of date" per ricostruire la mappa a una data (§84) e per il bilancio
   arboreo (§53).
6. **Selezione spaziale**: draw tools MapLibre (rettangolo/poligono/raggio/lasso) → `ST_Intersects`
   server-side → azioni massive (crea ODL, esporta, assegna, stampa, analizza).
7. **Import collaudo rilievi** (miglioria X03): validazioni automatiche di nomenclatura file,
   chiusura poligoni, copertura, tolleranze del MD prima dell'accettazione della consegna.

---

## 11. Strategia offline

Requisito critico (§34). Approccio **offline-first sulla PWA operatore**, non sul backoffice.

1. **Dati**: al login (o su comando "Scarica area") la PWA scarica in IndexedDB (Dexie) il *working
   set*: lavori assegnati, asset dell'area con geometrie semplificate, cataloghi, template checklist,
   listini minimi. Pacchetti per area con stima MB (X07). Tiles della zona pre-scaricate in Cache
   Storage (service worker, Workbox).
2. **Scritture**: ogni azione offline è un **comando** in coda locale (`sync_queue`):
   `{idempotency_key, tipo, payload, geom, timestamp, device}`. Le foto vengono compresse client-side
   e accodate come blob.
3. **Sync**: al ritorno della rete il service worker svuota la coda in ordine causale verso
   `/api/v1/sync` (batch). Il server applica con **optimistic locking** (`version` per record):
   conflitto → risposta 409 con both-versions → risoluzione: merge automatico per campi disgiunti,
   altrimenti UI di scelta per l'operatore/caposquadra; le foto non confliggono mai (append-only).
4. **Identità**: gli oggetti creati offline nascono con UUID client-side (nessuna collisione, nessun
   rimappaggio di ID).
5. **UX**: badge fisso `ONLINE` / `OFFLINE — X operazioni da sincronizzare`; log sync consultabile;
   nessuna perdita silenziosa (requisito §44 esteso alla sync).
6. **Limiti dichiarati**: il backoffice desktop richiede rete; la cartografia offline copre le aree
   scaricate, non l'intero territorio; il background sync è garantito con l'app aperta (Web) e in
   modo più robusto nel wrapper Android (v. §12).

Documento di dettaglio in Fase 0: `OFFLINE-SYNC.md`.

---

## 12. Strategia Zebra barcode/NFC/RFID

Risposta al quesito §99 (PWA vs app Android): **Web desktop + PWA operatore + thin wrapper Android
per i terminali Zebra**. Il wrapper è una piccola app (WebView/TWA + plugin nativi) che NON contiene
business logic: incapsula la PWA e le espone i servizi hardware che il browser non garantisce.

| Canale | Smartphone consumer | Zebra rugged (TC2x/TC5x…) |
|---|---|---|
| Barcode/QR | Camera + libreria scanning nella PWA (ZXing/BarcodeDetector) | **DataWedge** in modalità **Intent output** captato dal wrapper → bridge JS alla PWA (keystroke come fallback universale) |
| NFC | Web NFC (Chrome/Android) per NDEF | Wrapper con API Android NFC complete (UID, NTAG, protezioni) |
| RFID UHF (lettori RFD…) | — | SDK Zebra RFID nel wrapper (fase evolutiva) |
| Stampa etichette | Server-side: generazione **ZPL** inviata a stampanti di rete; Zebra Browser Print dove disponibile | Idem + stampanti BT via wrapper |
| Background sync | Service worker (best effort) | WorkManager nel wrapper (garantito) |

Architettura software: **Hardware Abstraction Layer** in TypeScript nella PWA
(`ScannerProvider | NfcProvider | PrinterProvider`) con implementazioni `web-*` e `zebra-*`
selezionate a runtime; la business logic non conosce il device (§35).

Flussi confermati: scan/tap → risoluzione tag → scheda operativa; `TAG NON ASSOCIATO` → associa /
crea / annulla (§38); associazione massiva a ciclo continuo (§42); scrittura NFC minimale
(UUID+URL+versione, dati solo nel DB, §39); tag mai usato come autenticazione (§40) — per attività
sensibili resta il login utente + permessi.

Prima dello sviluppo (Fase 0) produrremo `ZEBRA-INTEGRATION.md` con: modelli target, matrice
DataWedge (profili, intent, keystroke), NFC (NTAG213/215/216, password protection, lock bytes),
RFID, stampa ZPL, SDK necessari e limitazioni — come richiesto da §98.

**Confermato dal committente (10/08/2026)**: in campo si useranno sia terminali Zebra sia smartphone
consumer; tecnologie richieste: **barcode, QR code e NFC**. I modelli esatti non sono ancora
definiti: ZEBRA-INTEGRATION.md includerà quindi anche una raccomandazione d'acquisto
(terminale + tag NFC + stampante etichette).

---

## 13. Strategia sicurezza

- **AuthN**: password argon2id, MFA TOTP opzionale per ruolo, session hardening, rate limiting e
  lockout progressivo, API token con scope (Sanctum).
- **AuthZ**: RBAC server-side a policy (spatie/permission + policy Laravel) su
  modulo/cliente/contratto/sito/record/azione; deny-by-default; portale cliente e pubblico su guard
  separate; field-level permission sul portale cliente (X08).
- **Tenancy**: `tenant_id` obbligatorio con global scope + test automatici anti cross-tenant
  (permission test in CI, §90).
- **OWASP**: CSRF nativo, output escaping (XSS), query parametrizzate (SQLi), CSP restrittiva,
  security headers (HSTS, X-Frame-Options, Referrer-Policy), upload: whitelist estensioni +
  verifica MIME reale + antivirus hook (ClamAV container) + storage fuori webroot su S3 con signed
  URL a scadenza (no URL pubblici, §50).
- **Dati**: TLS ovunque (Let's Encrypt), encryption at rest (LUKS sul volume + backup cifrati
  age/gpg), secret in variabili d'ambiente/sops, GDPR by design (retention configurabile, export,
  pseudonimizzazione log).
- **Audit**: append-only su tabella dedicata senza UPDATE/DELETE grant per il ruolo applicativo;
  eventi §59 completi, inclusa associazione NFC.
- **CI security** (§91): composer/npm audit, SAST (Psalm/PHPStan security rules, Semgrep), secret
  scanning (gitleaks), container scan (Trivy).
- **Tag fisici** (§40): i tag contengono solo UUID/URL firmato; validazione server-side del
  binding tag↔asset; per NTAG21x valutare password protection e counter anti-clonazione; azioni
  sensibili richiedono sempre utente autenticato.

Documento di dettaglio: `SECURITY.md` (Fase 1).

---

## 14. Strategia multi-tenant

- **Modello**: single-database, **row-level tenancy** (`tenant_id` su ogni tabella + global scope +
  indice composito), il miglior rapporto semplicità/costo per decine-centinaia di organizzazioni su
  un'infrastruttura Aruba contenuta. Isolamento rafforzato da: policy centralizzata, test anti-leak
  in CI, backup/export per singolo tenant, bucket S3 con prefix per tenant e credenziali separate.
- **Perché non schema/database-per-tenant subito**: moltiplica migrazioni, backup e costi; ci
  ripensiamo (il codice lo consente: tenancy incapsulata in un layer) se arrivassero tenant enterprise
  con obblighi di isolamento fisico — in quel caso istanza dedicata su un secondo Cloud Server.
- **Per tenant**: utenti, ruoli custom, clienti, listini, cataloghi (fork del seed MD), configurazioni
  schede/workflow (§105), branding (logo, colori, dominio dedicato opzionale via SAN sul certificato),
  contatori/numerazioni.
- **Gerarchia commerciale** (modello "impresa che gestisce per conto di clienti", BR2 Enterprise):
  il tenant è l'impresa; i suoi clienti hanno utenti "portale cliente" confinati ai propri
  contratti/siti con permessi granulari.

---

## 15. Roadmap di sviluppo per fasi

| Fase | Contenuto | Deliverable | Durata indicativa |
|---|---|---|---|
| **0 — Fondazioni** | Setup repo/CI/CD, Docker, scheletro Laravel+PWA, decisioni finali; **ZEBRA-INTEGRATION.md**, **OFFLINE-SYNC.md**, **GIS-DATA-MODEL.md**; schema DB core; seed catalogo MD v2.1 | Ambiente dev funzionante, documenti tecnici, ER definitivo | 2-3 settimane |
| **1 — Core GIS + Censimento** | Tenancy, auth/RBAC, clienti/contratti/località/aree, Catalog Manager, CRUD asset con mappa (MVT), foto/documenti, ricerca globale, audit/versioning | Censimento utilizzabile da backoffice | 6-8 settimane |
| **2 — Alberi & VTA** | Scheda albero completa, timeline, VTA/VSA con difetti e classi, strumentali, scadenzario, dashboard, bilancio arboreo, dedicati/monumentali; **anticipo CAM** (decisione 10/08/2026, clienti PA entro 6-12 mesi): import/export shapefile RDN2008 di alberi e aree secondo il profilo base del Modello Dati v2.1 | Catasto alberi conforme L.10/2013 + primo export CAM | 5-7 settimane |
| **3 — Mobile offline v1** | PWA operatore (I MIEI LAVORI/MAPPA/SCANSIONA/…), offline working set + sync, censimento massivo, scan barcode/NFC web, indicatore sync | Operatività in campo su smartphone | 6-8 settimane |
| **4 — Lavori & Economia** | ODL workflow, programmazione (agenda/calendario/Gantt/mappa), squadre/mezzi/materiali, consuntivazione campo, listini, cicli ordinari, straordinaria, SAL, report economici | Ciclo lavori end-to-end | 6-8 settimane |
| **5 — Qualità & Ispezioni** | Template checklist, ispezioni, giri ispettivi, aree gioco EN1176, segnalazioni, NC, SLA | Modulo sicurezza/qualità completo | 4-5 settimane |
| **6 — Zebra & stampa** | Wrapper Android (DataWedge intent, NFC nativo), associazione massiva, stampa ZPL, test su device reali | Integrazione rugged certificata | 3-4 settimane |
| **7 — Import/Export CAM & Report** | Wizard import 7 step, GDAL, **completamento** profilo CAM/MD import+export (tutte le macro-categorie; il profilo base alberi/aree è anticipato in Fase 2), collaudo rilievi, generatore PDF template, export massivi | Conformità CAM completa dimostrabile | 4-5 settimane |
| **8 — Portali & Irrigazione** | Portale cliente, portale pubblico + QR pubblico, modulo irrigazione completo, gestione idrica base | Prodotto completo v1.0 | 5-6 settimane |
| **9+ — Evoluzioni** | Meteo/Water/Benefits (motori pluggable), ottimizzazione giri (OSRM), IoT, AI assistant, RFID UHF, detection anomalie | Moduli opzionali | a seguire |

Ogni fase termina con demo, test (unit/integration/E2E sulle feature della fase) e deploy su staging;
la produzione parte tipicamente con un cliente pilota alla fine della Fase 4/5.

---

## 16. Rischi tecnici e mitigazioni

| # | Rischio | Impatto | Mitigazione |
|---|---|---|---|
| 1 | **Sync offline con conflitti** (più operatori, stessa area) | Perdita/duplicazione dati campo | Comandi idempotenti, optimistic locking, UUID client-side, UI di risoluzione, test di sync simulati in CI; pilotare con un solo team prima del rollout |
| 2 | **Integrazione Zebra più fragile del previsto** (versioni DataWedge, Web NFC limitato) | Blocco operatività rugged | Fase 0 di verifica su device reali PRIMA dello sviluppo (richiesta modelli in Q4); HAL con fallback keystroke; wrapper Android già in architettura |
| 3 | **Performance GIS su milioni di geometrie** | Mappa lenta, timeout | MVT+cache, indici GIST, semplificazione per zoom, clustering, partizionamento; test di carico con dataset sintetico da 1M oggetti in Fase 1 |
| 4 | **Complessità del Catalog Manager** (campi custom + validazioni + offline) | Scope creep | JSONB + schema di validazione versionato distribuito alla PWA; congelare il set di tipi campo della v1 |
| 5 | **Conformità CAM/MD imprecisa** | Contestazioni da clienti PA | Il seed è il catalogo MD v2.1 letterale; export collaudato contro i tracciati record del documento; test di round-trip import→export |
| 6 | **Un solo server di produzione (budget)** | Downtime su guasto | Cloud Server PRO con storage ridondato e SLA 99,95%; backup off-site testati (restore test trimestrale); runbook di ripristino: RPO 1h (WAL), RTO 4h |
| 7 | **DBaaS Aruba senza PostGIS** | Nessuno (previsto) | PostgreSQL self-managed in container di default; DBaaS solo se Aruba conferma PostGIS per iscritto |
| 8 | **Foto in campo = volumi storage crescenti** | Costi/lentezza sync | Compressione client-side (max 2048px + originale opzionale), lifecycle S3 (originali → classe economica), quota per tenant |
| 9 | **Scope molto ampio (109 sezioni)** | Progetto che non converge | Roadmap a fasi con MVP chiaro (Fasi 1-4), priorità MoSCoW nella matrice, ogni fase rilascia valore usabile |
| 10 | **Team ridotto su stack multiplo** (PHP+Vue+GIS+Android) | Colli di bottiglia | Monolite modulare (una sola app), wrapper Android minimale, documentazione §96 mantenuta insieme al codice |

---

## 17. Domande indispensabili prima di iniziare

Rispondi anche solo con il numero e una riga.

1. **Chi è il primo utente reale?** La tua azienda (impresa di manutenzione multi-cliente) come tenant
   unico iniziale, o prevedi da subito più organizzazioni? (Determina quanto investire subito nella
   multitenancy self-service.)
2. **Clienti PA nel breve periodo?** L'export CAM/shapefile RDN2008 e il portale pubblico servono per
   gare imminenti (→ anticipo la Fase 7) o sono un "poi"?
3. **Dati esistenti da migrare**: hai già censimenti (Excel, SHP, altri gestionali)? In che formato e
   di che dimensioni?
4. **Modelli Zebra**: quali terminali/lettori/stampanti Zebra intendi acquistare o possiedi
   (es. TC22/TC53, RFD40, ZQ511)? Serve per ZEBRA-INTEGRATION.md e per la Fase 6. Hai già tag
   NFC/ArboTag di un fornitore specifico?
5. **Volumi attesi al primo anno**: n. operatori in campo, n. clienti, n. oggetti censiti stimati?
   (Dimensiona il taglio del server di produzione.)
6. **Lingue**: solo italiano, o serve bilinguismo (it/de come GreenSpaces) o altre lingue?
7. **Aruba**: quale abbonamento hai attivo oggi (hosting condiviso, dominio, PEC, cloud)? Confermi il
   budget infrastruttura ~60–95 €/mese a regime e l'acquisto del primo VPS dev (~6 €/mese) a fine
   Fase 1?
8. **Nome del prodotto**: il software deve avere nome autonomo (§108) — hai già un nome/brand, o vuoi
   una rosa di proposte?
9. **VTA**: i tuoi agronomi usano un metodo/scheda specifica (SIA, QTRA, metodo proprietario)? Serve
   per configurare il framework di valutazione senza copiare metodi proprietari.
10. **Firma e valore legale**: per consuntivi e perizie basta la firma touch grafometrica semplice, o
    serve firma digitale qualificata su alcuni PDF?
11. **Notifiche push/SMS/WhatsApp**: quali canali vuoi davvero in v1? (Email+in-app sono incluse;
    push richiede configurazione; SMS/WhatsApp hanno costi per messaggio.)
12. **Priorità tra moduli della Fase 8+**: irrigazione, portale cliente e portale pubblico — in che
    ordine di importanza per il tuo business?

---

**Prossimo passo**: attendo la tua approvazione (ed eventuali risposte alle domande) prima di creare
qualsiasi struttura di progetto o scrivere codice, come da §109 e §110 del prompt.
