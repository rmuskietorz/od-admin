#!/bin/bash
# chmod +x setup.sh
#
# od-admin — gefuehrter Server-Einrichtungs-Assistent (Stil wie rm-picvault).
#
# Richtet den kompletten Stack auf einem frischen Server ein:
#   GHCR-Login -> Open-Design-Fork klonen -> OD-Server starten
#   -> od-admin konfigurieren + starten -> Admin-User -> Claude-OAuth-Hinweis.
#
# Aufruf:
#   bash setup.sh            # gefuehrter Komplettlauf (empfohlen beim ersten Mal)
#   bash setup.sh <n> [..]   # einzelne Schritte, z.B.  bash setup.sh 5 6
#   bash setup.sh status     # Uebersicht: was laeuft, was fehlt
#
# Schritte:
#   0  Preflight (docker, compose, git, openssl)
#   1  GHCR-Login (Image aus privater Registry ziehbar machen)
#   2  Open-Design-Fork klonen / aktualisieren
#   3  OD-Server .env.claude-cli erzeugen
#   4  OD-Server-Stack starten (+ od-net + Watchtower)
#   5  od-admin .env.local erzeugen (Secrets generieren)
#   6  od-admin starten (Prod-Restart-Policy)
#   7  Admin-User anlegen
#   8  Abschluss: Claude-OAuth-Hinweis + Smoke-Test
#
# Idempotent: bestehende Dateien werden gesichert, nicht blind ueberschrieben.

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/lib/colors.sh
. "$SCRIPT_DIR/scripts/lib/colors.sh"

# ─── Defaults (per Env oder State-Datei ueberschreibbar) ──────────────────────

STATE_FILE="$SCRIPT_DIR/.setup.state"

GHCR_USER="${GHCR_USER:-rmuskietorz}"
OD_REPO_SSH="${OD_REPO_SSH:-git@github.com:rmuskietorz/open-design.git}"
OD_REPO_HTTPS="${OD_REPO_HTTPS:-https://github.com/rmuskietorz/open-design.git}"
OD_BRANCH="${OD_BRANCH:-claude-cli-deploy}"
OD_IMAGE="${OD_IMAGE:-ghcr.io/rmuskietorz/open-design-claude:latest}"
# Open-Design wird standardmaessig NEBEN od-admin geklont.
OD_DIR="${OD_DIR:-$(dirname "$SCRIPT_DIR")/open-design}"
OD_DOMAIN="${OD_DOMAIN:-}"
ADMIN_DOMAIN="${ADMIN_DOMAIN:-}"

# Frueher gespeicherte Antworten laden (ueberschreibt Defaults, nicht Env).
[ -f "$STATE_FILE" ] && . "$STATE_FILE"

_save_state() {
    cat > "$STATE_FILE" <<EOF
# Von setup.sh gespeichert — Antworten fuer Wiederholungslaeufe.
GHCR_USER="$GHCR_USER"
OD_DIR="$OD_DIR"
OD_IMAGE="$OD_IMAGE"
OD_DOMAIN="$OD_DOMAIN"
ADMIN_DOMAIN="$ADMIN_DOMAIN"
EOF
    # State nie committen (enthaelt Pfade/Domains, kein Secret, aber lokal).
    if [ -f "$SCRIPT_DIR/.gitignore" ] && ! grep -qxF '/.setup.state' "$SCRIPT_DIR/.gitignore"; then
        echo '/.setup.state' >> "$SCRIPT_DIR/.gitignore"
    fi
}

ask()        { local _a; read -r -p "  $1: " _a; echo "$_a"; }
ask_default() { local _a; read -r -p "  $1 [$2]: " _a; echo "${_a:-$2}"; }
confirm()    { local _a; read -r -p "  $1 (y/n): " _a; [ "$_a" = "y" ]; }

backup_file() {
    [ -f "$1" ] || return 0
    local bak="$1.bak-$(date +%Y%m%d-%H%M%S)"
    cp "$1" "$bak" && print_info "Gesichert: $(basename "$bak")"
}

gen_secret() {  # gen_secret <hex-bytes>
    openssl rand -hex "$1" 2>/dev/null \
        || head -c "$(( $1 * 2 ))" /dev/urandom | od -An -tx1 | tr -d ' \n'
}

