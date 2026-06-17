#!/bin/bash
# chmod +x helper.sh
# od-admin Helper — gleicher Stil wie rm-picvault/helper.sh.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=scripts/lib/colors.sh
. "$SCRIPT_DIR/scripts/lib/colors.sh"
# shellcheck source=scripts/lib/docker.sh
. "$SCRIPT_DIR/scripts/lib/docker.sh"

ask() {
    read -p "$1: " _answer
    echo "$_answer"
}

ask_secret() {
    read -s -p "$1: " _secret
    echo "" >&2
    echo "$_secret"
}

ask_default() { local _a; read -r -p "  $1 [$2]: " _a; echo "${_a:-$2}"; }
confirm()     { local _a; read -r -p "  $1 (y/n): " _a; [ "$_a" = "y" ]; }

# ─── Server-Einrichtung: Config + Helfer ──────────────────────────────────────
# Erst-Installation laeuft ueber das gleiche Menue (Gruppe "Server-Einrichtung").

STATE_FILE="$SCRIPT_DIR/.setup.state"

GHCR_USER="${GHCR_USER:-rmuskietorz}"
OD_REPO_SSH="${OD_REPO_SSH:-git@github.com:rmuskietorz/open-design.git}"
OD_REPO_HTTPS="${OD_REPO_HTTPS:-https://github.com/rmuskietorz/open-design.git}"
OD_BRANCH="${OD_BRANCH:-claude-cli-deploy}"
OD_IMAGE="${OD_IMAGE:-ghcr.io/rmuskietorz/open-design-claude:latest}"
OD_DIR="${OD_DIR:-$(dirname "$SCRIPT_DIR")/open-design}"
OD_DOMAIN="${OD_DOMAIN:-}"
ADMIN_DOMAIN="${ADMIN_DOMAIN:-}"
[ -f "$STATE_FILE" ] && . "$STATE_FILE"

_save_state() {
    cat > "$STATE_FILE" <<EOF
# Von helper.sh (Server-Einrichtung) gespeichert.
GHCR_USER="$GHCR_USER"
OD_DIR="$OD_DIR"
OD_IMAGE="$OD_IMAGE"
OD_DOMAIN="$OD_DOMAIN"
ADMIN_DOMAIN="$ADMIN_DOMAIN"
EOF
    if [ -f "$SCRIPT_DIR/.gitignore" ] && ! grep -qxF '/.setup.state' "$SCRIPT_DIR/.gitignore"; then
        echo '/.setup.state' >> "$SCRIPT_DIR/.gitignore"
    fi
}

backup_file() {
    [ -f "$1" ] || return 0
    local bak="$1.bak-$(date +%Y%m%d-%H%M%S)"
    cp "$1" "$bak" && print_info "Gesichert: $(basename "$bak")"
}

gen_secret() {  # gen_secret <hex-bytes>
    openssl rand -hex "$1" 2>/dev/null \
        || head -c "$(( $1 * 2 ))" /dev/urandom | od -An -tx1 | tr -d ' \n'
}

# Setzt KEY=VALUE in einer .env-Datei (Compose-Interpolations-Variablen).
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

setup_preflight() {
    print_header "Preflight"
    print_sep
    local rc=0 bin
    for bin in docker git openssl curl; do
        command -v "$bin" >/dev/null 2>&1 \
            && print_ok "$bin vorhanden" || { print_err "$bin fehlt"; rc=1; }
    done
    docker compose version >/dev/null 2>&1 \
        && print_ok "docker compose (v2) vorhanden" || { print_err "docker compose v2 fehlt"; rc=1; }
    docker info >/dev/null 2>&1 \
        && print_ok "Docker-Daemon erreichbar" || { print_err "Docker-Daemon nicht erreichbar"; rc=1; }
    return $rc
}

setup_login() {
    print_header "GHCR-Login"
    print_sep
    print_info "Damit der Server das private Image ziehen kann (+ Watchtower-Updates)."
    if docker pull "$OD_IMAGE" >/dev/null 2>&1; then
        print_ok "Image bereits ziehbar — Login evtl. nicht noetig."
        confirm "Trotzdem neu einloggen?" || return 0
    fi
    GHCR_USER=$(ask_default "GitHub-User" "$GHCR_USER")
    print_info "Personal Access Token (classic, Scope read:packages). Eingabe verdeckt."
    local pat; read -r -s -p "  Token: " pat; echo ""
    [ -z "$pat" ] && { print_err "Kein Token — abgebrochen."; return 1; }
    if echo "$pat" | docker login ghcr.io -u "$GHCR_USER" --password-stdin; then
        print_ok "Login ok → ~/.docker/config.json (von Watchtower gemountet)."
    else
        print_err "Login fehlgeschlagen. Token/Scope pruefen."; unset pat; return 1
    fi
    unset pat; _save_state
}

