# od-admin Server-Setup

End-to-End-Anleitung: frischer Ubuntu/Debian-Server → laufendes Setup mit od-admin + Open Design + TLS + fail2ban + Backups.

## 1. Server-Vorbereitung

```bash
# Docker
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER && newgrp docker

# nginx + certbot + fail2ban + utilities
sudo apt update
sudo apt install -y nginx certbot python3-certbot-nginx fail2ban git rsync openssl
```

## 2. Open Design installieren

```bash
sudo mkdir -p /opt && sudo chown $USER /opt
cd /opt
git clone --depth 1 --filter=blob:none --sparse \
    -b claude-cli-deploy git@github.com:rmuskietorz/open-design.git
cd open-design
git sparse-checkout set deploy

cp deploy/.env.claude-cli.example deploy/.env.claude-cli
# In .env.claude-cli setzen:
#   OPEN_DESIGN_IMAGE=ghcr.io/rmuskietorz/open-design-claude:latest
#   OPEN_DESIGN_ALLOWED_ORIGINS=https://admin.example.com
#   OPEN_DESIGN_BIND=127.0.0.1
nano deploy/.env.claude-cli

# GHCR login (falls Image privat)
echo $GHCR_PAT | docker login ghcr.io -u rmuskietorz --password-stdin

# Server-Mode starten (Watchtower mit dabei)
cd deploy/scripts
export OD_COMPOSE_FILE=$(pwd)/../docker-compose.claude-cli.server.yml
./od-claude.sh 17 1     # Pull + Up
```

## 3. od-admin installieren

```bash
cd /opt
git clone git@github.com:rmuskietorz/od-admin.git
cd od-admin

# Secrets generieren
APP_SECRET=$(openssl rand -hex 32)
TTYD_TOKEN=$(openssl rand -hex 16)

cp .env.local.example .env.local
sed -i "s/replace-with-output-of-openssl-rand-hex-32/${APP_SECRET}/" .env.local
sed -i "s/replace-with-output-of-openssl-rand-hex-16/${TTYD_TOKEN}/" .env.local
# TRUSTED_HOSTS anpassen: ^(admin\.example\.com)$

# Bauen + Starten
docker compose build
docker compose up -d

# Migration laeuft automatisch via entrypoint.sh

# Erster User
docker compose exec od-admin php bin/console app:create-user robin
# Passwort >= 12 Zeichen, zweimal eingeben
```

## 4. TLS + nginx Reverse Proxy

```bash
# Filter-Templates kopieren
sudo cp deploy/nginx/od-admin.example.conf /etc/nginx/sites-available/od-admin
sudo sed -i 's/admin\.example\.com/<deine-domain>/g' /etc/nginx/sites-available/od-admin
sudo ln -s /etc/nginx/sites-available/od-admin /etc/nginx/sites-enabled/

# nginx vor TLS-Konfig testen
sudo nginx -t
sudo systemctl reload nginx

# Let's Encrypt Zertifikat
sudo certbot --nginx -d <deine-domain>

# Auto-Renewal Cron ist von certbot schon eingerichtet (`systemctl status certbot.timer`)
```

## 5. fail2ban

```bash
sudo cp deploy/fail2ban/od-admin.filter.conf /etc/fail2ban/filter.d/od-admin.conf
sudo cp deploy/fail2ban/od-admin.jail.conf   /etc/fail2ban/jail.d/od-admin.conf

# Logfile-Pfad muss mit dem in nginx-vHost gesetzten Pfad uebereinstimmen
ls -la /var/log/nginx/od-admin.access.log

# Filter testen
sudo fail2ban-regex /var/log/nginx/od-admin.access.log \
    /etc/fail2ban/filter.d/od-admin.conf

# Aktivieren
sudo systemctl restart fail2ban
sudo fail2ban-client status od-admin
```

## 6. Firewall

```bash
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable

# Port 8080 und 7456 sind nur an 127.0.0.1 gebunden, nicht oeffentlich
sudo ss -tlnp | grep -E ':(7456|8080)'
```

## 7. Backups

```bash
# Manueller Run
sudo ./deploy/scripts/backup.sh

# Cron fuer taegliches Backup um 03:30
sudo tee /etc/cron.d/od-admin-backup <<EOF
30 3 * * * root /opt/od-admin/deploy/scripts/backup.sh >> /var/log/od-admin-backup.log 2>&1
EOF
```

Backup-Inhalt:
- `od_admin_data.tar.gz`     User-DB (SQLite)
- `open_design_data.tar.gz`  Projekte, Artefakte, OD-DB
- `claude_home.tar.gz`       OAuth-Credentials
- `od-admin-config.tar.gz`   Compose + .env (Snapshot)
- `open-design-config.tar.gz` OD-Compose + .env

Retention: 30 Tage, danach automatisch geloescht.

## 8. Restore-Pfad (Disaster Recovery)

```bash
TARGET=/var/backups/od-admin/<timestamp>

# Container stoppen
cd /opt/od-admin && docker compose down
cd /opt/open-design/deploy/scripts && ./od-claude.sh 11

# Volumes neu anlegen + Daten reinpacken
for vol in od_admin_data open_design_data claude_home; do
    docker volume create "$vol"
    docker run --rm -v "$vol:/data" -v "$TARGET:/backup:ro" \
        alpine sh -c "cd /data && tar -xzf /backup/${vol}.tar.gz"
done

# Container starten
cd /opt/od-admin && docker compose up -d
cd /opt/open-design/deploy/scripts && ./od-claude.sh 1
```

## 9. Updates

| Was | Wann | Wie |
|---|---|---|
| Open Design | automatisch alle 6h | Watchtower zieht GHCR-Image |
| Claude CLI | mit OD-Update | im Image gebundelt |
| od-admin | manuell | `cd /opt/od-admin && git pull && docker compose build && docker compose up -d` |
| nginx / fail2ban / system | wöchentlich | `apt update && apt upgrade` |

## 10. Monitoring (optional)

Healthchecks sind in Compose bereits eingebaut. Externes Monitoring (UptimeRobot, Hetzner-Status):

- `https://<domain>/admin/login` → 200 (od-admin Healthcheck-Ziel)
- `https://<domain>/api/health` → 200 (OD Daemon Healthcheck, geht durch od-admin reverse-proxy nur wenn eingeloggt – fuer Public-Healthcheck eigenen Path bauen oder nicht oeffentlich monitoren)

## 11. Sicherheits-Checkliste

- [ ] `OPEN_DESIGN_BIND=127.0.0.1` (kein direkter Public-Access auf OD)
- [ ] `OD_ADMIN_BIND=127.0.0.1`
- [ ] TLS aktiv, HSTS gesetzt
- [ ] `TRUSTED_HOSTS` in od-admin `.env.local` exakt auf die Domain
- [ ] Erster User hat starkes Passwort (>= 12 Zeichen)
- [ ] fail2ban aktiv (`fail2ban-client status od-admin`)
- [ ] UFW aktiv, nur 22/80/443 offen
- [ ] SSH: PubKey-Only, kein root-Login (`PermitRootLogin no`, `PasswordAuthentication no`)
- [ ] Backups laufen taeglich, Restore-Pfad einmal manuell getestet
- [ ] `.env.local` nicht im Git
- [ ] ANTHROPIC_API_KEY NICHT in der Env (sonst Subscription umgangen)
