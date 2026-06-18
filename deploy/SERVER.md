# od-admin Server-Setup

End-to-End: frischer Ubuntu/Debian-Server → Open Design + Admin-Dashboard auf
zwei Subdomains, TLS, Single-Sign-On, fail2ban, Backups. Fast alles läuft über
`bash helper.sh` (geführtes Menü).

## Architektur (Ist-Stand)

- **`open-design.robmus.de`** → Open Design (hinter Auth-Gate)
- **`admin.open-design.robmus.de`** → Admin-Dashboard (Wurzel)
- Beide laufen durch **od-admin** (nginx trennt nach Host); od-admin proxyt OD
  und hält das Login-Gate (`auth_request`).
- **Ein Login (SSO):** Cookie `OD_SESSION` mit Domain `open-design.robmus.de`
  → deckt nur OD + Admin-Subdomain, kein Leak an andere robmus.de-Seiten.
- **Claude-Auth:** Subscription-OAuth-Token `CLAUDE_CODE_OAUTH_TOKEN`
  (aus `claude setup-token`, **kein** `ANTHROPIC_API_KEY`). Wird vom Button-Login
  im Dashboard erzeugt und in den OD-Container injiziert.

## 1. Server-Vorbereitung

```bash
curl -fsSL https://get.docker.com | sh
sudo apt update
sudo apt install -y nginx certbot python3-certbot-nginx fail2ban git rsync openssl util-linux
```

## 2. DNS

Zwei A-Records auf die Server-IP:
- `open-design.robmus.de` → <SERVER-IP>
- `admin.open-design.robmus.de` → <SERVER-IP>  (Subdomain von open-design, nötig fürs SSO-Cookie)

## 3. Repos klonen

```bash
sudo mkdir -p /var/www/tools && cd /var/www/tools
git clone -b claude-cli-deploy git@github.com:rmuskietorz/open-design.git
git clone git@github.com:rmuskietorz/od-admin.git
cd od-admin
```

## 4. Geführte Einrichtung

```bash
bash helper.sh        # Menü; oder direkt einzelne Schritte:
```
- **8** Komplett-Einrichtung (Preflight → GHCR-Login → OD-Server → od-admin
  .env.local [Domains, SSO-Cookie, TRUSTED_HOSTS, freier Port] → od-admin starten
  → Admin-User → Abschluss). Defaults: `open-design.robmus.de` /
  `admin.open-design.robmus.de`.
- **92** nginx-vHosts für beide Subdomains
- **93** EIN Let's-Encrypt-Cert für beide (`certbot -d open-design… -d admin.open-design…`)

GHCR-PAT (classic, `read:packages`) für den Login-Schritt bereithalten, falls
das Image privat ist.

## 5. Claude-Login

Dashboard `https://admin.open-design.robmus.de/` → einloggen → **Claude Login** →
**Login starten** → URL autorisieren → Code einfügen → Bestätigen. Der Token wird
gespeichert (`var/data/od_oauth.env`) und der OD-Container neu erzeugt.

## 6. fail2ban

```bash
bash helper.sh 54     # installiert (falls nötig), kopiert Filter+Jail, restart, Status
bash helper.sh 53     # Status spaeter
```
Jail liest `/var/log/nginx/od-admin.access.log`, bannt nach 5 Login-POSTs/10 min.

## 7. Firewall

```bash
sudo ufw allow 22/tcp && sudo ufw allow 80/tcp && sudo ufw allow 443/tcp && sudo ufw enable
sudo ss -tlnp | grep -E ':(7456|8086|8088)'   # OD + od-admin nur an 127.0.0.1
```

## 8. Backups & Restore

```bash
bash helper.sh 7      # jetzt sichern (Volume-Auswahl: alle / einzeln)
bash helper.sh 73     # taeglicher Cron 03:30
bash helper.sh 71     # Backup-Liste
bash helper.sh 74     # Restore (Snapshot + Volumes waehlen, 'JA' bestaetigen)
```
Sichert die Volumes `od_admin_data` (User-DB/Audit/Token), `open_design_data`
(Designs/Projekte/Artefakte), `claude_home` + Compose/.env-Snapshots nach
`/var/backups/od-admin/<ts>/`. Retention 30 Tage. Designs überleben Rebuilds
(Named Volume) — Restore nur bei `down -v`/`volume rm` nötig.

## 9. Updates

| Was | Wann | Wie |
|---|---|---|
| Open Design (Image) | automatisch alle 6 h | Watchtower zieht GHCR `:latest` |
| Open Design (sofort) | manuell | Dashboard „Update (Pull + Up)" **oder** `bash helper.sh 62` |
| od-admin selbst | manuell | `cd /var/www/tools/od-admin && git pull && docker compose up -d --build` |
| Upstream-Features (OD) | bewusst | lokal `git merge upstream/main` auf claude-cli-deploy, Dockerfile an Drift anpassen, pushen → CI baut |
| System | wöchentlich | `apt update && apt upgrade` |

OD-Update behält den Token (läuft über od-admin mit beiden `--env-file`). od-admin
selbst NICHT direkt vom Host neu `up`-en, wenn Token-relevant — der Token liegt in
od-admins Volume.

## 10. Sicherheits-Checkliste

- [ ] `OPEN_DESIGN_BIND=127.0.0.1`, `OD_ADMIN_BIND=127.0.0.1` (kein Public-Direktzugriff)
- [ ] TLS aktiv (beide Subdomains, ein Cert), HSTS gesetzt
- [ ] `TRUSTED_HOSTS` = beide Domains (+ localhost), `COOKIE_DOMAIN=open-design.robmus.de`
- [ ] Admin-User starkes Passwort (≥ 12 Zeichen, bcrypt cost 13)
- [ ] fail2ban aktiv (`bash helper.sh 53`)
- [ ] UFW aktiv, nur 22/80/443
- [ ] SSH: PubKey-only, kein root-Login
- [ ] Backups täglich (Cron), Restore einmal getestet
- [ ] `.env.local` / `od_oauth.env` nicht im Git
- [ ] **`ANTHROPIC_API_KEY` NICHT gesetzt** (sonst API-Billing statt Abo)
- [ ] (empfohlen) docker-socket-proxy statt direktem RW-Socket-Mount; 2FA fürs Login
```
