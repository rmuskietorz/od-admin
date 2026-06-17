#!/bin/bash
# Restore der Volume-Backups, die backup.sh erzeugt hat.
# Spielt od_admin_data / open_design_data / claude_home aus einem Snapshot
# zurueck. DESTRUKTIV: ueberschreibt die aktuellen Volume-Inhalte.
#
# Usage:
#   ./restore.sh                 # interaktiv: Snapshot waehlen
#   ./restore.sh <backup-dir>    # konkreten Snapshot restoren
#   ./restore.sh <backup-dir> open_design_data   # nur ein Volume
#
# Default-Quelle: /var/backups/od-admin/<timestamp>/

set -euo pipefail

TARGET_BASE="${BACKUP_BASE:-/var/backups/od-admin}"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; RESET='\033[0m'
log()  { printf "${CYAN}[restore]${RESET} %s\n" "$*"; }
ok()   { printf "${GREEN}[restore]${RESET} %s\n" "$*"; }
err()  { printf "${RED}[restore]${RESET} %s\n" "$*" >&2; }

# Welcher Container haengt an welchem Volume (zum Stoppen vor dem Restore).
container_for() {
    case "$1" in
        od_admin_data)    echo "od-admin" ;;
        open_design_data) echo "open-design-claude" ;;
        claude_home)      echo "open-design-claude" ;;
        *)                echo "" ;;
    esac
}

# Echten (ggf. compose-praefixierten) Volume-Namen aufloesen.
resolve_volume() {
    local want="$1"
    if docker volume inspect "$want" >/dev/null 2>&1; then echo "$want"; return 0; fi
    docker volume ls --format '{{.Name}}' | grep -E "(^|_)${want}\$" | head -1
}

# ── Snapshot + Volume-Auswahl ────────────────────────────────────────────────
SRC="${1:-}"
shift || true
SEL_KEYS="$*"   # leer = alle im Snapshot vorhandenen

if [ -z "$SRC" ]; then
    log "Verfuegbare Backups in ${TARGET_BASE}:"
    mapfile -t SNAPS < <(find "$TARGET_BASE" -maxdepth 1 -type d -name "????????-??????" | sort -r)
    if [ "${#SNAPS[@]}" -eq 0 ]; then err "Keine Backups gefunden."; exit 1; fi
    i=1
    for s in "${SNAPS[@]}"; do
        printf "  %2d) %s  (%s)\n" "$i" "$(basename "$s")" "$(du -sh "$s" 2>/dev/null | cut -f1)"
        i=$((i+1))
    done
    read -r -p "  Nummer (oder Enter fuer neuestes): " pick
    if [ -z "$pick" ]; then SRC="${SNAPS[0]}"; else SRC="${SNAPS[$((pick-1))]:-}"; fi
fi

[ -d "$SRC" ] || { err "Backup-Verzeichnis nicht gefunden: $SRC"; exit 1; }
log "Quelle: $SRC"

# ── Welche Volumes sind im Snapshot? ─────────────────────────────────────────
VOLS=()
for key in od_admin_data open_design_data claude_home; do
    if [ -n "$SEL_KEYS" ]; then
        case " $SEL_KEYS " in *" $key "*) : ;; *) continue ;; esac
    fi
    [ -f "$SRC/${key}.tar.gz" ] && VOLS+=("$key")
done
[ "${#VOLS[@]}" -eq 0 ] && { err "Keine passenden *.tar.gz im Snapshot."; exit 1; }

echo ""
echo -e "${YELLOW}WIRD UEBERSCHRIEBEN:${RESET} ${VOLS[*]}"
read -r -p "  Tippe 'JA' zum Wiederherstellen: " confirm
[ "$confirm" = "JA" ] || { log "Abgebrochen."; exit 0; }

# ── Restore ──────────────────────────────────────────────────────────────────
STOPPED=()
stop_once() {
    local c="$1"
    [ -z "$c" ] && return 0
    case " ${STOPPED[*]:-} " in *" $c "*) return 0 ;; esac
    if docker inspect "$c" >/dev/null 2>&1; then
        log "Stoppe Container $c..."; docker stop "$c" >/dev/null || true
        STOPPED+=("$c")
    fi
}

for key in "${VOLS[@]}"; do
    vol="$(resolve_volume "$key")"
    if [ -z "$vol" ]; then err "Volume '*_${key}' nicht gefunden – ueberspringe."; continue; fi
    stop_once "$(container_for "$key")"
    log "Restore $key -> $vol ..."
    docker run --rm \
        -v "${vol}:/data" \
        -v "${SRC}:/backup:ro" \
        alpine:latest \
        sh -c "rm -rf /data/* /data/..?* /data/.[!.]* 2>/dev/null; tar -xzf /backup/${key}.tar.gz -C /data"
    ok "$key wiederhergestellt."
done

# ── Container wieder starten ─────────────────────────────────────────────────
for c in "${STOPPED[@]:-}"; do
    [ -z "$c" ] && continue
    log "Starte Container $c..."; docker start "$c" >/dev/null || err "Konnte $c nicht starten."
done

ok "Restore fertig."
