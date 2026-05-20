#!/bin/sh
set -e

# var/data ist Volume (od_admin_data), var/cache und var/log sind tmpfs
# (siehe compose.yml). Alles andere ist read-only.
# Daher KEINE chown-Operationen hier – Permissions sind im Build-Step gesetzt
# und tmpfs-Mounts werden von Docker mit dem richtigen User bereitgestellt.

cd /var/www/html

# Volume-Permission-Check: ein frisch angelegtes Docker-Volume kann je nach
# Volume-Treiber root:root als Besitzer haben, auch wenn das Image den
# Mount-Point mit 1000:1000 hatte. PDO_SQLite braucht Write auf das
# Verzeichnis (Journal/WAL-Dateien) – wenn das nicht geht, sind die
# Folge-Fehler kryptisch ("unable to open database file"). Hier hart pruefen.
if [ ! -w var/data ]; then
    echo "[entrypoint] FEHLER: var/data ist nicht beschreibbar fuer uid $(id -u)."
    echo "[entrypoint] Volume neu anlegen:"
    echo "[entrypoint]   docker compose down"
    echo "[entrypoint]   docker volume rm od-admin_od_admin_data"
    echo "[entrypoint]   docker compose up -d"
    ls -la var/data/ 2>&1
    exit 1
fi

if [ ! -f var/data/app.db ]; then
    echo "[entrypoint] var/data/app.db fehlt – lege SQLite-DB an"
    touch var/data/app.db
fi

# Cache & Logs liegen im tmpfs (frisch bei jedem Start) – warmup hier laufen
# lassen, weil der build-time-warmup vom tmpfs-Mount ueberschrieben wird.
echo "[entrypoint] Symfony Cache warmup..."
php bin/console cache:warmup --no-interaction || {
    echo "[entrypoint] cache:warmup fehlgeschlagen – Container wird trotzdem gestartet"
}

# Migrations idempotent ausfuehren (no-op wenn nichts ansteht)
echo "[entrypoint] Doctrine Migrations..."
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration || {
    echo "[entrypoint] Migrations fehlgeschlagen – Container wird trotzdem gestartet"
}

# PHP-FPM im Hintergrund, nginx im Vordergrund (PID 1 fuer Signale)
echo "[entrypoint] Starte php-fpm + nginx..."
php-fpm -D
exec nginx
