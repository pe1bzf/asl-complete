#!/bin/bash
# =============================================================================
# ASL Complete — Installatiescript / Installation Script
# Installeert / Installs: Activity Logger + Real-time Dashboard voor/for
#                         AllStarLink hub nodes
# Gebruik / Usage: sudo bash install.sh
# =============================================================================

set -e

W=72
H=22
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# ── Whiptail installeren indien nodig / Install whiptail if needed ─────────
for pkg in whiptail python3 curl; do
    if ! command -v "$pkg" &>/dev/null; then
        apt-get install -y "$pkg" 2>/dev/null || true
    fi
done

# ── Taal / Language selection ──────────────────────────────────────────────
LANG_SEL=$(whiptail --title "Language / Taal" --menu \
    "Choose your language / Kies uw taal:" \
    10 52 2 \
    "NL" "Nederlands" \
    "EN" "English" \
    3>&1 1>&2 2>&3) || LANG_SEL="NL"

# ── Tekst definities / String definitions ─────────────────────────────────
if [ "$LANG_SEL" = "EN" ]; then
    TITLE="ASL Complete Installation"
    L_ROOT_ERR="Run as root: sudo bash install.sh"
    L_WELCOME="Welcome to the ASL Complete installation.

This script installs:
  • Activity Logger  — Python daemon via Asterisk AMI
  • Web Dashboard    — Real-time PHP dashboard (vis.js + SSE)

Optional:
  • IAX2 keepalive   — prevents disconnect on inactivity
  • TX timeout       — configurable (default 3 min)

Make sure AllStarLink 3 (ASL3) is running before you start."
    L_NODE_PROMPT="Hub node number:"
    L_NODE_ERR="Node number is required."
    L_CALL_PROMPT="Callsign:"
    L_CALL_ERR="Callsign is required."
    L_AMI_HOST_PROMPT="AMI host (usually 127.0.0.1):"
    L_AMI_PORT_PROMPT="AMI port:"
    L_AMI_USER_PROMPT="AMI username:"
    L_AMI_PASS_PROMPT="AMI password:"
    L_AMI_PASS_ERR="AMI password is required."
    L_WEB_ROOT_PROMPT="Web path for dashboard:"
    L_TOTIME_PROMPT="Max talk time in minutes:"
    L_IAX_QUESTION="Set up IAX2 keepalive?

Prevents connections from dropping on inactivity.
Sends a keepalive packet every 25 seconds."
    L_SUMMARY_CONFIRM="Install with these settings?"
    L_SUMMARY_NODE="Node number "
    L_SUMMARY_CALL="Callsign    "
    L_SUMMARY_AMI="AMI         "
    L_SUMMARY_DASH="Dashboard   "
    L_SUMMARY_TIME="Talk time   "
    L_SUMMARY_IAX="IAX keepalive"
    L_YES="yes"
    L_NO="no"
    L_GAUGE="Installing..."
    L_DONE_TITLE="Installation complete!"
    L_DONE_STATUS="Logger status"
    L_DONE_LINES="Log lines    "
    L_DONE_DASH="Dashboard    "
    L_DONE_URL="Dashboard available at:"
    L_DONE_LOGS="View logs:"
    L_DONE_RESTART="Restart logger:"
    L_UNKNOWN="unknown"
else
    TITLE="ASL Complete Installatie"
    L_ROOT_ERR="Voer uit als root: sudo bash install.sh"
    L_WELCOME="Welkom bij de ASL Complete installatie.

Dit script installeert:
  • Activity Logger  — Python daemon via Asterisk AMI
  • Web Dashboard    — Real-time PHP dashboard (vis.js + SSE)

Optioneel:
  • IAX2 keepalive   — voorkomt disconnect bij inactiviteit
  • TX timeout       — aanpasbaar (standaard 3 min)

Zorg dat AllStarLink 3 (ASL3) actief is voor je begint."
    L_NODE_PROMPT="Hub node nummer:"
    L_NODE_ERR="Node nummer is verplicht."
    L_CALL_PROMPT="Roepnaam (callsign):"
    L_CALL_ERR="Roepnaam is verplicht."
    L_AMI_HOST_PROMPT="AMI host (meestal 127.0.0.1):"
    L_AMI_PORT_PROMPT="AMI poort:"
    L_AMI_USER_PROMPT="AMI gebruikersnaam:"
    L_AMI_PASS_PROMPT="AMI wachtwoord:"
    L_AMI_PASS_ERR="AMI wachtwoord is verplicht."
    L_WEB_ROOT_PROMPT="Web pad voor dashboard:"
    L_TOTIME_PROMPT="Max spreektijd in minuten:"
    L_IAX_QUESTION="IAX2 keepalive instellen?

