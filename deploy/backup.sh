#!/usr/bin/env bash
#
# Salvataggio giornaliero: banca dati (pg_dump) + file caricati (foto e documenti).
# Installato da provision.sh e da update.sh come /usr/local/bin/webgis-backup,
# lanciato dal cron alle 03:30 con il registro in /var/log/webgis-backup.log.
#
# Come lavora (dal 26/09/2026):
# - Banca dati: un dump al giorno in <destinazione>/db, conservato 14 giorni
#   e verificato subito (pg_restore --list): un dump illeggibile non passa
#   per buono.
# - File: NON piu' un archivio compresso intero ogni notte (con 36 GB di foto
#   erano 500 GB di copie in due settimane), ma un'istantanea incrementale
#   in <destinazione>/file/<data> fatta con rsync --link-dest: ogni notte una
#   cartella completa, dove i file gia' salvati la notte prima sono
#   collegamenti fisici allo stesso contenuto e non occupano spazio. Si
#   conservano 14 istantanee; lo spazio e' una copia dei file piu' le sole
#   novita'. Una foto cancellata per sbaglio resta nelle istantanee
#   precedenti per due settimane. "ultima" punta sempre all'istantanea piu'
#   recente.
# - Copia fuori dal server (consigliata: un salvataggio sullo stesso disco
#   non copre il disco che muore). Si accende scrivendo /etc/webgis-backup.conf:
#     BACKUP_REMOTO=utente@altro-server:/percorso   (rsync via SSH, chiave gia' scambiata)
#   oppure, per uno spazio a oggetti (Aruba, Hetzner, S3) con rclone configurato:
#     BACKUP_RCLONE=nomeremoto:contenitore/webgis
#   Con rsync si specchia tutto (istantanee comprese, collegamenti conservati);
#   con rclone vanno i dump e l'ultima istantanea dei file.
# - Prima di partire controlla lo spazio libero: un salvataggio che riempie
#   il disco fermerebbe il sito, che e' peggio di un salvataggio saltato.
#
# Uso:
#   webgis-backup            esegue il salvataggio
#   webgis-backup stato      eta' e dimensione dell'ultimo salvataggio (esce con 1 se e' vecchio)
#
# Ripristino (da root):
#   sudo -u postgres pg_restore --clean --if-exists -d webgis /var/backups/webgis/db/db-<data>.dump
#   rsync -a /var/backups/webgis/file/<data>/ /var/www/webgis/storage/app/ && chown -R www-data:www-data /var/www/webgis/storage
#
# Variabili per le prove: WEBGIS_BACKUP_APP_DIR, WEBGIS_BACKUP_DEST, WEBGIS_BACKUP_CONF,
# WEBGIS_BACKUP_PG_DUMP, WEBGIS_BACKUP_PG_RESTORE, WEBGIS_BACKUP_RSYNC, WEBGIS_BACKUP_GIORNI,
# WEBGIS_BACKUP_STAMP, WEBGIS_BACKUP_MB_MINIMI.
set -euo pipefail
umask 077

APP_DIR="${WEBGIS_BACKUP_APP_DIR:-/var/www/webgis}"
DEST="${WEBGIS_BACKUP_DEST:-/var/backups/webgis}"
CONF="${WEBGIS_BACKUP_CONF:-/etc/webgis-backup.conf}"
GIORNI="${WEBGIS_BACKUP_GIORNI:-14}"
MB_MINIMI="${WEBGIS_BACKUP_MB_MINIMI:-2048}"
PG_DUMP="${WEBGIS_BACKUP_PG_DUMP:-sudo -u postgres pg_dump --format=custom webgis}"
PG_RESTORE="${WEBGIS_BACKUP_PG_RESTORE:-pg_restore}"
RSYNC="${WEBGIS_BACKUP_RSYNC:-rsync}"
STAMP="${WEBGIS_BACKUP_STAMP:-$(date +%Y%m%d-%H%M%S)}"
SORGENTE="${APP_DIR}/storage/app"

BACKUP_REMOTO=""
BACKUP_RCLONE=""
# shellcheck disable=SC1090
[ -f "${CONF}" ] && . "${CONF}"

adesso() { date '+%Y-%m-%d %H:%M:%S'; }
log() { echo "[$(adesso)] $*"; }
errore() { echo "[$(adesso)] ERRORE: $*" >&2; exit 1; }
mb_liberi() { df -Pm "$1" 2>/dev/null | awk 'NR==2 {print $4}'; }
dimensione() { du -sh "$1" 2>/dev/null | cut -f1; }

