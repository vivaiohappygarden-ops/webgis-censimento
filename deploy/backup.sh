#!/usr/bin/env bash
#
# Backup giornaliero: database (pg_dump) + file caricati (foto e documenti).
# Conserva 14 giorni in /var/backups/webgis. Installato da provision.sh
# come /usr/local/bin/webgis-backup (cron alle 03:30).
#
set -euo pipefail

APP_DIR=/var/www/webgis
DEST=/var/backups/webgis
STAMP="$(date +%Y%m%d-%H%M)"

mkdir -p "${DEST}"

sudo -u postgres pg_dump --format=custom webgis > "${DEST}/db-${STAMP}.dump"

tar -czf "${DEST}/storage-${STAMP}.tar.gz" -C "${APP_DIR}/storage/app" . 2>/dev/null || true

find "${DEST}" -type f -mtime +14 -delete

echo "Backup ${STAMP} completato: $(du -sh "${DEST}" | cut -f1) totali in ${DEST}"
