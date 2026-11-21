FROM node:26-bookworm-slim@sha256:5df79989ed685e09f0a98019db4cebfb22fb9df1a53c0161664a011f7ff6b0f1 AS node
FROM composer:2@sha256:50ea4d33fdcde1adb0f045547295faa1548b33872e5f789dae5b02ce2fb5de93 AS composer
FROM dunglas/frankenphp:1.13.6-php8.5.13@sha256:17ff6554ba6de527d17aa5ec37e2a3bc3b182d7dbea9362f9a02eb5b4a1c4b95

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
