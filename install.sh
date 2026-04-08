#!/bin/bash
# =============================================================================
# ASL Complete — Installatiescript
# Installeert: Activity Logger + Real-time Dashboard voor AllStarLink hub nodes
# Gebruik: sudo bash install.sh
# =============================================================================

set -e

TITLE="ASL Complete Installatie"
W=72
H=22
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# ── Root check ────────────────────────────────────────────────────────────────
if [ "$EUID" -ne 0 ]; then
    echo "Voer uit als root: sudo bash install.sh"
    exit 1
fi

# ── Dependencies ──────────────────────────────────────────────────────────────
for pkg in whiptail python3 curl; do
    if ! command -v "$pkg" &>/dev/null; then
        apt-get install -y "$pkg" 2>/dev/null || true
    fi
done

# ── Welkom ────────────────────────────────────────────────────────────────────
whiptail --title "$TITLE" --msgbox \
"Welkom bij de ASL Complete installatie.

Dit script installeert:
  • Activity Logger  — Python daemon via Asterisk AMI
  • Web Dashboard    — Real-time PHP dashboard (vis.js + SSE)

Optioneel:
  • IAX2 keepalive   — voorkomt disconnect bij inactiviteit
  • TX timeout       — aanpasbaar (standaard 3 min)

Zorg dat AllStarLink 3 (ASL3) actief is voor je begint." $H $W

# ── Configuratie verzamelen ───────────────────────────────────────────────────
collect_config() {
    NODE=$(whiptail --title "$TITLE" \
        --inputbox "Hub node nummer:" 8 $W "" \
        3>&1 1>&2 2>&3) || exit 1
    [ -z "$NODE" ] && { whiptail --msgbox "Node nummer is verplicht." 7 $W; collect_config; return; }

    CALLSIGN=$(whiptail --title "$TITLE" \
        --inputbox "Roepnaam (callsign):" 8 $W "" \
        3>&1 1>&2 2>&3) || exit 1
    [ -z "$CALLSIGN" ] && { whiptail --msgbox "Roepnaam is verplicht." 7 $W; collect_config; return; }

    AMI_HOST=$(whiptail --title "$TITLE" \
        --inputbox "AMI host (meestal 127.0.0.1):" 8 $W "127.0.0.1" \
        3>&1 1>&2 2>&3) || exit 1

    AMI_PORT=$(whiptail --title "$TITLE" \
        --inputbox "AMI poort:" 8 $W "5038" \
        3>&1 1>&2 2>&3) || exit 1

    AMI_USER=$(whiptail --title "$TITLE" \
        --inputbox "AMI gebruikersnaam:" 8 $W "admin" \
        3>&1 1>&2 2>&3) || exit 1

    AMI_SECRET=$(whiptail --title "$TITLE" \
        --passwordbox "AMI wachtwoord:" 8 $W \
        3>&1 1>&2 2>&3) || exit 1
    [ -z "$AMI_SECRET" ] && { whiptail --msgbox "AMI wachtwoord is verplicht." 7 $W; collect_config; return; }

    WEB_ROOT=$(whiptail --title "$TITLE" \
        --inputbox "Web pad voor dashboard:" 8 $W "/var/www/html/asl" \
        3>&1 1>&2 2>&3) || exit 1

    TOTIME_MIN=$(whiptail --title "$TITLE" \
        --inputbox "Max spreektijd in minuten:" 8 $W "6" \
        3>&1 1>&2 2>&3) || exit 1
    TOTIME_MS=$(( TOTIME_MIN * 60000 ))

    if whiptail --title "$TITLE" --yesno \
        "IAX2 keepalive instellen?\n\nVoorkomt dat verbindingen wegvallen bij inactiviteit.\nStuurt elke 25 seconden een keepalive pakket." 10 $W; then
        DO_IAX=1
    else
        DO_IAX=0
    fi

    whiptail --title "$TITLE" --yesno \
"Installeren met deze instellingen?

  Node nummer  : $NODE
  Callsign     : $CALLSIGN
  AMI          : $AMI_USER@$AMI_HOST:$AMI_PORT
  Dashboard    : $WEB_ROOT
  Spreektijd   : $TOTIME_MIN minuten
  IAX keepalive: $([ $DO_IAX -eq 1 ] && echo 'ja' || echo 'nee')" \
    $H $W || exit 1
}

collect_config

# ── Helper: voortgangsbalk ────────────────────────────────────────────────────
run_steps() {
    {
        echo 10; install_logger
        echo 35; install_service
        echo 60; install_dashboard
        echo 75; [ "$DO_IAX" -eq 1 ] && fix_iax || true
        echo 88; fix_rpt
        echo 100
    } | whiptail --title "$TITLE" --gauge "Installeren..." 7 $W 0
}