# Setzt KEY=VALUE in einer .env-Datei: ersetzt aktive oder auskommentierte
# Zeile, sonst angehaengt. Nur fuer Compose-Interpolations-Variablen gedacht.
upsert_env() {  # upsert_env <file> <key> <value>
    local f="$1" k="$2" v="$3"
    if grep -qE "^${k}=" "$f"; then
        sed -i -E "s|^${k}=.*|${k}=${v}|" "$f"
    elif grep -qE "^# *${k}=" "$f"; then
        sed -i -E "s|^# *${k}=.*|${k}=${v}|" "$f"
    else
        printf '%s=%s\n' "$k" "$v" >> "$f"
    fi
}

# ─── Schritt 0: Preflight ─────────────────────────────────────────────────────

step_preflight() {
    print_header "Schritt 0 — Preflight"
    print_sep
    local ok=0
    for bin in docker git openssl curl; do
        if command -v "$bin" >/dev/null 2>&1; then
            print_ok "$bin vorhanden"
        else
            print_err "$bin fehlt — bitte installieren"; ok=1
        fi
    done
    if docker compose version >/dev/null 2>&1; then
        print_ok "docker compose (v2) vorhanden"
    else
        print_err "docker compose (v2-Plugin) fehlt"; ok=1
    fi
    if docker info >/dev/null 2>&1; then
        print_ok "Docker-Daemon erreichbar"
    else
        print_err "Docker-Daemon nicht erreichbar (laeuft der Dienst? Rechte?)"; ok=1
    fi
    return $ok
}

# ─── Schritt 1: GHCR-Login ────────────────────────────────────────────────────

step_login() {
    print_header "Schritt 1 — GHCR-Login"
    print_sep
    print_info "Damit der Server das private Image ziehen kann (und Watchtower Updates)."
    if docker pull "$OD_IMAGE" >/dev/null 2>&1; then
        print_ok "Image bereits ziehbar ($OD_IMAGE) — Login evtl. nicht noetig."
        confirm "Trotzdem neu einloggen?" || return 0
    fi
    GHCR_USER=$(ask_default "GitHub-User" "$GHCR_USER")
    print_info "Personal Access Token (classic, Scope read:packages). Eingabe ist verdeckt."
    local pat
    read -r -s -p "  Token: " pat; echo ""
    if [ -z "$pat" ]; then print_err "Kein Token eingegeben — abgebrochen."; return 1; fi
    if echo "$pat" | docker login ghcr.io -u "$GHCR_USER" --password-stdin; then
        print_ok "Login erfolgreich → ~/.docker/config.json (von Watchtower gemountet)."
    else
        print_err "Login fehlgeschlagen. Token/Scope pruefen."; unset pat; return 1
    fi
    unset pat
    _save_state
}

# ─── Schritt 2: Open-Design-Fork klonen ───────────────────────────────────────

step_clone() {
    print_header "Schritt 2 — Open-Design-Fork"
    print_sep
    OD_DIR=$(ask_default "Zielverzeichnis fuer den Fork" "$OD_DIR")
    if [ -d "$OD_DIR/.git" ]; then
        print_info "Repo existiert bereits — aktualisiere ($OD_BRANCH)..."
        git -C "$OD_DIR" fetch --quiet origin "$OD_BRANCH" \
            && git -C "$OD_DIR" checkout --quiet "$OD_BRANCH" \
            && git -C "$OD_DIR" pull --quiet --ff-only origin "$OD_BRANCH" \
            && print_ok "Fork aktuell" \
            || { print_err "git-Update fehlgeschlagen"; return 1; }
    else
        local url="$OD_REPO_SSH"
        confirm "SSH ($OD_REPO_SSH) nutzen? (n = HTTPS)" || url="$OD_REPO_HTTPS"
        print_info "Klone $url ($OD_BRANCH)..."
        git clone --quiet -b "$OD_BRANCH" "$url" "$OD_DIR" \
            && print_ok "Geklont nach $OD_DIR" \
            || { print_err "git clone fehlgeschlagen"; return 1; }
    fi
    if [ ! -f "$OD_DIR/deploy/docker-compose.claude-cli.server.yml" ]; then
        print_err "deploy/docker-compose.claude-cli.server.yml fehlt im Fork — falscher Branch?"
        return 1
    fi
    _save_state
}