# --- stato -------------------------------------------------------------------
if [ "${1:-}" = "stato" ]; then
  esito=0
  ultimo_dump="$(ls -1t "${DEST}"/db/db-*.dump 2>/dev/null | head -1 || true)"
  if [ -n "${ultimo_dump}" ]; then
    eta_ore=$(( ( $(date +%s) - $(stat -c %Y "${ultimo_dump}") ) / 3600 ))
    echo "  banca dati: $(basename "${ultimo_dump}") ($(dimensione "${ultimo_dump}"), ${eta_ore} ore fa)"
    [ "${eta_ore}" -gt 48 ] && { echo "  ATTENZIONE: l'ultimo salvataggio della banca dati ha piu' di due giorni"; esito=1; }
  else
    echo "  banca dati: nessun salvataggio trovato in ${DEST}/db"
    esito=1
  fi
  if [ -L "${DEST}/file/ultima" ] && [ -d "${DEST}/file/ultima" ]; then
    nome="$(readlink "${DEST}/file/ultima")"
    eta_ore=$(( ( $(date +%s) - $(stat -c %Y "${DEST}/file/${nome}/.completata" 2>/dev/null || echo 0) ) / 3600 ))
    echo "  file: istantanea ${nome} (${eta_ore} ore fa), $(ls -1d "${DEST}"/file/2* 2>/dev/null | wc -l) istantanee conservate, $(dimensione "${DEST}/file") in tutto"
    [ "${eta_ore}" -gt 48 ] && { echo "  ATTENZIONE: l'ultima istantanea dei file ha piu' di due giorni"; esito=1; }
  else
    echo "  file: nessuna istantanea trovata in ${DEST}/file"
    esito=1
  fi
  echo "  spazio occupato dai salvataggi: $(dimensione "${DEST}" || echo 0); libero sul disco: $(mb_liberi "${DEST}" 2>/dev/null || mb_liberi /) MB"
  if [ -n "${BACKUP_REMOTO}" ]; then echo "  copia fuori dal server: rsync verso ${BACKUP_REMOTO}"
  elif [ -n "${BACKUP_RCLONE}" ]; then echo "  copia fuori dal server: rclone verso ${BACKUP_RCLONE}"
  else echo "  copia fuori dal server: NON configurata (${CONF}): un disco che muore porta via anche i salvataggi"; fi
  exit "${esito}"
fi

# --- controlli preliminari --------------------------------------------------
[ -d "${SORGENTE}" ] || errore "cartella dei file non trovata: ${SORGENTE}"

install -d -m 700 "${DEST}" "${DEST}/db" "${DEST}/file"
esito=0

liberi="$(mb_liberi "${DEST}")"
if [ -n "${liberi}" ] && [ "${liberi}" -lt "${MB_MINIMI}" ]; then
  errore "spazio insufficiente su ${DEST}: ${liberi} MB liberi, ne servono almeno ${MB_MINIMI}. Salvataggio non eseguito per non fermare il sito"
fi

log "Salvataggio ${STAMP} in ${DEST}"

# --- 1. banca dati -----------------------------------------------------------
# Prima su file temporaneo, poi rinomina: mai dump troncati con lo stesso
# nome di un salvataggio buono. La verifica legge l'indice del dump: se e'
# corrotto ci si accorge stanotte, non il giorno del ripristino
dump="${DEST}/db/db-${STAMP}.dump"
${PG_DUMP} > "${dump}.tmp"
${PG_RESTORE} --list "${dump}.tmp" >/dev/null || { rm -f "${dump}.tmp"; errore "il dump della banca dati non e' leggibile: salvataggio annullato"; }
mv "${dump}.tmp" "${dump}"
log "banca dati: $(dimensione "${dump}")"