setup_clone() {
    print_header "Open-Design-Fork klonen / aktualisieren"
    print_sep
    OD_DIR=$(ask_default "Zielverzeichnis fuer den Fork" "$OD_DIR")
    if [ -d "$OD_DIR/.git" ]; then
        print_info "Repo existiert — aktualisiere ($OD_BRANCH)..."
        git -C "$OD_DIR" fetch --quiet origin "$OD_BRANCH" \
            && git -C "$OD_DIR" checkout --quiet "$OD_BRANCH" \
            && git -C "$OD_DIR" pull --quiet --ff-only origin "$OD_BRANCH" \
            && print_ok "Fork aktuell" || { print_err "git-Update fehlgeschlagen"; return 1; }
    else
        local url="$OD_REPO_SSH"
        confirm "SSH ($OD_REPO_SSH) nutzen? (n = HTTPS)" || url="$OD_REPO_HTTPS"
        print_info "Klone $url ($OD_BRANCH)..."
        git clone --quiet -b "$OD_BRANCH" "$url" "$OD_DIR" \
            && print_ok "Geklont nach $OD_DIR" || { print_err "git clone fehlgeschlagen"; return 1; }
    fi
    [ -f "$OD_DIR/deploy/docker-compose.claude-cli.server.yml" ] \
        || { print_err "deploy-Compose fehlt im Fork — falscher Branch?"; return 1; }
    _save_state
}

setup_env_od() {
    print_header "OD-Server .env.claude-cli"
    print_sep
    local deploy="$OD_DIR/deploy" envf="$OD_DIR/deploy/.env.claude-cli"
    [ -f "$deploy/.env.claude-cli.example" ] \
        || { print_err "$deploy/.env.claude-cli.example fehlt — erst Fork klonen."; return 1; }
    OD_IMAGE=$(ask_default "OD-Image" "$OD_IMAGE")
    OD_DOMAIN=$(ask_default "OD-Domain (ALLOWED_ORIGINS, leer = keine)" "$OD_DOMAIN")
    backup_file "$envf"
    cp "$deploy/.env.claude-cli.example" "$envf"
    upsert_env "$envf" "OPEN_DESIGN_IMAGE" "$OD_IMAGE"
    [ -n "$OD_DOMAIN" ] && upsert_env "$envf" "OPEN_DESIGN_ALLOWED_ORIGINS" "https://$OD_DOMAIN"
    if grep -qE '^ANTHROPIC_API_KEY=.+' "$envf"; then
        sed -i -E 's|^(ANTHROPIC_API_KEY=).*|# \1  # ABSICHTLICH LEER — Abo-Token via ttyd|' "$envf"
        print_info "ANTHROPIC_API_KEY entschaerft."
    fi
    print_ok ".env.claude-cli geschrieben"
    _save_state
}

setup_start_od() {
    print_header "OD-Server starten"
    print_sep
    local deploy="$OD_DIR/deploy"
    local c="docker compose -f $deploy/docker-compose.claude-cli.server.yml --env-file $deploy/.env.claude-cli"
    print_info "Starte OD + Watchtower (legt Netz 'od-net' an)..."
    ( cd "$deploy" && $c up -d ) || { print_err "Start fehlgeschlagen"; return 1; }
    print_info "Warte auf Health (max 60s)..."
    local i
    for i in $(seq 1 30); do
        if curl -sf http://127.0.0.1:7456/api/health >/dev/null 2>&1; then
            print_ok "OD healthy"; ( cd "$deploy" && $c ps ); return 0
        fi
        sleep 2
    done
    print_err "OD nicht rechtzeitig healthy. Logs: cd $deploy && $c logs --tail 80 open-design"
    return 1
}

setup_env_admin() {
    print_header "od-admin .env.local"
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
    app_secret=$(gen_secret 32); ttyd_token=$(gen_secret 16)
    [ -z "$app_secret" ] || [ -z "$ttyd_token" ] && { print_err "Secret-Generierung fehlgeschlagen."; return 1; }
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

TRUSTED_HOSTS=^($domain_esc)\$
TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12,192.168.0.0/16
EOF
    unset app_secret ttyd_token
    print_ok ".env.local geschrieben (Secrets generiert, nicht angezeigt)"
    # Compose liest fuer ${...}-Interpolation NUR .env, nicht .env.local. .env ist
    # getrackt → skip-worktree, damit Server-Pfade nicht dirty/Konflikt werden.
    git -C "$SCRIPT_DIR" update-index --skip-worktree .env 2>/dev/null \
        && print_info ".env auf skip-worktree gesetzt"
    upsert_env "$envmain" "HOST_OD_DEPLOY_DIR" "$OD_DIR/deploy"
    upsert_env "$envmain" "OD_ADMIN_RESTART" "unless-stopped"
    print_ok ".env: HOST_OD_DEPLOY_DIR + OD_ADMIN_RESTART=unless-stopped gesetzt"
    _save_state
}

