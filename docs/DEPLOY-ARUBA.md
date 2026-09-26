# Mettere online WebGIS Censimento su Aruba — guida passo passo

Guida pensata per chi non è tecnico: seguendo i passi nell'ordine si arriva
dall'acquisto del server all'applicazione funzionante con lucchetto HTTPS.
Tempo previsto: 30-45 minuti (in gran parte attese).

---

## 1. Cosa acquistare su Aruba

### 1.1 Il server (obbligatorio)

1. Andare su **cloud.it** (Aruba Cloud) e accedere o creare l'account.
2. Scegliere **Cloud Server** → **Server basic** (infrastruttura OpenStack).
3. Configurazione da selezionare:

| Voce | Scelta |
|---|---|
| Immagine (sistema operativo) | **Ubuntu Server 24.04 LTS** |
| Taglia | **Small** (1 vCPU, 2 GB RAM, 20 GB disco) per la prova; **Medium** (2 vCPU, 4 GB) quando si lavora in più persone |
| Datacenter | Italia (IT1 o IT2) |
| Indirizzo IP | pubblico (proposto in automatico) |

4. Costo indicativo: **circa 3-4 €/mese** per la taglia Small, addebito a consumo
   orario, disdicibile in qualunque momento eliminando il server.
5. Al termine dell'attivazione, annotare **l'indirizzo IP** del server e la
   **password di root** (arriva via email o si imposta nel pannello).

### 1.2 Il nome del sito (consigliato)

Si può partire anche **senza dominio**, usando l'indirizzo IP: l'applicazione
funziona, ma senza lucchetto HTTPS restano fuori tre cose — la **posizione del
dispositivo** sulla mappa e nell'app di campo, l'**installazione dell'app di
campo** sul telefono e le **pagine pubbliche col QR** senza avvisi del browser.
Il passaggio si fa in seguito con un comando solo (vedi 6.2).

Serve un indirizzo tipo `verde.tuodominio.it`. Tre strade:

- **Dominio già posseduto su Aruba**: nel pannello DNS del dominio creare un
  **record A** con nome `verde` (o quello preferito) che punta all'IP del server.
- **Nessun dominio**: acquistarne uno su Aruba (~10 €/anno) e poi creare il
  record A come sopra.
