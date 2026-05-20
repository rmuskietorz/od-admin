# shellcheck shell=bash
# Docker / compose Aliase fuer od-admin. Sourced vom Helper.

COMPOSE="docker compose"
SERVICE="od-admin"
APP="$COMPOSE exec -u 1000:1000 ${SERVICE}"
APP_TTY="$COMPOSE exec -it -u 1000:1000 ${SERVICE}"
CONSOLE="$APP php bin/console"

compose_is_up() {
    [ -n "$($COMPOSE ps -q 2>/dev/null)" ]
}
