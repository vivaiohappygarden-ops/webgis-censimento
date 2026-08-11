#!/usr/bin/env bash
#
# Backup giornaliero: database (pg_dump) + file caricati (foto e documenti).
# Conserva 14 giorni in /var/backups/webgis. Installato da provision.sh
# come /usr/local/bin/webgis-backup (cron alle 03:30).
#
set -euo pipefail
umask 077

APP_DIR=/var/www/webgis
DEST=/var/backups/webgis
STAMP="$(date +%Y%m%d-%H%M)"

# I backup contengono tutti i dati: leggibili solo da root
install -d -m 700 "${DEST}"

# Prima su file temporaneo, poi rinomina: mai dump troncati con lo stesso
# nome di un backup buono
sudo -u postgres pg_dump --format=custom webgis > "${DEST}/db-${STAMP}.dump.tmp"
mv "${DEST}/db-${STAMP}.dump.tmp" "${DEST}/db-${STAMP}.dump"

tar -czf "${DEST}/storage-${STAMP}.tar.gz.tmp" -C "${APP_DIR}/storage/app" .
mv "${DEST}/storage-${STAMP}.tar.gz.tmp" "${DEST}/storage-${STAMP}.tar.gz"

find "${DEST}" -type f -mtime +14 -delete

echo "Backup ${STAMP} completato: $(du -sh "${DEST}" | cut -f1) totali in ${DEST}"
