# od-admin

Admin-Panel + Reverse-Proxy vor Open Design Server. Symfony 7, eigene User-Auth, Docker-Steuerung via gemounteten Socket, Claude-Login via eingebettetes ttyd-Terminal.

## Architektur

```
Internet
   │ HTTPS (via externes nginx oder Caddy)
   ▼
[od-admin Container]
   ├── nginx
   │    - /admin/*        → PHP-FPM (Symfony)
   │    - /admin/ttyd/*   → http://ttyd:7681  (Terminal-Embed)
   │    - /*              → http://od:7456    (Open Design, mit auth_request)
   │
   └── php-fpm (Symfony 7)
        - SecurityController  /admin/login, /admin/logout
        - AdminController     /admin, /admin/status, /admin/restart, /admin/update, /admin/logs, /admin/claude/*
        - AuthCheckController /_auth_check  (nginx subrequest)
        - DockerClient        spricht /var/run/docker.sock
   │
[ttyd Container]   tsl0922/ttyd, exec → docker exec für Claude-Login
[open-design]      ghcr.io/<user>/open-design-claude:latest
[watchtower]       Auto-Update alle 6h
```

## Setup

```bash
# 1. Repo lokal initialisieren (composer + git)
cd ~/projects/web/od-admin
composer install
git init && git add . && git commit -m "feat: initial od-admin skeleton"

# 2. .env.local anlegen
cp .env.local.example .env.local
# APP_SECRET, DATABASE_URL, OD_CONTAINER_NAME, TTYD_TOKEN setzen

# 3. Container bauen + starten
docker compose build
docker compose up -d

# 4. DB-Migration + erster User
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec php php bin/console app:create-user robin
# → bcrypt Hash wird interaktiv abgefragt

# 5. UI im Browser oeffnen
open http://localhost:8080/admin/login
```

## Konfiguration

`.env.local`:

```ini
APP_ENV=prod
APP_SECRET=<openssl rand -hex 32>
DATABASE_URL=sqlite:///%kernel.project_dir%/var/data.db

# Open Design Container (muss im selben Docker-Netz hängen)
OD_CONTAINER_NAME=open-design-claude
OD_UPSTREAM_URL=http://open-design-claude:7456

# ttyd Sidecar
TTYD_UPSTREAM_URL=http://ttyd:7681
TTYD_SHARED_TOKEN=<openssl rand -hex 16>

# Compose-File des OD-Stacks (auf dem Host gemountet ins od-admin Container)
OD_COMPOSE_FILE=/host/open-design/docker-compose.claude-cli.server.yml
```

## Erster User anlegen

```bash
docker compose exec php php bin/console app:create-user robin
# Passwort interaktiv eingeben (2x), wird mit bcrypt gehasht in SQLite gespeichert.
```

## Routes

| Pfad | Handler | Auth |
|---|---|---|
| `/admin/login` | SecurityController::login | public |
| `/admin/logout` | SecurityController::logout | logged-in |
| `/admin` | AdminController::dashboard | logged-in |
| `/admin/status` | AdminController::status (JSON) | logged-in |
| `/admin/restart` | AdminController::restart (POST) | logged-in |
| `/admin/update` | AdminController::update (POST) | logged-in |
| `/admin/logs` | AdminController::logs (SSE) | logged-in |
| `/admin/claude/login` | ClaudeController::login (ttyd-Iframe) | logged-in |
| `/admin/claude/test` | ClaudeController::test (POST) | logged-in |
| `/admin/claude/status` | ClaudeController::status (JSON) | logged-in |
| `/_auth_check` | AuthCheckController | internal (nginx) |
| `/*` (alles andere) | nginx-Proxy → OD | logged-in (via auth_request) |

## Sicherheits-Härtung

Der Container mounted den Docker-Socket. Härtung im Compose:

```yaml
read_only: true
tmpfs: [/tmp, /var/cache, /var/log/nginx, /run]
cap_drop: [ALL]
cap_add: [CHOWN, SETUID, SETGID, DAC_OVERRIDE, NET_BIND_SERVICE]
security_opt: [no-new-privileges:true]
user: "1000:1000"
```

Symfony läuft als non-root, kein Schreibzugriff auf `/`, keine privesc möglich.

## Migration auf Docker Socket Proxy (spätere Stufe)

Wenn du auf `tecnativa/docker-socket-proxy` migrieren willst:

1. Socket-Proxy-Service in `compose.yml` hinzufügen
2. `od-admin` Volume `/var/run/docker.sock` entfernen
3. `DOCKER_HOST=tcp://docker-socket-proxy:2375` Env-Var setzen
4. `DockerClient` nutzt automatisch `DOCKER_HOST` wenn gesetzt

Aufwand: ~10 Zeilen Compose-Änderung, **kein** PHP-Code-Change nötig.
