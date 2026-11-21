FROM node:26-bookworm-slim@sha256:5df79989ed685e09f0a98019db4cebfb22fb9df1a53c0161664a011f7ff6b0f1 AS node
FROM composer:2@sha256:50ea4d33fdcde1adb0f045547295faa1548b33872e5f789dae5b02ce2fb5de93 AS composer
FROM dunglas/frankenphp:1.13.6-php8.5.13@sha256:17ff6554ba6de527d17aa5ec37e2a3bc3b182d7dbea9362f9a02eb5b4a1c4b95 AS php

COPY --chmod=0644 docker/php-extensions.txt /usr/local/etc/php/extensions.txt

# Distro security updates must not be blocked by stale exact apt pins.
# A daily cache key makes cached builds pick up new Debian security updates (as in the Lighthouse image).
ARG APT_SNAPSHOT_DATE
# hadolint ignore=DL3008
RUN if [ -z "$APT_SNAPSHOT_DATE" ]; then echo 'APT_SNAPSHOT_DATE is required; set it to the current UTC date (YYYY-MM-DD)' >&2; exit 1; fi \
    && apt-get update \
    && apt-get upgrade -y \
    && apt-get install -y --no-install-recommends curl dnsutils postgresql-client procps supervisor tini unzip \
    && rm -rf /var/lib/apt/lists/* \
    && awk 'NF && $1 !~ /^#/ && $1 != "pcov" { print $1 }' /usr/local/etc/php/extensions.txt > /tmp/php-extensions \
    && xargs install-php-extensions < /tmp/php-extensions \
    && rm /tmp/php-extensions
# pcov is the dev/CI coverage extension, never installed in the production stages.
WORKDIR /app

FROM php AS dependencies
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY . .
# A strict checkout umask must not make source files private inside the image.
RUN chmod -R u=rwX,go=rX /app \
    && chmod 0755 artisan docker/prod/*.sh \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Wayfinder's Vite plugin invokes PHP, so the asset builder needs the same PHP extensions.
FROM dependencies AS assets
COPY --from=node /usr/local/ /usr/local/
RUN npm ci --ignore-scripts && npm run build

FROM php AS runtime
COPY --from=dependencies --chown=www-data:www-data /app /app
COPY --from=assets --chown=www-data:www-data /app/public/build /app/public/build
COPY --chmod=0644 docker/prod/Caddyfile /etc/caddy/Caddyfile
COPY --chmod=0644 docker/prod/php.ini /usr/local/etc/php/conf.d/prod.ini
# High ports need no bind capability; inherited file caps conflict with cap_drop: ALL.
RUN mkdir -p /backups /data/caddy /config/caddy storage/app/private storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data /backups /data /config /app/storage /app/bootstrap/cache \
    && ln -s /app/storage/app/public /app/public/storage \
    && setcap -r /usr/local/bin/frankenphp
ARG HEPTAPOD_VERSION=unknown
ARG OCI_SOURCE=unknown
ARG OCI_REVISION=unknown
ARG OCI_CREATED
LABEL com.heptapod.component=app \
    org.opencontainers.image.title="Heptapod" \
    org.opencontainers.image.vendor="Heptapod contributors" \
    org.opencontainers.image.url=$OCI_SOURCE \
    org.opencontainers.image.description="Self-hosted monitoring application" \
    org.opencontainers.image.source=$OCI_SOURCE \
    org.opencontainers.image.revision=$OCI_REVISION \
    org.opencontainers.image.version=$HEPTAPOD_VERSION \
    org.opencontainers.image.licenses="AGPL-3.0-or-later" \
    org.opencontainers.image.created=$OCI_CREATED
ENV HEPTAPOD_VERSION=$HEPTAPOD_VERSION
# Image ENV also reaches administrative commands started by docker compose exec.
ENV APP_ENV=production APP_DEBUG=false SERVER_NAME=http://localhost:8080 XDG_DATA_HOME=/data XDG_CONFIG_HOME=/config \
    LARAVEL_STORAGE_PATH=/tmp/heptapod/storage \
    APP_PACKAGES_CACHE=/tmp/heptapod/packages.php \
    APP_SERVICES_CACHE=/tmp/heptapod/services.php \
    APP_CONFIG_CACHE=/tmp/heptapod/config.php \
    APP_ROUTES_CACHE=/tmp/heptapod/routes.php \
    APP_EVENTS_CACHE=/tmp/heptapod/events.php \
    APP_MAINTENANCE_DRIVER=cache APP_MAINTENANCE_STORE=redis
USER 33:33
ENTRYPOINT ["/usr/bin/tini", "-s", "--", "sh", "/app/docker/prod/entrypoint.sh"]
EXPOSE 8080 8443
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 CMD ["sh", "/app/docker/prod/healthcheck.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
