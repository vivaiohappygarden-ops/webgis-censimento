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

Serve un indirizzo tipo `verde.tuodominio.it`. Due strade:

- **Dominio già posseduto su Aruba**: nel pannello DNS del dominio creare un
  **record A** con nome `verde` (o quello preferito) che punta all'IP del server.
- **Nessun dominio**: acquistarne uno su Aruba (~10 €/anno) e poi creare il
  record A come sopra.

Il certificato HTTPS (il lucchetto) è **gratuito e automatico**: ci pensa il
server da solo, nessun acquisto da fare.

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
export WEBGIS_DOMAIN=verde.tuodominio.it
export WEBGIS_REPO=https://github.com/vivaiohappygarden-ops/webgis-censimento.git
```

```bash
curl -fsSL https://raw.githubusercontent.com/vivaiohappygarden-ops/webgis-censimento/main/deploy/provision.sh -o provision.sh
```

```bash
bash provision.sh
```

Lo script fa tutto da solo (10-15 minuti): installa PHP, il database con le
estensioni cartografiche, Redis, GDAL, il server web con HTTPS automatico,
compila l'interfaccia, crea il database con una password sicura generata sul
momento, attiva il backup notturno. Se qualcosa lo interrompe, si può
rilanciare senza danni: riprende da dove era.

> Nota: se il repository è privato, serve prima una chiave di accesso
> (deploy key) su GitHub — in quel caso chiedere assistenza a Claude in una
> sessione di lavoro e la prepariamo insieme.

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
2. Accedere con le credenziali del passo 4.
3. Dal telefono, aprire lo stesso indirizzo e andare su **Campo (operatore)**:
   il browser proporrà di "Aggiungere alla schermata Home" — così l'app di
   campo si apre come un'app vera e funziona anche senza rete.

---

## 6. Gestione quotidiana

| Attività | Come |
|---|---|
| **Aggiornare l'applicazione** | `bash /var/www/webgis/deploy/update.sh` (30 secondi di manutenzione) |
| **Backup** | automatico ogni notte alle 03:30 in `/var/backups/webgis` (14 giorni conservati); in più, dal pannello Aruba si può attivare lo **snapshot** del server |
| **Nuovi utenti** | dall'interfaccia (in arrivo) o chiedere a Claude |
| **Spegnere tutto** | eliminare il server dal pannello Aruba: l'addebito si ferma |

## 7. Se qualcosa non va

- Il sito non risponde: dal pannello Aruba riavviare il server, attendere
  2 minuti.
- Errori dopo un aggiornamento: rilanciare `bash /var/www/webgis/deploy/update.sh`.
- Per tutto il resto: aprire una sessione di lavoro con Claude descrivendo
  cosa si vede sullo schermo.

---

*Questa guida accompagna gli script in `deploy/`: `provision.sh`
(installazione), `update.sh` (aggiornamenti), `backup.sh` (salvataggi),
`Caddyfile` (server web) e `.env.production.example` (configurazione).*
