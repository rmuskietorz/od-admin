# od-admin: PHP-FPM 8.3 + nginx + Symfony 7.
# Single-Container Setup mit s6-overlay-light Pattern: tini startet nginx + php-fpm
# via einem winzigen Wrapper-Script. Fuer dieses Admin-Tool ausreichend, kein
# Multi-Service-Container Overhead.

ARG PHP_VERSION=8.3

FROM composer:2 AS composer

FROM php:${PHP_VERSION}-fpm-alpine AS base

# System-Dependencies + PHP-Extensions
RUN apk add --no-cache \
        nginx \
        tini \
        bash \
        sqlite \
        sqlite-libs \
        sqlite-dev \
        icu-libs \
        icu-dev \
        oniguruma-dev \
        libzip-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-install -j$(nproc) \
        pdo_sqlite \
        intl \
        opcache \
    && apk del --no-cache $PHPIZE_DEPS icu-dev oniguruma-dev libzip-dev sqlite-dev \
    && rm -rf /var/cache/apk/*

# PHP-Konfig (Production).
# Hinweis: opcache.preload wird bewusst NICHT gesetzt – fuer ein Admin-Panel
# mit niedriger Last bringt das keinen messbaren Vorteil und macht den
# composer-install-Step im Build kaputt, weil config/ zu dem Zeitpunkt
# noch nicht im Container liegt.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=1'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.max_accelerated_files=10000'; \
        echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/opcache.ini \
    && { \
        echo 'memory_limit=256M'; \
        echo 'expose_php=Off'; \
        echo 'date.timezone=Europe/Berlin'; \
    } > /usr/local/etc/php/conf.d/zz-app.ini

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Composer-Deps zuerst (Layer-Cache)
COPY composer.json composer.lock* symfony.lock* ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader \
    && composer clear-cache

# Quellcode
COPY config ./config
COPY public ./public
COPY src ./src
COPY templates ./templates
COPY migrations ./migrations
COPY bin ./bin
COPY .env ./.env

# Cache warmen, Permissions setzen
RUN mkdir -p var/cache var/log var/data \
    && composer dump-autoload --no-dev --optimize --classmap-authoritative \
    && chown -R 1000:1000 var/ public/

# Hinweis: cache:warmup laeuft NICHT hier sondern beim Container-Start
# (entrypoint.sh). Grund: var/cache wird zur Laufzeit per tmpfs gemountet
# wegen read_only:true – ein build-time-Warmup wuerde ueberschrieben.

# nginx-Konfig + Entrypoint
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh \
    && mkdir -p /run/nginx /var/lib/nginx/tmp /var/log/nginx \
    # Alpine-nginx hat /var/lib/nginx/logs als compile-time-default und
    # versucht dort beim Start zu oeffnen, bevor es die conf liest.
    # Symlink auf den tatsaechlichen Log-Pfad (tmpfs, beschreibbar fuer 1000).
    && rm -rf /var/lib/nginx/logs \
    && ln -sf /var/log/nginx /var/lib/nginx/logs \
    && chown -R 1000:1000 /run/nginx /var/lib/nginx /var/log/nginx

EXPOSE 8080

ENTRYPOINT ["/sbin/tini", "--", "/entrypoint.sh"]
