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

Zorg dat Asterisk actief is voor je begint." $H $W

# ── Configuratie verzamelen ───────────────────────────────────────────────────
collect_config() {
    NODE=$(whiptail --title "$TITLE" \
        --inputbox "Hub node nummer:" 8 $W "449581" \
        3>&1 1>&2 2>&3) || exit 1

    CALLSIGN=$(whiptail --title "$TITLE" \
        --inputbox "Roepnaam (callsign):" 8 $W "PE1BZF" \
        3>&1 1>&2 2>&3) || exit 1

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

    WEB_ROOT=$(whiptail --title "$TITLE" \
        --inputbox "Web pad voor dashboard:" 8 $W "/var/www/html/asl" \
        3>&1 1>&2 2>&3) || exit 1

    # TX timeout
    TOTIME_MIN=$(whiptail --title "$TITLE" \
        --inputbox "Max spreektijd in minuten:" 8 $W "6" \
        3>&1 1>&2 2>&3) || exit 1
    TOTIME_MS=$(( TOTIME_MIN * 60000 ))

    # IAX2 keepalive
    if whiptail --title "$TITLE" --yesno \
        "IAX2 keepalive instellen?\n\nVoorkomt dat app-verbindingen wegvallen bij inactiviteit.\nStuurt elke 25 seconden een keepalive pakket." 10 $W; then
        DO_IAX=1
    else
        DO_IAX=0
    fi

    # Samenvatting
    whiptail --title "$TITLE" --yesno \
"Installeren met deze instellingen?

  Node nummer : $NODE
  Callsign    : $CALLSIGN
  AMI         : $AMI_USER@$AMI_HOST:$AMI_PORT
  Dashboard   : $WEB_ROOT
  Spreektijd  : $TOTIME_MIN minuten
  IAX keepalive: $([ $DO_IAX -eq 1 ] && echo 'ja' || echo 'nee')" \
    $H $W || exit 1
}

collect_config

# ── Helper: voortgangsbalk ────────────────────────────────────────────────────
progress() {
    echo "$1" | whiptail --title "$TITLE" --gauge "$2" 7 $W 0
}

