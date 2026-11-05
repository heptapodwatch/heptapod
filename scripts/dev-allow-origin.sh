#!/usr/bin/env bash
# Allow a review URL's hostname in this checkout's development Reverb configuration.
set -euo pipefail
checkout=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd -P)
cd "$checkout"
# shellcheck source=scripts/dev-stack-lib.sh
. ./scripts/dev-stack-lib.sh
case "${1:-}" in
    --help|-h)
        echo 'Usage: scripts/dev-allow-origin.sh <http(s)://hostname[:port]>'
        echo 'Adds the hostname to .env and refreshes Reverb on a running local dev stack.'
        exit 0 ;;
esac
[ "$#" -eq 1 ] || die 'Usage: scripts/dev-allow-origin.sh <http(s)://hostname[:port]>'
[[ $1 =~ ^https?://([a-zA-Z0-9.-]+)(:[0-9]+)?/?$ ]] || die 'use an HTTP(S) origin without credentials, path, query or fragment'
host=${BASH_REMATCH[1]}
[ "$(env_value APP_ENV)" = local ] && [ ! -e .env.prod ] || die 'requires a local dev checkout without .env.prod'
validate_app_origin "$(env_value APP_URL)" "$(env_value APP_PORT)" >/dev/null
require_owned_stack
require_checkout_source
# Shell overrides would hide the .env change from Compose, leaving Reverb on the old host list.
[ "${REVERB_APP_ALLOWED_ORIGINS+x}" != x ] || die 'unset REVERB_APP_ALLOWED_ORIGINS in the shell; this script manages .env'
origin_key='^[[:space:]]*(export[[:space:]]+)?REVERB_APP_ALLOWED_ORIGINS[[:space:]]*[:=]'
mentions=$(grep -cE "$origin_key" .env || [ "$?" = 1 ])
[ "$mentions" -le 1 ] || die 'duplicate REVERB_APP_ALLOWED_ORIGINS in .env; remove duplicates first'
# Compose owns dotenv quoting/interpolation; do not treat a quoted value as a hostname.
origins=$(docker compose config --format json | jq -er '.services.reverb.environment.REVERB_APP_ALLOWED_ORIGINS')
if ! tr ',' '\n' <<<"$origins" | sed 's/^[[:space:]]*//; s/[[:space:]]*$//' | grep -qxF "$host"; then
    tmp=$(mktemp)
    trap 'rm -f "$tmp"' EXIT
    awk -v key="$origin_key" '$0 !~ key' .env > "$tmp"
    printf 'REVERB_APP_ALLOWED_ORIGINS="%s%s%s"\n' "$origins" "${origins:+,}" "$host" >> "$tmp"
    cat "$tmp" > .env
fi
# Compose recreates only if its effective environment changed; repeated calls do not restart Reverb.
docker compose up -d --no-deps --wait --wait-timeout 120 reverb
echo "Reverb allowed host: $host"
