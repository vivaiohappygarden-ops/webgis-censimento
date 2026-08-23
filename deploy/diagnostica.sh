#!/usr/bin/env bash
#
# Fotografia dello stato del server, in italiano.
#
# Serve quando qualcosa "a volte non funziona": dice quante richieste sono
# state rifiutate e perché, se i processi PHP bastano, se la memoria è finita
# e quali errori ha scritto il programma.
#
# Uso (da root, sul server):
#   bash /var/www/webgis/deploy/diagnostica.sh
#
set -uo pipefail

PHP_V="${WEBGIS_PHP_VERSION:-8.4}"
APP_DIR="${WEBGIS_APP_DIR:-/var/www/webgis}"
LOG_WEB="${WEBGIS_LOG_WEB:-/var/log/caddy/webgis-access.log}"
LOG_PHP="${WEBGIS_LOG_PHP:-/var/log/php${PHP_V}-fpm.log}"
LOG_APP="${APP_DIR}/storage/logs/laravel.log"
FPM_CONF="${WEBGIS_FPM_CONF:-/etc/php/${PHP_V}/fpm/pool.d/www.conf}"

titolo() { printf '\n===== %s =====\n' "$1"; }

titolo "Memoria del server"
free -h 2>/dev/null || echo "  (comando free non disponibile)"
if [ "$(awk '/^SwapTotal:/ {print $2}' /proc/meminfo 2>/dev/null || echo 0)" = "0" ]; then
  echo "  Attenzione: non c'è memoria di scambio (swap). Se la memoria finisce,"
  echo "  il sistema chiude un programma a caso e il sito dà errore per qualche istante."
fi

titolo "Spazio su disco"
df -h / 2>/dev/null | sed -n '1,2p'

titolo "Servizi"
for s in "php${PHP_V}-fpm" caddy postgresql redis-server webgis-queue; do
  stato="$(systemctl is-active "$s" 2>/dev/null || true)"
  printf '  %-18s %s\n' "$s" "${stato:-sconosciuto}"
done

titolo "Processi PHP"
if [ -f "${FPM_CONF}" ]; then
  grep -E '^pm(\.|$| )' "${FPM_CONF}" | sed 's/^/  /'
else
  echo "  configurazione non trovata: ${FPM_CONF}"
fi
attivi="$(ps --no-headers -C php-fpm 2>/dev/null | wc -l)"
echo "  processi PHP in esecuzione ora (compreso il capofila): ${attivi}"
if [ -f "${LOG_PHP}" ]; then
  saturi="$(grep -c 'reached pm.max_children' "${LOG_PHP}" 2>/dev/null || true)"
  saturi="${saturi:-0}"
  echo "  volte in cui i processi sono finiti tutti: ${saturi}"
  [ "${saturi}" != "0" ] && echo "  -> alza il numero: bash ${APP_DIR}/deploy/php-fpm-config.sh"
else
  echo "  registro PHP non trovato: ${LOG_PHP}"
fi

titolo "Esiti delle richieste (registro del server web)"
if [ -f "${LOG_WEB}" ]; then
  righe="$(wc -l < "${LOG_WEB}")"
  echo "  richieste registrate: ${righe}"
  echo "  riepilogo per esito (le più frequenti):"
  grep -o '"status":[0-9]*' "${LOG_WEB}" 2>/dev/null \
    | sed 's/"status"://' | sort | uniq -c | sort -rn | head -12 \
    | awk '{
        d = "";
        if ($2 == 200 || $2 == 204 || $2 == 304) d = "va bene";
        else if ($2 == 401 || $2 == 419) d = "sessione scaduta";
        else if ($2 == 403) d = "permesso negato";
        else if ($2 == 404) d = "indirizzo inesistente";
        else if ($2 == 429) d = "TROPPE RICHIESTE: e il motivo per cui i dati non si caricano";
        else if ($2 == 500) d = "errore del programma";
        else if ($2 == 502 || $2 == 503 || $2 == 504) d = "PHP occupato o non raggiungibile";
        printf "    %8d  esito %s  %s\n", $1, $2, d;
      }'
  echo "  ultime richieste rifiutate:"
  rifiutate="$(grep -E '"status":(4[0-9][0-9]|5[0-9][0-9])' "${LOG_WEB}" 2>/dev/null | tail -8)"
  if [ -n "${rifiutate}" ]; then
    printf '%s\n' "${rifiutate}" | sed -E 's/.*"uri":"([^"]*)".*"status":([0-9]+).*/    esito \2 su \1/'
  else
    echo "    nessuna"
  fi
else
  echo "  registro non trovato: ${LOG_WEB}"
fi

titolo "Ultimi errori del programma"
if [ -f "${LOG_APP}" ]; then
  errori="$(grep -E '^\[.*\] [a-z]+\.(ERROR|CRITICAL|ALERT|EMERGENCY)' "${LOG_APP}" 2>/dev/null | tail -8 | cut -c1-200)"
  if [ -n "${errori}" ]; then
    printf '%s\n' "${errori}" | sed 's/^/  /'
  else
    echo "  nessun errore recente"
  fi
else
  echo "  registro non trovato: ${LOG_APP}"
fi

titolo "Database e memoria condivisa"
sudo -u postgres psql -tAc "SELECT count(*) || ' collegamenti su ' || setting FROM pg_stat_activity, pg_settings WHERE name='max_connections'" 2>/dev/null \
  | sed 's/^/  PostgreSQL: /' || echo "  PostgreSQL: non interrogabile"
redis-cli info memory 2>/dev/null | grep -E '^(used_memory_human|maxmemory_human)' | sed 's/^/  Redis: /' \
  || echo "  Redis: non interrogabile"

printf '\nFine. Copia e incolla tutto quello che vedi qui sopra.\n'
