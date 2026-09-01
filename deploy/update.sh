#!/usr/bin/env bash
#
# Aggiornamento dell'applicazione alla versione più recente del branch corrente.
# Uso (da root): bash /var/www/webgis/deploy/update.sh
#
set -euo pipefail

APP_DIR=/var/www/webgis
cd "${APP_DIR}"

git config --global --add safe.directory "${APP_DIR}" 2>/dev/null || true

echo "==> Codice"
git pull --ff-only

echo "==> Dipendenze e build"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci --silent
npm run build --silent

echo "==> Estensioni del database"
# La ricerca senza accenti ("citta" trova "Città") usa l'estensione unaccent,
# che può creare solo il superutente postgres: si crea qui, prima delle
# migrazioni, che poi costruiscono la funzione e gli indici. Se non riesce
# l'aggiornamento prosegue: la ricerca continua solo a distinguere gli accenti
sudo -u postgres psql -d webgis -qc "CREATE EXTENSION IF NOT EXISTS unaccent" \
  || echo "  (estensione unaccent non creata: la ricerca distingue ancora gli accenti)"

echo "==> Manutenzione breve"
php artisan down --retry=15 || true
# Qualunque cosa accada, il sito non resta in manutenzione
trap 'php artisan up || true' EXIT
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Processi PHP"
# Ridimensiona i processi PHP sulla memoria del server: il valore di serie
# (cinque) accoda le richieste di una pagina appena aperta
bash "${APP_DIR}/deploy/php-fpm-config.sh" || echo "  (dimensionamento non riuscito: si prosegue con la configurazione attuale)"

echo "==> Permessi e servizi"
chown -R www-data:www-data "${APP_DIR}"
systemctl restart webgis-queue
systemctl reload php8.4-fpm || true

echo "==> Aggiornamento completato."
