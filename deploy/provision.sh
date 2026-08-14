#!/usr/bin/env bash
#
# Installazione completa di WebGIS Censimento su un server Ubuntu 24.04 pulito
# (VPS Aruba Cloud). Idempotente: rilanciarlo non rompe un'installazione esistente.
#
# Uso (da root sul server):
#   export WEBGIS_DOMAIN=verde.esempio.it
#   export WEBGIS_REPO=https://github.com/vivaiohappygarden-ops/webgis-censimento.git
#   export WEBGIS_BRANCH=claude/aruba-hosting-specifics-atsiy4   # facoltativo
#   bash provision.sh
#
set -euo pipefail

WEBGIS_DOMAIN="${WEBGIS_DOMAIN:?Impostare WEBGIS_DOMAIN (es. verde.esempio.it)}"
WEBGIS_REPO="${WEBGIS_REPO:?Impostare WEBGIS_REPO (URL del repository git)}"
# Ramo predefinito del repository (il progetto non usa "main")
WEBGIS_BRANCH="${WEBGIS_BRANCH:-claude/aruba-hosting-specifics-atsiy4}"
APP_DIR=/var/www/webgis
PHP_V=8.4

# Un indirizzo IP non può avere un certificato pubblico: in quel caso si
# serve in HTTP e il cookie di sessione non va marcato "solo HTTPS", o il
# login girerebbe a vuoto. Con un nome di dominio, invece, HTTPS automatico.
if [[ "${WEBGIS_DOMAIN}" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]]; then
  SITE_ADDRESS="http://${WEBGIS_DOMAIN}"
  APP_SCHEME="http"
  SECURE_COOKIE="false"
else
  SITE_ADDRESS="${WEBGIS_DOMAIN}"
  APP_SCHEME="https"
  SECURE_COOKIE="true"
fi

log() { echo -e "\n==> $*"; }

[ "$(id -u)" -eq 0 ] || { echo "Eseguire come root."; exit 1; }
. /etc/os-release
[ "${ID}" = "ubuntu" ] || echo "Avviso: testato su Ubuntu 24.04, trovato ${PRETTY_NAME}."

export DEBIAN_FRONTEND=noninteractive

log "Pacchetti di sistema"
apt-get update -qq
apt-get install -y -qq software-properties-common curl git unzip gnupg2 ca-certificates openssl

log "PHP ${PHP_V} (PPA ondrej)"
add-apt-repository -y ppa:ondrej/php >/dev/null
apt-get update -qq
apt-get install -y -qq \
  "php${PHP_V}-fpm" "php${PHP_V}-cli" "php${PHP_V}-pgsql" "php${PHP_V}-redis" \
  "php${PHP_V}-gd" "php${PHP_V}-mbstring" "php${PHP_V}-xml" "php${PHP_V}-curl" \
  "php${PHP_V}-zip" "php${PHP_V}-bcmath" "php${PHP_V}-intl"

# I default di PHP (2/8 MB) rifiuterebbero le foto dal telefono e gli import
# CAM: i limiti applicativi arrivano a 50 MB, qui si dà margine
cat > "/etc/php/${PHP_V}/fpm/conf.d/90-webgis.ini" <<INI
upload_max_filesize = 64M
post_max_size = 64M
memory_limit = 256M
INI
systemctl reload "php${PHP_V}-fpm" 2>/dev/null || systemctl restart "php${PHP_V}-fpm" || true

log "PostgreSQL + PostGIS, Redis, GDAL"
apt-get install -y -qq postgresql-16 postgresql-16-postgis-3 redis-server gdal-bin
systemctl enable --now postgresql redis-server

log "Composer"
if ! command -v composer >/dev/null; then
  curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

log "Node 22 (build asset)"
if ! command -v node >/dev/null || [ "$(node -v | cut -c2-3)" -lt 22 ]; then
  curl -fsSL https://deb.nodesource.com/setup_22.x | bash - >/dev/null
  apt-get install -y -qq nodejs
fi

log "Caddy (HTTPS automatico)"
if ! command -v caddy >/dev/null; then
  curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' \
    | gpg --dearmor --yes -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
  curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' \
    > /etc/apt/sources.list.d/caddy-stable.list
  apt-get update -qq && apt-get install -y -qq caddy
fi
# Il socket PHP-FPM è accessibile solo al gruppo www-data: senza questo
# ogni pagina risponderebbe 502
usermod -aG www-data caddy

log "Database webgis"
DB_PASS_FILE=/root/.webgis-db-password
if [ ! -f "${DB_PASS_FILE}" ]; then
  # openssl e umask: niente pipe da /dev/urandom, che con pipefail
  # ucciderebbe lo script in silenzio (SIGPIPE su tr|head)
  (umask 077; openssl rand -hex 16 > "${DB_PASS_FILE}")
fi
chmod 600 "${DB_PASS_FILE}"
DB_PASS="$(cat "${DB_PASS_FILE}")"
sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='webgis'" | grep -q 1 \
  || sudo -u postgres psql -qc "CREATE ROLE webgis LOGIN"
# La password passa su stdin, mai come argomento (visibile in ps/log)
printf "ALTER ROLE webgis PASSWORD '%s';\n" "${DB_PASS}" | sudo -u postgres psql -q
sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='webgis'" | grep -q 1 \
  || sudo -u postgres createdb -O webgis webgis
