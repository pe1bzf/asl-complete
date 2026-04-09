# EchoLink installatie voor ASL3

## Probleem en oplossing

ASL3 `chan_echolink.so` (versie 3.7.1) verwacht het EchoLink directory protocol in
het **oude formaat** (plain text met `@@@` header). De EchoLink servers sturen
nu echter het **nieuwe formaat**: 4-byte LE uint32 (decompressed size) gevolgd
door zlib-gecomprimeerde data (die zelf de oude `@@@` tekst bevat).

De `echolink-dir-proxy` service lost dit op door als tussenpersoon te fungeren:
chan_echolink verbindt met de proxy, de proxy haalt de directory op van de
echte server, decomprimeert de data, en stuurt het oude formaat terug.

## Login protocol (voor de proxy)

EchoLink login format (met 0xAC scheiders):
```
l<callsign><0xAC><0xAC><password>\rONLINE<version>(<hour>: <day>)\r<location>\r<email>\r
```

## EchoLink registratie vereist

**Belangrijk:** de roepnaam (bijv. N0CALL-L) moet geregistreerd zijn op de EchoLink
website als **link station (-L)**. Ga naar https://www.echolink.org/ → My Account.

Als de server "INVALID CALLSIGN" teruggeeft in de directory, betekent dat:
- De login credentials kloppen (server herkent de login)
- Maar de roepnaam staat niet correct in de EchoLink database

## Bestanden

- `echolink-dir-proxy` — Python proxy service
- `echolink-dir-proxy.service` — systemd service definitie
- `echolink.conf` — Asterisk EchoLink configuratie (met proxy servers)

## Installatie

```bash
# Proxy installeren
cp echolink-dir-proxy /usr/local/bin/
chmod +x /usr/local/bin/echolink-dir-proxy
cp echolink-dir-proxy.service /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now echolink-dir-proxy

# /etc/hosts aanpassen
echo "127.0.0.1 el-proxy.local" >> /etc/hosts

# echolink.conf kopiëren
cp echolink.conf /etc/asterisk/

# modules.conf: chan_echolink.so enablen (load = chan_echolink.so)

# Firewall (UFW)
ufw allow 5198/udp comment "EchoLink audio"
ufw allow 5199/udp comment "EchoLink control"

# Asterisk herladen
asterisk -rx "module load chan_echolink.so"
```
