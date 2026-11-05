#!/bin/sh
# Run via docker compose exec app sh scripts/check-dev-startup.sh after startup.
set -eu
snapshot=$(mktemp)
trap 'rm -f "$snapshot" "$snapshot.after"' EXIT HUP INT TERM

snapshot_dependencies() {
    stat -c '%n %i %y %z' bootstrap/cache/packages.php bootstrap/cache/services.php \
        vendor/autoload.php node_modules/.package-lock.json
}

snapshot_dependencies > "$snapshot"
INSTALL_DEPENDENCIES=1 dev-entrypoint true &
first=$!
INSTALL_DEPENDENCIES=1 dev-entrypoint true &
second=$!
wait "$first"
wait "$second"
snapshot_dependencies > "$snapshot.after"
diff -u "$snapshot" "$snapshot.after"
echo 'PASS unchanged concurrent entrypoints leave dependencies and Laravel manifests untouched'