setup_start_admin() {
    print_header "od-admin starten"
    print_sep
    docker network inspect od-net >/dev/null 2>&1 \
        || { print_err "Netz 'od-net' fehlt — erst OD-Server starten."; return 1; }
    print_info "Baue + starte od-admin (Restart-Policy aus .env)..."
    ( cd "$SCRIPT_DIR" && docker compose up -d --build ) \
        || { print_err "Start fehlgeschlagen"; return 1; }
    ( cd "$SCRIPT_DIR" && docker compose ps )
    print_ok "od-admin gestartet"
}

setup_finish() {
    print_header "Abschluss & Claude-OAuth"
    print_sep
    if curl -sf http://127.0.0.1:7456/api/health >/dev/null 2>&1; then
        print_ok "OD-Server : /api/health ok"
    else
        print_err "OD-Server : /api/health nicht erreichbar"
    fi
    local p; p=$(grep -E '^OD_ADMIN_PORT=' "$SCRIPT_DIR/.env" 2>/dev/null | cut -d= -f2); p="${p:-8082}"
    if curl -sf "http://127.0.0.1:${p}/admin/login" >/dev/null 2>&1; then
        print_ok "od-admin  : /admin/login ok (Port $p)"
    else
        print_err "od-admin  : /admin/login nicht erreichbar (Port $p)"
    fi
    echo ""
    echo -e "  ${BOLD}${CYAN}Claude OAuth (Abo-Token, KEIN API-Key):${RESET}"
    echo "    1. Reverse-Proxy/TLS auf Admin-Domain (Upstream 127.0.0.1:${p})."
    echo "    2. https://${ADMIN_DOMAIN:-<admin-domain>}/admin oeffnen, einloggen."
    echo "    3. ttyd-Terminal oeffnen → 'claude setup-token' laeuft automatisch."
    echo "    4. URL autorisieren, Code zurueck ins Terminal (Token → claude_home-Volume)."
    echo ""
}

setup_status() {
    print_header "Einrichtungs-Status"
    print_sep
    echo "    OD-Verzeichnis : $OD_DIR"
    echo "    OD-Image       : $OD_IMAGE"
    echo "    Admin-Domain   : ${ADMIN_DOMAIN:-(nicht gesetzt)}"
    echo ""
    [ -f "$OD_DIR/deploy/.env.claude-cli" ] && print_ok ".env.claude-cli" || print_err ".env.claude-cli fehlt"
    [ -f "$SCRIPT_DIR/.env.local" ] && print_ok "od-admin .env.local" || print_err ".env.local fehlt"
    docker network inspect od-net >/dev/null 2>&1 && print_ok "od-net existiert" || print_err "od-net fehlt"
    echo ""
    docker ps --filter "name=open-design-claude" --filter "name=od-admin" --filter "name=watchtower" \
        --format '    {{.Names}}\t{{.Status}}' 2>/dev/null || true
}

setup_user() {
    print_header "Admin-User anlegen"
    local username; username=$(ask "Username")
    [ -z "$username" ] && { print_err "Kein Username."; return 1; }
    print_info "Passwort min. 12 Zeichen (bcrypt cost 13)."
    $APP_TTY php bin/console app:create-user "$username"
}

run_setup_all() {
    print_header "Server-Einrichtung — gefuehrter Komplettlauf"
    print_sep
    echo "  Preflight → GHCR-Login → Fork → OD-Server → od-admin-Config"
    echo "  → od-admin-Start → Admin-User → Abschluss"
    echo ""
    confirm "Starten?" || { print_info "Abgebrochen."; return 0; }
    setup_preflight  || { print_err "Preflight fehlgeschlagen."; return 1; }
    setup_login      || return 1
    setup_clone      || return 1
    setup_env_od     || return 1
    setup_start_od   || return 1
    setup_env_admin  || return 1
    setup_start_admin || return 1
    setup_user       || print_info "User-Schritt uebersprungen — spaeter Menuepunkt 87."
    setup_finish
    print_ok "Einrichtung abgeschlossen."
}

# ─── TUI ──────────────────────────────────────────────────────────────────────