- **Subito e gratis, senza comprare niente**: usare un nome derivato dall'IP,
  nella forma `80-211-79-223.sslip.io` (i punti dell'IP diventano trattini).
  È un servizio pubblico che risponde già con l'indirizzo del server: non c'è
  nulla da registrare e il lucchetto funziona. Il nome è brutto da leggere, ma
  si può cambiare in qualsiasi momento con un dominio vero.

Il certificato HTTPS (il lucchetto) è **gratuito e automatico**: ci pensa il
server da solo, nessun acquisto da fare, e si rinnova senza intervento.

**Se in futuro servono i portali pubblici dei Comuni** (`mentana.tuodominio.it`),
conviene scegliere fin da ora un dominio che si legga bene con il nome del
Comune davanti, per esempio `censimentoalberi.it`. Non è una decisione da
prendere subito: si può collegare in qualsiasi momento (vedi 6.3), anche un
dominio diverso da quello del gestionale.

### 1.3 Cosa NON serve

- Hosting Linux/Windows condiviso: non è adatto (manca PostgreSQL/PostGIS).
- Database as a Service, Object Storage, licenze: non servono per la prova
  (se ne riparla quando i volumi crescono).

---

## 2. Preparare l'accesso al server

Dal proprio computer serve un terminale:

- **Windows**: aprire "Prompt dei comandi" o "PowerShell".
- **Mac**: aprire "Terminale".

Collegarsi al server (sostituire `IP_DEL_SERVER` con l'indirizzo annotato):

```
ssh root@IP_DEL_SERVER
```

Alla prima connessione rispondere `yes`, poi inserire la password di root.

---

## 3. Installare l'applicazione (3 comandi)

Copiare e incollare nel terminale del server, **un blocco alla volta**,
sostituendo il dominio con il proprio:

```bash
# Con un nome di dominio (consigliato: lucchetto HTTPS e app di campo sul
# telefono). Senza dominio, mettere qui l'indirizzo IP del server: si parte
# in HTTP, senza lucchetto, e l'app di campo non si installa sul telefono.
export WEBGIS_DOMAIN=verde.tuodominio.it
export WEBGIS_REPO=https://github.com/vivaiohappygarden-ops/webgis-censimento.git
export WEBGIS_BRANCH=claude/aruba-hosting-specifics-atsiy4
```

```bash
curl -fsSL https://raw.githubusercontent.com/vivaiohappygarden-ops/webgis-censimento/claude/aruba-hosting-specifics-atsiy4/deploy/provision.sh -o provision.sh
```

```bash
bash provision.sh
```

Lo script fa tutto da solo (10-15 minuti): installa PHP, il database con le
estensioni cartografiche, Redis, GDAL, il server web con HTTPS automatico,
compila l'interfaccia, crea il database con una password sicura generata sul
momento, attiva il backup notturno. Se qualcosa lo interrompe, si può
rilanciare senza danni: riprende da dove era.

> Nota: se il repository GitHub è privato, il comando `curl` qui sopra non
> funziona (risponde "404 Not Found"). In quel caso: aprire una sessione di
> lavoro con Claude, che prepara la chiave di accesso (deploy key) e i
> comandi alternativi — è un passaggio di 5 minuti da fare una volta sola.

---

## 4. Creare la propria organizzazione

Sempre nel terminale del server:

```bash
cd /var/www/webgis
sudo -u www-data php artisan tenant:create "Happy Garden" happy-garden latuaemail@esempio.it
```

Il comando stampa **email e password** dell'amministratore: conservarle e
cambiare la password al primo accesso. Vengono installati automaticamente i
ruoli (amministratore, tecnico, operatore, cliente) e il catalogo ministeriale
completo dei 387 tipi.

---

## 5. Primo accesso

1. Aprire `https://verde.tuodominio.it` dal browser (il lucchetto può
   richiedere qualche minuto la prima volta: sta emettendo il certificato).
   Se l'installazione è su indirizzo IP, aprire `http://IP_DEL_SERVER`.
2. Accedere con le credenziali del passo 4.
3. Dal telefono, aprire lo stesso indirizzo e andare su **Campo (operatore)**:
   il browser proporrà di "Aggiungere alla schermata Home" — così l'app di
   campo si apre come un'app vera e funziona anche senza rete.

---

## 6. Gestione quotidiana

| Attività | Come |
|---|---|
| **Aggiornare l'applicazione** | da solo, entro cinque minuti da ogni pubblicazione, se l'aggiornamento automatico e' acceso (paragrafo 6.6); a mano: `bash /var/www/webgis/deploy/update.sh` (30 secondi di manutenzione) |
| **Accendere il sito aziendale** | `bash /var/www/webgis/deploy/set-sito-domain.sh <dominio>` (paragrafo 6.2-bis) |
| **Cambiare indirizzo / attivare HTTPS** | `bash /var/www/webgis/deploy/set-domain.sh nome.dominio.it` (vedi 6.2) |
| **Salvataggi** | automatici ogni notte alle 03:30 in `/var/backups/webgis` (14 giorni conservati, vedi 6.7); `webgis-backup stato` dice quando e' stato fatto l'ultimo; consigliata la copia fuori dal server (6.7) |
| **Ridurre le foto caricate intere in passato** | `cd /var/www/webgis && sudo -u www-data php artisan foto:riduci-archivio` mostra quanto spazio si recupera; con `--esegui` lo fa davvero (le foto delle perizie validate non si toccano) |
| **Nuovi utenti** | dalla pagina **Utenti** dell'applicazione |
| **Spegnere tutto** | eliminare il server dal pannello Aruba: l'addebito si ferma |

### 6.1 Riepilogo email delle scadenze

Ogni mattina alle 6:30 l'applicazione può inviare ad amministratori e tecnici
un'email con le scadenze da attenzionare (patentini, ricontrolli VTA, controlli
ricorrenti, segnalazioni fuori tempo, lavori in ritardo). Se non c'è nulla da
segnalare, non parte nessuna email.

Per attivarla serve una casella di posta da cui spedire. Con una **casella
email Aruba** (inclusa se si è acquistato il dominio con la posta):

1. Aprire sul server il file `/var/www/webgis/.env` e completare le righe
   `MAIL_`: cambiare `MAIL_MAILER=log` in `MAIL_MAILER=smtp` e inserire
   indirizzo e password della casella in `MAIL_USERNAME`, `MAIL_PASSWORD`
   e `MAIL_FROM_ADDRESS` (host `smtps.aruba.it`, porta `465` sono già
   precompilati).
2. Ricaricare la configurazione: `cd /var/www/webgis && sudo -u www-data php artisan config:cache`.
3. Prova immediata: `sudo -u www-data php artisan notifications:daily` e
   controllare che l'email arrivi.

Finché `MAIL_MAILER` resta `log`, le email non partono: finiscono solo nel
registro dell'applicazione (utile in prova). Chi non vuole ricevere il
riepilogo si esclude dalla pagina **Utenti**: aprire la modifica dell'utente
e togliere la spunta a "Riceve il riepilogo email delle scadenze".

### 6.2 Passare a HTTPS (il lucchetto)

Se l'applicazione è stata installata sull'indirizzo IP, si passa a HTTPS in un
comando solo, senza reinstallare niente:

```bash
bash /var/www/webgis/deploy/set-domain.sh nome.dominio.it
```

Il comando controlla che il nome punti davvero a questo server, apre le porte
del web, riconfigura il server web e l'applicazione, aspetta l'emissione del
certificato e verifica che il sito risponda. Dura circa un minuto.

**Senza comprare un dominio** si può usare subito un nome derivato dall'IP:

```bash
bash /var/www/webgis/deploy/set-domain.sh 80-211-79-223.sslip.io
```

(i punti dell'indirizzo IP diventano trattini). Funziona come un dominio vero
per il certificato; quando si comprerà un dominio proprio basterà rilanciare lo
stesso comando con il nome nuovo.

Con il lucchetto attivo si sbloccano: la **posizione del dispositivo** sulla
mappa e nell'app di campo, l'**installazione dell'app di campo** sul telefono
(Aggiungi a schermata Home) e le **pagine pubbliche col QR** senza avvisi.

Il vecchio indirizzo numerico non smette di funzionare: chi lo apre viene
portato al nome nuovo, pagina per pagina. Cosi' i cartellini con il QR gia'
stampati e i collegamenti salvati continuano a funzionare.

Per tornare all'indirizzo IP (raro): `bash /var/www/webgis/deploy/set-domain.sh 80.211.79.223`.

### 6.2-bis Pubblicare il sito aziendale

Il sito che parla ai Comuni sta sul **dominio nudo** (`censimentoalberature.it`,
senza prefisso); il suo `www` rinvia al dominio nudo. Finche' non lo si accende,
quel dominio non risponde e il sito si guarda solo dall'indirizzo di collaudo
`https://<indirizzo del gestionale>/sito`, che resta sempre attivo ma fuori dai
motori di ricerca (`noindex`). Non c'e' nessun ripiego automatico: senza il
comando qui sotto la radice del dominio non pubblica niente, nemmeno se i
portali dei Comuni stanno sullo stesso dominio.

Si accende con un comando solo, che fa tre cose: controlla il DNS, scrive la
configurazione (applicazione e server web) e chiede i dati dell'azienda.

1. Nel pannello DNS del dominio, due record verso l'indirizzo IP del server
   (lo stesso dei portali dei Comuni):

   | Nome | Tipo | Valore |
   | --- | --- | --- |
   | `@` (il dominio nudo) | A | indirizzo IP del server |
   | `www` | A | indirizzo IP del server |

2. Sul server:

   ```
   bash /var/www/webgis/deploy/set-sito-domain.sh censimentoalberature.it
   ```

   Il comando dice subito se i due record DNS sono a posto e, se mancano,
   stampa esattamente quelli da creare. Poi chiede i dati dell'azienda, uno
   per riga: ragione sociale, sede, codice fiscale, partita IVA, Registro delle
   Imprese, REA, capitale sociale, telefono, email, PEC e le voci facoltative
   (orari, territorio servito, da quanto si opera, chi firma le perizie e con
   che titolo). I dati societari verificati di DAMA S.R.L. e la PEC sono gia'
   scritti di serie in `config/sito.php`: Invio li conferma. **Quello che si
   lascia vuoto non compare sul sito**: il programma non stampa mai un dato
   inventato, ne' un'etichetta vuota. Un trattino (`-`) svuota una voce.

3. Per compilare o correggere i dati in un secondo momento:

   ```
   bash /var/www/webgis/deploy/set-sito-domain.sh --dati
   ```

Con il dominio acceso le pagine portano la `canonical` sul dominio nudo, i dati
strutturati (organizzazione e briciole) e l'anteprima per i social; il server
serve `robots.txt` (con la mappa e il divieto sul percorso di collaudo) e
`sitemap.xml`. Il lucchetto HTTPS lo prende da solo appena i record DNS sono
attivi, di solito entro un'ora; non serve rilanciare niente. Per spegnere il
sito sul dominio nudo: `set-sito-domain.sh --rimuovi` (resta l'indirizzo di
collaudo).

### 6.3 Il dominio dei portali dei Comuni

I portali pubblici stanno ognuno sul proprio indirizzo, con il nome del Comune
davanti:

```
https://mentana.censimentoalberi.it
https://guidoniamontecelio.censimentoalberi.it
```

Serve **un dominio solo**, comprato una volta, e vale per tutti i Comuni.

**1) Comprare il dominio.** Va bene qualunque gestore (Aruba, Cloudflare,
Namecheap): costo indicativo 10-15 euro l'anno per un `.it`. Conviene un nome
che si legga bene davanti al Comune, per esempio `censimentoalberi.it`. Il
dominio del gestionale può essere lo stesso (`gestionale.censimentoalberi.it`)
oppure un altro: non fa differenza.

**2) Un solo record DNS.** Nel pannello del gestore del dominio si crea un
record jolly, che copre in un colpo solo tutti i Comuni presenti e futuri:

