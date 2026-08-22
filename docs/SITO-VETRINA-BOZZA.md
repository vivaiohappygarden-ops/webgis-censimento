# Sito aziendale: struttura e testi (bozza da rivedere)

Bozza dei contenuti per il sito su `censimentoalberature.it`. Serve per non
partire dal foglio bianco: i testi sono da leggere, correggere e far propri.

**Regola che ho seguito:** dove servono fatti che riguardano l'azienda (anni di
attività, referenze, titoli, partita IVA) ho lasciato `[DA COMPILARE]`. Non li
invento: su un sito che parla a un ufficio tecnico comunale, un dato gonfiato
è un danno, non una spinta.

**A chi parla questo sito.** Non a un privato con il giardino. A un
**responsabile dell'ufficio tecnico o dell'ambiente di un Comune**, che ha un
capitolato da scrivere, un bilancio da giustificare e una responsabilità sulla
sicurezza degli alberi in aree pubbliche. Scrive poco, legge in fretta, e cerca
prove, non aggettivi.

---

## Pagina 1 — Home

### Titolo principale

> Censimento e catasto del verde urbano per i Comuni

### Sottotitolo

> Rilievo in campo, schedatura di ogni albero, valutazione di stabilità e
> consegna dei dati nei formati richiesti dai Capitolati Ambientali Minimi.
> Ogni Comune riceve anche un portale pubblico dove i cittadini consultano
> il patrimonio arboreo del proprio territorio.

### I tre punti, in evidenza

**Il dato resta al Comune.**
Alla consegna il Comune riceve i propri dati in formati aperti e standard, non
un archivio chiuso dentro un programma. Se domani cambia fornitore, il lavoro
fatto non si perde.

**Ogni albero ha una storia, non una riga.**
Specie, misure, stato fitosanitario, vincoli che gravano sull'area, interventi
eseguiti con data e atto collegato, prossima verifica prevista. È quello che
serve quando bisogna dimostrare di aver vigilato.

**I cittadini lo vedono.**
Un portale pubblico per ogni Comune, con lo stemma e i colori dell'ente:
mappa del verde, scheda di ogni albero, ricerca per numero di cartellino.
Trasparenza che si comunica, senza aprire un ufficio in più.

### Chiusura della home

> [DA COMPILARE: una riga sul territorio in cui operi, per esempio
> "Operiamo in Lazio e regioni limitrofe"]

> Richiedi un sopralluogo o un preventivo → [collegamento ai contatti]

---

## Pagina 2 — Il censimento del verde

### Che cos'è

Il censimento è l'inventario di quello che c'è: dove si trova ogni albero, di
che specie è, quanto è grande, in che condizioni si trova. Senza questo, ogni
decisione sul verde pubblico si prende a memoria.

### Come lavoriamo

**1. Definizione dell'ambito.** Con l'ufficio tecnico si stabilisce cosa
rilevare: alberature stradali, parchi, aree scolastiche, cimiteri. Si concorda
il livello di dettaglio, che determina tempi e costo.

**2. Rilievo in campo.** Ogni elemento viene posizionato con GPS e schedato sul
posto, con fotografia. Il rilievo funziona **anche senza copertura telefonica**:
i dati si allineano da soli quando il segnale torna. Nelle aree rurali e nei
parchi non è un dettaglio.

**3. Cartellinatura.** Ogni albero riceve un cartellino numerato con codice QR.
La numerazione è progressiva per Comune, con un prefisso dedicato, e non viene
mai riassegnata: un cartellino applicato resta valido.

**4. Verifica e consegna.** I dati vengono controllati, e il Comune riceve
l'archivio completo con le fotografie.

### Cosa viene registrato per ogni albero

Specie e cultivar, circonferenza del tronco, altezza, ampiezza della chioma,
data del rilievo, area e località, coordinate, stato fitosanitario, difetti
rilevati, interventi già eseguiti, vincoli che gravano sull'area.

Le misure derivate si calcolano automaticamente: non si copiano a mano e non si
sbagliano.

---

## Pagina 3 — Valutazione di stabilità (VTA)

### Perché serve

Un albero in area pubblica che cade è una responsabilità del proprietario. La
valutazione di stabilità secondo il metodo VTA (Visual Tree Assessment) è lo
strumento tecnico con cui il Comune dimostra di aver vigilato: non elimina il
rischio, ma documenta che è stato valutato da un tecnico e gestito.

### Cosa consegniamo

- Scheda di valutazione per ogni albero esaminato, con classe di propensione al
  cedimento e difetti rilevati con fotografia
- Indicazione degli interventi necessari e della loro urgenza
- **Data della prossima verifica**, per ogni albero
- Relazione tecnica firmata

### Lo scadenzario

