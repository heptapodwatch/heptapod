#!/usr/bin/env bash
# Hash the saved config bytes: Docker's image .Id can identify a manifest instead.
set -euo pipefail
if [ "$#" -ne 1 ] || [ -z "$1" ]; then
    echo 'Usage: scripts/image-config-digest.sh <image-reference>' >&2
    exit 2
fi
temporary=$(mktemp -d)
trap 'rm -rf "$temporary"' EXIT
docker image save --output "$temporary/image.tar" "$1"
config=$(tar -xOf "$temporary/image.tar" manifest.json | jq -er '
    if type == "array" and length == 1 then .[0].Config
    else error("Expected exactly one saved image") end
    | if type == "string" and length > 0 then .
    else error("Missing saved image config") end')
tar -xOf "$temporary/image.tar" -- "$config" > "$temporary/config.json"
sha256sum "$temporary/config.json" | awk '{ print "sha256:" $1 }'
