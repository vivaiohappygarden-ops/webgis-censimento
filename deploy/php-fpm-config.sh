#!/usr/bin/env bash
#
# Dimensiona i processi PHP in base alla memoria del server.
#
# Il pacchetto di Ubuntu esce con cinque processi PHP: aprendo una pagina del
# gestionale partono più richieste insieme (elenco, committenti, aree,
# catalogo, riquadri della mappa) e cinque non bastano. Le richieste in
# eccesso si mettono in coda, la pagina rallenta e a volte scade: è uno dei
# motivi per cui i dati "a volte non si caricano".
#
# Uso (da root, sul server):
#   bash /var/www/webgis/deploy/php-fpm-config.sh
#
# Variabili per le prove: WEBGIS_PROVA, WEBGIS_FPM_CONF, WEBGIS_RAM_MB.
set -euo pipefail

PHP_V="${WEBGIS_PHP_VERSION:-8.4}"
CONF="${WEBGIS_FPM_CONF:-/etc/php/${PHP_V}/fpm/pool.d/www.conf}"
PROVA="${WEBGIS_PROVA:-}"

[ -f "${CONF}" ] || { echo "File di configurazione PHP non trovato: ${CONF}"; exit 1; }

# Memoria totale in MB
if [ -n "${WEBGIS_RAM_MB:-}" ]; then
  RAM_MB="${WEBGIS_RAM_MB}"
else
  RAM_MB=$(awk '/^MemTotal:/ {printf "%d", $2/1024}' /proc/meminfo)
fi

# Metà della memoria va a PHP: l'altra metà serve a PostgreSQL, Redis, al
# server web e al sistema. Una richiesta Laravel usa in media 80 MB.
FIGLI=$(( RAM_MB / 2 / 80 ))
[ "${FIGLI}" -lt 8 ] && FIGLI=8      # sotto gli otto le pagine si accodano
[ "${FIGLI}" -gt 48 ] && FIGLI=48    # oltre, il collo di bottiglia è il database

AVVIO=$(( FIGLI / 4 )); [ "${AVVIO}" -lt 2 ] && AVVIO=2
MINIMO=$(( FIGLI / 8 )); [ "${MINIMO}" -lt 2 ] && MINIMO=2
MASSIMO=$(( FIGLI / 2 )); [ "${MASSIMO}" -lt 4 ] && MASSIMO=4
[ "${MASSIMO}" -gt "${FIGLI}" ] && MASSIMO="${FIGLI}"

imposta() {
  local chiave="$1" valore="$2"
  local cercato
  cercato="$(printf '%s' "${chiave}" | sed 's/\./\\./g')"
  if grep -qE "^;?[[:space:]]*${cercato}[[:space:]]*=" "${CONF}"; then
    sed -i -E "s|^;?[[:space:]]*${cercato}[[:space:]]*=.*|${chiave} = ${valore}|" "${CONF}"
  else
    printf '%s = %s\n' "${chiave}" "${valore}" >> "${CONF}"
  fi
}

# Copia di sicurezza: se la configurazione non passa il controllo si torna
# indietro, il sito non deve restare senza PHP
BACKUP="${CONF}.prima-di-webgis"
[ -f "${BACKUP}" ] || cp "${CONF}" "${BACKUP}"

imposta "pm" "dynamic"
imposta "pm.max_children" "${FIGLI}"
imposta "pm.start_servers" "${AVVIO}"
imposta "pm.min_spare_servers" "${MINIMO}"
imposta "pm.max_spare_servers" "${MASSIMO}"
# Ogni processo si rigenera dopo 500 richieste: tiene a bada le perdite di
# memoria delle librerie senza pesare sulle prestazioni
imposta "pm.max_requests" "500"

echo "==> Processi PHP dimensionati su ${RAM_MB} MB di memoria"
echo "    processi contemporanei: ${FIGLI} (erano 5 di serie)"
echo "    all'avvio: ${AVVIO}, di scorta: da ${MINIMO} a ${MASSIMO}"

if [ -n "${PROVA}" ]; then
  echo "    (prova: nessun riavvio del servizio)"
  exit 0
fi

if ! php-fpm"${PHP_V}" -t >/dev/null 2>&1; then
  echo "La configurazione generata non è valida: ripristino quella precedente."
  cp "${BACKUP}" "${CONF}"
  php-fpm"${PHP_V}" -t || true
  exit 1
fi

systemctl reload "php${PHP_V}-fpm" 2>/dev/null || systemctl restart "php${PHP_V}-fpm"
echo "    servizio PHP ricaricato."