Le verifiche non si esauriscono con la consegna: ogni albero ha la sua data di
controllo successivo. Il Comune riceve un elenco delle scadenze e sa in
anticipo cosa va rivisto e quando, invece di accorgersene dopo.

> [DA COMPILARE: titolo professionale di chi firma le perizie. Va scritto
> quello che è vero oggi. Se il titolo è in corso di conseguimento, o le
> perizie le firma un tecnico abilitato in collaborazione, va detto così:
> un ufficio tecnico lo verifica, e una dichiarazione imprecisa qui vale
> molto più cara del vantaggio che dà.]

---

## Pagina 4 — Il portale pubblico per i cittadini

### Che cos'è

Ogni Comune che lo richiede riceve un proprio sito pubblico, all'indirizzo
`nomedelcomune.censimentoalberature.it`, con lo stemma e i colori dell'ente.

### Cosa ci trova il cittadino

- La **mappa** del verde pubblico, con lo stato di ogni albero
- La **scheda del singolo albero**: specie, misure, data del rilievo,
  interventi eseguiti
- La **ricerca per numero di cartellino**: si legge il numero sull'albero, si
  digita, si apre la scheda
- Il **codice QR** sul cartellino porta direttamente alla scheda, senza dover
  digitare niente

### Cosa decide il Comune

Non esce niente in automatico. Ogni intervento, ogni perizia e ogni vincolo
viene pubblicato **solo se il Comune lo decide**. I singoli alberi si possono
nascondere. Le note interne e le fotografie dei difetti non escono mai.

Il portale non usa cookie e non raccoglie statistiche sui visitatori: non c'è
niente da far accettare a chi lo apre, e niente da dichiarare.

### La stima dell'anidride carbonica

Su richiesta il portale può mostrare la stima dell'anidride carbonica
immagazzinata dal patrimonio arboreo. È un dato **stimato e dichiarato come
tale**, con il metodo di calcolo scritto in chiaro nella pagina: si può
comunicare senza esporsi.

---

## Pagina 5 — Conformità e consegna dei dati

### Capitolati Ambientali Minimi

I dati vengono consegnati nei formati previsti dai CAM per il verde pubblico:
struttura dei campi, codifiche del catalogo ministeriale, sistema di
riferimento nazionale.

La consegna comprende i dati geografici in formato aperto (GeoJSON e shapefile),
le fotografie collegate e un documento che descrive il contenuto del pacchetto.

### Perché conta

Un capitolato che chiede i CAM e riceve un file generico costringe l'ufficio a
rifare il lavoro. Consegnare già nel formato giusto significa che il dato entra
direttamente nei sistemi del Comune e nelle comunicazioni alla Regione.

### Il dato è del Comune

Formati aperti, nessun blocco, nessun riscatto. Se il Comune cambia fornitore,
porta con sé tutto quello che ha pagato.

---

## Pagina 6 — Chi siamo

> [DA COMPILARE — è la pagina che un funzionario apre per capire con chi ha a
> che fare. Serve:
>  - ragione sociale, sede, partita IVA
>  - da quanto tempo si opera nel settore
>  - qualifiche e titoli, quelli veri
>  - attrezzature e mezzi, se rilevanti
>  - eventuali lavori già svolti, con il nome del committente SOLO se
>    autorizzato: le referenze non autorizzate si citano come
>    "Comune in provincia di ..." senza nome
>  - una fotografia della squadra al lavoro vale più di mezza pagina di testo]

---

## Pagina 7 — Contatti

> [DA COMPILARE: telefono, email, PEC, indirizzo, orari]

Modulo di contatto con: nome, ente, telefono, email, e una casella di testo
libero. Poche voci: chi scrive da un ufficio tecnico non compila moduli lunghi.

---

## Note legali (obbligatorie)

- Informativa privacy per il modulo di contatto
- Partita IVA e dati societari nel piè di pagina
- Se il sito usa strumenti di statistica, il banner dei cookie

---

## Consigli sui testi, se li riscrivi

**Numeri invece di aggettivi.** "Rilievo di 1.200 alberi in 18 giorni" dice più
di "professionalità e serietà". Se i numeri non ci sono ancora, meglio nessun
numero che un aggettivo.

**Niente superlativi.** "Il migliore", "all'avanguardia", "innovativo" non li
legge nessuno. Descrivere cosa succede, in ordine, funziona meglio.

**Frasi corte.** Chi legge da un ufficio tecnico ha venti schede aperte.

**Una sola azione per pagina.** Ogni pagina finisce con un solo invito:
"richiedi un sopralluogo". Non tre.

**Mostra il prodotto.** Un collegamento a un portale comunale vero, che si può
aprire e toccare, convince più di qualunque descrizione. È la cosa che hai e
che quasi nessun concorrente può far vedere allo stesso modo.