# ─── Schritt 3: OD-Server .env.claude-cli ─────────────────────────────────────

step_env_od() {
    print_header "Schritt 3 — OD-Server .env.claude-cli"
    print_sep
    local deploy="$OD_DIR/deploy" envf="$OD_DIR/deploy/.env.claude-cli"
    if [ ! -f "$deploy/.env.claude-cli.example" ]; then
        print_err "$deploy/.env.claude-cli.example fehlt — erst Schritt 2."; return 1
    fi
    OD_IMAGE=$(ask_default "OD-Image" "$OD_IMAGE")
    OD_DOMAIN=$(ask_default "OD-Domain (fuer ALLOWED_ORIGINS, leer = keine)" "$OD_DOMAIN")
    if [ -f "$envf" ]; then backup_file "$envf"; fi
    cp "$deploy/.env.claude-cli.example" "$envf"
    upsert_env "$envf" "OPEN_DESIGN_IMAGE" "$OD_IMAGE"
    if [ -n "$OD_DOMAIN" ]; then
        upsert_env "$envf" "OPEN_DESIGN_ALLOWED_ORIGINS" "https://$OD_DOMAIN"
    fi
    # Sicherheit: ANTHROPIC_API_KEY darf NICHT gesetzt sein (Abo statt API-Billing).
    if grep -qE '^ANTHROPIC_API_KEY=.+' "$envf"; then
        sed -i -E 's|^(ANTHROPIC_API_KEY=).*|# \1  # ABSICHTLICH LEER — Abo-Token via ttyd|' "$envf"
        print_info "ANTHROPIC_API_KEY entschaerft (auskommentiert)."
    fi
    print_ok ".env.claude-cli geschrieben"
    print_info "Image:   $OD_IMAGE"
    print_info "Origins: ${OD_DOMAIN:+https://$OD_DOMAIN}${OD_DOMAIN:-（keine)}"
    _save_state
}

# ─── Schritt 4: OD-Server-Stack starten ───────────────────────────────────────

step_start_od() {
    print_header "Schritt 4 — OD-Server starten"
    print_sep
    local deploy="$OD_DIR/deploy"
    local compose="docker compose -f $deploy/docker-compose.claude-cli.server.yml --env-file $deploy/.env.claude-cli"
    print_info "Starte OD + Watchtower (legt Netz 'od-net' an)..."
    ( cd "$deploy" && $compose up -d ) \
        || { print_err "Start fehlgeschlagen"; return 1; }
    print_info "Warte auf Health (max 60s)..."
    local i
    for i in $(seq 1 30); do
        if curl -sf http://127.0.0.1:7456/api/health >/dev/null 2>&1; then
            print_ok "OD healthy (http://127.0.0.1:7456/api/health)"
            ( cd "$deploy" && $compose ps )
            return 0
        fi
        sleep 2
    done
    print_err "OD wurde nicht rechtzeitig healthy. Logs pruefen:"
    echo "    cd $deploy && $compose logs --tail 80 open-design"
    return 1
}

# ─── Schritt 5: od-admin .env.local ───────────────────────────────────────────

