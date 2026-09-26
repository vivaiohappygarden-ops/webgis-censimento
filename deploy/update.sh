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

echo "==> Salvataggi"
# Il salvataggio notturno vive fuori dalla cartella del programma
# (/usr/local/bin/webgis-backup): senza questo passo un miglioramento dello
# script non arriverebbe mai ai server gia' installati. rsync serve alle
# istantanee incrementali dei file
if [ "$(id -u)" -eq 0 ]; then
  command -v rsync >/dev/null 2>&1 || apt-get install -y -qq rsync >/dev/null 2>&1 \
    || echo "  (rsync non installato: i file non verranno salvati finche' non si installa con apt-get install -y rsync)"
  install -m 750 "${APP_DIR}/deploy/backup.sh" /usr/local/bin/webgis-backup
  [ -f /etc/cron.d/webgis-backup ] || printf '30 3 * * * root /usr/local/bin/webgis-backup >> /var/log/webgis-backup.log 2>&1\n' > /etc/cron.d/webgis-backup
else
  echo "  (non root: lo script dei salvataggi non e' stato reinstallato)"
fi

echo "==> Permessi e servizi"
chown -R www-data:www-data "${APP_DIR}"
systemctl restart webgis-queue
systemctl reload php8.4-fpm || true

# La configurazione del server web nasce dal file .env e da caddy-config.sh:
# quando lo script cambia (per esempio quando ha imparato a servire il sito
# aziendale sul dominio nudo) il server deve saperlo senza aspettare che
# qualcuno lo rilanci a mano. Si tocca solo un file generato da noi, mai uno
# scritto a mano; se la configurazione non fosse valida resta quella di prima.
if head -1 /etc/caddy/Caddyfile 2>/dev/null | grep -q "generato da deploy/caddy-config.sh"; then
  bash "${APP_DIR}/deploy/caddy-config.sh" || echo "  (server web non riconfigurato: si prosegue con la configurazione attuale)"
fi

echo "==> Aggiornamento completato."
