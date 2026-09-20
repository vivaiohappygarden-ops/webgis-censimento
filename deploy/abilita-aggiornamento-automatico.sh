#!/usr/bin/env bash
#
# Accende (o spegne) l'aggiornamento automatico del server: ogni cinque
# minuti un timer di sistema controlla se sul ramo seguito c'e' una versione
# piu' nuova e, se c'e', lancia update.sh (deploy/aggiornamento-automatico.sh).
#
# Uso (da root, una volta sola):
#   bash /var/www/webgis/deploy/abilita-aggiornamento-automatico.sh
#   bash /var/www/webgis/deploy/abilita-aggiornamento-automatico.sh --stato
#   bash /var/www/webgis/deploy/abilita-aggiornamento-automatico.sh --disabilita
#
# Con WEBGIS_PROVA=1 (verifiche automatiche) scrive le unita' nella cartella
# WEBGIS_SYSTEMD_DIR e non tocca systemd.
#
set -euo pipefail

APP_DIR="${WEBGIS_APP_DIR:-/var/www/webgis}"
PROVA="${WEBGIS_PROVA:-}"
UNITA_DIR="${WEBGIS_SYSTEMD_DIR:-/etc/systemd/system}"
INTERVALLO="${WEBGIS_INTERVALLO:-5min}"
REGISTRO=/var/log/webgis-aggiornamento.log
ARGOMENTO="${1:-}"

if [[ -z "${PROVA}" && $EUID -ne 0 ]]; then
  echo "Va lanciato da root: sudo bash ${APP_DIR}/deploy/abilita-aggiornamento-automatico.sh" >&2
  exit 1
fi

case "${ARGOMENTO}" in
  --stato)
    systemctl status webgis-aggiornamento.timer --no-pager || true
    echo
    echo "Ultime righe del registro (${REGISTRO}):"
    tail -n 20 "${REGISTRO}" 2>/dev/null || echo "  (nessun aggiornamento automatico ancora eseguito)"
    exit 0
    ;;
  --disabilita)
    systemctl disable --now webgis-aggiornamento.timer
    echo "Aggiornamento automatico spento. Il server si aggiorna solo con: bash ${APP_DIR}/deploy/update.sh"
    exit 0
    ;;
  "")
    ;;
  *)
    echo "Uso: bash ${APP_DIR}/deploy/abilita-aggiornamento-automatico.sh [--stato|--disabilita]" >&2
    exit 1
    ;;
esac

if [[ ! -f "${APP_DIR}/deploy/aggiornamento-automatico.sh" ]]; then
  echo "Non trovo ${APP_DIR}/deploy/aggiornamento-automatico.sh: prima aggiornare l'applicazione (update.sh)." >&2
  exit 1
fi

mkdir -p "${UNITA_DIR}"

cat > "${UNITA_DIR}/webgis-aggiornamento.service" <<UNITA
[Unit]
Description=WebGIS: aggiornamento automatico all'ultima versione pubblicata
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
WorkingDirectory=${APP_DIR}
ExecStart=/usr/bin/env bash ${APP_DIR}/deploy/aggiornamento-automatico.sh
UNITA

cat > "${UNITA_DIR}/webgis-aggiornamento.timer" <<UNITA
[Unit]
Description=WebGIS: ogni ${INTERVALLO} controlla se c'e' una versione nuova

[Timer]
OnBootSec=2min
OnUnitActiveSec=${INTERVALLO}
RandomizedDelaySec=30
Persistent=true

[Install]
WantedBy=timers.target
UNITA

if [[ -z "${PROVA}" ]]; then
  systemctl daemon-reload
  systemctl enable --now webgis-aggiornamento.timer
  touch "${REGISTRO}"
fi

echo "Aggiornamento automatico acceso."
echo "  Ogni ${INTERVALLO} il server controlla il ramo $(cd "${APP_DIR}" && git rev-parse --abbrev-ref HEAD 2>/dev/null || echo 'corrente') e,"
echo "  se c'e' una versione nuova, si aggiorna da solo (breve manutenzione)."
echo "  Registro: ${REGISTRO}"
echo "  Stato:    bash ${APP_DIR}/deploy/abilita-aggiornamento-automatico.sh --stato"
echo "  Spegnere: bash ${APP_DIR}/deploy/abilita-aggiornamento-automatico.sh --disabilita"