_MITEMS=(
  "G:Docker"
  "1:Container starten"
  "11:Container stoppen"
  "12:Container neu bauen"
  "13:Container neu starten"
  "14:Status / Health"
  "15:Logs (follow)"
  "16:Shell im Container"
  "17:Frischer Reset (Volume neu anlegen)"
  "G:Setup & Datenbank"
  "2:composer install"
  "21:composer dump-autoload"
  "22:Migrations ausfuehren"
  "23:Migration generieren"
  "24:Cache leeren"
  "G:User-Verwaltung"
  "3:Admin-User anlegen"
  "31:User-Liste (SQLite)"
  "G:Tests & Qualitaet"
  "4:Alle Tests ausfuehren"
  "41:Einzelnen Test (Filter)"
  "42:PHPStan analysieren"
  "43:Twig-Lint"
  "44:Alle Checks (Lint+Stan+Test)"
  "G:Audit-Log & Security"
  "5:Letzte Login-Versuche anzeigen"
  "51:Fehlgeschlagene Logins (heute)"
  "52:Audit purgen (90 Tage)"
  "53:fail2ban Status (Host)"
  "G:Open Design Steuerung"
  "6:OD-Status (via DockerClient)"
  "61:OD restart"
  "62:OD update (pull + up)"
  "63:OD Logs streamen"
  "64:Claude CLI Status"
  "65:Claude Test-Prompt"
  "G:Server-Einrichtung (Erstinstallation)"
  "8:Komplett-Einrichtung (gefuehrt)"
  "81:Preflight"
  "82:GHCR-Login"
  "83:Fork klonen/aktualisieren"
  "84:OD .env.claude-cli"
  "85:OD-Server starten"
  "86:od-admin .env.local"
  "87:Admin-User anlegen"
  "88:Abschluss (Smoke + OAuth)"
  "89:Einrichtungs-Status"
  "G:Deploy & Backup"
  "7:Backup jetzt ausfuehren"
  "71:Backup-Liste anzeigen"
  "72:Server-Setup-Doku oeffnen"
  "G:Konfiguration"
  "9:.env.local bearbeiten"
  "91:Compose-Konfig anzeigen"
  "99:Container + Volumes loeschen (DESTRUKTIV)"
)

declare -a _MK _ML _MG _MGN
_mgc=-1
for _me in "${_MITEMS[@]}"; do
  _mk="${_me%%:*}"; _mv="${_me#*:}"
  if [[ "$_mk" == "G" ]]; then
    ((_mgc++)); _MGN+=("$_mv")
  else
    _MK+=("$_mk"); _ML+=("$_mv"); _MG+=("$_mgc")
  fi
