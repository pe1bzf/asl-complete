# ASL Complete — AllStarLink Hub Node Setup

Volledige setup voor AllStarLink hub nodes.

Bevat: activity logger, real-time web dashboard, configuratie-aanpassingen en voorbeelddata.

---

## Inhoud

```
asl-complete/
├── dashboard/
│   └── asl-activity.php          ← Real-time web dashboard (PHP + vis.js)
├── echolink/
│   ├── echolink.conf             ← EchoLink configuratiesjabloon
│   ├── echolink-dir-proxy        ← Directory proxy (ASL3 formaat compatibiliteit)
│   └── echolink-dir-proxy.service ← systemd service definitie
├── logger/
│   ├── asl-activity-logger       ← Python AMI logger (draait als systemd service)
│   ├── asl-activity-logger.service ← systemd service definitie
│   ├── rpt-activity.log          ← Voorbeeldlog (gegenereerd door logger)
│   └── asl-topology.json         ← Voorbeeld netwerktopologie cache
├── install.sh                    ← Interactief installatiescript (NL/EN)
└── README.md
```

---

## Installatie

```bash
sudo bash install.sh
```

Het script vraagt interactief naar:
- Node nummer en roepnaam
- AMI host, poort, gebruikersnaam en wachtwoord
- Webpad voor het dashboard
- Max spreektijd (TX timeout)
- IAX2 keepalive (optioneel)

---

## Componenten

### 1. Activity Logger (`logger/asl-activity-logger`)

Python 3 daemon die via de **Asterisk AMI** luistert naar `RPT_ALINKS` en `RPT_LINKS` events.

**Logt naar** `/var/log/asterisk/rpt-activity.log`:

| Event | Betekenis |
|---|---|
| `[TX-AAN]` | Node begint uitzenden |
| `[TX-UIT]` | Node stopt uitzenden |
| `[LINK]` | Node verbindt met hub |
| `[UNLINK]` | Node verbreekt verbinding |
| `[INFO]` | Informatiemelding |
| `[WARN]` | Waarschuwing |

**Logformaat:**
```
2026-01-01 12:00:00 [TX-AAN]  node=400001 begint uitzenden
2026-01-01 12:00:05 [TX-UIT]  node=400001 stopt uitzenden
2026-01-01 12:01:00 [LINK]    node=400002 (N0CALL) verbonden
2026-01-01 12:02:00 [UNLINK]  node=400002 (N0CALL) losgekoppeld
```

EchoLink nodes verschijnen als `3xxxxxx` in ASL. De logger vertaalt deze automatisch naar roepnamen via de EchoLink online directory.

**Functies:**
- Haalt ASL callsigns op via `https://allmondb.allstarlink.org/` (cache 15 min)
- Haalt EchoLink roepnamen op via `https://www.echolink.org/logins.jsp` (cache 5 min)
- EchoLink nodes (`3xxxxxx`) worden automatisch omgezet naar roepnaam, bijv. `3567531 (EL:N0CALL)`
- Leert indirecte node-topologie (welke nodes via andere nodes verbonden zijn)
- Slaat topology op in `asl-topology.json` voor gebruik na herstart
- Debounce van 2 seconden op TX-UIT (voorkomt dubbele log bij snelle keying)
- Conferentie node 1999 wordt gefilterd uit sub-node weergave

**Handmatig installeren:**
```bash
cp logger/asl-activity-logger /usr/local/bin/
chmod +x /usr/local/bin/asl-activity-logger
cp logger/asl-activity-logger.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now asl-activity-logger
```

**AMI credentials instellen** (`/etc/asterisk/manager.conf`):
```ini
[admin]
secret = jouw-ami-wachtwoord
```

Pas de waarden bovenaan `asl-activity-logger` aan:
```python
HOST, PORT = '127.0.0.1', 5038
USER, PASSWD = 'admin', 'jouw-ami-wachtwoord'
MY_NODE = 'jouw-nodenummer'
```

---

### 2. Web Dashboard (`dashboard/asl-activity.php`)

Single-file PHP dashboard met real-time updates via **Server-Sent Events (SSE)**.

**Vereisten:**
- PHP 7.4 of hoger (getest op PHP 8.x)
- Apache of nginx met PHP
- Logbestand: `/var/log/asterisk/rpt-activity.log` (gegenereerd door activity logger)

**Installeren:**
```bash
mkdir -p /var/www/html/asl
cp dashboard/asl-activity.php /var/www/html/asl/index.php
chown www-data:www-data /var/www/html/asl/index.php
```

Open in browser: `http://jouw-server/asl/`

**Nginx — SSE buffering uitschakelen:**
```nginx
location /asl/ {
    proxy_buffering off;
    proxy_cache off;
}
```

**Features:**
- Real-time updates via SSE — maximaal ~100ms vertraging na log-wijziging
- **Nu aan het uitzenden** — live TX met begintijd en pulserende indicator
- **Laatste sessies** — 5 meest recente TX-sessies met begin- en eindtijd
- **Verbonden nodes** — alle gelinkte nodes met tijdstip
- **Netwerk** — interactieve vis.js graph, kleurt live mee
- **EchoLink roepnamen** — EchoLink inbellers als `EL:N0CALL` i.p.v. nodenummer
- **Recente activiteit** — laatste 100 logregels, kleurgecodeerd
- Automatische herverbinding; fallback naar 2s polling bij ontbrekende SSE

**Aanpassen aan je eigen node** (bovenaan het PHP-bestand):
```php
$LOG_FILE = '/var/log/asterisk/rpt-activity.log';
$MY_NODE  = 'jouw-nodenummer';
$MY_CALL  = 'JOUW-ROEPNAAM';
```

---

### 3. EchoLink (`echolink/`)

- **`echolink.conf`** — Configuratiesjabloon voor `chan_echolink`. Vul roepnaam, wachtwoord en locatie in.
- **`echolink-dir-proxy`** — Python proxy die het nieuwe EchoLink directory-formaat omzet naar het oude formaat dat `chan_echolink` verwacht.
- **`echolink-dir-proxy.service`** — systemd service, start vóór Asterisk.

---

### 4. Configuratie-aanpassingen

#### `iax.conf` — IAX2 keepalive (voorkomt disconnect bij inactiviteit)

Toegevoegd aan `[iaxrpt]` en `[iaxclient]`:
```ini
qualify = yes
qualifyfreq = 25    ; keepalive elke 25 seconden (vóór NAT-timeout van 30-60s)
```

#### `rpt.conf` — TX timeout

```ini
totime = 360000     ; 6 minuten (standaard 3 minuten)
```

---

## Auteur

Amateur radio operator — Nederland  
[AllStarLink](https://allstarlink.org)
