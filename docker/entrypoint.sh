#!/bin/sh
set -e

# Erstinitialisierung: SQLite-Datei + Migrations + Cache.
# Idempotent — laeuft bei jedem Start, macht aber nur Arbeit wenn was fehlt.

cd /var/www/html

mkdir -p var/data var/cache var/log
chown -R 1000:1000 var/

if [ ! -f var/data.db ]; then
    echo "[entrypoint] var/data.db fehlt – lege SQLite-DB an"
    su-exec 1000:1000 touch var/data.db || touch var/data.db
fi

# Migrations ausfuehren (no-op wenn nichts ansteht)
su-exec 1000:1000 php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration 2>/dev/null || \
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# PHP-FPM im Background, nginx im Foreground (PID 1 fuer Signale)
php-fpm -D
exec nginx