# Le estensioni richiedono il superutente: le stesse attese dalle migrazioni
sudo -u postgres psql -d webgis -qc "CREATE EXTENSION IF NOT EXISTS postgis; CREATE EXTENSION IF NOT EXISTS pg_trgm; CREATE EXTENSION IF NOT EXISTS citext; CREATE EXTENSION IF NOT EXISTS btree_gist;"

log "Codice applicativo in ${APP_DIR}"
# Il repo appartiene a www-data ma git gira da root: senza questa eccezione
# ogni comando git fallirebbe con "dubious ownership" dal secondo avvio in poi
git config --global --add safe.directory "${APP_DIR}" 2>/dev/null || true
if [ ! -d "${APP_DIR}/.git" ]; then
  git clone --branch "${WEBGIS_BRANCH}" "${WEBGIS_REPO}" "${APP_DIR}"
else
  git -C "${APP_DIR}" fetch origin "${WEBGIS_BRANCH}"
  git -C "${APP_DIR}" checkout "${WEBGIS_BRANCH}"
  git -C "${APP_DIR}" pull --ff-only origin "${WEBGIS_BRANCH}"
fi
cd "${APP_DIR}"

log "Dipendenze e build"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci --silent
npm run build --silent

log "Configurazione .env"
if [ ! -f .env ]; then
  cp deploy/.env.production.example .env
  sed -i "s|^APP_URL=.*|APP_URL=${APP_SCHEME}://${WEBGIS_DOMAIN}|" .env
  sed -i "s|^SESSION_SECURE_COOKIE=.*|SESSION_SECURE_COOKIE=${SECURE_COOKIE}|" .env
  sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=${DB_PASS}|" .env
  php artisan key:generate --force
else
  echo ".env già presente: non modificato."
  # Cambiando indirizzo (es. dall'IP a un dominio) queste due righe vanno
  # aggiornate a mano, o il login e i collegamenti resterebbero sul vecchio
  if ! grep -q "^APP_URL=${APP_SCHEME}://${WEBGIS_DOMAIN}$" .env; then
    echo "  ATTENZIONE: in .env aggiornare APP_URL=${APP_SCHEME}://${WEBGIS_DOMAIN}"
    echo "  e SESSION_SECURE_COOKIE=${SECURE_COOKIE}, poi: php artisan config:cache"
  fi
fi

log "Migrazioni e cache"
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link || true

log "Permessi"
chown -R www-data:www-data "${APP_DIR}"
find "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache" -type d -exec chmod 775 {} +
# Il file di configurazione contiene password e chiavi: solo proprietario e gruppo
chmod 640 "${APP_DIR}/.env"

log "Worker della coda (systemd)"
cat > /etc/systemd/system/webgis-queue.service <<UNIT
[Unit]
Description=WebGIS Censimento queue worker
After=network.target redis-server.service postgresql.service

[Service]
User=www-data
Group=www-data
Restart=always
RestartSec=5
ExecStart=/usr/bin/php ${APP_DIR}/artisan queue:work --sleep=3 --tries=3 --max-time=3600

[Install]
WantedBy=multi-user.target
UNIT
systemctl daemon-reload
systemctl enable --now webgis-queue

log "Caddy"
sed "s|SOSTITUIRE.esempio.it|${SITE_ADDRESS}|" deploy/Caddyfile > /etc/caddy/Caddyfile
# La directory dei log deve appartenere a caddy, altrimenti il caricamento
# della configurazione fallisce
install -d -o caddy -g caddy /var/log/caddy
systemctl enable --now caddy
systemctl restart caddy

log "Firewall: apertura delle porte del web"
# Su alcune immagini Ubuntu ufw è attivo e lascia passare solo l'SSH:
# senza queste regole il sito risponde solo dall'interno del server
if command -v ufw >/dev/null && ufw status | grep -q "Status: active"; then
  ufw allow 22/tcp >/dev/null || true
  ufw allow 80/tcp >/dev/null || true
  ufw allow 443/tcp >/dev/null || true
  echo "  ufw: aperte le porte 22, 80 e 443."
else
  echo "  ufw non attivo: nessuna regola da aggiungere."
fi

log "Backup giornaliero (03:30)"
install -m 750 deploy/backup.sh /usr/local/bin/webgis-backup
cat > /etc/cron.d/webgis-backup <<CRON
30 3 * * * root /usr/local/bin/webgis-backup >> /var/log/webgis-backup.log 2>&1
CRON

log "Scheduler Laravel (riepilogo email e attivita' pianificate)"
cat > /etc/cron.d/webgis-scheduler <<CRON
* * * * * www-data cd ${APP_DIR} && php artisan schedule:run >> /dev/null 2>&1
CRON

log "Fatto."
echo
echo "Prossimi passi:"
if [ "${APP_SCHEME}" = "https" ]; then
  echo "  1. Puntare il DNS di ${WEBGIS_DOMAIN} all'IP di questo server (se non già fatto)."
else
  echo "  1. Installazione su indirizzo IP: niente lucchetto HTTPS e app di campo"
  echo "     non installabile sul telefono. Per averli serve un nome di dominio:"
  echo "     creare il record DNS e rilanciare provision.sh con WEBGIS_DOMAIN=nome.dominio.it"
fi
echo "  2. Creare l'organizzazione:  cd ${APP_DIR} && sudo -u www-data php artisan tenant:create \"Nome Azienda\" nome-azienda admin@esempio.it"
echo "  3. Aprire ${APP_SCHEME}://${WEBGIS_DOMAIN} e accedere con le credenziali mostrate."
