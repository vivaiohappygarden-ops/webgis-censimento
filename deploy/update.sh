#!/usr/bin/env bash
#
# Aggiornamento dell'applicazione alla versione più recente del branch corrente.
# Uso (da root): bash /var/www/webgis/deploy/update.sh
#
set -euo pipefail

APP_DIR=/var/www/webgis
cd "${APP_DIR}"

echo "==> Codice"
git pull --ff-only

echo "==> Dipendenze e build"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci --silent
npm run build --silent

echo "==> Manutenzione breve"
php artisan down --retry=15 || true
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan up

echo "==> Permessi e servizi"
chown -R www-data:www-data "${APP_DIR}"
systemctl restart webgis-queue
systemctl reload php8.4-fpm || true

echo "==> Aggiornamento completato."
