#!/usr/bin/env bash
#
# Collega il dominio dei portali pubblici dei Comuni, quello che rende gli
# indirizzi del tipo:
#
#   https://mentana.censimentoalberi.it
#   https://guidonia.censimentoalberi.it
#
# invece del ripiego di collaudo https://indirizzo-del-sito/comune/mentana.
#
# Uso (da root, sul server):
#   bash /var/www/webgis/deploy/set-portal-domain.sh censimentoalberi.it
#   bash /var/www/webgis/deploy/set-portal-domain.sh --rimuovi
#
# Il dominio si compra una volta sola. Nel pannello del gestore del dominio
# serve un record jolly:
#
#   Nome: *      Tipo: A      Valore: indirizzo IP di questo server
#
# Con il record jolly ogni nuovo Comune è immediato: si accende dal gestionale
# e l'indirizzo funziona subito, certificato compreso. In alternativa si crea
# un record A per ogni Comune.
#
set -euo pipefail

APP_DIR=/var/www/webgis
DOMINIO="${1:-}"

if [[ -z "${DOMINIO}" ]]; then
  echo "Uso: bash ${APP_DIR}/deploy/set-portal-domain.sh <dominio-dei-portali>" >&2
  echo "     bash ${APP_DIR}/deploy/set-portal-domain.sh --rimuovi" >&2
  exit 1
fi
if [[ $EUID -ne 0 ]]; then
  echo "Questo comando va eseguito da root (sudo)." >&2
  exit 1
fi
if [[ ! -d "${APP_DIR}" ]]; then
  echo "Applicazione non trovata in ${APP_DIR}." >&2
  exit 1
fi

cd "${APP_DIR}"

log() { echo; echo "==> $*"; }

# Scrive (o riscrive) una riga del file .env
scrivi_env() {
  local chiave="$1" valore="$2"
  if grep -q "^${chiave}=" .env; then
    sed -i "s|^${chiave}=.*|${chiave}=${valore}|" .env
  else
    echo "${chiave}=${valore}" >> .env
  fi
}

ricarica() {
  sudo -u www-data php artisan config:cache >/dev/null
  # Le rotte dei portali sono legate al dominio: senza rigenerare la cache
  # delle rotte il sottodominio continuerebbe a rispondere "non trovato"
  sudo -u www-data php artisan route:cache >/dev/null
  sudo -u www-data php artisan view:cache >/dev/null
  systemctl restart webgis-queue || true
}

if [[ "${DOMINIO}" = "--rimuovi" ]]; then
  log "Rimozione del dominio dei portali"
  scrivi_env PORTAL_BASE_HOST ""
  ricarica
  bash deploy/caddy-config.sh
  echo
  echo "Fatto. I portali tornano agli indirizzi di collaudo del tipo:"
  echo "  $(grep -E '^APP_URL=' .env | cut -d= -f2-)/comune/<nome-comune>"
  exit 0
fi

# Un dominio, non un indirizzo completo: si accettano solo lettere, cifre,
# trattini e punti, con almeno un punto
DOMINIO="$(echo "${DOMINIO}" | tr '[:upper:]' '[:lower:]')"
DOMINIO="${DOMINIO#*://}"
DOMINIO="${DOMINIO%%/*}"

if [[ ! "${DOMINIO}" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}$ ]]; then
  echo "\"${DOMINIO}\" non sembra un nome a dominio valido." >&2
  echo "Esempi validi: censimentoalberi.it, alberi.iltuodominio.it" >&2
  exit 1
fi

APP_URL="$(grep -E '^APP_URL=' .env | tail -1 | cut -d= -f2- | tr -d '"')"
HOST_GESTIONALE="${APP_URL#*://}"
HOST_GESTIONALE="${HOST_GESTIONALE%%/*}"

if [[ "${DOMINIO}" = "${HOST_GESTIONALE}" ]]; then
  echo "Questo è già l'indirizzo del gestionale: serve un dominio diverso," >&2
  echo "oppure un sotto-livello, per esempio alberi.${DOMINIO}." >&2
  exit 1
fi

IP_SERVER="$(ip route get 1.1.1.1 2>/dev/null | awk '{for (i=1; i<=NF; i++) if ($i == "src") print $(i+1)}' | head -1)"

log "Controllo del DNS per ${DOMINIO}"
echo "  Questo server è ${IP_SERVER:-di indirizzo non rilevabile}."

# Un nome che nessuno creerebbe a mano: se risponde, c'è il record jolly
IP_JOLLY="$(getent ahostsv4 "verifica-jolly.${DOMINIO}" 2>/dev/null | awk '{print $1; exit}')"
JOLLY="no"

if [[ -n "${IP_JOLLY}" && "${IP_JOLLY}" = "${IP_SERVER}" ]]; then
  JOLLY="si"
  echo "  Record jolly attivo: ogni nuovo Comune sarà immediato."
elif [[ -n "${IP_JOLLY}" ]]; then
  echo "  Attenzione: *.${DOMINIO} punta a ${IP_JOLLY}, non a questo server."
else
  echo "  Nessun record jolly: ogni Comune avrà bisogno del suo record A."
  echo "  Consigliato, nel pannello del gestore del dominio:"
  echo "    Nome: *    Tipo: A    Valore: ${IP_SERVER:-indirizzo IP di questo server}"
fi

log "Firewall: le porte del web devono essere aperte"
if command -v ufw >/dev/null && ufw status | grep -q "Status: active"; then
  ufw allow 80/tcp >/dev/null || true
  ufw allow 443/tcp >/dev/null || true
  echo "  ufw: porte 80 e 443 aperte."
else
  echo "  ufw non attivo: nessuna regola da aggiungere."
fi

log "Configurazione dell'applicazione"
scrivi_env PORTAL_BASE_HOST "${DOMINIO}"
ricarica
echo "  Dominio dei portali: ${DOMINIO}"

log "Configurazione del server web"
bash deploy/caddy-config.sh

log "Indirizzi dei Comuni"
sudo -u www-data php artisan portale:indirizzi --verifica --ip="${IP_SERVER}" || true

echo
if [[ "${JOLLY}" = "si" ]]; then
  echo "Fatto. Ogni Comune che accendi dal gestionale è subito in linea:"
  echo "il certificato viene emesso da solo alla prima visita."
else
  echo "Fatto. Per i Comuni con il DNS ancora da creare, aggiungi il record A"
  echo "indicato qui sopra: da quel momento l'indirizzo funziona senza altro."
fi
