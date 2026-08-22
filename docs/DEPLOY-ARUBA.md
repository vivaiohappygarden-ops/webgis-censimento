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
| **Aggiornare l'applicazione** | `bash /var/www/webgis/deploy/update.sh` (30 secondi di manutenzione) |
| **Cambiare indirizzo / attivare HTTPS** | `bash /var/www/webgis/deploy/set-domain.sh nome.dominio.it` (vedi 6.2) |
| **Backup** | automatico ogni notte alle 03:30 in `/var/backups/webgis` (14 giorni conservati); in più, dal pannello Aruba si può attivare lo **snapshot** del server |
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

Per tornare all'indirizzo IP (raro): `bash /var/www/webgis/deploy/set-domain.sh 80.211.79.223`.

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

## 7. Se qualcosa non va

- Il sito non risponde: dal pannello Aruba riavviare il server, attendere
  2 minuti.
- Errori dopo un aggiornamento: rilanciare `bash /var/www/webgis/deploy/update.sh`.
- Per tutto il resto: aprire una sessione di lavoro con Claude descrivendo
  cosa si vede sullo schermo.

---

*Questa guida accompagna gli script in `deploy/`: `provision.sh`
(installazione), `update.sh` (aggiornamenti), `set-domain.sh` (indirizzo del
sito e HTTPS), `set-portal-domain.sh` (dominio dei portali dei Comuni),
`caddy-config.sh` (configurazione del server web, generata dal file `.env`),
`backup.sh` (salvataggi) e `.env.production.example` (configurazione).*
