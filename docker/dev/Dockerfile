FROM node:24-bookworm-slim AS node
FROM composer:2 AS composer
FROM dunglas/frankenphp:1.13.5-php8.5.12@sha256:7e73a27eb7712a5b4073b92f8f0dec91e9400138604b76cc791589faaa6a639c

COPY docker/php-extensions.txt /usr/local/etc/php/extensions.txt

# Dev packages track distro security updates; exact apt pins go stale in Debian archives.
# hadolint ignore=DL3008
RUN apt-get update \
    && apt-get upgrade -y \
    && apt-get install -y --no-install-recommends aspell aspell-en curl dnsutils git postgresql-client unzip \
    && rm -rf /var/lib/apt/lists/* \
    && awk 'NF && $1 !~ /^#/ { print $1 }' /usr/local/etc/php/extensions.txt > /tmp/php-extensions \
    && xargs install-php-extensions < /tmp/php-extensions \
    && rm /tmp/php-extensions

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=node /usr/local/ /usr/local/

# Pest uses Playwright's protocol, not the separate browser service's CDP endpoint.
ENV PLAYWRIGHT_BROWSERS_PATH=/opt/playwright
WORKDIR /tmp/browser-tools
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts \
    && npx playwright install --with-deps --only-shell chromium \
    && npm cache clean --force \
    && rm -rf /tmp/browser-tools /var/lib/apt/lists/*

WORKDIR /workspace
COPY docker/dev/Caddyfile /etc/caddy/Caddyfile
COPY docker/dev/php.ini /usr/local/etc/php/conf.d/dev.ini
COPY docker/dev/entrypoint.sh /usr/local/bin/dev-entrypoint
RUN chmod +x /usr/local/bin/dev-entrypoint

ENV COMPOSER_ALLOW_SUPERUSER=1
ENTRYPOINT ["dev-entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

# Read by scripts/dev-up.sh to refuse an image built from older inputs. Last, so a new hash rebuilds only this layer.
ARG DEV_IMAGE_INPUT_HASH=
LABEL heptapod.dev-input-hash="$DEV_IMAGE_INPUT_HASH"