Voorkomt dat verbindingen wegvallen bij inactiviteit.
Stuurt elke 25 seconden een keepalive pakket."
    L_SUMMARY_CONFIRM="Installeren met deze instellingen?"
    L_SUMMARY_NODE="Node nummer  "
    L_SUMMARY_CALL="Callsign     "
    L_SUMMARY_AMI="AMI          "
    L_SUMMARY_DASH="Dashboard    "
    L_SUMMARY_TIME="Spreektijd   "
    L_SUMMARY_IAX="IAX keepalive"
    L_YES="ja"
    L_NO="nee"
    L_GAUGE="Installeren..."
    L_DONE_TITLE="Installatie voltooid!"
    L_DONE_STATUS="Logger status"
    L_DONE_LINES="Log regels   "
    L_DONE_DASH="Dashboard    "
    L_DONE_URL="Dashboard bereikbaar op:"
    L_DONE_LOGS="Logs bekijken:"
    L_DONE_RESTART="Logger herstarten:"
    L_UNKNOWN="onbekend"
fi

# ── Root check ────────────────────────────────────────────────────────────────
if [ "$EUID" -ne 0 ]; then
    echo "$L_ROOT_ERR"
    exit 1
fi

# ── Welkom ────────────────────────────────────────────────────────────────────
whiptail --title "$TITLE" --msgbox "$L_WELCOME" $H $W

# ── Configuratie verzamelen ───────────────────────────────────────────────────
collect_config() {
    NODE=$(whiptail --title "$TITLE" \
        --inputbox "$L_NODE_PROMPT" 8 $W "" \
        3>&1 1>&2 2>&3) || exit 1
    [ -z "$NODE" ] && { whiptail --msgbox "$L_NODE_ERR" 7 $W; collect_config; return; }

    CALLSIGN=$(whiptail --title "$TITLE" \
        --inputbox "$L_CALL_PROMPT" 8 $W "" \
        3>&1 1>&2 2>&3) || exit 1
    [ -z "$CALLSIGN" ] && { whiptail --msgbox "$L_CALL_ERR" 7 $W; collect_config; return; }

    AMI_HOST=$(whiptail --title "$TITLE" \
        --inputbox "$L_AMI_HOST_PROMPT" 8 $W "127.0.0.1" \
        3>&1 1>&2 2>&3) || exit 1

    AMI_PORT=$(whiptail --title "$TITLE" \
        --inputbox "$L_AMI_PORT_PROMPT" 8 $W "5038" \
        3>&1 1>&2 2>&3) || exit 1

    AMI_USER=$(whiptail --title "$TITLE" \
        --inputbox "$L_AMI_USER_PROMPT" 8 $W "admin" \
        3>&1 1>&2 2>&3) || exit 1

    AMI_SECRET=$(whiptail --title "$TITLE" \
        --passwordbox "$L_AMI_PASS_PROMPT" 8 $W \
        3>&1 1>&2 2>&3) || exit 1
    [ -z "$AMI_SECRET" ] && { whiptail --msgbox "$L_AMI_PASS_ERR" 7 $W; collect_config; return; }

    WEB_ROOT=$(whiptail --title "$TITLE" \
        --inputbox "$L_WEB_ROOT_PROMPT" 8 $W "/var/www/html/asl" \
        3>&1 1>&2 2>&3) || exit 1

    TOTIME_MIN=$(whiptail --title "$TITLE" \
        --inputbox "$L_TOTIME_PROMPT" 8 $W "6" \
        3>&1 1>&2 2>&3) || exit 1
    TOTIME_MS=$(( TOTIME_MIN * 60000 ))

    if whiptail --title "$TITLE" --yesno "$L_IAX_QUESTION" 10 $W; then
        DO_IAX=1
    else
        DO_IAX=0
    fi

    whiptail --title "$TITLE" --yesno \
"${L_SUMMARY_CONFIRM}

  ${L_SUMMARY_NODE}: $NODE
  ${L_SUMMARY_CALL}: $CALLSIGN
  ${L_SUMMARY_AMI}: $AMI_USER@$AMI_HOST:$AMI_PORT
  ${L_SUMMARY_DASH}: $WEB_ROOT
  ${L_SUMMARY_TIME}: $TOTIME_MIN min
  ${L_SUMMARY_IAX}: $([ $DO_IAX -eq 1 ] && echo "$L_YES" || echo "$L_NO")" \
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
    } | whiptail --title "$TITLE" --gauge "$L_GAUGE" 7 $W 0
}

# ── 1. Activity Logger installeren ───────────────────────────────────────────
install_logger() {
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
        -e "s|'CHANGE_ME_NODE'|'${NODE}'|g" \
        -e "s|'N0CALL'|'${CALLSIGN}'|g" \
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
LOGGER_STATUS=$(systemctl is-active asl-activity-logger 2>/dev/null || echo "$L_UNKNOWN")
LOG_LINES=$(wc -l < /var/log/asterisk/rpt-activity.log 2>/dev/null || echo "0")
IP=$(hostname -I | awk '{print $1}')
WEB_PATH="${WEB_ROOT#/var/www/html}"

whiptail --title "$TITLE" --msgbox \
"${L_DONE_TITLE}

  ${L_DONE_STATUS}: $LOGGER_STATUS
  ${L_DONE_LINES}: $LOG_LINES
  ${L_DONE_DASH}: $WEB_ROOT/index.php

${L_DONE_URL}
  http://${IP}${WEB_PATH}/

${L_DONE_LOGS}
  tail -f /var/log/asterisk/rpt-activity.log

${L_DONE_RESTART}
  systemctl restart asl-activity-logger" $H $W

exit 0
