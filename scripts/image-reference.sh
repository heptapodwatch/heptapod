#!/bin/sh
# Read the single published-image default from the production Compose model, without starting Docker.
# Overrides remain Compose's responsibility; CI publishes to the default registry, never an operator override.
set -eu
case "${1:-}" in
    app) key=app ;;
    lighthouse) key=lighthouse ;;
    *) echo 'Usage: scripts/image-reference.sh <app|lighthouse>' >&2; exit 2 ;;
esac
root=$(CDPATH='' cd -- "$(dirname "$0")/.." && pwd)
reference=$(sed -nE "s|^x-$key-image: &$key-image \\$\\{[A-Z_]+:-([^}]+)\\}$|\\1|p" "$root/compose.prod.yaml")
if [ "$(printf '%s\n' "$reference" | wc -l)" -ne 1 ] || ! printf '%s\n' "$reference" | grep -Eq '^ghcr\.io/[a-z0-9_-]+/[a-z0-9_-]+:main$'; then
    echo "Invalid or missing x-$key-image default in compose.prod.yaml" >&2
    exit 1
fi
printf '%s\n' "${reference%:main}"