step_env_admin() {
    print_header "Schritt 5 — od-admin .env.local"
    print_sep
    local envlocal="$SCRIPT_DIR/.env.local" envmain="$SCRIPT_DIR/.env"
    ADMIN_DOMAIN=$(ask_default "Admin-Domain (TRUSTED_HOSTS)" "${ADMIN_DOMAIN:-admin.example.com}")
    local domain_esc; domain_esc=$(printf '%s' "$ADMIN_DOMAIN" | sed 's/\./\\./g')

    if [ -f "$envlocal" ]; then
        backup_file "$envlocal"
        confirm "Bestehende .env.local ueberschreiben?" || { print_info "Uebersprungen."; return 0; }
    fi

    print_info "Generiere Secrets..."
    local app_secret ttyd_token
    app_secret=$(gen_secret 32)
    ttyd_token=$(gen_secret 16)
    if [ -z "$app_secret" ] || [ -z "$ttyd_token" ]; then
        print_err "Secret-Generierung fehlgeschlagen (openssl?)."; return 1
    fi

    # Container-Env (via env_file geladen). OD_COMPOSE_FILE ist der IN-CONTAINER-
    # Pfad (bind-mount /host/open-design), NICHT der Host-Pfad.
    cat > "$envlocal" <<EOF
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=$app_secret

DATABASE_URL="sqlite:///%kernel.project_dir%/var/data/app.db"

OD_CONTAINER_NAME=open-design-claude
OD_UPSTREAM_URL=http://open-design-claude:7456
OD_COMPOSE_FILE=/host/open-design/docker-compose.claude-cli.server.yml

TTYD_UPSTREAM_URL=http://ttyd:7681
TTYD_SHARED_TOKEN=$ttyd_token

DOCKER_HOST=unix:///var/run/docker.sock

# Session-Cookie unter / (OD-Proxy liegt auf /, nicht /admin) — siehe DEBT-002.
COOKIE_PATH=/

# Trusted Hosts: nur die echte Admin-Domain.
TRUSTED_HOSTS=^($domain_esc)\$
TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12,192.168.0.0/16
EOF
    unset app_secret ttyd_token
    print_ok ".env.local geschrieben (Secrets generiert, nicht angezeigt)"

    # Compose-Interpolations-Variablen MUESSEN in .env stehen — docker compose
    # liest fuer ${...}-Substitution NUR .env, nicht .env.local. .env ist
    # getrackt → skip-worktree, damit Server-Pfade nicht als dirty/Konflikt
    # auftauchen. (Genau die .env.local-Override-Falle.)
    git -C "$SCRIPT_DIR" update-index --skip-worktree .env 2>/dev/null \
        && print_info ".env auf skip-worktree gesetzt (lokale Edits unsichtbar)"
    upsert_env "$envmain" "HOST_OD_DEPLOY_DIR" "$OD_DIR/deploy"
    upsert_env "$envmain" "OD_ADMIN_RESTART" "unless-stopped"
    print_ok ".env: HOST_OD_DEPLOY_DIR + OD_ADMIN_RESTART=unless-stopped gesetzt"
    _save_state
}

# ─── Schritt 6: od-admin starten ──────────────────────────────────────────────

step_start_admin() {
    print_header "Schritt 6 — od-admin starten"
    print_sep
    if ! docker network inspect od-net >/dev/null 2>&1; then
        print_err "Netz 'od-net' fehlt — erst Schritt 4 (OD-Server) ausfuehren."; return 1
    fi
    print_info "Baue + starte od-admin (Restart-Policy aus .env)..."
    ( cd "$SCRIPT_DIR" && docker compose up -d --build ) \
        || { print_err "Start fehlgeschlagen"; return 1; }
    ( cd "$SCRIPT_DIR" && docker compose ps )
    print_ok "od-admin gestartet"
}

# ─── Schritt 7: Admin-User ────────────────────────────────────────────────────

step_user() {
    print_header "Schritt 7 — Admin-User anlegen"
    print_sep
    if [ -z "$(cd "$SCRIPT_DIR" && docker compose ps -q od-admin 2>/dev/null)" ]; then
        print_err "od-admin laeuft nicht — erst Schritt 6."; return 1
    fi
    local username; username=$(ask "Username")
    [ -z "$username" ] && { print_err "Kein Username."; return 1; }
    print_info "Passwort min. 12 Zeichen (bcrypt cost 13)."
    ( cd "$SCRIPT_DIR" && docker compose exec -it -u 1000:1000 od-admin \
        php bin/console app:create-user "$username" )
}

# ─── Schritt 8: Abschluss ─────────────────────────────────────────────────────

