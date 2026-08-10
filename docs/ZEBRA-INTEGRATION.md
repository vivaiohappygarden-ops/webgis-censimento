# ZEBRA-INTEGRATION.md

**Piattaforma WebGIS gestione del verde — Integrazione dispositivi Zebra (barcode / QR / NFC / RFID / stampa etichette)**
Documento tecnico di Fase 0 — versione 1.0 del 10/08/2026 — risponde al capitolato §98 e ai requisiti M05–M13 della matrice.

> **Contesto**: architettura approvata = monolite modulare Laravel 12 + PostgreSQL/PostGIS + PWA
> operatore Vue 3 offline-first (Dexie + Workbox) + API /api/v1. Decisioni committente (10/08/2026):
> in campo si useranno **sia terminali Zebra sia smartphone consumer**; tecnologie richieste
> **barcode, QR e NFC**; i modelli Zebra **non sono ancora stati acquistati**; clienti PA attesi
> entro 6–12 mesi. Le verifiche sul portafoglio Zebra sono state fatte ad **agosto 2026** su
> zebra.com, techdocs.zebra.com e rivenditori; i prezzi sono indicativi (street price, IVA esclusa)
> e vanno confermati con un distributore italiano prima dell'ordine.

**Strategia in una riga**: la PWA fa tutto ciò che il browser garantisce (camera scanning, Web NFC,
DataWedge in modalità keystroke); un **thin wrapper Android** (WebView + bridge JS, zero business
logic) aggiunge sui terminali Zebra ciò che il browser non garantisce (DataWedge via intent, NFC
nativo con protezioni NTAG, stampa Bluetooth, sync in background con WorkManager). Tutto passa da un
**Hardware Abstraction Layer TypeScript** (§13): la business logic non sa mai su quale device gira.

---

## Indice