done
_MTN=${#_MK[@]}
declare -a _MSL
declare -a _MCLINES
for ((i=0;i<_MTN;i++)); do _MSL+=(0); done
_MSL_NEXT=1

_MCUR=0
_MNCOLS=1
_MRSZ=0
_MSEP='────────────────────────────────────────────────────────────────────────────'
trap '_MRSZ=1' SIGWINCH

_m_cols() {
  local c
  c=${COLUMNS:-0}
  (( c > 0 )) && { echo "$c"; return; }
  c=$(stty size 2>/dev/null | awk '{print $2}')
  [[ -n "$c" && "$c" -gt 0 ]] 2>/dev/null && { echo "$c"; return; }
  c=$(tput cols 2>/dev/null)
  [[ -n "$c" && "$c" -gt 0 ]] 2>/dev/null && { echo "$c"; return; }
  echo 80
}

_m_update_ncols() {
  local w; w=$(_m_cols)
  if   (( w >= 180 )); then _MNCOLS=3
  elif (( w >= 110 )); then _MNCOLS=2
  else _MNCOLS=1; fi
}

_m_item_col() {
  local g=${_MG[$1]}
  local gpc=$(( (${#_MGN[@]} + _MNCOLS - 1) / _MNCOLS ))
  _MITEMCOL=$(( g / gpc ))
  (( _MITEMCOL >= _MNCOLS )) && _MITEMCOL=$(( _MNCOLS - 1 ))
}

_m_nav_v() {
  local dir=$1
  _m_item_col $_MCUR; local curcol=$_MITEMCOL
  local i
  if (( dir > 0 )); then
    for ((i=_MCUR+1; i<_MTN; i++)); do
      _m_item_col $i; (( _MITEMCOL == curcol )) && { _MCUR=$i; return; }
    done
    for ((i=0; i<_MCUR; i++)); do
      _m_item_col $i; (( _MITEMCOL == curcol )) && { _MCUR=$i; return; }
    done
  else
    for ((i=_MCUR-1; i>=0; i--)); do
      _m_item_col $i; (( _MITEMCOL == curcol )) && { _MCUR=$i; return; }
    done
    for ((i=_MTN-1; i>_MCUR; i--)); do
      _m_item_col $i; (( _MITEMCOL == curcol )) && { _MCUR=$i; return; }
    done
  fi
}

_m_row_in_col() {
  local target=$1
  _m_item_col $target; local tcol=$_MITEMCOL
  local row=0 i
  for ((i=0; i<_MTN; i++)); do
    _m_item_col $i; (( _MITEMCOL != tcol )) && continue
    (( i == target )) && { echo $row; return; }
    ((row++))
  done
  echo 0
}

_m_item_at_row() {
  local col=$1 row=$2
  local r=0 last=-1 i
  for ((i=0; i<_MTN; i++)); do
    _m_item_col $i; (( _MITEMCOL != col )) && continue
    last=$i
    (( r == row )) && { echo $i; return; }
    ((r++))
  done
  (( last >= 0 )) && echo $last || echo 0
}

_m_nav_h() {
  local dir=$1
  _m_item_col $_MCUR; local curcol=$_MITEMCOL
  local tcol=$(( curcol + dir ))
  (( tcol < 0 || tcol >= _MNCOLS )) && return
  local currow; currow=$(_m_row_in_col $_MCUR)
  local target; target=$(_m_item_at_row $tcol $currow)
  _MCUR=$target
}

_m_render_col() {
  _MCLINES=()
  local cidx=$1 cw=$2
  local ng=${#_MGN[@]}
  local gpc=$(( (ng + _MNCOLS - 1) / _MNCOLS ))
  local gs=$(( cidx * gpc ))
  local ge=$(( gs + gpc - 1 ))
  (( ge >= ng )) && ge=$(( ng - 1 ))
  (( gs >= ng )) && return
  local last_g=-1 lbl_max=$(( cw - 11 )) i line pad_s
  for ((i=0; i<_MTN; i++)); do
    local g=${_MG[$i]}
    (( g < gs || g > ge )) && continue
    if (( g != last_g )); then
      [[ $last_g -ge 0 ]] && _MCLINES+=("")
      local gn="${_MGN[$g]}"
      (( ${#gn} > cw-5 )) && gn="${gn:0:$((cw-6))}…"
      local slen=$(( cw - ${#gn} - 4 ))
      (( slen < 1 )) && slen=1
      printf -v line "\033[36;1m── %s %s\033[0m" "$gn" "${_MSEP:0:$slen}"
      _MCLINES+=("$line")
      last_g=$g
    fi
    local key="${_MK[$i]}" lbl="${_ML[$i]}"
    (( ${#lbl} > lbl_max )) && lbl="${lbl:0:$((lbl_max-1))}…"
    local pad=$(( cw - 11 - ${#lbl} ))
    (( pad < 0 )) && pad=0
    local csr="  " chk="[ ]"
    (( i == _MCUR )) && csr=$'\033[1m\xe2\x96\xb6 \033[0m'
    local _ord=${_MSL[$i]}
    if   (( _ord == 0 ));  then chk="[ ]"
    elif (( _ord <= 9 ));  then chk=$'\033[32m['"$_ord"$']\033[0m'
    else                        chk=$'\033[32m[+]\033[0m'
    fi
    printf -v pad_s '%*s' $pad ''
    printf -v line "%s%s \033[33m%3s\033[0m  %s%s" "$csr" "$chk" "$key" "$lbl" "$pad_s"
    _MCLINES+=("$line")
  done
}

_m_draw() {
  _m_update_ncols
  local w; w=$(_m_cols)
  local cw=$(( (w - 2*(_MNCOLS-1)) / _MNCOLS ))
  (( cw < 28 )) && cw=28

  printf '\033[H'
  printf "\033[36;1m╔══════════════════════════════════════════╗\n"
  printf "║   od-admin — Helper                      ║\n"
  printf "╚══════════════════════════════════════════╝\033[0m\n\n"

  declare -a _MC0 _MC1 _MC2
  _m_render_col 0 $cw; _MC0=("${_MCLINES[@]}")
  _MC1=(); _MC2=()
  (( _MNCOLS >= 2 )) && { _m_render_col 1 $cw; _MC1=("${_MCLINES[@]}"); }
  (( _MNCOLS >= 3 )) && { _m_render_col 2 $cw; _MC2=("${_MCLINES[@]}"); }

  local nrows=${#_MC0[@]}
  (( ${#_MC1[@]} > nrows )) && nrows=${#_MC1[@]}
  (( ${#_MC2[@]} > nrows )) && nrows=${#_MC2[@]}

  local row col line
  for ((row=0; row<nrows; row++)); do
    for ((col=0; col<_MNCOLS; col++)); do
      case $col in
        0) line="${_MC0[$row]:-}" ;;
        1) line="${_MC1[$row]:-}" ;;
        2) line="${_MC2[$row]:-}" ;;
      esac
      printf '%s' "$line"
      [[ -z "$line" ]] && printf '%*s' $cw ''
      (( col < _MNCOLS-1 )) && printf '  '
    done
    printf '\n'
  done

  printf '\n\033[36m──────────────────────────────────────────────────────\033[0m\n'
  printf ' \033[1m↑↓\033[0m/Pfeile: nav  \033[1mSpace\033[0m: auswählen'
  printf '  \033[1mEnter\033[0m: ausführen  \033[1ma\033[0m: alle  \033[1mn\033[0m: keine  \033[1mq\033[0m: Ende\n'
  printf '\033[J'
}

_m_drain() { while read -rsn1 -t 0 _mdrain 2>/dev/null; do :; done; }

_m_readkey() {
  _MKEY='IGNORE'
  local _buf _rest
  IFS= read -rsn1 _buf
  if [[ "$_buf" == $'\e' ]]; then
    IFS= read -rsn2 -t 1 _rest 2>/dev/null
    case "$_rest" in
      '[A'|'OA') _MKEY='UP'    ;;
      '[B'|'OB') _MKEY='DOWN'  ;;
      '[C'|'OC') _MKEY='RIGHT' ;;
      '[D'|'OD') _MKEY='LEFT'  ;;
    esac
    _m_drain
  elif [[ "$_buf" == ' ' || "$_buf" == '' || "$_buf" == [aAnNqQ] ]]; then
    _MKEY="$_buf"
  elif [[ "$_buf" =~ [0-9] ]]; then
    local _num="$_buf"
    IFS= read -rsn1 -t 0.5 _rest 2>/dev/null
    if [[ "$_rest" =~ [0-9] ]]; then
      _num+="$_rest"
    fi
    local _found=0
    for ((i=0;i<_MTN;i++)); do
      if [[ "${_MK[$i]}" == "$_num" ]]; then
        _MCUR=$i
        if (( ${_MSL[$_MCUR]} == 0 )); then
          _MSL[$_MCUR]=$_MSL_NEXT; ((_MSL_NEXT++))
        else
          local _rm=${_MSL[$_MCUR]}; _MSL[$_MCUR]=0
          for ((j=0;j<_MTN;j++)); do
            (( ${_MSL[$j]} > _rm )) && _MSL[$j]=$(( ${_MSL[$j]} - 1 ))
          done
          ((_MSL_NEXT--))
        fi
        _found=1; break
      fi
    done
    if (( !_found )); then _MKEY='IGNORE'; fi
  fi
  _m_drain
}

tui_menu() {
  local _stty_save; _stty_save=$(stty -g 2>/dev/null)
  tput smcup 2>/dev/null; tput civis 2>/dev/null; stty -echo 2>/dev/null
  local _done=0 _res=""
  tput clear 2>/dev/null
  while (( !_done )); do
    (( _MRSZ )) && { tput clear 2>/dev/null; _MRSZ=0; }
    _m_draw
    _m_readkey
    case "$_MKEY" in
      UP)    _m_nav_v -1 ;;
      DOWN)  _m_nav_v  1 ;;
      LEFT)  _m_nav_h -1 ;;
      RIGHT) _m_nav_h  1 ;;
      ' ')
        if (( ${_MSL[$_MCUR]} == 0 )); then
          _MSL[$_MCUR]=$_MSL_NEXT; ((_MSL_NEXT++))
        else
          local _rm=${_MSL[$_MCUR]}; _MSL[$_MCUR]=0
          for ((i=0;i<_MTN;i++)); do
            (( ${_MSL[$i]} > _rm )) && _MSL[$i]=$(( ${_MSL[$i]} - 1 ))
          done
          ((_MSL_NEXT--))
        fi ;;
      a|A)
        for ((i=0;i<_MTN;i++)); do _MSL[$i]=$((i+1)); done
        _MSL_NEXT=$((_MTN+1)) ;;
      n|N)
        for ((i=0;i<_MTN;i++)); do _MSL[$i]=0; done
        _MSL_NEXT=1 ;;
      '')
        _res=""
        for ((n=1; n<_MSL_NEXT; n++)); do
          for ((i=0;i<_MTN;i++)); do
            (( ${_MSL[$i]} == n )) && _res+="${_MK[$i]} "
          done
        done
        [[ -z "${_res// }" ]] && _res="${_MK[$_MCUR]}"
        _done=1 ;;
      q|Q) _res=""; _done=1 ;;
      IGNORE) ;;
    esac
  done
  tput cnorm 2>/dev/null
  [[ -n "$_stty_save" ]] && stty "$_stty_save" 2>/dev/null
  tput rmcup 2>/dev/null
  _TUIRES="${_res% }"
}

# ─── Parameteruebergabe oder Menue ────────────────────────────────────────────

if [ $# -eq 0 ]; then
    _TUIRES=""
    tui_menu
    _OPTS="$_TUIRES"
    [ -z "$_OPTS" ] && exit 0
else
    _OPTS="$*"
fi

# ─── Aktionen ────────────────────────────────────────────────────────────────

for option in $_OPTS; do
echo ""
case $option in

    # Docker
    1)
        # Sicherstellen, dass das geteilte Netz existiert (wird normalerweise
        # vom Open-Design-Compose angelegt; falls OD noch nicht laeuft,
        # legen wir das Netz hier an, damit es spaeter wiederverwendet wird).
        if ! docker network inspect od-net >/dev/null 2>&1; then
            print_info "Netzwerk 'od-net' fehlt – wird angelegt..."
            docker network create od-net >/dev/null \
                && print_ok "od-net angelegt" \
                || { print_err "od-net konnte nicht angelegt werden"; exit 1; }
        fi

        print_info "Container starten..."
        if $COMPOSE up -d; then
            print_ok "Container laeuft"
        else
            print_err "Start fehlgeschlagen (siehe Output oben). Abbruch."
            exit 1
        fi
        ;;
    11)
        print_info "Container stoppen..."
        $COMPOSE down && print_ok "Container gestoppt" || print_err "Stop fehlgeschlagen"
        ;;
    12)
        print_info "Image neu bauen..."
        if $COMPOSE build; then
            print_ok "Build abgeschlossen"
        else
            print_err "Build fehlgeschlagen. Folge-Aktionen werden abgebrochen."
            exit 1
        fi
        ;;
    13)
        print_info "Container neu starten..."
        $COMPOSE restart && print_ok "Restart fertig" || { print_err "Restart fehlgeschlagen"; exit 1; }
        ;;
    14)
        print_header "Status"
        $COMPOSE ps
        ;;
    15)
        print_info "Logs (Strg+C zum Beenden)..."
        $COMPOSE logs -f --tail=200
        ;;
    16)
        if ! compose_is_up; then
            print_err "Container laeuft nicht. Erst Option 1 ausfuehren."; continue
        fi
        print_info "Shell im Container..."
        $APP_TTY sh
        ;;
    17)
        print_header "Frischer Reset"
        echo ""
        echo -e "  ${YELLOW}Stoppt Container und LOESCHT das od_admin_data Volume."
        echo -e "  Damit gehen alle User + Audit-Log verloren.${RESET}"
        read -p "  Fortfahren? (y/n): " CONFIRM
        if [ "$CONFIRM" != "y" ]; then
            print_info "Abgebrochen."; continue
        fi
        $COMPOSE down
        docker volume rm od-admin_od_admin_data 2>/dev/null \
            && print_ok "Volume od-admin_od_admin_data entfernt" \
            || print_info "Volume war schon weg"
        print_info "Starte frisch..."
        $COMPOSE up -d && print_ok "Container laeuft mit frischem Volume" \
            || { print_err "Start fehlgeschlagen"; exit 1; }
        ;;

    # Setup & DB
    2)
        print_info "composer install..."
        $APP composer install
        print_ok "Fertig"
        ;;
    21)
        print_info "composer dump-autoload..."
        $APP composer dump-autoload -o
        print_ok "Autoload neu generiert"
        ;;
    22)
        print_info "Doctrine Migrations..."
        $CONSOLE doctrine:migrations:migrate --no-interaction
        print_ok "Migrations ausgefuehrt"
        ;;
    23)
        print_info "Neue Migration generieren..."
        $CONSOLE make:migration
        ;;
    24)
        print_info "Cache leeren..."
        $CONSOLE cache:clear
        print_ok "Cache geleert"
        ;;

    # User-Verwaltung
    3)
        print_header "Admin-User anlegen"
        username=$(ask "Username")
        $APP_TTY php bin/console app:create-user "$username"
        ;;
    31)
        print_header "User-Liste"
        $APP sqlite3 /var/www/html/var/data/app.db \
            "SELECT username, roles, created_at FROM app_user ORDER BY id;" \
            2>/dev/null || print_err "Fehler beim DB-Zugriff"
        ;;

    # Tests
    4)
        print_info "Tests..."
        $APP php vendor/bin/phpunit
        ;;
    41)
        filter=$(ask "Testklasse oder Methode")
        $APP php vendor/bin/phpunit --filter="$filter"
        ;;
    42)
        print_info "PHPStan..."
        $APP php vendor/bin/phpstan analyse
        ;;
    43)
        print_info "Twig-Lint..."
        $CONSOLE lint:twig templates/
        ;;
    44)
        print_info "Twig-Lint..."
        $CONSOLE lint:twig templates/
        print_info "PHPStan..."
        $APP php vendor/bin/phpstan analyse
        print_info "Tests..."
        $APP php vendor/bin/phpunit
        print_ok "Alle Checks abgeschlossen"
        ;;

    # Audit-Log
    5)
        print_header "Letzte 20 Login-Versuche"
        $APP sqlite3 -header -column /var/www/html/var/data/data.db \
            "SELECT created_at, username, ip, success, reason FROM login_attempt ORDER BY created_at DESC LIMIT 20;" \
            2>/dev/null
        ;;
    51)
        print_header "Fehlgeschlagene Logins heute"
        $APP sqlite3 -header -column /var/www/html/var/data/data.db \
            "SELECT created_at, username, ip, reason FROM login_attempt
             WHERE success = 0 AND date(created_at) = date('now')
             ORDER BY created_at DESC;" 2>/dev/null
        ;;
    52)
        days=$(ask "Aufbewahrungsdauer in Tagen (Default 90)")
        days="${days:-90}"
        $CONSOLE app:audit:purge --days="$days"
        ;;
    53)
        print_header "fail2ban Status (Host-Befehl)"
        if command -v fail2ban-client >/dev/null 2>&1; then
            sudo fail2ban-client status od-admin 2>/dev/null \
                || print_info "Jail 'od-admin' nicht aktiv – siehe deploy/SERVER.md"
        else
            print_info "fail2ban-client nicht installiert. Siehe deploy/SERVER.md Schritt 5."
        fi
        ;;

    # OD Steuerung (via curl auf eigene API)
    6)
        print_header "OD Status"
        $APP curl -s http://127.0.0.1:8080/admin/status 2>/dev/null \
            | python3 -m json.tool 2>/dev/null \
            || print_err "Status nicht erreichbar (od-admin laeuft? eingeloggt?)"
        ;;
    61)
        print_info "OD restart triggern (POST /admin/restart)..."
        $APP curl -s -X POST http://127.0.0.1:8080/admin/restart \
            | python3 -m json.tool 2>/dev/null
        ;;
    62)
        print_info "OD update (pull + up) triggern..."
        $APP curl -s -X POST http://127.0.0.1:8080/admin/update \
            | python3 -m json.tool 2>/dev/null
        ;;
    63)
        print_info "OD Logs (Strg+C)..."
        $APP docker logs --follow --tail 100 open-design-claude
        ;;
    64)
        $APP curl -s http://127.0.0.1:8080/admin/claude/status 2>/dev/null \
            | python3 -m json.tool 2>/dev/null
        ;;
    65)
        $APP curl -s -X POST http://127.0.0.1:8080/admin/claude/test 2>/dev/null \
            | python3 -m json.tool 2>/dev/null
        ;;

    # Server-Einrichtung (Erstinstallation)
    8)  run_setup_all ;;
    81) setup_preflight ;;
    82) setup_login ;;
    83) setup_clone ;;
    84) setup_env_od ;;
    85) setup_start_od ;;
    86) setup_env_admin ;;
    87) setup_user ;;
    88) setup_finish ;;
    89) setup_status ;;

    # Deploy & Backup
    7)
        print_info "Backup ausfuehren..."
        if [ -x "$SCRIPT_DIR/deploy/scripts/backup.sh" ]; then
            sudo "$SCRIPT_DIR/deploy/scripts/backup.sh"
        else
            print_err "deploy/scripts/backup.sh nicht gefunden / nicht executable"
        fi
        ;;
    71)
        print_header "Backups"
        sudo ls -lh /var/backups/od-admin/ 2>/dev/null \
            || print_info "Noch keine Backups vorhanden"
        ;;
    72)
        print_info "Oeffne deploy/SERVER.md..."
        ${EDITOR:-less} "$SCRIPT_DIR/deploy/SERVER.md"
        ;;

    # Konfiguration
    9)
        ${EDITOR:-vi} "$SCRIPT_DIR/.env.local"
        ;;
    91)
        $COMPOSE config
        ;;
    99)
        print_header "Container + Volumes loeschen"
        echo -e "  ${RED}${BOLD}Loescht User-DB UND Audit-Log!${RESET}"
        read -p "  Tippe 'JA' zum Bestaetigen: " CONFIRM
        if [ "$CONFIRM" != "JA" ]; then
            print_info "Abgebrochen."; continue
        fi
        $COMPOSE down -v
        print_ok "Entfernt"
        ;;

    *)
        print_err "Unbekannte Option: $option"
        ;;
esac
done
