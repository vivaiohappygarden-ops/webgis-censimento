#!/usr/bin/env bash
#
# Accende il sito aziendale, quello che parla ai Comuni, sul dominio nudo:
#
#   https://censimentoalberature.it      (e https://www.censimentoalberature.it)
#
# Finche' non si lancia, il sito si guarda solo dall'indirizzo di collaudo
# https://<indirizzo del gestionale>/sito e il dominio nudo non risponde.
#
# Uso (da root, sul server):
#   bash /var/www/webgis/deploy/set-sito-domain.sh censimentoalberature.it
#   bash /var/www/webgis/deploy/set-sito-domain.sh --dati       (solo i dati dell'azienda)
#   bash /var/www/webgis/deploy/set-sito-domain.sh --rimuovi
#
# Nel pannello del gestore del dominio servono due record, verso l'indirizzo
# IP di questo server (lo stesso dei portali dei Comuni):
#
#   Nome: @      Tipo: A      Valore: indirizzo IP di questo server
#   Nome: www    Tipo: A      Valore: indirizzo IP di questo server
#
# Il comando chiede anche i dati dell'azienda (ragione sociale, partita IVA,
# recapiti...). Quello che si lascia vuoto NON compare sul sito: il programma
# non stampa mai un dato inventato. Si possono compilare anche dopo,
# rilanciando il comando con --dati.
#
set -euo pipefail

APP_DIR="${WEBGIS_APP_DIR:-/var/www/webgis}"
# Con WEBGIS_PROVA=1 (verifiche automatiche) si scrive solo il file .env di
# prova: niente root, niente cache, niente server web.
PROVA="${WEBGIS_PROVA:-}"
ARGOMENTO="${1:-}"

uso() {
  echo "Uso: bash ${APP_DIR}/deploy/set-sito-domain.sh <dominio-nudo>" >&2
  echo "     bash ${APP_DIR}/deploy/set-sito-domain.sh --dati" >&2
  echo "     bash ${APP_DIR}/deploy/set-sito-domain.sh --rimuovi" >&2
  exit 1
}

[[ -z "${ARGOMENTO}" ]] && uso
if [[ -z "${PROVA}" && $EUID -ne 0 ]]; then
  echo "Questo comando va eseguito da root (sudo)." >&2
  exit 1
fi
if [[ ! -d "${APP_DIR}" ]]; then
  echo "Applicazione non trovata in ${APP_DIR}." >&2
  exit 1
fi

cd "${APP_DIR}"

log() { echo; echo "==> $*"; }

