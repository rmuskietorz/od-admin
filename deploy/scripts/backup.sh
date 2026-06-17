#!/bin/bash
# Backup-Skript fuer od-admin + open-design Volumes.
# Sichert: SQLite User-DB, OD-Projekte/Artefakte, Claude-State.
#
# Usage:
#   ./backup.sh                         # alle Volumes
#   ./backup.sh open_design_data        # nur einzelne(s) Volume(s)
#   BACKUP_BASE=/pfad ./backup.sh ...   # Zielbasis ueberschreiben
#
# Default target: /var/backups/od-admin/<timestamp>/

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET_BASE="${BACKUP_BASE:-/var/backups/od-admin}"
ALL_KEYS="od_admin_data open_design_data claude_home"
KEYS="${*:-$ALL_KEYS}"
TS="$(date +%Y%m%d-%H%M%S)"
TARGET="${TARGET_BASE}/${TS}"

mkdir -p "${TARGET}"

# Farben
RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; CYAN='\033[0;36m'; RESET='\033[0m'
log()  { printf "${CYAN}[backup]${RESET} %s\n" "$*"; }
ok()   { printf "${GREEN}[backup]${RESET} %s\n" "$*"; }
err()  { printf "${RED}[backup]${RESET} %s\n" "$*" >&2; }

# Volumes-Backup via temporaerem Container (sicher gegen ext4-Snapshot-Hiccups)
backup_volume() {
    local vol="$1"
    local file="$2"

    if ! docker volume inspect "$vol" >/dev/null 2>&1; then
        err "Volume '$vol' existiert nicht – ueberspringe."
        return 0
    fi

    log "Sichere Volume '$vol' nach $(basename "$file")..."
    docker run --rm \
        -v "${vol}:/data:ro" \
        -v "${TARGET}:/backup" \
        alpine:latest \
        tar -czf "/backup/$(basename "$file")" -C /data .
    ok "fertig: $(du -sh "${file}" | cut -f1)"
}

# Compose praefixiert Volumes mit dem Projektnamen (open-design-claude_...,
# od-admin_...). Echten Namen per Suffix aufloesen, sonst wuerde alles
# uebersprungen.
resolve_volume() {
    local want="$1"
    if docker volume inspect "$want" >/dev/null 2>&1; then
        echo "$want"; return 0
    fi
    docker volume ls --format '{{.Name}}' | grep -E "(^|_)${want}\$" | head -1
}

log "Sichere Volumes: ${KEYS}"
for v in $KEYS; do
    real="$(resolve_volume "$v")"
    if [ -n "$real" ]; then
        backup_volume "$real" "${TARGET}/${v}.tar.gz"
    else
        err "Kein Volume '*_${v}' gefunden – ueberspringe."
    fi
done

# Compose-Files + .env mitsichern (Rollback-Zustand komplett). Pfade aus dem
# Skript-Ort + od-admin/.env ableiten, nicht /opt hartkodieren.
OD_ADMIN_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
OD_DEPLOY_DIR="$(grep -E '^HOST_OD_DEPLOY_DIR=' "${OD_ADMIN_DIR}/.env" 2>/dev/null | cut -d= -f2)"
[ -z "${OD_DEPLOY_DIR}" ] && OD_DEPLOY_DIR="$(dirname "${OD_ADMIN_DIR}")/open-design/deploy"

log "Sichere Compose-Files (od-admin: ${OD_ADMIN_DIR}, OD: ${OD_DEPLOY_DIR})..."
if [ -d "${OD_ADMIN_DIR}" ]; then
    tar -czf "${TARGET}/od-admin-config.tar.gz" \
        -C "${OD_ADMIN_DIR}" \
        --exclude='var' --exclude='vendor' --exclude='node_modules' \
        compose.yml .env .env.local docker/ 2>/dev/null || true
fi
if [ -d "${OD_DEPLOY_DIR}" ]; then
    tar -czf "${TARGET}/open-design-config.tar.gz" \
        -C "${OD_DEPLOY_DIR}" \
        $(ls "${OD_DEPLOY_DIR}"/*.yml "${OD_DEPLOY_DIR}"/.env.claude-cli 2>/dev/null | xargs -n1 basename) 2>/dev/null || true
fi

# Retention: Backups aelter als 30 Tage loeschen
log "Loesche Backups aelter als 30 Tage..."
find "${TARGET_BASE}" -maxdepth 1 -type d -name "????????-??????" -mtime +30 -exec rm -rf {} \;

ok "Backup fertig: ${TARGET}"
du -sh "${TARGET}"
