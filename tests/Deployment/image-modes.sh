#!/usr/bin/env bash
# Build from restrictive checkout modes, then check the image as its runtime user.
set -euo pipefail
root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$root"

case "${1:-}" in
    prepare)
        context=${2:?Usage: image-modes.sh prepare NEW_CONTEXT_DIRECTORY}
        (
            umask 077
            mkdir "$context"
            # Copy tracked working-tree contents, including the candidate's local edits.
            git ls-files -z | tar --null -T - -cf - | tar --no-same-permissions -xf - -C "$context"
            printf 'image-modes exclusion sentinel\n' > "$context/.env.image-modes"
            printf 'image-modes exclusion sentinel\n' > "$context/docker/.env.image-modes"
        )
        test "$(stat -c %a "$context/docker/lighthouse/package.json")" = 600
        test "$(stat -c %a "$context/docker/prod/php.ini")" = 600
        test "$(stat -c %a "$context/public")" = 700
        test "$(stat -c %a "$context/artisan")" = 700
        stat -c '%a %n' "$context/docker/lighthouse/package.json" "$context/docker/prod/php.ini" "$context/public" "$context/artisan"
        echo 'PASS: build context has umask-077 checkout modes'
        ;;
    app)
        image=${2:?Usage: image-modes.sh app IMAGE}
        docker run --rm --user www-data --entrypoint sh "$image" -eu -c '
            test "$(id -un)" = www-data
            for file in /usr/local/etc/php/conf.d/prod.ini /app/composer.json /app/public/index.php /app/bootstrap/app.php /app/docker/prod/supervisord.conf /etc/caddy/Caddyfile; do
                stat -c "%a %n" "$file"
                test "$(stat -c %a "$file")" = 644
                test -r "$file"
                head -c 1 "$file" >/dev/null
            done
            for directory in /app /app/public /app/public/build /app/bootstrap /app/config; do
                stat -c "%a %n" "$directory"
                test "$(stat -c %a "$directory")" = 755
                test -r "$directory"
                test -x "$directory"
            done
            for executable in /app/artisan /app/docker/prod/*.sh; do
                stat -c "%a %n" "$executable"
                test "$(stat -c %a "$executable")" = 755
                test -r "$executable"
                test -x "$executable"
            done
            test ! -e /app/.env.image-modes
            test ! -e /app/docker/.env.image-modes
            echo "PASS: app files readable and entrypoints executable by www-data; .env sentinels excluded"
        '
        ;;
    lighthouse)
        image=${2:?Usage: image-modes.sh lighthouse IMAGE}
        docker run --rm --user node --entrypoint sh "$image" -eu -c '
            test "$(id -un)" = node
            for file in /service/package.json /service/package-lock.json /service/server.mjs /service/audit.mjs /service/browser.mjs /service/diagnostics.mjs /config/credential-parameters.json; do
                stat -c "%a %n" "$file"
                test "$(stat -c %a "$file")" = 644
                test -r "$file"
                head -c 1 "$file" >/dev/null
            done
            test "$(stat -c %a /service)" = 755
            test "$(stat -c %a /config)" = 755
            test -r /service
            test -x /service
            test "$(stat -c %a /usr/local/bin/lighthouse-proxy)" = 755
            test -x /usr/local/bin/lighthouse-proxy
            node --input-type=module -e '\''await import("lighthouse"); await import("/service/browser.mjs"); await import("/service/diagnostics.mjs"); console.log("PASS: Lighthouse runtime dependencies import as node")'\''
            echo "PASS: Lighthouse server files readable and proxy executable by node"
        '
        ;;
    *)
        echo 'Usage: image-modes.sh {prepare NEW_CONTEXT_DIRECTORY|app IMAGE|lighthouse IMAGE}' >&2
        exit 2
        ;;
esac