1. [Dispositivi Zebra compatibili](#1-dispositivi-zebra-compatibili)
2. [Modalità di acquisizione barcode](#2-modalità-di-acquisizione-barcode)
3. [DataWedge](#3-datawedge)
4. [NFC](#4-nfc)
5. [RFID UHF (predisposizione futura)](#5-rfid-uhf-predisposizione-futura)
6. [Browser/PWA: cosa funziona senza wrapper](#6-browserpwa-cosa-funziona-senza-wrapper)
7. [Wrapper Android (thin WebView + bridge JS)](#7-wrapper-android-thin-webview--bridge-js)
8. [Gestione offline lato device](#8-gestione-offline-lato-device)
9. [Sicurezza dei tag](#9-sicurezza-dei-tag)
10. [Stampa Zebra / ZPL](#10-stampa-zebra--zpl)
11. [SDK necessari (e quando NON servono)](#11-sdk-necessari-e-quando-non-servono)
12. [Limitazioni note](#12-limitazioni-note)
13. [Architettura Hardware Abstraction Layer (TypeScript)](#13-architettura-hardware-abstraction-layer-typescript)
14. [Raccomandazione d'acquisto](#14-raccomandazione-dacquisto)

---

## 1. Dispositivi Zebra compatibili

Portafoglio verificato ad agosto 2026 sulle pagine prodotto/spec-sheet zebra.com.

### 1.1 Terminali touch (target primario della PWA)

| Modello | Stato | Caratteristiche chiave | Idoneità progetto |
|---|---|---|---|
| **TC22 / TC27** (serie TC2x) | **Corrente** — entry-level professionale | Display 6" 2160×1080, Qualcomm QCM5430 (8 GB/128 GB), Android (upgrade pluriennali garantiti da Zebra), IP68, Wi-Fi 6/6E, 5G (TC27), **NFC**, scan engine **SE4710** o **SE55 advanced range**, batteria sostituibile 3.800/5.200 mAh, pulsante scan laterale | ★★★ **Consigliato come standard di flotta**: miglior rapporto prezzo/funzioni per censimento e squadre |
| **TC53e / TC58e** (serie TC5x, gen. "e") | **Corrente** — fascia alta | Display 6", Wi-Fi 6E, 5G (TC58e), processore Qualcomm recente, SE55 advanced range, variante **TC53e-RFID** con lettore **UHF RFID short-range integrato** | ★★★ Per capisquadra/rilevatori intensivi; TC53e-RFID è la via più semplice all'RFID futuro (§5) |
| **TC53 / TC58** (2022) | Correnti, generazione precedente | Simili ai "e" con Wi-Fi 6E/5G | ★★ Alternativa se offerti a forte sconto |
| **EM45 / EM45 RFID** | **Corrente** (lancio 2025) — "enterprise mobile" formato smartphone | 6,7" 120 Hz, Qualcomm QCM5430, 8/128 GB, IP65/68, camera 50 MP OIS (**scanning via camera, nessuno scan engine dedicato**), 5G/Wi-Fi 6E; EM45 RFID con lettore UHF integrato (20 tag/s, ~1,2 m) | ★★ Per figure "ibride" ufficio/campo che vogliono un form factor smartphone; non per scansione intensiva |
| **MC2200 / MC2700 (MC27)** | **In fine vita commerciale**: diversi rivenditori li marcano *discontinued*; verificare disponibilità/EOL con il distributore | Terminale con tastiera fisica 34 tasti, display 4", Android datato | ✗ **Sconsigliati**: tastiera inutile per una PWA touch a pulsanti grandi; ciclo di vita residuo breve |

Tutti i terminali touch elencati hanno: Android con GMS (quindi **Chrome e Web NFC disponibili**),
NFC integrato, **DataWedge preinstallato**, camera posteriore utilizzabile come fallback di scansione.

### 1.2 Lettori RFID a slitta (sled)

| Modello | Stato | Note |
|---|---|---|
| **RFD40** (Standard/Premium/Premium+) | Corrente | UHF RAIN RFID; aggancio al terminale via **adattatore eConnex** dedicato per modello (ADP-RFD40-TC2X-2E per TC22/27, ADP-RFD40-TC5X-2E per TC53/58) oppure Bluetooth |
| **RFD90** | Corrente | Versione ultra-rugged, portata superiore, per ambienti severi |

Solo predisposizione futura (§5): **non acquistare ora**.

### 1.3 Stampanti etichette (linguaggio ZPL II, piattaforma Link-OS)

| Modello | Stato | Tipo | Idoneità progetto |
|---|---|---|---|
| **ZD421** (serie ZD4xx; anche ZD411 2") | Corrente | Desktop 4", termica diretta (ZD421d) o **trasferimento termico (ZD421t)**, USB + opzioni LAN/Wi-Fi/BT | ★★★ **Consigliata**: stampa in ufficio di etichette QR/barcode durevoli (trasferimento termico su polietilene/poliestere) |
| **ZD621** (serie ZD6xx; anche ZD611 2") | Corrente | Desktop premium, 203/300 dpi, display LCD, più veloce | ★★ Se i volumi di stampa crescono |
| **ZQ511 / ZQ521** (serie ZQ5xx) | Correnti | Mobili 3"/4", termica diretta, IP54, MIL-STD-810G, **Bluetooth 4.1 (Classic+LE) + Wi-Fi 802.11ac**, ZPL+CPCL, varianti linerless e RFID | ★★ Stampa in campo (cartellini provvisori, ricevute intervento); attenzione: termica diretta = non adatta a etichette esposte al sole (§10.4) |

---

## 2. Modalità di acquisizione barcode

Due canali, entrambi supportati dall'HAL (§13); requisito M06: QR, Code128, Code39, DataMatrix, EAN.

| Criterio | **Camera + libreria software** (smartphone consumer, EM45) | **Scan engine dedicato** (SE4710/SE55 su TC2x/TC5x) |
|---|---|---|
| Come | `getUserMedia` + `BarcodeDetector` (nativo Chrome Android, accelerato ML Kit) con polyfill ZXing/`zxing-wasm` | Modulo imager hardware con mira laser/LED, gestito da **DataWedge** |
| Velocità | 0,5–2 s (inquadratura + fuoco) | < 200 ms, alla pressione del tasto fisico |
| Pieno sole / codici rovinati, sporchi, bagnati | Debole (riflessi, contrasto) | Ottima (illuminatore dedicato, decoder industriale) |
| Distanza | ~10–40 cm | SE4710: contatto–60 cm; **SE55 advanced range: da vicino a diversi metri** (utile per cartellini su alberi alti o dietro recinzioni) |
| Scansione intensiva (associazione massiva §42, giri ispettivi §101) | Affaticante (mirare con lo schermo) | Progettata per centinaia di letture/ora, tasto trigger fisico |
| Consumo batteria | Alto (camera + CPU) | Trascurabile |
| Costo | Zero (device già in tasca) | Incluso nel terminale rugged |

**Decisione**: la PWA implementa **sempre** il canale camera (funziona ovunque, incluso l'EM45 che
non ha scan engine); sui terminali Zebra il canale DataWedge è preferito automaticamente dall'HAL.
Per il rilievo massivo e i giri ispettivi il terminale con scan engine è fortemente raccomandato;
per l'operatore occasionale basta lo smartphone.

---

## 3. DataWedge

### 3.1 Che cos'è, versioni

**DataWedge** è il servizio Zebra **preinstallato su tutti i terminali Android Zebra** che
intercetta lo scan engine (e altri input: camera, lettori BT, RFID) e consegna il dato decodificato
alle applicazioni **senza scrivere codice nativo di scansione** (niente EMDK, §11). È configurato a
**profili** e pilotabile via **API a intent Android**.

- Versioni correnti (2025–2026): ramo **13.x/14.x** (es. DataWedge 14.3.5 distribuito con i
  LifeGuard update Android 13/14 del 2025). Tutti i dispositivi in §1.1 spediscono con DW ≥ 11.
- Tutte le funzioni usate in questo progetto (profili, keystroke, intent output, `SOFT_SCAN_TRIGGER`,
  `SET_CONFIG`, `SWITCH_TO_PROFILE`) sono stabili **da DataWedge 8.x in poi**: nessun rischio di
  versione sui device correnti. Riferimento: techdocs.zebra.com/datawedge/latest.

### 3.2 Profili

Un profilo lega: **app associata** (package/activity) → **input** (barcode: decoder abilitati,
illuminazione, picklist) → **elaborazione** (Basic/Advanced Data Formatting: prefissi, suffissi,
sostituzioni) → **output** (keystroke | intent | IP). Il profilo del progetto (`VerdePWA`):

- decoder abilitati **solo**: QR Code, Code 128, Code 39, DataMatrix, EAN-8/13 (riduce false letture);
- picklist ON (in un giro ispettivo più cartellini possono essere nel campo visivo);
- nessuna trasformazione dati (il payload è già un URL/UUID completo, §9.1);
- output secondo il canale (v. sotto).

I profili si esportano come file `.db` e si distribuiscono in massa via **StageNow** (tool Zebra
gratuito, provisioning a barcode) o EMM: configurazione identica su tutta la flotta senza toccare i
device uno a uno.

### 3.3 Output keystroke vs intent

| | **Keystroke output** | **Intent output** |
|---|---|---|
| Meccanismo | DataWedge "digita" il codice come tastiera nel campo a fuoco | DataWedge invia un **broadcast intent Android** con il dato nel payload |
| Funziona nel browser Chrome (PWA pura)? | **Sì** (profilo associato a `com.android.chrome`) | **No**: una pagina web non può registrare un `BroadcastReceiver` |
| Robustezza | Fragile: richiede un input a fuoco, subisce layout di tastiera/IME, autocorrezione, caratteri speciali alterati, nessun metadato | **Robusta**: consegna atomica di dato + simbologia + sorgente, indipendente dal focus UI |
| Metadati (simbologia, timestamp) | No | Sì (`com.symbol.datawedge.label_type`, ecc.) |
| Uso nel progetto | **Fallback universale** in Fase 3 (PWA in Chrome sul terminale Zebra, prima del wrapper): campo di input nascosto sempre a fuoco nelle schermate di scansione, suffisso ENTER come terminatore | **Canale primario** dalla Fase 6, dentro il wrapper (§7) |

### 3.4 API intent (esempi essenziali)

Configurazione del profilo da codice (una tantum, dal wrapper al primo avvio):

```kotlin
// Wrapper Kotlin — crea/configura il profilo via DataWedge API
val config = Bundle().apply {
    putString("PROFILE_NAME", "VerdePWA")
    putString("PROFILE_ENABLED", "true")
    putString("CONFIG_MODE", "CREATE_IF_NOT_EXIST")
    putParcelableArray("APP_LIST", arrayOf(Bundle().apply {
        putString("PACKAGE_NAME", "it.acme.verde.wrapper")   // package del wrapper
        putStringArray("ACTIVITY_LIST", arrayOf("*"))
    }))
    putBundle("PLUGIN_CONFIG", Bundle().apply {
        putString("PLUGIN_NAME", "INTENT")
        putString("RESET_CONFIG", "true")
        putBundle("PARAM_LIST", Bundle().apply {
            putString("intent_output_enabled", "true")
            putString("intent_action", "it.acme.verde.SCAN")
            putString("intent_delivery", "2")                 // 2 = Broadcast
        })
    })
}
sendBroadcast(Intent("com.symbol.datawedge.api.ACTION").apply {
    putExtra("com.symbol.datawedge.api.SET_CONFIG", config)
})
```

Ricezione dello scan e trigger software (per il pulsante SCANSIONA a schermo):

```kotlin
// BroadcastReceiver nel wrapper
override fun onReceive(context: Context, intent: Intent) {
    val data = intent.getStringExtra("com.symbol.datawedge.data_string") ?: return
    val symbology = intent.getStringExtra("com.symbol.datawedge.label_type") ?: "unknown"
    bridge.emitToWebView("scan", JSONObject(mapOf("data" to data, "symbology" to symbology)))
}

// Avvio scansione da UI (equivalente alla pressione del trigger fisico)
sendBroadcast(Intent("com.symbol.datawedge.api.ACTION").apply {
    putExtra("com.symbol.datawedge.api.SOFT_SCAN_TRIGGER", "START_SCANNING")
})
```

### 3.5 Configurazione per PWA/WebView

Tre scenari, in ordine cronologico di progetto:

1. **Fase 3 — PWA in Chrome su Zebra (senza wrapper)**: profilo `VerdePWA-Chrome` associato a
   `com.android.chrome`, **keystroke output** + suffisso ENTER. Limite: il profilo vale per tutte le
   schede Chrome; la PWA deve tenere il focus su un input dedicato nelle viste di scansione.
2. **Fase 6 — wrapper WebView**: profilo associato al package del wrapper, **intent output**
   (broadcast); il wrapper inoltra alla PWA via bridge JS (§7). È l'approccio dimostrato dal sample
   ufficiale **ZebraDevs/DW-WEBVIEW** su GitHub (testato nel 2025 su Android 13/14, WebView 117+).
3. **TWA (Trusted Web Activity)**: scartata come contenitore principale — una TWA non permette
   bridge JS custom; si usa la WebView di sistema (o una activity ibrida) che lo permette.

### 3.6 Nota operativa

DataWedge sostituisce interamente la scrittura di codice di scansione nativo: **nessuna dipendenza
da EMDK** (§11). L'unico codice nostro è il `BroadcastReceiver` + bridge del wrapper (~100 righe).

---

## 4. NFC

### 4.1 Hardware

Tutti i terminali in §1.1 e gli smartphone Android di fascia media/alta hanno controller NFC
13,56 MHz (ISO 14443 A/B, ISO 15693) e leggono/scrivono i tag NDEF proposti. Gli iPhone leggono i
tag NDEF (anche da Safari tramite prompt di sistema per URL), ma **non supportano Web NFC** (§4.2).

### 4.2 Web NFC su Chrome Android: capacità e limiti reali

Verificato su developer.chrome.com/docs/capabilities/nfc e MDN (agosto 2026):

| Capacità | Stato |
|---|---|
| Lettura/scrittura **NDEF** (`NDEFReader.scan()` / `.write()`) | ✔ Chrome Android ≥ 89 (anche Edge/Opera/Samsung Internet Android) |
| UID del tag (`serialNumber` nell'evento `reading`) | ✔ disponibile |
| Blocco permanente in sola lettura (`makeReadOnly()`) | ✔ da Chrome 100 |
| Requisiti | Solo HTTPS; la lettura parte da un **gesto utente**; pagina **visibile e in foreground** |
| Comandi raw (ISO-DEP, NfcA `transceive`) → **password NTAG (PWD_AUTH), READ_SIG, counter config** | ✘ non supportati: solo NDEF |
| Lettura in background / app chiusa | ✘ |
| iOS / iPadOS (Safari e qualsiasi browser iOS) | ✘ nessun supporto, nessuna roadmap |
| Card emulation (HCE), peer-to-peer | ✘ |

**Conseguenze progettuali**: (a) i flussi "tap → scheda" e "associa tag" (M08, §38) funzionano in
PWA pura su Android; (b) le operazioni di **protezione avanzata** dei tag (password NTAG, verifica
firma di originalità) richiedono il wrapper (§7) o tag pre-configurati dal fornitore (§9); (c) su
iPhone il tap NFC apre comunque l'URL del tag nel browser (record NDEF URI) — flusso di sola
consultazione, non operativo.

### 4.3 Payload NDEF

Un solo record **URI**, es. `https://verde.example.it/t/9f3c2e5a-...` (UUID del tag, §9.1):
~60–90 byte. Compatibile con qualunque lettore (anche il prompt iOS). Il DB (`asset_tags`)
conserva UID, tipo, stato e storia dell'associazione; **il tag non contiene dati applicativi**.

### 4.4 Scelta del chip: NTAG213 / 215 / 216

| Chip | Memoria utente | Note | Uso |
|---|---|---|---|
| **NTAG213** | 144 B | Sufficiente per l'URL+UUID; il più economico e diffuso | **Standard di progetto** |
| NTAG215 | 504 B | Nessun vantaggio per noi (celebre solo per usi consumer) | — |
| **NTAG216** | 888 B | Margine per URL firmati lunghi o payload futuri | Alternativa a basso sovrapprezzo |

Tutta la famiglia NTAG21x offre: UID 7 byte, **password protection** (32 bit, contro riscrittura),
**counter NFC 24 bit**, **firma di originalità ECDSA** (comando READ_SIG) — funzioni usate in §9.

### 4.5 Form factor: tag per alberi e per arredo (resistenza UV/esterno)

**Alberi — tag a chiodo** (il tag si muove lungo il chiodo mentre il tronco cresce, senza danno):

- **ArboTag / Signumat Urban Forest Line** (Latschbacher, AT — in Italia distribuito anche da
  R3GIS): sistema professionale targa numerata + chiodo inox + martello magnetico + caricatore;
  ~100 alberi/ora per operatore. La targa classica è **visuale/numerata** (abbinabile a QR inciso);
  la linea Signumat offre anche versioni **con transponder** — verificare col fornitore chip e
  frequenza (richiedere esplicitamente **NFC HF 13,56 MHz NTAG21x**, non solo UHF).
- **Chiodi NFC dedicati** ("RFID nail tag"): corpo ABS/PPS con chip NTAG213/216, pensati per
  tronchi e legname, resistenti a UV, pioggia e -20/+70 °C; fornitori: Shop NFC (IT),
  RFIDConnect/Xiucheng e simili (min. ordine tipico 100–500 pz).
- Alternativa per piante giovani/arbusti: **fascette/etichette ad anello** in PE stabilizzato UV con
  QR stampato (nessun chiodo), da sostituire alla crescita.

**Arredo urbano e giochi — tag adesivi/rivettabili**:

- **on-metal** obbligatorio su superfici metalliche (panchine, cestini, strutture gioco in acciaio):
  disco 30–34 mm con strato ferrite, ABS/PVC/epoxy, **IP68**, adesivo 3M VHB o foro per rivetto;
  disponibili NTAG213/216 (es. linea "Industrial IP68" di Shop NFC, Tagstand, AtlasRFIDStore).
- Su legno/plastica: tag epoxy o etichette PET outdoor UV-resistant.
- Ogni tag NFC è sempre **affiancato dal QR ridondante** stampato (stessa URL): fallback per iPhone,
  per tag NFC guasti e per la stampa interna (§10).

---

## 5. RFID UHF (predisposizione futura)

**Non in scope per la v1** (roadmap Fase 9+). Cosa predisponiamo ora, a costo quasi zero:

1. **Modello dati**: `asset_tags.type` include già `RFID`; per tag UHF si registrano EPC (96 bit) e
   TID. Nessuna migrazione futura.
2. **HAL**: interfaccia `RfidProvider` riservata (§13.1) senza implementazioni: la UI di inventario
   massivo si aggancerà lì.
3. **Hardware pronto sul mercato** (verificato 2026): sled **RFD40/RFD90** con adattatore eConnex per
   TC22/27 e TC53/58 (o Bluetooth); **TC53e-RFID** e **EM45 RFID** con lettore UHF short-range
   integrato (~1,2 m, ~20 tag/s) — sufficiente per inventari di magazzino attrezzature e verifiche di
   prossimità, non per varchi.
4. **Integrazione software**: il lettore UHF **non è accessibile dal browser**; richiederà lo
   **Zebra RFID SDK for Android** dentro il wrapper (nuovo provider `zebra-rfid`). Nessun impatto
   sull'architettura: si aggiunge un modulo al bridge.

Caso d'uso realistico futuro: inventario rapido attrezzature/mezzi in deposito e conteggio arredi
metallici senza linea di vista. Per gli **alberi l'UHF è poco indicato** (il legno umido assorbe il
segnale; costi tag on-tree alti): NFC+QR resta la scelta corretta.

**Raccomandazione**: non acquistare sled ora; se si vuole l'opzione a portata di mano, preferire
TC53e-RFID al posto del TC53e nella configurazione completa (§14).

---

## 6. Browser/PWA: cosa funziona senza wrapper

Cosa copre la **PWA pura in Chrome Android** (smartphone consumer e terminali Zebra, Fasi 3–5):

| Funzione | Meccanismo | Limiti |
|---|---|---|
| Scan QR/barcode via camera | `getUserMedia` + **`BarcodeDetector`** (nativo su Chrome Android, formati: qr, code_128, code_39, data_matrix, ean_13/8, ecc.) con **polyfill `zxing-wasm`/`barcode-detector`** quando assente | `BarcodeDetector` non c'è su desktop/Firefox/iOS → il polyfill WASM è sempre incluso nel bundle; prestazioni inferiori allo scan engine (§2) |
| Scan con scan engine Zebra | DataWedge **keystroke output** su profilo associato a Chrome (§3.5.1) | Fragilità del keystroke: focus obbligatorio, niente metadati |
| NFC lettura/scrittura NDEF + UID + lock | **Web NFC** (§4.2) | Solo Android/Chrome, foreground, niente comandi raw (no password NTAG) |
| Offline dati+mappe | Dexie/IndexedDB + Workbox (v. OFFLINE-SYNC.md) | Quota storage gestita dal browser; eviction possibile se non `persist()` |
| Sync in background | Background Sync API / periodic sync | **Best effort**: garantito solo ad app aperta; Chrome può posticipare |
| Foto | `<input capture>` / getUserMedia + compressione client | OK |
| GPS | Geolocation API | OK (precisione dichiarata salvata su ogni rilievo) |
| Stampa etichette | Solo via server (stampanti di rete, §10.2) o Browser Print su PC backoffice | Nessun accesso Bluetooth SPP dal browser |
| iPhone (consumer) | Camera QR ✔, consultazione via tap NFC ✔ | Web NFC ✘, PWA iOS con limiti storage → dichiarato: **operatività di campo = Android** |

**Conclusione**: la PWA pura è pienamente operativa per censimento, ispezioni e lavori su Android.
Il wrapper (§7) non è un prerequisito del go-live: è un **potenziamento per la flotta Zebra**.

---

## 7. Wrapper Android (thin WebView + bridge JS)

### 7.1 Quando serve

Il wrapper diventa necessario quando si vuole una (o più) di queste garanzie sui terminali Zebra:

1. **DataWedge via intent** invece del keystroke (affidabilità e metadati, §3.3);
2. **NFC nativo completo**: lettura in reader-mode più reattiva, comandi raw NTAG21x
   (password/PWD_AUTH, READ_SIG anti-clonazione, configurazione counter — §9);
3. **stampa Bluetooth** su ZQ511 in campo (§10.3);
4. **sync garantita in background** con **WorkManager** (anche ad app chiusa/riavvio device);
5. modalità **kiosk/launcher** (Enterprise Home Screen) per device dedicati al solo uso aziendale;
6. domani: **RFID SDK** (§5).

### 7.2 Architettura

```
┌────────────────────────── APK "VerdeField" (wrapper, Kotlin, ~1.500 righe) ─────────────────────┐
│  WebView (stessa PWA, stessa URL, stessa cache/IndexedDB del dominio)                           │
│     ▲ window.ZebraBridge (addJavascriptInterface) / WebMessageListener (postMessage)            │
│     │                                                                                           │
│  ┌──┴───────────┬──────────────────┬───────────────────┬───────────────────────┐               │
│  │ DataWedge    │ NfcModule        │ PrinterModule     │ SyncModule            │               │
│  │ Receiver     │ NfcAdapter       │ BT socket SPP →   │ WorkManager: job      │               │
│  │ (intent      │ reader-mode,     │ ZPL raw a ZQ511;  │ periodico che sveglia │               │
│  │  broadcast)  │ NfcA.transceive  │ discovery paired  │ la coda di sync della │               │
│  │              │ (PWD_AUTH, SIG)  │ devices           │ PWA / flush foto      │               │
│  └──────────────┴──────────────────┴───────────────────┴───────────────────────┘               │
└──────────────────────────────────────────────────────────────────────────────────────────────────┘
```

Regole ferree: il wrapper **non contiene business logic né UI** (solo splash + WebView), non parla
con le API server (eccetto il flush della coda già preparata dalla PWA), è versionato separatamente
e cambia di rado. La PWA rileva il bridge a runtime (§13.4) e degrada senza errori quando assente.

### 7.3 Cosa espone il bridge (contratto JS)

```ts
// Iniettato dal wrapper come window.ZebraBridge
interface ZebraBridgeNative {
  getInfo(): string;                        // JSON: model, serial, dwVersion, bridgeVersion
  dwSoftScanTrigger(action: 'START' | 'STOP'): void;
  nfcStartRead(optionsJson: string): void;  // eventi → callback registrata
  nfcWriteUrl(url: string): void;
  nfcProtect(optionsJson: string): void;    // password NTAG, lock, counter mirror
  btListPrinters(): string;                 // JSON PrinterInfo[]
  btPrintZpl(mac: string, zpl: string): void;
  scheduleSync(json: string): void;         // registra job WorkManager
}
// Eventi wrapper → PWA: window.dispatchEvent(new CustomEvent('zebra:<tipo>', {detail}))
//   tipi: 'scan' | 'nfc-tag' | 'nfc-error' | 'print-result' | 'sync-request'
```

### 7.4 Distribuzione

Sideload via **StageNow**/EMM sulla flotta Zebra (nessun obbligo di Play Store); in alternativa
Managed Google Play privato. Aggiornamenti del wrapper rari: la logica vive nella PWA, che si
aggiorna da sola via service worker.

---

## 8. Gestione offline lato device

Il dettaglio della sync è in OFFLINE-SYNC.md; qui i punti specifici di campo/hardware:

1. **Risoluzione tag offline**: la PWA scarica nel working set l'indice `tag → asset` dell'area
   (Dexie, tabella `tags`): scan/tap risolvono la scheda **senza rete**. Tag sconosciuto offline →
   flusso `TAG NON ASSOCIATO` (§38): l'associazione entra in coda con idempotency key e viene
   validata dal server alla sync (conflitto: il server rifiuta doppie associazioni → UI di
   risoluzione).
2. **Associazione massiva offline** (§42): ciclo continuo scan→conferma→prossimo interamente locale;
   contatore "X associati, Y in coda".
3. **Foto**: compresse client-side (max 2048 px) in coda blob; su Zebra con wrapper il flush è
   affidato a WorkManager (riprende dopo riavvio, rete a tratti, doze mode).
4. **Storage**: `navigator.storage.persist()` richiesto al primo avvio; i terminali in §1.1 hanno
   128 GB — nessun vincolo pratico per aree cartografiche pre-scaricate.
5. **Batteria**: turni lunghi → batterie PowerPrecision sostituibili a caldo (TC2x/TC5x); lo
   scanning via scan engine consuma molto meno della camera (§2).
6. **Kiosk**: sui Zebra dedicati, Enterprise Home Screen blocca il device sulla sola app di campo.

---

## 9. Sicurezza dei tag

### 9.1 Contenuto: solo UUID/URL, mai dati

- Il tag (NFC o QR) contiene **solo** `https://<dominio>/t/{uuid}` con **UUID v4 dedicato al tag**
  (≠ UUID dell'asset ≠ codice censimento, requisito C05): nessun dato personale/applicativo, nessuna
  informazione inferibile. Revoca = si stacca il binding nel DB, il tag fisico diventa inerte.
- Variante rafforzata (opzionale, per tenant PA): path con firma HMAC troncata
  `/t/{uuid}.{sig}` — un URL forgiato a caso non risolve mai (riduce enumerazione/scan abuse).
- Il server risponde in base al **contesto**: utente autenticato dell'organizzazione → scheda
  operativa; anonimo → pagina pubblica dell'albero (se il tenant la abilita, §56) o 404.

### 9.2 Anti-clonazione e anti-riscrittura (NTAG21x)

| Minaccia | Contromisura | Dove si fa |
|---|---|---|
| Riscrittura del tag (URL sostituito) | **Password protection NTAG** (AUTH0 su pagine di config: scrittura solo con password) oppure lock permanente `makeReadOnly()` | Password: wrapper (comandi raw) o tag pre-configurati dal fornitore; lock: anche Web NFC (Chrome ≥100) |
| Clonazione su tag vergine | Verifica **UID** (7 byte, univoco di fabbrica) registrato in `asset_tags` alla scrittura: URL giusto su UID sbagliato → alert; in profondità: **firma di originalità ECDSA NXP** via READ_SIG | UID: già disponibile via Web NFC (`serialNumber`); READ_SIG: solo wrapper |
| Replay/copia statica dell'URL | **Counter NFC 24 bit** con counter-mirror nell'URL (stile SUN "light"): il server rileva contatori non monotoni | Configurazione counter: wrapper o fornitore |
| Sostituzione fisica tra due asset | L'associazione tag↔asset è **storicizzata e auditata** (chi, quando, dove — GPS); ri-associazione richiede permesso RBAC dedicato | Server |

Nota pratica: password e counter-mirror si possono **ordinare pre-configurati** dal fornitore dei
tag (encoding service): così anche la flotta senza wrapper posa tag già protetti.

### 9.3 Il tag non è mai autenticazione

Principio (§40): scan/tap **identificano l'oggetto, mai l'operatore**. Ogni azione (chiudi lavoro,
associa tag, firma ispezione) richiede sessione utente autenticata + RBAC; il possesso fisico del
tag non conferisce alcun privilegio. Gli eventi NFC sensibili (associazione, dissociazione,
riscrittura) finiscono nell'audit log immutabile (§59).

---

## 10. Stampa Zebra / ZPL

### 10.1 Generazione ZPL server-side

Le etichette (QR + codice censimento + eventuale Code128) sono generate come **template ZPL II**
lato Laravel (stringhe Blade parametriche; anteprima di sviluppo con Labelary). Esempio essenziale:

```zpl
^XA
^CI28
^FO30,30^BQN,2,6^FDLA,https://verde.example.it/t/{{ $tagUuid }}^FS
^FO260,40^A0N,34,34^FD{{ $censusCode }}^FS
^FO260,90^A0N,24,24^FD{{ $speciesAbbr }}^FS
^FO260,130^BCN,60,N,N,N^FD{{ $censusCode }}^FS
^XZ
```

Un solo formato sorgente per tutte le stampanti ZPL (ZD4xx/ZD6xx/ZQ5xx). La stampa è un **job**:
`POST /api/v1/print-jobs` → coda Horizon → canale di uscita.

### 10.2 Canali di stampa

| Canale | Come | Quando |
|---|---|---|
| **Stampante di rete** (ZD421 con LAN/Wi-Fi) | Il worker Laravel apre TCP **porta 9100** e invia lo ZPL raw (la stampante deve essere raggiungibile dal server: LAN aziendale con agente/print-relay o VPN) | Stampa massiva in ufficio (lotti di etichette per campagne di censimento) — **canale principale** |
| **Zebra Browser Print** | Servizio gratuito Zebra su PC **Windows/macOS** + libreria JS: il backoffice stampa su stampanti **USB/di rete locali** senza dialogo di stampa | Postazione ufficio senza infrastruttura server-side; **non esiste per Android** |
| **Bluetooth in campo** (ZQ511) | Wrapper: socket SPP verso la stampante accoppiata, invio ZPL raw (o Link-OS SDK, §11) | Cartellini provvisori/ricevute a bordo squadra — Fase 6 |

### 10.3 Flusso operativo consigliato

Etichette definitive per esterno: **pre-stampate in ufficio** su ZD421t e portate in campo in fogli
numerati; l'operatore associa con lo scan (M12). La stampa in campo resta per usi provvisori.

### 10.4 Materiali (critico per l'esterno)

- **Termica diretta** (ZQ511, ZD421d): l'immagine **sbiadisce con sole/calore** — solo usi
  provvisori/interni.
- Esterno pluriennale: **trasferimento termico** (ZD421t/ZD621t) su **poliestere/polipropilene**
  (es. Zebra Z-Ultimate 3000T / PolyPro) con **ribbon resina**: resistenza UV, pioggia, abrasione
  5+ anni. Il QR va stampato ≥ 25×25 mm con quiet zone per letture affidabili da camera.

---

## 11. SDK eventualmente necessari (e quando NON servono)

| SDK / strumento | Serve? | Motivazione |
|---|---|---|
| **EMDK for Android** | **NO** | Serve solo per controllo nativo avanzato dello scanner; **DataWedge copre il 100% dei nostri casi** senza codice nativo di scansione. Non introdurlo evita una dipendenza pesante |
| **DataWedge (API intent)** | **SÌ** (built-in) | Preinstallato: non è un SDK da integrare, solo intent Android documentati |
| **Enterprise Browser (Zebra)** | **NO** | Prodotto a licenza che incapsula web app con API device: il nostro wrapper sottile fa lo stesso, gratis e su misura |
| **Link-OS Multiplatform SDK** | **Opzionale** (Fase 6) | Per la stampa BT dal wrapper con discovery/stato stampante; in alternativa basta un socket SPP + ZPL raw (più semplice, meno feature di diagnostica) |
| **Browser Print** | **Sì, solo backoffice desktop** | Installabile sui PC ufficio (Windows/macOS) per stampa USB locale; non esiste per Android |
| **Zebra RFID SDK for Android** | **Futuro** (Fase 9+, §5) | Unica via per RFD40/90 e lettori UHF integrati; entrerà nel wrapper come modulo |
| **StageNow / Enterprise Home Screen** | **Sì (gratuiti, ops)** | Provisioning profili DataWedge, Wi-Fi, kiosk sulla flotta; nessun impatto sul codice |
| Web API (BarcodeDetector, Web NFC, ecc.) | **SÌ** | Zero SDK: standard di piattaforma + polyfill `zxing-wasm` |

---

## 12. Limitazioni note

1. **Web NFC solo su Chrome/Android, solo NDEF, solo foreground**: niente iOS; niente password
   NTAG/READ_SIG dal browser (serve wrapper o tag pre-configurati); la pagina deve essere visibile.
2. **iPhone**: operatività di campo non supportata (Web NFC assente, PWA con limiti); il tap NFC
   su iPhone apre solo l'URL pubblico del tag. Dichiarato al committente: campo = Android.
3. **BarcodeDetector non ovunque**: assente su desktop/Firefox/iOS → polyfill WASM sempre incluso
   (bundle +~300 KB, prestazioni camera comunque inferiori allo scan engine).
4. **Keystroke output fragile**: dipende dal focus, può alterare caratteri (layout/IME), nessun
   metadato: accettato solo come fallback pre-wrapper (§3.3).
5. **Intent DataWedge richiede componente nativo**: una PWA pura non può riceverli — è il motivo
   d'esistenza del wrapper.
6. **Background sync best-effort nel browser**: garanzie forti solo col wrapper (WorkManager).
7. **Stampa Bluetooth impossibile dal browser** (Web Bluetooth non copre SPP): in campo serve il
   wrapper; Browser Print non esiste per Android.
8. **Browser Print solo Windows/macOS** e con servizio locale da installare/aggiornare.
9. **Termica diretta non adatta all'esterno** (sbiadisce): per etichette permanenti serve
   trasferimento termico + materiali sintetici (§10.4).
10. **UHF su alberi poco efficace** (assorbimento del legno umido) e sled da abbinare al modello
    esatto di terminale (adattatore eConnex specifico): RFID confinato a inventari attrezzature.
11. **MC2200/MC2700 in fine vita** presso i canali di vendita: esclusi dalle raccomandazioni.
12. **Ciclo di vita Android dei device Zebra**: aggiornamenti garantiti per anni via LifeGuard, ma
    ogni modello ha una finestra di supporto — verificare la data di fine supporto del modello al
    momento dell'ordine (zebra.com → Support & Downloads → Lifecycle).
13. **Prezzi e disponibilità variabili**: i valori in §14 sono street price agosto 2026 da
    rivenditori US/EU; il listino Italia via distributore può differire del ±20%.
14. **DataWedge profilo-per-browser**: nella fase senza wrapper il profilo keystroke associato a
    Chrome intercetta gli scan in *qualunque* scheda Chrome aperta sul device.

---

## 13. Architettura Hardware Abstraction Layer (TypeScript)

Requisito M05/§35: la business logic della PWA non conosce il device. Tre interfacce (+1 riservata),
implementazioni `web-*` e `zebra-*`, selezione a runtime.

### 13.1 Interfacce

```ts
// src/hal/types.ts
export type Symbology = 'qr' | 'code128' | 'code39' | 'datamatrix' | 'ean13' | 'ean8' | 'unknown';

export interface ScanResult {
  data: string;                 // payload decodificato (URL/UUID del tag)
  symbology: Symbology;
  source: 'camera' | 'datawedge';
  at: number;                   // epoch ms
}

export interface ScannerProvider {
  readonly id: 'web-camera' | 'zebra-datawedge';
  isAvailable(): Promise<boolean>;
  start(onScan: (r: ScanResult) => void): Promise<void>;   // avvia sessione di scansione
  stop(): Promise<void>;
  softTrigger?(): void;         // solo zebra: pulsante SCANSIONA a schermo
}

export interface NfcTag {
  uid?: string;                 // serial number (7 byte, hex)
  url?: string;                 // primo record NDEF URI, se presente
  writable?: boolean;
}

export interface NfcProvider {
  readonly id: 'web-nfc' | 'zebra-nfc';
  isAvailable(): Promise<boolean>;
  startRead(onTag: (t: NfcTag) => void, onError?: (e: Error) => void): Promise<void>;
  writeUrl(url: string): Promise<void>;         // scrittura record URI (M09)
  makeReadOnly?(): Promise<void>;               // lock permanente (web: Chrome ≥100)
  protect?(opts: { password: string; enableCounterMirror?: boolean }): Promise<void>; // solo zebra-nfc
  stop(): Promise<void>;
}

export interface PrinterInfo { id: string; name: string; channel: 'server' | 'bluetooth'; }

export interface PrinterProvider {
  readonly id: 'web-server-print' | 'zebra-bt-print';
  isAvailable(): Promise<boolean>;
  listPrinters(): Promise<PrinterInfo[]>;
  printZpl(zpl: string, printer: PrinterInfo): Promise<void>;
}

// Riservata per Fase 9+ (§5): nessuna implementazione in v1.
export interface RfidProvider {
  readonly id: 'zebra-rfid';
  startInventory(onTag: (epc: string, rssi: number) => void): Promise<void>;
  stop(): Promise<void>;
}
```

### 13.2 Implementazioni web-*

```ts
// src/hal/web/camera-scanner.ts — BarcodeDetector nativo con fallback zxing-wasm
import { prepareZXingModule, BarcodeDetector as ZXingDetector } from 'barcode-detector/pure';

export class WebCameraScanner implements ScannerProvider {
  readonly id = 'web-camera' as const;
  private stream?: MediaStream; private raf = 0;

  async isAvailable() { return !!navigator.mediaDevices?.getUserMedia; }

  async start(onScan: (r: ScanResult) => void) {
    const Detector: typeof BarcodeDetector =
      'BarcodeDetector' in window ? window.BarcodeDetector : (ZXingDetector as any);
    const detector = new Detector({
      formats: ['qr_code', 'code_128', 'code_39', 'data_matrix', 'ean_13', 'ean_8'],
    });
    this.stream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: 'environment', width: { ideal: 1280 } },
    });
    const video = Object.assign(document.createElement('video'), { srcObject: this.stream });
    await video.play();
    const tick = async () => {
      const codes = await detector.detect(video).catch(() => []);
      if (codes.length) {
        onScan({ data: codes[0].rawValue, symbology: mapFormat(codes[0].format),
                 source: 'camera', at: Date.now() });
      } else { this.raf = requestAnimationFrame(tick); }
    };
    this.raf = requestAnimationFrame(tick);
  }
  async stop() { cancelAnimationFrame(this.raf); this.stream?.getTracks().forEach(t => t.stop()); }
}
```

```ts
// src/hal/web/web-nfc.ts
export class WebNfc implements NfcProvider {
  readonly id = 'web-nfc' as const;
  private reader?: NDEFReader; private abort?: AbortController;

  async isAvailable() { return 'NDEFReader' in window; }

  async startRead(onTag: (t: NfcTag) => void, onError?: (e: Error) => void) {
    this.reader = new NDEFReader(); this.abort = new AbortController();
    await this.reader.scan({ signal: this.abort.signal });   // richiede gesto utente + HTTPS
    this.reader.onreading = (ev) => {
      const uri = ev.message.records.find(r => r.recordType === 'url');
      onTag({ uid: ev.serialNumber,
              url: uri ? new TextDecoder().decode(uri.data) : undefined });
    };
    this.reader.onreadingerror = () => onError?.(new Error('NFC_READ_ERROR'));
  }
  async writeUrl(url: string) { await new NDEFReader().write({ records: [{ recordType: 'url', data: url }] }); }
  async makeReadOnly() { await new NDEFReader().makeReadOnly(); }  // Chrome ≥ 100
  async stop() { this.abort?.abort(); }
}
```

L'implementazione `web-server-print` chiama semplicemente `POST /api/v1/print-jobs` (il server
instrada su stampante di rete, §10.2) — nessun accesso hardware locale.

### 13.3 Implementazioni zebra-* (sul bridge del wrapper)

```ts
// src/hal/zebra/datawedge-scanner.ts
export class ZebraDataWedgeScanner implements ScannerProvider {
  readonly id = 'zebra-datawedge' as const;
  constructor(private bridge: ZebraBridgeNative) {}
  private handler?: (e: Event) => void;

  async isAvailable() { return true; }              // istanziato solo se il bridge esiste
  async start(onScan: (r: ScanResult) => void) {
    this.handler = (e: Event) => {
      const d = (e as CustomEvent).detail;          // {data, symbology} dal BroadcastReceiver (§3.4)
      onScan({ data: d.data, symbology: mapDwLabelType(d.symbology),
               source: 'datawedge', at: Date.now() });
    };
    window.addEventListener('zebra:scan', this.handler);
  }
  softTrigger() { this.bridge.dwSoftScanTrigger('START'); }
  async stop() { if (this.handler) window.removeEventListener('zebra:scan', this.handler); }
}
```

```ts
// src/hal/zebra/zebra-nfc.ts — NFC nativo: reader-mode + comandi raw NTAG21x
export class ZebraNfc implements NfcProvider {
  readonly id = 'zebra-nfc' as const;
  constructor(private bridge: ZebraBridgeNative) {}

  async isAvailable() { return true; }
  async startRead(onTag: (t: NfcTag) => void) {
    window.addEventListener('zebra:nfc-tag', (e) => onTag((e as CustomEvent).detail));
    this.bridge.nfcStartRead(JSON.stringify({}));
  }
  async writeUrl(url: string) { this.bridge.nfcWriteUrl(url); }
  async protect(opts: { password: string; enableCounterMirror?: boolean }) {
    this.bridge.nfcProtect(JSON.stringify(opts));   // PWD_AUTH/AUTH0 + counter mirror, lato Kotlin
  }
  async stop() { /* rimozione listener */ }
}
```

### 13.4 Factory: selezione a runtime

```ts
// src/hal/index.ts — unico punto in cui il device è "conosciuto"
declare global { interface Window { ZebraBridge?: ZebraBridgeNative } }

export function createHal() {
  const bridge = window.ZebraBridge;               // iniettato solo dal wrapper (§7.3)
  return {
    scanner: bridge ? new ZebraDataWedgeScanner(bridge) : new WebCameraScanner(),
    nfc:     bridge ? new ZebraNfc(bridge)         : new WebNfc(),
    printer: bridge ? new ZebraBtPrinter(bridge)   : new WebServerPrint(),
    capabilities: {
      hasHardwareScanner: !!bridge,
      canProtectTags:     !!bridge,                // password NTAG solo via wrapper
      canPrintBluetooth:  !!bridge,
      hasWebNfc:          'NDEFReader' in window,
    },
  };
}
// Uso nella business logic (unica API vista dai componenti Vue):
//   const hal = createHal();
//   await hal.scanner.start(r => resolveTag(r.data));   // stesso codice su ogni device
```

Nota: sui terminali Zebra **senza** wrapper (Fase 3) la factory restituisce i provider `web-*` e lo
scan engine arriva comunque come keystroke nel campo di ricerca a fuoco (fallback §3.3) — il flusso
utente resta identico.

---

## 14. Raccomandazione d'acquisto

Premessa: **niente acquisti massivi ora**. Si compra **1 terminale di riferimento subito** (serve in
Fase 3 per testare keystroke/Web NFC su hardware reale — rischio #2 del piano) e il resto al pilota
(Fase 4/5). Prezzi = street price indicativi agosto 2026, IVA esclusa, da confermare con un
distributore/partner Zebra italiano (Jarltech, Logiscenter, ecc.).

### Configurazione A — base economica (~1.700–2.400 € una tantum)

Per: 1 squadra pilota + backoffice; copre censimento, ispezioni, associazione tag, stampa etichette.

| Voce | Prodotto | Q.tà | Prezzo indicativo | Fonte prezzo |
|---|---|---|---|---|
| Terminale rugged | **Zebra TC22** Wi-Fi 6, SE4710, 8/128 GB (WLAN-only; TC27 se serve SIM) | 1 | ~1.050–1.250 € (listing US da ~1.156 $) | barcodediscount.com, logiscenter.com |
| Tag NFC alberi | **Chiodo NFC NTAG213/216** ABS/PPS per tronchi (min. 100–500 pz) — in alternativa sistema **ArboTag/Signumat** targa+chiodo con QR e kit martello+caricatore | 500 | ~0,80–1,50 €/pz (chiodo NFC); kit ArboTag da preventivo | shopnfc.com, rfidconnectus.com, latschbacher.com |
| Tag NFC arredo | **On-metal NTAG213 IP68** disco 30–34 mm, adesivo 3M/foro rivetto | 200 | ~1,00–2,50 €/pz | shopnfc.com ("Industrial IP68"), tagstand.com, atlasrfidstore.com |
| Stampante etichette | **Zebra ZD421t** trasferimento termico 203 dpi, USB (opz. LAN) | 1 | ~450–600 € | listini rivenditori EU |
| Consumabili | Etichette poliestere (Z-Ultimate o equiv.) + ribbon resina, 1 rotolo ciascuno | — | ~80–120 € | rivenditori Zebra IT |
| Smartphone consumer | già in possesso degli operatori (PWA) | n | 0 € | — |

### Configurazione B — completa (~4.500–6.500 € una tantum)

Per: 2–3 squadre + rilevatore censimento intensivo + stampa in campo; pronta all'RFID futuro.

| Voce | Prodotto | Q.tà | Prezzo indicativo | Fonte prezzo |
|---|---|---|---|---|
| Terminale fascia alta | **Zebra TC53e** con SE55 advanced range — variante **TC53e-RFID** (+~300–500 €) se si vuole l'opzione UHF integrata (§5) | 1 | ~1.700–2.200 € | listini rivenditori EU (spec: zebra.com) |
| Terminali squadra | **Zebra TC22** SE4710 | 2 | ~1.050–1.250 €/pz | come sopra |
| Stampante ufficio | **Zebra ZD621t** trasferimento termico (o seconda ZD421t) | 1 | ~700–900 € | listini rivenditori EU |
| Stampante campo | **Zebra ZQ511** 3", Bluetooth+Wi-Fi (termica diretta: usi provvisori) | 1 | ~600–750 € | posguys.com, delfi.com |
| Tag NFC alberi | Chiodo NFC NTAG216 (payload margine) o Signumat con transponder | 1.000 | ~0,70–1,30 €/pz a volume | shopnfc.com, rfidconnectus.com |
| Tag NFC arredo | On-metal NTAG216 IP68 + etichette PET outdoor con QR | 500 | ~1,00–2,50 €/pz | shopnfc.com, atlasrfidstore.com |
| Accessori | Batterie di scorta PowerPrecision, cradle ricarica, ribbon/etichette scorta | — | ~300–500 € | rivenditori Zebra IT |
| **Rimandato** | Sled RFD40 + adattatore eConnex (solo quando partirà l'RFID, §5) | 0 | (~1.300–1.800 € quando servirà) | atlasrfidstore.com |

### Note d'acquisto

1. **Ordinare i tag NFC con encoding service** (URL + password + counter mirror pre-configurati,
   §9.2) se il wrapper non è ancora in campo al momento della posa.
2. Chiedere al distributore la **data di fine supporto software** (LifeGuard) del lotto offerto.
3. Per gli alberi valutare un **campione misto** (50 chiodi NFC + 50 ArboTag+QR) nel pilota: la
   scelta definitiva dipende dalla praticità di posa constatata dalle squadre.
4. TC27/TC58e (varianti cellulari) solo se le squadre operano fuori copertura Wi-Fi aziendale e si
   vuole sync in tempo quasi reale; altrimenti Wi-Fi + offline-first è sufficiente e più economico.

---

## Fonti principali (verifiche agosto 2026)

- Zebra — spec sheet TC22/TC27: <https://www.zebra.com/us/en/products/spec-sheets/mobile-computers/handheld/tc22-tc27.html>
- Zebra — spec sheet TC53e / TC53e-RFID / TC58e: <https://www.zebra.com/us/en/products/spec-sheets/mobile-computers/handheld/tc53e-tc53e-rfid-tc58e.html>
- Zebra — EM45 / EM45 RFID: <https://www.zebra.com/us/en/products/mobile-computers/handheld/em45-series/em45-rfid.html>
- Zebra — MC2200/MC2700 (pagine rivenditori con stato *discontinued*): <https://posguys.com/mobile-computers_6/Zebra-MC2200-MC2700_3785/>
- Zebra — RFD40 accessory guide (adattatori eConnex TC2x/TC5x): <https://www.zebra.com/content/dam/zebra_dam/en/guide/configuration-and-accessories/rfd40-standard-guide-accessory-en-us.pdf>
- Zebra — ZQ511/ZQ521: <https://www.zebra.com/us/en/products/spec-sheets/printers/mobile/zq511-zq521.html>
- Zebra TechDocs — DataWedge (profili, keystroke, intent, API): <https://techdocs.zebra.com/datawedge/latest/guide/output/intent/>
- ZebraDevs — sample DataWedge in WebView: <https://github.com/ZebraDevs/DW-WEBVIEW>
- Chrome Developers — Web NFC: <https://developer.chrome.com/docs/capabilities/nfc>
- MDN — Web NFC API / Barcode Detection API: <https://developer.mozilla.org/en-US/docs/Web/API/Web_NFC_API>, <https://developer.mozilla.org/en-US/docs/Web/API/Barcode_Detection_API>
- caniuse — BarcodeDetector: <https://caniuse.com/mdn-api_barcodedetector>
- Polyfill BarcodeDetector (ZXing-wasm): <https://github.com/gruhn/barcode-detector-polyfill>
- Zebra — Browser Print: <https://www.zebra.com/us/en/support-downloads/software/printer-software/browser-print.html>
- Latschbacher/Signumat — Urban Forest Line (ArboTag): <https://www.latschbacher.com/en/tagging-technology/signumat-urban-forest-line/>
- Shop NFC — tag industrial IP68 on-metal NTAG213/216: <https://shopnfc.com/en/industrial-nfc-tags/180-140-industrial-ip68-nfc-tags.html>
- AtlasRFIDStore — tag NFC (prezzi indicativi): <https://www.atlasrfidstore.com/nfc-tags/>
- RFIDConnect — nail tag NFC per alberi: <https://www.rfidconnectus.com/rfid-asset-tag/rfid-nail-tag.html>
- Prezzi TC22 (street US): <https://www.barcodediscount.com/catalog/zebra/tc22.htm>, <https://www.logiscenter.us/zebra-tc22-mobile-computing>

*Documento redatto in Fase 0; da rivedere alla consegna dei device reali (inizio Fase 6) e a ogni
major release di DataWedge/Chrome.*