valore_env() {
  grep -E "^${1}=" .env 2>/dev/null | tail -1 | cut -d= -f2- | tr -d '"'\''' || true
}

# Scrive (o riscrive) una riga del file .env. I valori con spazi vanno fra
# virgolette, altrimenti Laravel legge solo la prima parola.
scrivi_env() {
  local chiave="$1" valore="$2" riga virgoletta='"'
  valore="${valore//${virgoletta}/}"
  if [[ "${valore}" == *[[:space:]#]* ]]; then
    riga="${chiave}=${virgoletta}${valore}${virgoletta}"
  else
    riga="${chiave}=${valore}"
  fi
  if grep -q "^${chiave}=" .env; then
    # il separatore | non puo' comparire nei valori: si toglie prima
    sed -i "s|^${chiave}=.*|${riga//|/}|" .env
  else
    echo "${riga}" >> .env
  fi
}

ricarica() {
  [[ -n "${PROVA}" ]] && return 0
  sudo -u www-data php artisan config:cache >/dev/null
  # Le rotte del sito sono legate al dominio: senza rigenerare la cache
  # delle rotte il dominio nudo continuerebbe a rispondere "non trovato"
  sudo -u www-data php artisan route:cache >/dev/null
  sudo -u www-data php artisan view:cache >/dev/null
  systemctl restart webgis-queue || true
}

server_web() {
  [[ -n "${PROVA}" ]] && return 0
  bash deploy/caddy-config.sh
}

# ------------------------------------------------------------ i dati
# Ogni voce: chiave del file .env | domanda | esempio. Vuoto = non compare.
DATI=(
  "SITO_RAGIONE_SOCIALE|Ragione sociale (come in visura)|Verde Pubblico srl"
  "SITO_SEDE|Sede legale (via, CAP, citta')|Via Roma 1, 00013 Mentana (RM)"
  "SITO_PIVA|Partita IVA|01234567890"
  "SITO_REA|Numero REA (facoltativo)|RM-1234567"
  "SITO_TELEFONO|Telefono|06 1234567"
  "SITO_EMAIL|Email|info@esempio.it"
  "SITO_PEC|PEC (facoltativa)|azienda@pec.it"
  "SITO_ORARI|Orari in cui si risponde (facoltativi)|lunedi'-venerdi' 9-13 e 14-18"
  "SITO_INDIRIZZO|Indirizzo dell'ufficio, se diverso dalla sede (facoltativo)|"
  "SITO_TERRITORIO|Una riga sul territorio servito (facoltativa)|Operiamo nel Lazio e nelle regioni vicine"
  "SITO_ESPERIENZA|Da quanto operate nel settore, come volete leggerlo (facoltativo)|dal 1998"
  "SITO_PERIZIE_FIRMATARIO|Chi firma le perizie di stabilita' (facoltativo)|dott. agr. Mario Rossi"
  "SITO_PERIZIE_TITOLO|Con che titolo (facoltativo)|Dottore agronomo, iscritto all'Ordine di Roma n. 123"
)

chiedi_dati() {
  log "Dati dell'azienda"
  echo "  Quello che lasci vuoto non compare sul sito. Invio conferma il valore fra parentesi."
  echo "  Per svuotare una voce scrivi un trattino: -"
  local voce chiave domanda esempio attuale risposta
  for voce in "${DATI[@]}"; do
    IFS='|' read -r chiave domanda esempio <<< "${voce}"
    attuale="$(valore_env "${chiave}")"
    if [[ -n "${attuale}" ]]; then
      read -r -p "  ${domanda} [${attuale}]: " risposta
    elif [[ -n "${esempio}" ]]; then
      read -r -p "  ${domanda} (es. ${esempio}): " risposta
    else
      read -r -p "  ${domanda}: " risposta
    fi
    if [[ "${risposta}" = "-" ]]; then
      scrivi_env "${chiave}" ""
    elif [[ -n "${risposta}" ]]; then
      scrivi_env "${chiave}" "${risposta}"
    fi
  done
}

riepilogo_dati() {
  local voce chiave domanda esempio valore mancanti=()
  for voce in "${DATI[@]}"; do
    IFS='|' read -r chiave domanda esempio <<< "${voce}"
    valore="$(valore_env "${chiave}")"
    [[ -z "${valore}" && "${domanda}" != *facoltativ* ]] && mancanti+=("${domanda}")
  done
  if (( ${#mancanti[@]} )); then
    echo
    echo "Voci ancora vuote (sul sito non compaiono; la pagina Contatti lo dice):"
    printf '  - %s\n' "${mancanti[@]}"
    echo "Per compilarle: bash ${APP_DIR}/deploy/set-sito-domain.sh --dati"
  fi
}

# ------------------------------------------------------------ i comandi
if [[ "${ARGOMENTO}" = "--rimuovi" ]]; then
  log "Spegnimento del sito sul dominio nudo"
  scrivi_env SITO_BASE_HOST ""
  ricarica
  server_web
  echo
  echo "Fatto. Il sito resta visibile solo dall'indirizzo di collaudo:"
  echo "  $(valore_env APP_URL)/sito"
  echo "Attenzione: se i portali dei Comuni stanno sullo stesso dominio, il sito"
  echo "torna a rispondere sul dominio nudo con il prossimo caddy-config.sh:"
  echo "e' il ripiego scritto in config/sito.php."
  exit 0
fi

if [[ "${ARGOMENTO}" = "--dati" ]]; then
  chiedi_dati
  ricarica
  riepilogo_dati
  echo
  echo "Fatto: il sito mostra i dati nuovi."
  exit 0
fi

# Un dominio nudo: lettere, cifre, trattini e punti, con almeno un punto
DOMINIO="$(echo "${ARGOMENTO}" | tr '[:upper:]' '[:lower:]')"
DOMINIO="${DOMINIO#*://}"
DOMINIO="${DOMINIO%%/*}"
DOMINIO="${DOMINIO#www.}"

if [[ ! "${DOMINIO}" =~ ^[a-z0-9]([a-z0-9.-]*[a-z0-9])?\.[a-z]{2,}$ ]]; then
  echo "\"${DOMINIO}\" non sembra un nome a dominio valido." >&2
  echo "Esempi validi: censimentoalberature.it, verdepubblico.it" >&2
  exit 1
fi

APP_URL="$(valore_env APP_URL)"
HOST_GESTIONALE="${APP_URL#*://}"
HOST_GESTIONALE="${HOST_GESTIONALE%%/*}"

if [[ "${DOMINIO}" = "${HOST_GESTIONALE}" ]]; then
  echo "Questo e' gia' l'indirizzo del gestionale: il sito vuole il dominio nudo," >&2
  echo "per esempio ${HOST_GESTIONALE#*.}." >&2
  exit 1
fi

IP_SERVER="${WEBGIS_IP_SERVER:-$(ip route get 1.1.1.1 2>/dev/null | awk '{for (i=1; i<=NF; i++) if ($i == "src") print $(i+1)}' | head -1)}"

log "Controllo del DNS per ${DOMINIO}"
echo "  Questo server e' ${IP_SERVER:-di indirizzo non rilevabile}."
DA_CREARE=()
for NOME in "${DOMINIO}" "www.${DOMINIO}"; do
  # getent esce con 2 quando il nome non esiste: con pipefail farebbe morire
  # lo script proprio quando deve dire quale record manca
  IP_NOME="$(getent ahostsv4 "${NOME}" 2>/dev/null | awk '{print $1; exit}' || true)"
  if [[ -n "${IP_NOME}" && "${IP_NOME}" = "${IP_SERVER}" ]]; then
    echo "  ${NOME}: a posto, punta a questo server."
  elif [[ -n "${IP_NOME}" ]]; then
    echo "  Attenzione: ${NOME} punta a ${IP_NOME}, non a questo server."
    DA_CREARE+=("${NOME}")
  else
    echo "  ${NOME}: nessun record DNS."
    DA_CREARE+=("${NOME}")
  fi
done
if (( ${#DA_CREARE[@]} )); then
  IP_TESTO="${IP_SERVER}"
  [[ -z "${IP_TESTO}" ]] && IP_TESTO="l'indirizzo IP di questo server"
  echo
  echo "  Nel pannello del gestore del dominio, verso ${IP_TESTO}:"
  echo "    Nome: @      Tipo: A    Valore: ${IP_SERVER:-indirizzo IP di questo server}"
  echo "    Nome: www    Tipo: A    Valore: ${IP_SERVER:-indirizzo IP di questo server}"
  echo "  Il certificato viene emesso da solo appena i record sono attivi (di solito"
  echo "  entro un'ora): non serve rilanciare niente."
fi

log "Firewall: le porte del web devono essere aperte"
if [[ -z "${PROVA}" ]] && command -v ufw >/dev/null && ufw status | grep -q "Status: active"; then
  ufw allow 80/tcp >/dev/null || true
  ufw allow 443/tcp >/dev/null || true
  echo "  ufw: porte 80 e 443 aperte."
else
  echo "  niente da fare."
fi

log "Configurazione dell'applicazione"
scrivi_env SITO_BASE_HOST "${DOMINIO}"
echo "  Dominio del sito: ${DOMINIO} (e www.${DOMINIO})"

if [[ -t 0 && -z "${PROVA}" ]]; then
  chiedi_dati
else
  echo "  (nessun terminale: i dati dell'azienda si compilano con --dati)"
fi
ricarica

log "Configurazione del server web"
server_web

riepilogo_dati
echo
echo "Fatto. Il sito risponde su https://${DOMINIO} e https://www.${DOMINIO}"
echo "appena il DNS punta a questo server; intanto resta anche su ${APP_URL}/sito."
