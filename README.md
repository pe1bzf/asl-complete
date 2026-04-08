# ASL Complete — AllStarLink Hub Node Setup PE1BZF

Volledige setup voor AllStarLink hub node **449581** op Strato VPS (`nxdn-almere.nl`, `87.106.80.104`).

Bevat: activity logger, real-time web dashboard, configuratie-aanpassingen en log snapshot.

---

## Inhoud

```
asl-complete/
├── dashboard/
│   └── asl-activity.php          ← Real-time web dashboard (PHP + vis.js)
├── logger/
│   ├── asl-activity-logger       ← Python AMI logger (draait als systemd service)
│   ├── asl-activity-logger.service ← systemd service definitie
│   ├── rpt-activity.log          ← Log snapshot (gegenereerd door logger)
│   └── asl-topology.json         ← Netwerktopologie cache (gegenereerd door logger)
└── README.md
```

---

## Componenten

### 1. Activity Logger (`logger/asl-activity-logger`)

Python 3 daemon die via de **Asterisk AMI** luistert naar `RPT_ALINKS` en `RPT_LINKS` events op node 449581.

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
2026-04-07 08:52:23 [TX-AAN]  node=449582 (PE1BZF) begint uitzenden
2026-04-07 08:52:27 [TX-UIT]  node=449582 (PE1BZF) stopt uitzenden
2026-04-07 08:22:40 [LINK]    node=69560 (PA5WIL) verbonden
2026-04-07 09:00:00 [UNLINK]  node=69560 (PA5WIL) losgekoppeld
2026-04-07 10:00:00 [TX-AAN]  node=3567531 (EL:PE1BZF) begint uitzenden
2026-04-07 10:00:05 [TX-UIT]  node=3567531 (EL:PE1BZF) stopt uitzenden
```

EchoLink nodes verschijnen als `3xxxxxx` in ASL. De logger vertaalt deze automatisch naar roepnamen via de EchoLink online directory.

**Functies:**
- Haalt ASL callsigns op via `https://allmondb.allstarlink.org/` (cache 15 min)
- Haalt EchoLink roepnamen op via `https://www.echolink.org/logins.jsp` (cache 5 min)
- EchoLink nodes (`3xxxxxx`) worden automatisch omgezet naar roepnaam, bijv. `3567531 (EL:PE1BZF)`
- Leert indirecte node-topologie (welke nodes via andere nodes verbonden zijn)
- Sla topology op in `asl-topology.json` voor gebruik na herstart
- Debounce van 2 seconden op TX-UIT (voorkomt dubbele log bij snelle keying)
- Conferentie node 1999 wordt gefilterd uit sub-node weergave

**Installeren op VPS:**
```bash
cp logger/asl-activity-logger /usr/local/bin/
chmod +x /usr/local/bin/asl-activity-logger
cp logger/asl-activity-logger.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now asl-activity-logger
```

**AMI credentials** (`/etc/asterisk/manager.conf`):
- User: `admin`
- Secret: `make4An1ce-secret`

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
- **EchoLink roepnamen** — EchoLink inbellers als `EL:PE1BZF` i.p.v. nodenummer
- **Recente activiteit** — laatste 100 logregels, kleurgecodeerd
- Automatische herverbinding; fallback naar 2s polling bij ontbrekende SSE

**Node kleuren in netwerk:**
| Kleur | Betekenis |
|---|---|
| Cyaan | Eigen hub node (449581) |
| Groen | Momenteel aan het uitzenden |
| Blauw | Verbonden |
| Grijs | Indirect verbonden of bekend maar niet actief |

**Technische werking:**
1. PHP leest het volledige logbestand bij initieel laden en bepaalt huidige staat
2. Daarna opent PHP het bestand op de laatste positie en leest elke 100ms nieuwe regels
3. State wordt incrementeel bijgehouden in geheugen
4. Bij wijziging → direct push naar browser via SSE

**Aanpassen aan andere node:**
```php
$LOG_FILE = '/var/log/asterisk/rpt-activity.log';
$MY_NODE  = '449581';
$MY_CALL  = 'PE1BZF';
```

---

### 3. Configuratie-aanpassingen

#### `iax.conf` — IAX2 keepalive (voorkomt disconnect bij inactiviteit)

Toegevoegd aan `[iaxrpt]` en `[iaxclient]`:
```ini
qualify = yes
qualifyfreq = 25    ; keepalive elke 25 seconden (vóór NAT-timeout van 30-60s)
```

#### `rpt.conf` — TX timeout verhoogd

```ini
totime = 360000     ; 6 minuten (was 180000 = 3 minuten)
```

---

## VPS toegang

```bash
ssh root@87.106.80.104          # via Pi's id_ed25519 (direct)
```

SSH vanuit de Pi werkt via `~/.ssh/id_ed25519` (toegevoegd aan root's authorized_keys op 2026-04-07).

---

## Auteur

PE1BZF — Amateur radio operator, Nederland  
Node 449581 (hub VPS, nxdn-almere.nl) / 449582 (lokale RPi, 192.168.2.224)
