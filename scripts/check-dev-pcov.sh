#!/bin/sh
# Run via docker compose exec app sh scripts/check-dev-pcov.sh after startup.
set -eu

php -r '
if (!extension_loaded("pcov") || ini_get("pcov.enabled") !== "1") {
    fwrite(STDERR, "FAIL CLI coverage must remain enabled\n");
    exit(1);
}
echo "PASS CLI pcov.enabled=1\n";
'

probe=$(mktemp public/runtime-pcov-probe.XXXXXX.php)
trap 'rm -f "$probe"' EXIT HUP INT TERM
printf '%s\n' '<?php header("Content-Type: text/plain"); echo ini_get("pcov.enabled");' > "$probe"
web_value=$(curl -fsS "http://127.0.0.1/$(basename "$probe")")
if [ "$web_value" != '0' ]; then
    echo "FAIL WEB coverage must be disabled; got $web_value" >&2
    exit 1
fi
echo 'PASS WEB pcov.enabled=0'
