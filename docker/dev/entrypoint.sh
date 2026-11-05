#!/bin/sh
set -eu

# Only app installs dependencies; its healthcheck gates the other PHP/Node services.
if [ "${INSTALL_DEPENDENCIES:-0}" = "1" ]; then
    (
        # App recreations and one-offs share this checkout. Never install concurrently.
        flock 9
        # A fresh named cache volume shadows the tracked ignore file.
        if [ ! -e bootstrap/cache/.gitignore ] && [ ! -L bootstrap/cache/.gitignore ]; then
            printf '%s\n' '*' '!.gitignore' > bootstrap/cache/.gitignore
        fi
        composer_inputs=$(sha256sum composer.json composer.lock addons/*/composer.json)
        composer_stamp=vendor/.dev-composer-inputs
        if [ ! -f vendor/autoload.php ] || [ ! -f "$composer_stamp" ] || [ "$(cat "$composer_stamp")" != "$composer_inputs" ]; then
            rm -f "$composer_stamp"
            composer install --no-interaction --prefer-dist
            printf '%s\n' "$composer_inputs" > "$composer_stamp"
        fi

        npm_inputs=$(sha256sum package.json package-lock.json)
        npm_stamp=node_modules/.dev-npm-inputs
        if [ ! -f node_modules/.package-lock.json ] || [ ! -f "$npm_stamp" ] || [ "$(cat "$npm_stamp")" != "$npm_inputs" ]; then
            rm -f "$npm_stamp"
            npm ci
            printf '%s\n' "$npm_inputs" > "$npm_stamp"
        fi
        # HTTP health gates workers; do not serve a fresh checkout before its schema exists.
        php artisan migrate --force --no-interaction
        php artisan heptapod:reclassify-links --policy=1 --no-interaction
        php artisan storage:link --no-interaction
        php artisan heptapod:notifications:attention --no-interaction
    ) 9> bootstrap/cache/.dev-dependencies.lock
fi

exec "$@"