# --- 2. file: istantanea incrementale ---------------------------------------
# Senza rsync i file non si salvano, ma la banca dati e' gia' al sicuro: si
# avvisa forte e si esce con errore alla fine, non si rinuncia a tutto
nuova="${DEST}/file/${STAMP}"
[ -e "${nuova}" ] && errore "istantanea ${STAMP} gia' esistente"
if command -v "${RSYNC}" >/dev/null 2>&1; then
  collega=()
  if [ -L "${DEST}/file/ultima" ] && [ -d "${DEST}/file/ultima" ]; then
    collega=(--link-dest="$(cd "${DEST}/file/ultima" && pwd -P)")
  fi
  "${RSYNC}" -a --delete "${collega[@]}" "${SORGENTE}/" "${nuova}.tmp/"
  date '+%Y-%m-%d %H:%M:%S' > "${nuova}.tmp/.completata"
  mv "${nuova}.tmp" "${nuova}"
  ln -sfn "${STAMP}" "${DEST}/file/ultima"
  log "file: istantanea ${STAMP} ($(dimensione "${nuova}") visibili, $(dimensione "${DEST}/file") occupati in tutto dalle istantanee)"
else
  echo "[$(adesso)] ERRORE: rsync non installato (apt-get install -y rsync): la banca dati e' salvata, le foto e i documenti NO" >&2
  esito=1
fi

# --- 3. pulizia --------------------------------------------------------------
# Dump piu' vecchi del limite, e i salvataggi nel formato di prima (archivi
# interi nella radice), che altrimenti resterebbero li' per sempre
find "${DEST}/db" -maxdepth 1 -name 'db-*.dump' -type f -mtime "+${GIORNI}" -delete
find "${DEST}" -maxdepth 1 \( -name 'db-*.dump' -o -name 'storage-*.tar.gz' \) -type f -mtime "+${GIORNI}" -delete
# Istantanee: si giudicano dal nome (rsync conserva le date dei file, quindi
# la data della cartella non dice quando e' stata fatta); l'ultima resta sempre
limite="$(date -d "-${GIORNI} days" +%Y%m%d)"
for cartella in "${DEST}"/file/2*; do
  [ -d "${cartella}" ] || continue
  nome="$(basename "${cartella}")"
  [ "${nome}" = "${STAMP}" ] && continue
  if [ "${nome:0:8}" -lt "${limite}" ] 2>/dev/null; then
    rm -rf "${cartella}"
    log "eliminata l'istantanea ${nome} (piu' vecchia di ${GIORNI} giorni)"
  fi
done
rm -rf "${DEST}"/file/*.tmp 2>/dev/null || true

# --- 4. copia fuori dal server (facoltativa) --------------------------------
if [ -n "${BACKUP_REMOTO}" ]; then
  # -H conserva i collegamenti fisici fra le istantanee: senza, sul server
  # remoto ogni istantanea occuperebbe lo spazio di una copia intera
  if command -v "${RSYNC}" >/dev/null 2>&1 \
     && "${RSYNC}" -aH --delete -e "ssh -o BatchMode=yes -o StrictHostKeyChecking=accept-new" "${DEST}/" "${BACKUP_REMOTO}/"; then
    log "copia fuori dal server aggiornata: ${BACKUP_REMOTO}"
  else
    echo "[$(adesso)] ATTENZIONE: copia fuori dal server NON riuscita verso ${BACKUP_REMOTO} (il salvataggio locale c'e')" >&2
  fi
elif [ -n "${BACKUP_RCLONE}" ]; then
  if command -v rclone >/dev/null 2>&1 \
     && rclone sync "${DEST}/db" "${BACKUP_RCLONE}/db" \
     && { [ ! -d "${nuova}" ] || rclone sync "${nuova}" "${BACKUP_RCLONE}/file"; }; then
    log "copia fuori dal server aggiornata: ${BACKUP_RCLONE}"
  else
    echo "[$(adesso)] ATTENZIONE: copia fuori dal server NON riuscita verso ${BACKUP_RCLONE} (rclone assente o errore; il salvataggio locale c'e')" >&2
  fi
else
  log "copia fuori dal server non configurata (${CONF}): consigliata, un disco che muore porta via anche i salvataggi"
fi

# --- 5. resoconto ------------------------------------------------------------
liberi="$(mb_liberi "${DEST}")"
log "completato: $(dimensione "${DEST}") occupati dai salvataggi, ${liberi:-?} MB liberi sul disco"
if [ -n "${liberi}" ] && [ "${liberi}" -lt $(( MB_MINIMI * 2 )) ]; then
  echo "[$(adesso)] ATTENZIONE: lo spazio libero si sta esaurendo (${liberi} MB): allargare il disco o spostare i salvataggi" >&2
fi
exit "${esito}"