| Nome | Tipo | Valore |
|---|---|---|
| `*` | A | indirizzo IP del server |

Chi preferisce tenere il controllo nome per nome crea invece un record `A` per
ogni Comune (`mentana`, `guidoniamontecelio`, ...): funziona uguale, ma va
rifatto a ogni Comune nuovo.

Il record jolly non tocca il dominio senza prefisso: `censimentoalberi.it` resta
libero per un eventuale sito di presentazione ospitato altrove.

**3) Un comando sul server.**

```bash
sudo bash /var/www/webgis/deploy/set-portal-domain.sh censimentoalberi.it
```

Il comando controlla il DNS, scrive la configurazione dell'applicazione e del
server web, e alla fine stampa l'indirizzo di ogni Comune con lo stato del suo
DNS. Per tornare indietro: `sudo bash .../set-portal-domain.sh --rimuovi`.

Da quel momento **ogni Comune nuovo non richiede più niente sul server**: si
accende dalla pagina Territorio e l'indirizzo funziona subito.

Se il gestionale sta sullo stesso dominio dei portali (per esempio
`gestionale.censimentoalberature.it` con i Comuni su
`<comune>.censimentoalberature.it`), va tutto bene: il comando se ne accorge da
solo, e il programma tiene il nome del gestionale fuori dai nomi assegnabili ai
Comuni. Il comando aggiunge anche la riga che serve al certificato. Senza, il server web darebbe per scontato che il certificato
dei Comuni copra anche il gestionale, non gliene chiederebbe uno, e il
gestionale smetterebbe di rispondere.