step_finish() {
    print_header "Schritt 8 — Abschluss & Claude-OAuth"
    print_sep
    echo ""
    echo -e "  ${BOLD}Smoke-Test (lokal auf dem Server):${RESET}"
    if curl -sf http://127.0.0.1:7456/api/health >/dev/null 2>&1; then
        print_ok "OD-Server  : /api/health ok"
    else
        print_err "OD-Server  : /api/health nicht erreichbar"
    fi
    local admin_port; admin_port=$(grep -E '^OD_ADMIN_PORT=' "$SCRIPT_DIR/.env" 2>/dev/null | cut -d= -f2)
    admin_port="${admin_port:-8082}"
    if curl -sf "http://127.0.0.1:${admin_port}/admin/login" >/dev/null 2>&1; then
        print_ok "od-admin   : /admin/login ok (Port $admin_port)"
    else
        print_err "od-admin   : /admin/login nicht erreichbar (Port $admin_port)"
    fi
    echo ""
    echo -e "  ${BOLD}${CYAN}Claude OAuth-Login (Abo-Token, KEIN API-Key):${RESET}"
    echo "    1. Reverse-Proxy/TLS auf die Admin-Domain richten (nginx/Caddy)."
    echo "       Upstream: 127.0.0.1:${admin_port}"
    echo "    2. https://${ADMIN_DOMAIN:-<admin-domain>}/admin  oeffnen, einloggen."
    echo "    3. ttyd-Terminal oeffnen → laeuft automatisch 'claude setup-token'."
    echo "    4. Angezeigte URL im Browser autorisieren, Code zurueck ins Terminal."
    echo "       → Token liegt im claude_home-Volume (uebersteht Updates)."
    echo ""
    echo -e "  ${BOLD}Laufender Betrieb:${RESET}  bash helper.sh   (Status, Logs, Restart, Backups)"
    echo ""
}

# ─── Status ───────────────────────────────────────────────────────────────────

step_status() {
    print_header "Status-Uebersicht"
    print_sep
    echo -e "  ${BOLD}Konfiguration${RESET}"
    echo "    OD-Verzeichnis : $OD_DIR"
    echo "    OD-Image       : $OD_IMAGE"
    echo "    Admin-Domain   : ${ADMIN_DOMAIN:-(nicht gesetzt)}"
    echo ""
    echo -e "  ${BOLD}Dateien${RESET}"
    [ -f "$OD_DIR/deploy/.env.claude-cli" ] && print_ok ".env.claude-cli" || print_err ".env.claude-cli fehlt"
    [ -f "$SCRIPT_DIR/.env.local" ]         && print_ok "od-admin .env.local" || print_err ".env.local fehlt"
    echo ""
    echo -e "  ${BOLD}Container${RESET}"
    docker ps --filter "name=open-design-claude" --filter "name=od-admin" --filter "name=watchtower" \
        --format '    {{.Names}}\t{{.Status}}' 2>/dev/null || true
    echo ""
    echo -e "  ${BOLD}Netz${RESET}"
    docker network inspect od-net >/dev/null 2>&1 \
        && print_ok "od-net existiert" || print_err "od-net fehlt"
}

# ─── Komplettlauf ─────────────────────────────────────────────────────────────

run_all() {
    print_header "od-admin — Server-Einrichtung (Komplettlauf)"
    print_sep
    echo "  Reihenfolge: Preflight → GHCR-Login → Fork → OD-Server"
    echo "               → od-admin-Config → od-admin-Start → User → Abschluss"
    echo ""
    confirm "Starten?" || { print_info "Abgebrochen."; return 0; }
    step_preflight || { print_err "Preflight fehlgeschlagen — bitte erst beheben."; return 1; }
    step_login     || return 1
    step_clone     || return 1
    step_env_od    || return 1
    step_start_od  || return 1
    step_env_admin || return 1
    step_start_admin || return 1
    step_user      || print_info "User-Schritt uebersprungen — spaeter: bash setup.sh 7"
    step_finish
    print_ok "Einrichtung abgeschlossen."
}

# ─── Dispatch ─────────────────────────────────────────────────────────────────

usage() {
    cat <<EOF
od-admin setup.sh — Server-Einrichtung

  bash setup.sh             Gefuehrter Komplettlauf
  bash setup.sh status      Status-Uebersicht
  bash setup.sh <n> [..]    Einzelne Schritte

Schritte:
  0  Preflight        4  OD-Server starten     8  Abschluss/OAuth
  1  GHCR-Login       5  od-admin .env.local
  2  Fork klonen      6  od-admin starten
  3  OD .env          7  Admin-User
EOF
}

if [ $# -eq 0 ]; then
    run_all
    exit $?
fi

for arg in "$@"; do
    echo ""
    case "$arg" in
        0) step_preflight ;;
        1) step_login ;;
        2) step_clone ;;
        3) step_env_od ;;
        4) step_start_od ;;
        5) step_env_admin ;;
        6) step_start_admin ;;
        7) step_user ;;
        8) step_finish ;;
        status) step_status ;;
        all) run_all ;;
        -h|--help|help) usage ;;
        *) print_err "Unbekannter Schritt: $arg"; usage; exit 1 ;;
    esac
done
