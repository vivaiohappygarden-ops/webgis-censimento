#!/usr/bin/env bash
#
# Cambia l'indirizzo con cui si raggiunge l'applicazione, senza reinstallare.
#
# Uso (da root, sul server):
#   bash /var/www/webgis/deploy/set-domain.sh gestionale.miodominio.it
#   bash /var/www/webgis/deploy/set-domain.sh 80-211-79-223.sslip.io
#   bash /var/www/webgis/deploy/set-domain.sh 80.211.79.223        (torna a http)
#
# Con un nome di dominio il certificato HTTPS viene richiesto e rinnovato da
# Caddy in automatico (Let's Encrypt): serve solo che il nome punti a questo
# server e che le porte 80 e 443 siano aperte. Con un indirizzo IP l'HTTPS non
# è possibile e si torna a http.
#
set -euo pipefail

APP_DIR=/var/www/webgis
DOMINIO="${1:-}"

if [[ -z "${DOMINIO}" ]]; then
  echo "Uso: bash ${APP_DIR}/deploy/set-domain.sh <nome-dominio-o-indirizzo-IP>" >&2
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

if [[ "${DOMINIO}" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]]; then
  SCHEMA="http"
  COOKIE_SICURO="false"
  echo "Indirizzo IP: il sito tornerà in http (niente lucchetto, niente posizione"
  echo "sulla mappa, app di campo non installabile sul telefono)."
else
  SCHEMA="https"
  COOKIE_SICURO="true"

  log "Controllo del nome ${DOMINIO}"
  IP_SERVER="$(ip route get 1.1.1.1 2>/dev/null | awk '{for (i=1; i<=NF; i++) if ($i == "src") print $(i+1)}' | head -1)"
  IP_DOMINIO="$(getent ahostsv4 "${DOMINIO}" 2>/dev/null | awk '{print $1; exit}')"

  if [[ -z "${IP_DOMINIO}" ]]; then
    echo "  Il nome ${DOMINIO} non risulta registrato nel DNS." >&2
    echo "  Crea il record A che punta a ${IP_SERVER:-questo server} e riprova tra qualche minuto." >&2
    exit 1
  fi
  if [[ -n "${IP_SERVER}" && "${IP_DOMINIO}" != "${IP_SERVER}" ]]; then
    echo "  Il nome ${DOMINIO} punta a ${IP_DOMINIO}, ma questo server è ${IP_SERVER}." >&2
    echo "  Correggi il record A del dominio e riprova: senza, il certificato non può essere emesso." >&2
    exit 1
  fi
  echo "  ${DOMINIO} punta correttamente a questo server."
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
sed -i "s|^APP_URL=.*|APP_URL=${SCHEMA}://${DOMINIO}|" .env
scrivi_env() {
  if grep -q "^$1=" .env; then
    sed -i "s|^$1=.*|$1=$2|" .env
  else
    printf '%s=%s\n' "$1" "$2" >> .env
  fi
}
scrivi_env SESSION_SECURE_COOKIE "${COOKIE_SICURO}"
# Nomi da cui il programma riconosce chi ha già fatto l'accesso: se l'indirizzo
# usato dal browser non è in elenco, le pagine si aprono ma i dati rispondono
# "non autenticato" e restano vuoti. Si scrive esplicitamente, invece di
# affidarsi al valore dedotto da APP_URL, perché l'errore sarebbe silenzioso.
scrivi_env SANCTUM_STATEFUL_DOMAINS "${DOMINIO},localhost,127.0.0.1"
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
systemctl restart webgis-queue || true

# La configurazione del server web si genera dal file .env appena aggiornato,
# cosi' resta sempre in accordo con l'indirizzo del gestionale e con il
# dominio dei portali dei Comuni
log "Configurazione del server web"
bash deploy/caddy-config.sh

if [[ "${SCHEMA}" = "https" ]]; then
  log "Attesa del certificato (fino a due minuti)"
  # Alla prima richiesta Caddy deve ottenere il certificato da Let's Encrypt:
  # nel frattempo la connessione può fallire, quindi si riprova
  ESITO=""
  for _ in $(seq 1 24); do
    ESITO="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 10 "https://${DOMINIO}/login" 2>/dev/null || true)"
    [[ "${ESITO}" =~ ^(200|302)$ ]] && break
    sleep 5
  done
  if [[ "${ESITO}" =~ ^(200|302)$ ]]; then
    echo "  Certificato attivo: https://${DOMINIO} risponde."
  else
    echo "  Il sito non risponde ancora in https (ultimo esito: ${ESITO:-nessuna risposta})." >&2
    echo "  Controlla con: journalctl -u caddy -n 40 --no-pager" >&2
    echo "  Se il DNS è appena stato creato, riprova tra qualche minuto." >&2
    exit 1
  fi
fi

log "Fatto."
echo
echo "Indirizzo dell'applicazione: ${SCHEMA}://${DOMINIO}"
if [[ "${SCHEMA}" = "https" ]]; then
  echo
  echo "Con il lucchetto attivo funzionano anche:"
  echo "  - la posizione del dispositivo sulla mappa e nell'app di campo;"
  echo "  - l'installazione dell'app di campo sul telefono (Aggiungi a schermata Home);"
  echo "  - le pagine pubbliche degli alberi con il QR, senza avvisi del browser."
  echo
  echo "Il certificato si rinnova da solo. Non serve fare altro."
fi