**Il certificato (il lucchetto) non va richiesto a mano.** Viene emesso da solo
alla prima visita. Prima di emetterlo il server web chiede all'applicazione se
quel nome corrisponde a un committente con il portale acceso, e se la risposta è
no il certificato non viene rilasciato: così nessuno può far emettere
certificati puntando un proprio nome sul nostro server.

Per rivedere in qualsiasi momento l'elenco degli indirizzi:

```bash
sudo -u www-data php /var/www/webgis/artisan portale:indirizzi --verifica
```

### 6.4 Accendere il portale di un Comune

Dalla pagina **Territorio**, scelto il committente:

1. spuntare "Portale pubblico attivo";
2. controllare il **nome nell'indirizzo** (è la parte davanti al dominio: meglio
   `mentana` che `comunedimentana`). Sotto al campo compare l'indirizzo esatto
   che avrà il portale;
3. caricare lo **stemma**, scegliere il **colore** e il **nome pubblico**;
4. compilare **titolare del trattamento** e, se l'ente ce l'ha, l'indirizzo
   della **dichiarazione di accessibilità**;
5. indicare l'**indirizzo mail per le segnalazioni**.

Il portale funziona anche senza i dati legali, ma la pagina "Privacy e note
legali" dice apertamente che il titolare non è stato indicato.