# ── 1. Activity Logger installeren ───────────────────────────────────────────
install_logger() {
    # Kopieer het echte logger-bestand en vervang configuratie-placeholders
    sed \
        -e "s|HOST, PORT = '127\.0\.0\.1', 5038|HOST, PORT = '${AMI_HOST}', ${AMI_PORT}|" \
        -e "s|USER, PASSWD = 'admin', 'CHANGE_ME_SECRET'|USER, PASSWD = '${AMI_USER}', '${AMI_SECRET}'|" \
        -e "s|MY_NODE     = 'CHANGE_ME_NODE'|MY_NODE     = '${NODE}'|" \
        "$SCRIPT_DIR/logger/asl-activity-logger" \
        > /usr/local/bin/asl-activity-logger
    chmod +x /usr/local/bin/asl-activity-logger
}

# ── 2. Systemd service installeren ───────────────────────────────────────────
install_service() {
    sed "s|AllStarLink Activity Logger|AllStarLink Activity Logger (node ${NODE})|" \
        "$SCRIPT_DIR/logger/asl-activity-logger.service" \
        > /etc/systemd/system/asl-activity-logger.service
    systemctl daemon-reload
    systemctl enable asl-activity-logger
    systemctl restart asl-activity-logger
}

# ── 3. Dashboard installeren ──────────────────────────────────────────────────
install_dashboard() {
    mkdir -p "$WEB_ROOT"
    sed \
        -e "s|'449581'|'${NODE}'|g" \
        -e "s|'PE1BZF'|'${CALLSIGN}'|g" \
        -e "s|Node 449581 · PE1BZF|Node ${NODE} · ${CALLSIGN}|g" \
        -e "s|nxdn-almere\.nl|$(hostname -f 2>/dev/null || hostname)|g" \
        "$SCRIPT_DIR/dashboard/asl-activity.php" > "$WEB_ROOT/index.php"

    if id www-data &>/dev/null; then
        chown -R www-data:www-data "$WEB_ROOT"
    fi
}

# ── 4. iax.conf aanpassen ────────────────────────────────────────────────────
fix_iax() {
    local CONF="/etc/asterisk/iax.conf"
    [ -f "$CONF" ] || return

    cp "$CONF" "${CONF}.bak-$(date +%Y%m%d%H%M%S)"

    for section in iaxrpt iaxclient; do
        if grep -q "^\[$section\]" "$CONF"; then
            if ! awk "/^\[$section\]/{found=1} found && /^qualify/{exit 0} END{exit !found}" "$CONF"; then
                sed -i "/^\[$section\]/a qualify = yes\nqualifyfreq = 25" "$CONF"
            fi
        fi
    done

    asterisk -rx 'iax2 reload' 2>/dev/null || true
}

# ── 5. rpt.conf aanpassen ────────────────────────────────────────────────────
fix_rpt() {
    local CONF="/etc/asterisk/rpt.conf"
    [ -f "$CONF" ] || return

    cp "$CONF" "${CONF}.bak-$(date +%Y%m%d%H%M%S)"
    if grep -q "^totime" "$CONF"; then
        sed -i "s/^totime\s*=.*/totime = ${TOTIME_MS}/" "$CONF"
    else
        sed -i "/^\[${NODE}\]/a totime = ${TOTIME_MS}" "$CONF"
    fi
    asterisk -rx 'module reload app_rpt.so' 2>/dev/null || true
}

# ── Installatie uitvoeren ─────────────────────────────────────────────────────
run_steps

# ── Eindresultaat ─────────────────────────────────────────────────────────────
sleep 2
LOGGER_STATUS=$(systemctl is-active asl-activity-logger 2>/dev/null || echo "onbekend")
LOG_LINES=$(wc -l < /var/log/asterisk/rpt-activity.log 2>/dev/null || echo "0")
IP=$(hostname -I | awk '{print $1}')
WEB_PATH="${WEB_ROOT#/var/www/html}"

whiptail --title "$TITLE" --msgbox \
"Installatie voltooid!

  Logger status : $LOGGER_STATUS
  Log regels    : $LOG_LINES
  Dashboard     : $WEB_ROOT/index.php

Dashboard bereikbaar op:
  http://${IP}${WEB_PATH}/

Logs bekijken:
  tail -f /var/log/asterisk/rpt-activity.log

Logger herstarten:
  systemctl restart asl-activity-logger" $H $W

exit 0
