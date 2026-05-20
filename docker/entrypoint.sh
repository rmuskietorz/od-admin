#!/bin/sh
set -e

# var/data ist Volume (od_admin_data), var/cache und var/log sind tmpfs
# (siehe compose.yml). Alles andere ist read-only.
# Daher KEINE chown-Operationen hier – Permissions sind im Build-Step gesetzt
# und tmpfs-Mounts werden von Docker mit dem richtigen User bereitgestellt.

cd /var/www/html

# Volume-Permission-Diagnose: ein frisch angelegtes Docker-Volume kann je
# nach Volume-Treiber root:root als Besitzer haben, auch wenn das Image den
# Mount-Point mit 1000:1000 hatte. PDO_SQLite braucht Write auf das
# Verzeichnis UND die Datei (Journal/WAL/SHM-Files daneben).
echo "[entrypoint] Diagnose var/data:"
echo "[entrypoint]   ls -la var/data/:"
ls -la var/data/ 2>&1 | sed 's/^/[entrypoint]     /'
echo "[entrypoint]   id: $(id)"

# Harter Write-Test: kann uid 1000 in var/data schreiben?
if ! touch var/data/.write_test 2>/dev/null; then
    echo "[entrypoint] FEHLER: var/data ist nicht beschreibbar fuer uid $(id -u)."
    echo "[entrypoint] Das Volume hat falsche Permissions. Reset:"
    echo "[entrypoint]   ./helper.sh 17"
    exit 1
fi
rm -f var/data/.write_test

# Sqlite-Test: kann uid 1000 eine SQLite-DB anlegen + schreiben?
if ! sqlite3 var/data/.sqlite_test.db "CREATE TABLE t(x); INSERT INTO t VALUES (1);" 2>/dev/null; then
    echo "[entrypoint] FEHLER: sqlite3 kann nicht in var/data schreiben."
    echo "[entrypoint] Vermutlich Permissions auf Subfile-Ebene. Reset:"
    echo "[entrypoint]   ./helper.sh 17"
    rm -f var/data/.sqlite_test.db
    exit 1
fi
rm -f var/data/.sqlite_test.db
echo "[entrypoint]   SQLite-Write-Test OK"

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