Il nome nell'indirizzo si può cambiare, ma cambiandolo il vecchio indirizzo
smette di rispondere: conviene sceglierlo bene prima di darlo all'ente.

Finché il DNS non è pronto, ogni portale è già visitabile dall'indirizzo di
collaudo `https://indirizzo-del-gestionale/comune/mentana`, che resta valido
anche dopo (nella pagina Territorio c'è il collegamento "indirizzo di
collaudo").

### 6.5 Gli sfondi della mappa

Il portale offre tre sfondi: **stradale**, **satellite** e **scuro**. Quelli
predefiniti sono servizi pubblici usati con attribuzione, come fanno i portali
civici di riferimento, e vanno bene per partire.

Non sono però contratti. Prima di consegnare a un ente che paga conviene
passare a un fornitore con chiave (CARTO, Esri, MapTiler): si registra un
account, si ottiene una chiave e si incolla l'indirizzo delle mappe nel file
`/var/www/webgis/.env`, alle voci `PORTAL_TILES_*`. Il programma non si tocca.

Una regola da ricordare: quelle voci vanno lasciate **commentate** finché non
si cambia fornitore. Una riga vuota non vale "usa il valore predefinito", vale
"vuoto", e quello sfondo sparirebbe dal selettore.

Le immagini aeree recenti non sono gratuite da nessun fornitore: le ortofoto
pubbliche degli enti sono libere ma vecchie di anni, le riprese aggiornate si
pagano. È un costo da mettere nel preventivo al committente, come il dominio.

### 6.6 Aggiornamento automatico

Invece di collegarsi al server a ogni modifica, il server puo' aggiornarsi da
solo: ogni cinque minuti controlla se sul ramo che segue e' stata pubblicata
una versione nuova e, se c'e', lancia `update.sh` (con la sua breve
manutenzione). Pubblicare una modifica diventa quindi un'operazione sola, da
parte di chi la scrive; sul server non serve fare niente.

Si accende una volta sola, da root:

```
bash /var/www/webgis/deploy/abilita-aggiornamento-automatico.sh
```

Per vedere che cosa ha fatto (stato del timer e ultime righe del registro
`/var/log/webgis-aggiornamento.log`):

```
bash /var/www/webgis/deploy/abilita-aggiornamento-automatico.sh --stato
```

Per spegnerlo: lo stesso comando con `--disabilita`. Anche `diagnostica.sh`
riporta lo stato dell'aggiornamento automatico.

Prudenze incorporate: si aggiorna solo se la storia del codice avanza in linea
retta (se qualcuno ha modificato file sul server, non tocca niente e lo scrive
nel registro); due aggiornamenti non partono mai insieme; un aggiornamento
fallito resta scritto nel registro con il suo errore, e il sito torna comunque
raggiungibile perche' `update.sh` toglie la manutenzione in ogni caso.

## 7. Se qualcosa non va

- Il sito non risponde: dal pannello Aruba riavviare il server, attendere
  2 minuti.
- Errori dopo un aggiornamento: rilanciare `bash /var/www/webgis/deploy/update.sh`.
- Per tutto il resto: aprire una sessione di lavoro con Claude descrivendo
  cosa si vede sullo schermo.

### 7.1 "A volte i dati non si caricano"

Il programma ora ci prova da solo: se una richiesta viene respinta per un
motivo passeggero la ripete, e solo se non ce la fa mostra un avviso rosso con
un numero fra parentesi e il pulsante "Riprova". Quel numero dice cosa e'
successo:

| Numero | Cosa vuol dire |
| --- | --- |
| 429 | Troppe richieste in poco tempo (tetto superato) |
| 502, 503, 504 | I processi PHP erano tutti occupati o fermi |
| 401 | La sessione e' scaduta: bisogna rientrare |
| 403 | L'utente non ha il permesso per quei dati |
| 500 | Errore del programma: va guardato il registro |

Per capire cosa e' successo sul server, un comando solo:

```bash
bash /var/www/webgis/deploy/diagnostica.sh
```

Stampa in italiano: memoria e spazio disponibili, servizi attivi, quanti
processi PHP ci sono, quante richieste sono state rifiutate e con quale
esito, e gli ultimi errori del programma. Va copiato e incollato per intero
in una sessione di lavoro con Claude.

Se dice che i processi PHP sono finiti tutti, si rilancia il dimensionamento:

```bash
bash /var/www/webgis/deploy/php-fpm-config.sh
```

Il pacchetto di Ubuntu prevede cinque processi PHP: aprendo una pagina del
gestionale ne partono di piu' insieme, e cinque non bastano. Lo script li
calcola sulla memoria del server (da 8 a 48) e ricarica il servizio solo dopo
aver verificato che la configurazione sia valida.

### 6.7 Salvataggi

Ogni notte alle 03:30 il server salva da solo, in `/var/backups/webgis`:

- la **banca dati**, un file al giorno in `db/` (piccolo: con 30.000 alberi
  circa un gigabyte), verificato subito dopo essere stato scritto;
- i **file caricati** (fotografie e documenti) in `file/<data>/`, un'istantanea
  al giorno. Le istantanee sono incrementali: i file gia' salvati la notte
  prima non occupano spazio una seconda volta, quindi 14 notti costano una
  copia delle foto piu' le sole novita'. Una foto cancellata per sbaglio resta
  nelle istantanee dei 14 giorni precedenti. `file/ultima` punta sempre alla
  piu' recente.

Per sapere come stanno i salvataggi:

```bash
webgis-backup stato
```

(lo stesso riepilogo compare in `deploy/diagnostica.sh`). Il registro e' in
`/var/log/webgis-backup.log`. Se sul disco restano meno di 2 GB il salvataggio
non parte e lo scrive nel registro: un salvataggio che riempie il disco
fermerebbe il sito.

**Copia fuori dal server.** Un salvataggio sullo stesso disco non copre il
disco che muore. Per specchiare i salvataggi altrove basta creare il file
`/etc/webgis-backup.conf` con una riga:

```bash
# un altro server raggiungibile via SSH (chiave gia' scambiata, senza password)
BACKUP_REMOTO=utente@altro-server:/percorso/salvataggi-webgis
# oppure uno spazio a oggetti (Aruba, Hetzner, S3...) configurato con rclone
BACKUP_RCLONE=nomeremoto:contenitore/webgis
```

Dalla notte seguente, alla fine del salvataggio, la copia viene aggiornata. In
alternativa, dal pannello Aruba, si puo' attivare lo **snapshot** del server.

**Ripristino** (da root, sul server):

```bash
# la banca dati, da un salvataggio scelto
sudo -u postgres pg_restore --clean --if-exists -d webgis /var/backups/webgis/db/db-<data>.dump
# i file, dall'istantanea dello stesso giorno
rsync -a /var/backups/webgis/file/<data>/ /var/www/webgis/storage/app/
chown -R www-data:www-data /var/www/webgis/storage
```

---

*Questa guida accompagna gli script in `deploy/`: `provision.sh`
(installazione), `update.sh` (aggiornamenti), `set-sito-domain.sh` (sito
aziendale), `set-domain.sh` (indirizzo del
sito e HTTPS), `set-portal-domain.sh` (dominio dei portali dei Comuni),
`caddy-config.sh` (configurazione del server web, generata dal file `.env`),
`php-fpm-config.sh` (dimensionamento dei processi PHP), `diagnostica.sh`
(fotografia dello stato del server), `backup.sh` (salvataggi) e
`.env.production.example` (configurazione).*
