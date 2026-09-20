#!/usr/bin/env bash
#
# Aggiornamento automatico: se su GitHub c'e' una versione piu' nuova del ramo
# che il server segue, lancia update.sh; altrimenti non fa niente e non
# scrive niente. Lo chiama ogni cinque minuti il timer webgis-aggiornamento,
# installato da deploy/abilita-aggiornamento-automatico.sh.
#
# Cosi' pubblicare una modifica vuol dire solo spingerla sul ramo: entro
# cinque minuti il server se ne accorge e si aggiorna da solo (con la breve
# manutenzione di update.sh). Il registro sta in /var/log/webgis-aggiornamento.log.
#
# Prudenze:
#   - solo avanzamenti in linea retta: se la storia locale e quella remota
#     divergono (qualcuno ha toccato il codice sul server) non si tocca niente
#     e si scrive nel registro che serve una mano;
#   - un lucchetto impedisce due aggiornamenti insieme;
#   - le variabili WEBGIS_* servono alle verifiche automatiche (cartelle e
#     comando sostituibili), in produzione non vanno impostate.
#
set -euo pipefail

APP_DIR="${WEBGIS_APP_DIR:-/var/www/webgis}"
REGISTRO="${WEBGIS_REGISTRO:-/var/log/webgis-aggiornamento.log}"
LUCCHETTO="${WEBGIS_LUCCHETTO:-/run/lock/webgis-aggiornamento.lock}"
AGGIORNA="${WEBGIS_COMANDO_AGGIORNAMENTO:-bash ${APP_DIR}/deploy/update.sh}"

cd "${APP_DIR}"
git config --global --add safe.directory "${APP_DIR}" 2>/dev/null || true

exec 9>"${LUCCHETTO}"
if ! flock -n 9; then
  echo "Un aggiornamento e' gia' in corso: questo giro salta."
  exit 0
fi

RAMO="$(git rev-parse --abbrev-ref HEAD)"
git fetch -q origin "${RAMO}"
LOCALE="$(git rev-parse HEAD)"
REMOTO="$(git rev-parse "origin/${RAMO}")"

if [[ "${LOCALE}" == "${REMOTO}" ]]; then
  exit 0
fi

adesso() { date '+%Y-%m-%d %H:%M:%S'; }

if ! git merge-base --is-ancestor "${LOCALE}" "${REMOTO}"; then
  echo "==== $(adesso) storia divergente sul ramo ${RAMO} (locale ${LOCALE:0:7}, remoto ${REMOTO:0:7}): l'aggiornamento automatico non tocca niente. Serve una mano: bash ${APP_DIR}/deploy/update.sh" | tee -a "${REGISTRO}" >&2
  exit 1
fi

{
  echo "==== $(adesso) nuova versione sul ramo ${RAMO}: ${LOCALE:0:7} -> ${REMOTO:0:7}"
  git log --format='     %h %s' "${LOCALE}..${REMOTO}"
  if ${AGGIORNA}; then
    echo "==== $(adesso) aggiornamento riuscito: ora alla versione $(git rev-parse --short HEAD)"
  else
    codice=$?
    echo "==== $(adesso) AGGIORNAMENTO FALLITO (codice ${codice}): controllare le righe qui sopra e rilanciare bash ${APP_DIR}/deploy/update.sh"
    exit "${codice}"
  fi
} >> "${REGISTRO}" 2>&1
