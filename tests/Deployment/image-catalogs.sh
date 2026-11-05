#!/bin/sh
# Compare the checkout catalog inventory with the real production image, without a source mount.
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$root"
: "${APP_IMAGE:?Set APP_IMAGE to the built production image}"
set -- lang/*/*.json
[ -f "$1" ]
docker run --rm --entrypoint sh "$APP_IMAGE" -eu -c '
    missing=0
    for catalog do
        if [ ! -f "/app/$catalog" ]; then
            echo "Missing production catalog: $catalog" >&2
            missing=1
        fi
    done
    test "$missing" = 0
    test ! -e /app/auth.json
    echo "PASS: all $# source language catalogs shipped; root Composer auth.json excluded"
' sh "$@"
