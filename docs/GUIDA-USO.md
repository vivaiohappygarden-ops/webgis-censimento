# Guida all'uso

Questa guida è disponibile anche dentro l'applicazione, alla voce **Guida** della barra
laterale. È scritta per chi usa il sistema, non per chi lo sviluppa.

## In breve

Il sistema tiene insieme tre cose: il **censimento del verde** (che cosa c'è e dove), i
**lavori** (che cosa va fatto, chi lo fa, quanto vale) e la **qualità** (controlli,
ispezioni, segnalazioni). Tutto quello che si fa in campo con il telefono funziona anche
senza connessione e si allinea da solo appena torna la rete.

### I ruoli

- **Amministratore**: vede e gestisce tutto, compresi listini, catalogo e utenti.
- **Tecnico**: gestisce censimento, lavori, ispezioni e segnalazioni.
- **Operatore**: lavora dal telefono con l'app di campo; vede i lavori assegnati alla sua
  squadra e registra rilievi, foto, consuntivi, ispezioni e segnalazioni.
- **Cliente**: consultazione (in arrivo con il portale dedicato).

## Il censimento

La pagina **Mappa** mostra gli elementi censiti sul territorio; la pagina **Censimento** è
l'elenco completo, con ricerca per codice e note. Ogni elemento ha una scheda con il tipo
(dal catalogo ministeriale), le misure, le foto, gli attributi del suo tipo e la storia
delle modifiche.

Se due persone modificano la stessa scheda, vince chi salva per primo; al secondo il
sistema chiede di ricaricare la scheda aggiornata prima di riprovare. Nessuna modifica va
persa in silenzio.

## Alberi e VTA

La pagina **VTA** raccoglie il catasto alberi: scheda completa per pianta, valutazioni di
stabilità con difetti e classi di propensione al cedimento, analisi strumentali con
referto, scadenzario delle rivalutazioni, alberi monumentali o dedicati, e il bilancio
arboreo (messi a dimora, abbattuti, sostituiti).

## I lavori

Ogni intervento è un **ordine di lavoro** con un codice (ODL-anno-numero), gli elementi
coinvolti, la squadra assegnata e uno stato che avanza: bozza, pianificato, assegnato, in
corso, sospeso, completato (o annullato). L'**agenda** mostra la settimana per squadra; il
**rendiconto** riepiloga i lavori completati per cliente e periodo, con i valori calcolati
dal listino.

Nella pagina **Listini** si definiscono le voci (prezzo per unità di misura, spese
generali, oneri di sicurezza). Se all'ordine è collegato un listino, il consuntivo
registrato in campo viene valorizzato da solo; il rendiconto segnala i casi in cui manca
qualcosa (nessun listino, nessuna voce, quantità mancante).

## Qualità e ispezioni

Nella pagina **Lavori**, vista Qualità, ci sono i controlli di fine lavoro e le **non
conformità** (aperta, azione correttiva, verificata, chiusa). Nella pagina **Ispezioni** si
preparano i **modelli di checklist** (per aree o per elementi, con domande OK/KO, testi e
numeri) e si registrano le esecuzioni: l'esito è calcolato dalle risposte e ogni voce
negativa può aprire da sola una non conformità.

Se un modello ha una periodicità (per esempio: area giochi ogni 90 giorni), la scheda
**Scadenzario** mostra per ogni area o elemento l'ultima ispezione e la data entro cui
ripeterla, con i ritardi in cima e in rosso.

## Le segnalazioni

Una segnalazione (di un collega o di un cliente) ha un codice SEG, una gravità e uno
stato: aperta, presa in carico, risolta oppure archiviata. In base alla gravità il sistema
fissa due scadenze: entro quando va **presa in carico** (da 1 giorno per le critiche a 10
per le lievi) ed entro quando va **risolta** (da 3 a 30 giorni). La colonna Tempi e i
filtri mostrano subito che cosa è fuori tempo massimo. Da una segnalazione si genera
l'ordine di lavoro con un solo pulsante.

## L'app di campo (pagina Campo)

Pensata per il telefono, funziona **anche senza connessione**: prima di uscire conviene
aprire la scheda Sync e premere "Scarica i dati di lavoro". Da quel momento l'operatore
può censire elementi, misurare, fotografare, associare tag, compilare consuntivi dei
lavori assegnati, eseguire ispezioni su checklist e aprire segnalazioni: tutto resta in
coda sul dispositivo e parte da solo appena torna la rete.

Se un invio viene rifiutato, la scheda Sync lo elenca con il motivo scritto chiaro. Si può
riprovare (dopo aver aggiornato i dati) oppure scartare l'invio: in ogni caso il
dispositivo si riallinea e nulla resta a metà.

## Territorio e catalogo

La pagina **Territorio** organizza clienti, siti (comuni), località e aree di gestione:
ogni elemento censito appartiene a un'area. La pagina **Catalogo** contiene i tipi di
oggetto del Modello Dati ministeriale (387 tipi, non modificabili) e i **campi
personalizzati** che si possono aggiungere a ciascun tipo (per esempio la larghezza delle
siepi), con la possibilità di collegarli ai campi dei tracciati di consegna.

## La consegna CAM

Per lavorare con i Comuni serve consegnare il censimento nel formato del "Modello dati per
il censimento del verde urbano" (richiesto dai CAM). Dalla pagina **Censimento** si può
esportare un singolo layer (scegliendo categoria e formato) oppure premere **"Consegna
completa"**: un unico pacchetto con tutti i layer non vuoti nominati con il codice ISTAT
del comune, la cartella FOTO con le immagini e un riepilogo dei conteggi. L'import fa il
percorso inverso e accetta i file di altri sistemi nello stesso formato, con una prova a
vuoto (analisi) prima di scrivere qualunque cosa.

## Domande frequenti

**Il telefono era offline e ho censito 50 elementi: che succede?**
Restano sul dispositivo, numerati in ordine. Appena c'è rete l'app li invia da sola,
nell'ordine giusto; il diario della scheda Sync mostra l'esito di ogni invio.

**Chi vede che cosa?**
Ogni organizzazione vede solo i propri dati. Dentro l'organizzazione, i permessi seguono i
ruoli: per esempio i listini li gestisce chi coordina, non l'operatore.

**Ho sbagliato: posso eliminare?**
Le eliminazioni sono "morbide": il dato scompare dalle viste ma resta in archivio, con la
storia delle modifiche. Le cose ormai chiuse (ordini completati, segnalazioni risolte,
ispezioni registrate) non si modificano: fanno da registro.
