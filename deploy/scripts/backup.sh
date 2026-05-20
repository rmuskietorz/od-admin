#!/bin/bash
# Backup-Skript fuer od-admin + open-design Volumes.
# Sichert: SQLite User-DB, OD-Projekte/Artefakte, Claude-Credentials.
#
# Usage:
#   ./backup.sh [target-dir]
#
# Default target: /var/backups/od-admin/<timestamp>/

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TARGET_BASE="${1:-/var/backups/od-admin}"
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

backup_volume od_admin_data       "${TARGET}/od_admin_data.tar.gz"
backup_volume open_design_data    "${TARGET}/open_design_data.tar.gz"
backup_volume claude_home         "${TARGET}/claude_home.tar.gz"

# Optional: Compose-Files mitsichern, damit Rollback Zustand komplett ist
log "Sichere Compose-Files..."
if [ -d /opt/od-admin ]; then
    tar -czf "${TARGET}/od-admin-config.tar.gz" \
        -C /opt/od-admin \
        --exclude='var' --exclude='vendor' --exclude='node_modules' \
        compose.yml .env .env.local docker/ 2>/dev/null || true
fi
if [ -d /opt/open-design/deploy ]; then
    tar -czf "${TARGET}/open-design-config.tar.gz" \
        -C /opt/open-design/deploy \
        $(ls /opt/open-design/deploy/*.yml /opt/open-design/deploy/.env.claude-cli 2>/dev/null | xargs -n1 basename) 2>/dev/null || true
fi

# Retention: Backups aelter als 30 Tage loeschen
log "Loesche Backups aelter als 30 Tage..."
find "${TARGET_BASE}" -maxdepth 1 -type d -name "????????-??????" -mtime +30 -exec rm -rf {} \;

ok "Backup fertig: ${TARGET}"
du -sh "${TARGET}"
