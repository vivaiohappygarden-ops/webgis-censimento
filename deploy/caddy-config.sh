#!/usr/bin/env bash
#
# Riscrive /etc/caddy/Caddyfile leggendo i valori dal file .env
# dell'applicazione. La configurazione del server web non si scrive mai a
# mano: la generano set-domain.sh (indirizzo del gestionale) e
# set-portal-domain.sh (dominio dei portali pubblici dei Comuni).
#
# Uso (da root, sul server):
#   bash /var/www/webgis/deploy/caddy-config.sh
#
set -euo pipefail

# I due percorsi si possono cambiare solo per le verifiche automatiche:
# sul server valgono i valori predefiniti. Con WEBGIS_PROVA=1 la
# configurazione viene generata e stampata senza toccare il server web.
APP_DIR="${WEBGIS_APP_DIR:-/var/www/webgis}"
CADDYFILE="${WEBGIS_CADDYFILE:-/etc/caddy/Caddyfile}"
PROVA="${WEBGIS_PROVA:-}"

cd "${APP_DIR}"

valore_env() {
  grep -E "^${1}=" .env 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"'\''' || true
}

APP_URL="$(valore_env APP_URL)"
PORTALI="$(valore_env PORTAL_BASE_HOST)"

if [[ -z "${APP_URL}" ]]; then
  echo "APP_URL non impostato in ${APP_DIR}/.env" >&2
  exit 1
fi

# Da "https://gestionale.esempio.it" a "gestionale.esempio.it"
HOST_GESTIONALE="${APP_URL#*://}"
HOST_GESTIONALE="${HOST_GESTIONALE%%/*}"
SCHEMA="${APP_URL%%://*}"

# In http (indirizzo IP) il blocco deve dirlo esplicitamente, altrimenti
# Caddy proverebbe a emettere un certificato per un indirizzo IP
if [[ "${SCHEMA}" = "http" ]]; then
  BLOCCO_GESTIONALE="http://${HOST_GESTIONALE}"
else
  BLOCCO_GESTIONALE="${HOST_GESTIONALE}"
fi

if [[ -z "${PROVA}" ]]; then
  install -d -o caddy -g caddy /var/log/caddy
fi

# Si scrive prima in un file di lavoro: se la configurazione non fosse valida
# il server web resta con quella di prima, funzionante
TEMPORANEO="$(mktemp)"
trap 'rm -f "${TEMPORANEO}"' EXIT

{
  echo "# File generato da deploy/caddy-config.sh: non modificarlo a mano."
  echo "# Per cambiare l'indirizzo del gestionale: deploy/set-domain.sh"
  echo "# Per il dominio dei portali dei Comuni: deploy/set-portal-domain.sh"
  echo

  if [[ -n "${PORTALI}" ]]; then
    cat <<'GLOBALE'
{
	# Certificato al volo per i sottodomini dei Comuni. Prima di chiederlo
	# Caddy interroga l'applicazione, che risponde "sì" solo per un
	# committente con il portale acceso: senza questo controllo chiunque
	# potrebbe far emettere certificati puntando un proprio nome qui.
	on_demand_tls {
		ask http://127.0.0.1:8081/interno/tls
		interval 2m
		burst 5
	}
}

# Ascolto interno usato solo dal controllo qui sopra: non è raggiungibile
# da fuori, perché è legato all'indirizzo di loopback.
http://127.0.0.1:8081 {
	root * /var/www/webgis/public
	php_fastcgi unix//run/php/php8.4-fpm.sock
	file_server
}

GLOBALE
  fi

  cat <<'COMUNE'
(comune) {
	root * /var/www/webgis/public
	encode zstd gzip

	php_fastcgi unix//run/php/php8.4-fpm.sock

	file_server

	header {
		X-Content-Type-Options nosniff
		X-Frame-Options SAMEORIGIN
		Referrer-Policy strict-origin-when-cross-origin
		-Server
	}

	# Gli asset compilati hanno il nome con hash: cache lunga
	@static path /build/*
	header @static Cache-Control "public, max-age=31536000, immutable"

	log {
		output file /var/log/caddy/webgis-access.log {
			roll_size 50MiB
			roll_keep 10
		}
	}
}

COMUNE

  echo "# Gestionale"
  echo "${BLOCCO_GESTIONALE} {"
  echo -e "\timport comune"
  if [[ "${SCHEMA}" = "https" ]]; then
    echo
    echo -e "\t# HSTS solo qui: estenderlo ai sottodomini obbligherebbe ogni nuovo"
    echo -e "\t# Comune a essere in HTTPS prima ancora di avere il certificato"
    echo -e "\theader Strict-Transport-Security \"max-age=31536000\""
  fi
  echo "}"

  if [[ -n "${PORTALI}" ]]; then
    echo
    echo "# Portali pubblici dei Comuni: <comune>.${PORTALI}"
    echo "*.${PORTALI} {"
    echo -e "\ttls {"
    echo -e "\t\ton_demand"
    echo -e "\t}"
    echo
    echo -e "\timport comune"
    echo "}"
  fi
} > "${TEMPORANEO}"

if command -v caddy >/dev/null; then
  if ! ESITO="$(caddy validate --adapter caddyfile --config "${TEMPORANEO}" 2>&1)"; then
    echo "La configurazione generata non è valida: il server web non è stato toccato." >&2
    echo "${ESITO}" >&2
    exit 1
  fi
fi

install -m 644 "${TEMPORANEO}" "${CADDYFILE}"

if [[ -n "${PROVA}" ]]; then
  cat "${CADDYFILE}"
  exit 0
fi

systemctl reload caddy || systemctl restart caddy

echo "Server web riconfigurato:"
echo "  gestionale: ${APP_URL}"
if [[ -n "${PORTALI}" ]]; then
  echo "  portali dei Comuni: https://<comune>.${PORTALI}"
else
  echo "  portali dei Comuni: non configurati (deploy/set-portal-domain.sh)"
fi