# ── 1. Activity Logger installeren ───────────────────────────────────────────
install_logger() {
    cat > /usr/local/bin/asl-activity-logger << PYEOF
#!/usr/bin/env python3
# ASL Activity Logger — gegenereerd door install.sh
# Node: ${NODE} (${CALLSIGN})
import socket, time, threading, urllib.request, json, os

HOST, PORT   = '${AMI_HOST}', ${AMI_PORT}
USER, PASSWD = '${AMI_USER}', '${AMI_SECRET}'
LOGFILE      = '/var/log/asterisk/rpt-activity.log'
TOPOFILE     = '/var/log/asterisk/asl-topology.json'
MY_NODE      = '${NODE}'
DEBOUNCE     = 2.0
DB_REFRESH   = 15 * 60

# --- Node database ---
node_db = {}
db_lock = threading.Lock()

def fetch_node_db():
    try:
        req = urllib.request.Request(
            'https://allmondb.allstarlink.org/',
            headers={'User-Agent': 'Allmon3/1.7.0'}
        )
        with urllib.request.urlopen(req, timeout=30) as r:
            data = r.read().decode(errors='ignore')
        db = {}
        for line in data.splitlines():
            parts = line.split('|')
            if len(parts) >= 2:
                db[parts[0].strip()] = parts[1].strip()
        with db_lock:
            node_db.clear()
            node_db.update(db)
    except Exception as e:
        log(f'[WARN] node-database refresh mislukt: {e}')

def db_refresher():
    while True:
        fetch_node_db()
        time.sleep(DB_REFRESH)

def callsign(node):
    with db_lock:
        return node_db.get(str(node), '?')

def node_label(node):
    cs = callsign(node)
    return f'{node} ({cs})'

# --- Logging ---
def log(msg):
    line = time.strftime('%Y-%m-%d %H:%M:%S') + ' ' + msg
    print(line, flush=True)
    with open(LOGFILE, 'a') as f:
        f.write(line + '\n')

# --- Parsers ---
def parse_alinks(value):
    parts = value.split(',')
    result = {}
    for part in parts[1:]:
        if len(part) >= 3:
            result[part[:-2]] = part[-2:]
    return result

def parse_links_set(value):
    parts = value.split(',')
    result = set()
    for part in parts[1:]:
        node = part[1:] if part.startswith('T') else part
        if node:
            result.add(node)
    return result

def is_permanent(node):
    if node == '1999':
        return True
    if node.isdigit() and len(node) == 7 and node.startswith('3'):
        return True
    return False

def real_subs(subs):
    return {n for n in subs if not is_permanent(n)}

def sub_label(subs):
    return ', '.join(
        f'{n} ({callsign(n)})' if callsign(n) != '?' else n
        for n in sorted(subs)
    )

# --- Topology ---
node_history = {}

def load_topology():
    global node_history
    try:
        with open(TOPOFILE) as f:
            raw = json.load(f)
        node_history = {k: set(v) for k, v in raw.items()}
        log(f'[INFO] topology geladen: {len(node_history)} nodes uit {TOPOFILE}')
    except FileNotFoundError:
        pass
    except Exception as e:
        log(f'[WARN] topology laden mislukt: {e}')

def save_topology():
    try:
        raw = {k: list(v) for k, v in node_history.items()}
        with open(TOPOFILE, 'w') as f:
            json.dump(raw, f, indent=2)
    except Exception as e:
        log(f'[WARN] topology opslaan mislukt: {e}')

parent_map = {}

def build_initial_parent_map(direct_set, indirect_set):
    parent_map.clear()
    for direct, known_subs in node_history.items():
        if direct in direct_set:
            matching = known_subs & indirect_set
            if matching:
                parent_map[direct] = set(matching)

# --- TX logging ---
def log_tx_aan(node, subs):
    display = real_subs(subs)
    if len(display) == 1:
        sub = next(iter(display))
        log(f'[TX-AAN]  node={node_label(sub)} via {node_label(node)} begint uitzenden')
    elif display:
        log(f'[TX-AAN]  node={node_label(node)} begint uitzenden [via: {sub_label(display)}]')
    else:
        log(f'[TX-AAN]  node={node_label(node)} begint uitzenden')

def log_tx_uit(node, subs):
    display = real_subs(subs)
    if len(display) == 1:
        sub = next(iter(display))
        log(f'[TX-UIT]  node={node_label(sub)} via {node_label(node)} stopt uitzenden')
    elif display:
        log(f'[TX-UIT]  node={node_label(node)} stopt uitzenden [via: {sub_label(display)}]')
    else:
        log(f'[TX-UIT]  node={node_label(node)} stopt uitzenden')

pending_uit = {}
log_lock = threading.Lock()

def fire_tx_uit(node, subs):
    with log_lock:
        pending_uit.pop(node, None)
        log_tx_uit(node, subs)

def ami_query_rpt_variables(s, node):
    cmd = f'Action: Command\r\nCommand: rpt show variables {node}\r\n\r\n'
    s.sendall(cmd.encode())
    time.sleep(0.5)
    s.settimeout(3)
    buf = b''
    for _ in range(10):
        try:
            buf += s.recv(4096)
        except socket.timeout:
            break
    text = buf.decode(errors='ignore')
    alinks = {}
    links_set = set()
    for line in text.splitlines():
        for prefix in ('Output:', '   '):
            line = line.replace(prefix, '').strip()
        if line.startswith('RPT_ALINKS='):
            alinks = parse_alinks(line.split('=', 1)[1])
        elif line.startswith('RPT_LINKS='):
            links_set = parse_links_set(line.split('=', 1)[1])
    return alinks, links_set

# --- Start ---
threading.Thread(target=db_refresher, daemon=True).start()
load_topology()

prev_alinks    = {}
prev_links_set = set()
initialized    = False

while True:
    try:
        s = socket.socket()
        s.connect((HOST, PORT))
        s.settimeout(60)
        s.recv(1024)
        s.sendall(b'Action: Login\r\nUsername: ' + USER.encode() +
                  b'\r\nSecret: ' + PASSWD.encode() + b'\r\n\r\n')
        s.recv(1024)
        log(f'AMI logger gestart (hub node {MY_NODE})')

        init_alinks, init_links = ami_query_rpt_variables(s, MY_NODE)
        if init_alinks:
            prev_alinks    = init_alinks
            prev_links_set = init_links
            direct_set     = set(init_alinks.keys())
            indirect_set   = init_links - direct_set
            build_initial_parent_map(direct_set, indirect_set)
            known = sum(1 for v in parent_map.values() if real_subs(v))
            log(f'[INFO] initiële staat: {len(direct_set)} directe nodes, '
                f'{len(indirect_set)} indirect, {known} met bekende sub-nodes')
            initialized = True
        else:
            initialized = False

        s.settimeout(60)
        buf = ''

        while True:
            try:
                buf += s.recv(4096).decode(errors='ignore')
            except socket.timeout:
                s.sendall(b'Action: Ping\r\n\r\n')
                continue

            new_alinks    = None
            new_links_set = None

            while '\r\n\r\n' in buf:
                block, buf = buf.split('\r\n\r\n', 1)
                fields = {}
                for line in block.strip().splitlines():
                    if ': ' in line:
                        k, v = line.split(': ', 1)
                        fields[k] = v
                if fields.get('Event') != 'VarSet':
                    continue
                var = fields.get('Variable', '')
                val = fields.get('Value', '')
                if var == 'RPT_ALINKS':
                    new_alinks = parse_alinks(val)
                elif var == 'RPT_LINKS':
                    new_links_set = parse_links_set(val)

            if new_alinks is None and new_links_set is None:
                continue

            with log_lock:
                effective_alinks = new_alinks    if new_alinks    is not None else prev_alinks
                effective_links  = new_links_set if new_links_set is not None else prev_links_set
                direct_set       = set(effective_alinks.keys())

                if not initialized:
                    if new_alinks is not None:
                        prev_alinks = effective_alinks
                        initialized = True
                    if new_links_set is not None:
                        prev_links_set = effective_links
                        build_initial_parent_map(
                            set(effective_alinks.keys()),
                            effective_links - set(effective_alinks.keys())
                        )
                    continue

                if new_alinks is not None:
                    removed_direct = set(prev_alinks.keys()) - direct_set
                    for rd in removed_direct:
                        if new_links_set is not None:
                            gone = (prev_links_set - new_links_set) - direct_set
                            if gone:
                                node_history[rd] = node_history.get(rd, set()) | gone
                                save_topology()
                        parent_map.pop(rd, None)
                        log(f'[UNLINK]  node={node_label(rd)} losgekoppeld')

                    new_direct = direct_set - set(prev_alinks.keys())
                    for nd in new_direct:
                        subs = set(node_history.get(nd, set()))
                        if new_links_set is not None:
                            new_in_links = (new_links_set - prev_links_set) - {nd} - direct_set
                            subs |= new_in_links
                        if subs:
                            parent_map[nd] = subs
                        log(f'[LINK]    node={node_label(nd)} verbonden')

                if new_links_set is not None and new_alinks is None:
                    gone_indirect = (prev_links_set - new_links_set) - direct_set
                    if gone_indirect:
                        for nd in list(parent_map):
                            parent_map[nd] -= gone_indirect
                            if not parent_map[nd]:
                                del parent_map[nd]

                if new_alinks is not None:
                    for node, status in effective_alinks.items():
                        old = prev_alinks.get(node, 'TU')
                        if status == 'TK' and old != 'TK':
                            subs = frozenset(parent_map.get(node, set()))
                            if node in pending_uit:
                                pending_uit.pop(node).cancel()
                            else:
                                log_tx_aan(node, subs)
                        elif status == 'TU' and old == 'TK':
                            subs = frozenset(parent_map.get(node, set()))
                            t = threading.Timer(DEBOUNCE, fire_tx_uit, args=[node, subs])
                            pending_uit[node] = t
                            t.start()
                    prev_alinks = effective_alinks

                if new_links_set is not None:
                    prev_links_set = effective_links

    except Exception as e:
        log(f'Reconnect na fout: {e}')
        time.sleep(5)
PYEOF
    chmod +x /usr/local/bin/asl-activity-logger
}

