# Mini manuale: collegare Comuni, agronomi e imprese alla piattaforma

Versione del 27/09/2026. Per chi gestisce la piattaforma e la affitta ad altri. Spiega quale forma dare a ogni collaborazione e i passi da fare, con le parole che si trovano nelle pagine del programma.

## 1. Prima di tutto: una regola per scegliere

La piattaforma ospita più **organizzazioni**, ognuna con i suoi utenti, i suoi committenti, i suoi dati: nessuna vede quelli delle altre. Dentro un'organizzazione i Comuni e i clienti sono **committenti**, con le loro aree e i loro portali.

La regola per decidere:

- **Un'organizzazione a sé** quando l'altro deve gestirsi da solo: creare i propri utenti, le proprie aree, i propri committenti, le proprie ditte. Tu non entri nei suoi dati, se non in assistenza.
- **Un committente dentro la tua organizzazione** quando il lavoro lo fai tu (o lo dirigi tu) e all'altro serve solo guardare: l'ufficio del Comune ha il portale riservato, i cittadini il portale pubblico, la ditta esterna l'app di campo per i soli lavori affidati.

## 2. Le figure e i loro ruoli

| Chi è | Ruolo nel programma | Che cosa vede e fa | Dove entra |
| --- | --- | --- | --- |
| Chi dirige l'organizzazione | Amministratore | Tutto: utenti, committenti, portali, impostazioni | Gestionale |
| Agronomo, tecnico dell'ufficio | Tecnico | Censimento, valutazioni di stabilità (VTA), lavori, territorio | Gestionale e app di campo |
| Chi rileva sul posto | Operatore | Rilievi, misure, foto, cartellini; i lavori della sua squadra | App di campo |
| Ditta esterna di giardinaggio | Esecutore | Solo i lavori affidati alla sua squadra: fatto, non fatto, foto, segnalazioni. Non tocca il censimento | App di campo |
| Referente di un'impresa appaltatrice | Impresa | Gli ordini delle sue squadre con date e prescrizioni; chiede riprogrammazioni | Portale delle imprese (`/impresa`) |
| Ufficio tecnico del Comune cliente | Cliente | Il proprio territorio: mappa, elementi, lavori fatti e in programma, documenti emessi, segnalazioni; nessun prezzo | Portale riservato (`/portale`) |
| Cittadini | Nessun accesso | Il portale pubblico del Comune, se acceso | `<comune>.dominio` |
| Tu | Gestore della piattaforma | La console con tutte le organizzazioni | Menu, voce Piattaforma |

I ruoli di serie non si cancellano; i loro permessi si regolano dalla pagina Utenti, riquadro "Ruoli e permessi", e se ne possono creare di nuovi su misura.

## 3. I quattro casi

### Caso 1. Un agronomo affitta la piattaforma per i suoi censimenti

Ha i suoi Comuni, i suoi rilevatori, le sue ditte, e vuole fare da solo.

- Forma: **un'organizzazione sua**. Tu la crei dalla console e gli consegni la password provvisoria dell'amministratore; da lì in poi è lui a fare tutto.
- Lui, dentro la sua organizzazione: crea gli utenti (tecnici, operatori), i committenti (i suoi Comuni) con sedi, località e aree, le squadre e le ditte esterne, i portali pubblici dei Comuni che lo vogliono.
- Tu vedi nella console solo i numeri (utenti, elementi, foto e spazio, ultimo accesso) e puoi sospendere l'organizzazione, per esempio a canone non rinnovato.

### Caso 2. Un Comune incarica un agronomo suo e collega una ditta di giardinaggio

L'agronomo non lo conosci; la ditta riceve gli incarichi dal Comune.

