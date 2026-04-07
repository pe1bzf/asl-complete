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

**Functies:**
- Haalt callsigns op via `https://allmondb.allstarlink.org/` (cache 15 min)
- Leert indirecte node-topologie (welke nodes via andere nodes verbonden zijn)
- Sla topology op in `asl-topology.json` voor gebruik na herstart
- Debounce van 2 seconden op TX-UIT (voorkomt dubbele log bij snelle keying)
- Permanente nodes (EchoLink 3xxxxxx, conferentie 1999) worden gefilterd

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

**Installeren op VPS:**
```bash
mkdir -p /var/www/html/asl
cp dashboard/asl-activity.php /var/www/html/asl/index.php
chown www-data:www-data /var/www/html/asl/index.php
```

**Bereikbaar op:** `http://nxdn-almere.nl/asl/`

**Werking:**
- Leest `/var/log/asterisk/rpt-activity.log` via tail-f methode (100ms polling)
- SSE pusht updates naar browser binnen ~100ms na log-wijziging
- Haalt netwerktopologie op via `https://stats.allstarlink.org/api/stats/449581` (cache 5 min)
- Fallback naar 2s polling als SSE niet beschikbaar is

**Panelen:**
1. **Nu aan het uitzenden** — live TX met laatste sessies
2. **Verbonden nodes** — alle gelinkte nodes met tijdstip
3. **Netwerk** — interactieve vis.js graph, kleurt live mee via SSE
4. **Recente activiteit** — laatste 100 logregels, kleurgecodeerd

**Node kleuren in netwerk:**
| Kleur | Betekenis |
|---|---|
| Cyaan | Eigen hub node (449581) |
| Groen | Momenteel aan het uitzenden |
| Blauw | Verbonden |
| Grijs | Indirect verbonden of bekend maar niet actief |

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