# ── 2. Systemd service installeren ───────────────────────────────────────────
install_service() {
    cat > /etc/systemd/system/asl-activity-logger.service << EOF
[Unit]
Description=AllStarLink Activity Logger (node ${NODE})
After=asterisk.service
Requires=asterisk.service

[Service]
ExecStart=/usr/local/bin/asl-activity-logger
Restart=always
RestartSec=5
StandardOutput=null

[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload
    systemctl enable asl-activity-logger
    systemctl restart asl-activity-logger
}

# ── 3. Dashboard installeren ──────────────────────────────────────────────────
install_dashboard() {
    mkdir -p "$WEB_ROOT"

    # Vervang node/callsign in dashboard
    sed \
        -e "s|'449581'|'${NODE}'|g" \
        -e "s|'PE1BZF'|'${CALLSIGN}'|g" \
        -e "s|nxdn-almere\.nl|$(hostname -f 2>/dev/null || hostname)|g" \
        "$SCRIPT_DIR/dashboard/asl-activity.php" > "$WEB_ROOT/index.php"

    # Webserver eigenaar
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
        # Voeg qualify toe als nog niet aanwezig
        if grep -q "^\[$section\]" "$CONF"; then
            if ! grep -A 20 "^\[$section\]" "$CONF" | grep -q "^qualify"; then
                python3 - "$CONF" "$section" << 'PYEOF'
import sys, re
conf, section = sys.argv[1], sys.argv[2]
text = open(conf).read()
pattern = rf'(\[{re.escape(section)}\].*?transfer\s*=\s*no)'
replacement = r'\1\nqualify = yes\nqualifyfreq = 25'
text = re.sub(pattern, replacement, text, flags=re.DOTALL)
open(conf, 'w').write(text)
PYEOF
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
    sed -i "s/^totime\s*=.*/totime = ${TOTIME_MS}/" "$CONF"
    asterisk -rx 'module reload app_rpt.so' 2>/dev/null || true
}

# ── Installatie uitvoeren ─────────────────────────────────────────────────────
{
    echo 10
    install_logger
    echo 35
    install_service
    echo 55
    install_dashboard
    echo 70
    [ "$DO_IAX" -eq 1 ] && fix_iax
    echo 85
    fix_rpt
    echo 100
} | whiptail --title "$TITLE" --gauge "Installeren..." 7 $W 0

# ── Status controleren ────────────────────────────────────────────────────────
sleep 2
LOGGER_STATUS=$(systemctl is-active asl-activity-logger 2>/dev/null || echo "onbekend")
LOG_LINES=$(wc -l < /var/log/asterisk/rpt-activity.log 2>/dev/null || echo "0")

whiptail --title "$TITLE" --msgbox \
"Installatie voltooid!

  Logger status : $LOGGER_STATUS
  Log regels    : $LOG_LINES
  Dashboard     : $WEB_ROOT/index.php

Dashboard bereikbaar op:
  http://$(hostname -I | awk '{print $1}')${WEB_ROOT#/var/www/html}

Logs bekijken:
  tail -f /var/log/asterisk/rpt-activity.log

Logger herstarten:
  systemctl restart asl-activity-logger" $H $W

exit 0