- Forma: **un'organizzazione del Comune**. Amministratore: l'ufficio tecnico del Comune (oppure l'agronomo, se il Comune preferisce delegare).
- L'agronomo: utente **Tecnico**. Fa censimento, VTA, perizie, lavori.
- La ditta di giardinaggio: una **squadra esterna** (pagina Utenti, sezione Squadre, spunta "impresa esterna") con dentro uno o più utenti **Esecutore**. Ogni ordine di lavoro affidato a quella squadra compare nella loro app di campo; rendicontano fatto e foto, il resto non lo vedono. Se la ditta vuole anche il quadro degli ordini con le date, un utente **Impresa** per il referente.
- I cittadini: il portale pubblico del Comune, acceso dall'amministratore.

### Caso 3. Un Comune affida a te il censimento e i servizi a una ditta esterna

Il registro lo tieni tu; la ditta lavora su tuo ordine.

- Forma: **un committente dentro la tua organizzazione** ("Comune di Parma"), con le sue aree. Nessuna organizzazione nuova.
- L'ufficio del Comune: un utente **Cliente** collegato a quel committente. Dal portale riservato vede il suo territorio, i lavori, i documenti emessi (perizie e verbali chiusi) e manda richieste. Non vede prezzi né note interne.
- La ditta esterna: squadra esterna del committente con utenti **Esecutore**; tu affidi gli ordini alla squadra.
- Tu e i tuoi tecnici: censimento, VTA, ordini, controlli qualità, consegne CAM.

### Caso 4. Un Comune affida tutto a te

Come il caso 3, con le squadre tue (utenti **Operatore**) al posto della ditta esterna. Il Comune ha il portale riservato per l'ufficio e, se vuole, il portale pubblico per i cittadini.

## 4. Creare un'organizzazione (casi 1 e 2)

Le organizzazioni si creano **dal programma**, dalla console. Il terminale del server serve **una volta sola, e solo per te**: dà al tuo utente la qualifica di gestore della piattaforma, che per sicurezza nessuna pagina può dare.

```bash
cd /var/www/webgis
sudo -u www-data php artisan piattaforma:gestore tua@email.it
```

Da quel momento nel menu compare la voce **Piattaforma**. La console si apre solo con la **verifica in due passaggi** attiva sul tuo utente (pagina "Il mio accesso", in fondo al menu).

Per ogni nuova organizzazione:

1. Menu, voce **Piattaforma**, pulsante **Nuova organizzazione**.
2. Compila: nome (es. "Comune di Prova"), slug (nome breve senza spazi, si compone da solo: è quello che l'organizzazione usa all'accesso se la stessa email esiste in più organizzazioni), partita IVA, nome ed email dell'amministratore.
3. Il programma mostra **una volta sola** la password provvisoria: copiala e consegnala in modo sicuro (non per email in chiaro insieme all'indirizzo).
4. L'organizzazione nasce pronta: ruoli di serie, catalogo Modello Dati v2.1, amministratore.

Che cosa fa il nuovo amministratore al primo accesso, in ordine:

1. Cambia la password da **Il mio accesso** e accende la verifica in due passaggi.
2. Sempre da **Il mio accesso**, regola dell'organizzazione: almeno "Gli amministratori".
3. Pagina **Utenti**: crea tecnici, operatori, esecutori; ognuno riceve una password provvisoria da cambiare.
4. Pagina **Territorio**: committenti, sedi, località, aree (o importazione da file dal censimento, menu "Altro").
5. Pagina **Utenti**, sezione **Squadre**: squadre interne ed esterne, con i membri.
6. Quando i dati sono in ordine: portale pubblico del Comune da **Territorio** (interruttore, colore dell'ente, recapiti; niente esce da solo: lavori, perizie e vincoli hanno la propria spunta "pubblico").

## 5. Collegare una ditta esterna nella propria organizzazione (casi 2 e 3)

1. **Utenti**, "Nuovo utente": nome, email, ruolo **Esecutore**. Consegna la password provvisoria.
2. **Utenti**, sezione **Squadre**, "Nuova squadra": nome della ditta, spunta "impresa esterna", committente di riferimento, membri (gli esecutori appena creati).
3. **Lavori**: nell'ordine, campo Squadra, scegli la ditta. Da quel momento l'ordine è nella loro app di campo con gli elementi e la mappa.
4. La ditta, dal telefono: apre "I miei lavori", segna fatto o da fare per elemento, fa le foto (restano agganciate al lavoro), apre segnalazioni. La sincronizzazione porta sul telefono solo gli elementi dei lavori affidati, senza le note interne.
5. Tu vedi tutto nella pagina del lavoro: cronologia, foto per elemento, consuntivo.

Se la ditta ha bisogno di vedere il programma dei lavori con le date e chiedere spostamenti, crea in più un utente con ruolo **Impresa** per il referente: entra dal portale delle imprese.

## 6. Dare l'accesso all'ufficio del Comune (casi 3 e 4)

1. **Utenti**, "Nuovo utente": ruolo **Cliente**, e nel campo "cliente collegato" il committente giusto. Senza il collegamento il portale è vuoto.
2. L'ufficio entra dalla stessa pagina di accesso e atterra sul portale riservato, in sei schede: Panoramica, Mappa, Patrimonio, Lavori, Segnalazioni, Documenti.
3. Vede solo il suo territorio. Nei Documenti trova le perizie **emesse** e i verbali di ispezione **chiusi**: una valutazione senza perizia emessa non compare, è lavoro in corso.
4. Le richieste che manda arrivano fra le tue segnalazioni; l'esito torna nel suo portale.

## 7. Sicurezza degli accessi

- **Verifica in due passaggi**: ognuno la accende da "Il mio accesso" con un'app gratuita del telefono (Google Authenticator, Microsoft Authenticator, FreeOTP o simili). Otto codici di recupero, da conservare fuori dal telefono.
- **Regola dell'organizzazione** (chi gestisce gli utenti): nessuno, amministratori, tutti. Chi è obbligato e non l'ha attivata trova solo quella pagina finché non lo fa.
- **Telefono perso**: dalla pagina Utenti, "Azzera verifica"; la persona rientra con la password e la riattiva.
- **Password**: almeno dieci caratteri; si cambia da "Il mio accesso"; "Nuova password" nella pagina Utenti per chi l'ha dimenticata.

## 8. Gestire nel tempo dalla console

- **Numeri**: utenti attivi, elementi, alberi, foto e spazio, portali accesi, ultimo accesso: quello che serve per fatturare.
- **Sospendere**: da subito nessuno dell'organizzazione entra più (chi ha la password giusta legge il motivo), le sessioni aperte si chiudono, i portali pubblici si spengono. Si riattiva quando si vuole; i dati restano intatti.
- **Note**: contratto, referente, canone, scadenze. Le vedi solo tu.
- **Assistenza**: entri nell'organizzazione con l'utente "Assistenza piattaforma", per otto ore al massimo, e torni alla console con il pulsante in alto. L'organizzazione non ne vede traccia né nel suo registro né fra i suoi utenti; l'annotazione resta solo nel tuo. Le modifiche che fai durante l'assistenza restano a nome "Assistenza piattaforma".

## 9. Domande frequenti

- **La stessa email in due organizzazioni?** Si può. All'accesso il programma chiede lo slug dell'organizzazione.
- **Un Comune passa da committente mio a organizzazione sua?** Non c'è un passaggio automatico: si esporta il censimento (CSV, Excel o CAM) dalla tua organizzazione e lo si importa nella nuova. Foto e cronologia non si spostano da sole: se serve, chiedilo prima di promettere una data.
- **Chi vede i prezzi?** Solo il gestionale (amministratore, tecnico). Mai il portale riservato, mai l'app dell'esecutore, mai il portale pubblico.
- **Posso far provare il programma?** Il Comune Demo (`demo:patrimonio`) ha alberi, valutazioni e lavori verosimili per mostrare gestionale e portale prima di avere i dati veri.
