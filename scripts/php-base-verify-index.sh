#!/usr/bin/env bash
# Check registry bytes against the record before any moving tag is changed.
set -euo pipefail
if [ "$#" -ne 1 ]; then
    echo 'Usage: php-base-verify-index.sh <record.json>' >&2
    exit 2
fi
root=$(cd -- "$(dirname -- "$0")/.." && pwd)
record=$1
jq -e -f "$root/scripts/php-base-record.jq" "$record" > /dev/null
reference=$(jq -er '.reference' "$record")
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
for target in runtime coverage; do
    jq --arg target "$target" '.indexes[] | select(.target == $target)' "$record" > "$work/target.json"
    digest=$(jq -er '.index_digest' "$work/target.json")
    docker buildx imagetools inspect "$reference@$digest" --raw > "$work/index.json"
    observed=$(docker buildx imagetools inspect "$reference@$digest" --format '{{json .Manifest}}' | jq -er '.digest')
    test "$observed" = "$digest"
    jq -e --slurpfile index "$work/index.json" '
        ($index[0].mediaType == "application/vnd.oci.image.index.v1+json" or
         $index[0].mediaType == "application/vnd.docker.distribution.manifest.list.v2+json") and
        ($index[0].manifests | length) == 2 and
        ([$index[0].manifests[] | {digest, os: .platform.os, architecture: .platform.architecture}] | sort_by(.architecture)) ==
        ([.cells[] | {digest, os: "linux", architecture}] | sort_by(.architecture))' "$work/target.json" > /dev/null
    for arch in amd64 arm64; do
        child=$(jq -er --arg arch "$arch" '.cells[] | select(.architecture == $arch).digest' "$work/target.json")
        config=$(jq -er --arg arch "$arch" '.cells[] | select(.architecture == $arch).scanned_config' "$work/target.json")
        observed=$(docker buildx imagetools inspect "$reference@$child" --raw | jq -er '.config.digest')
        test "$observed" = "$config"
    done
    jq -r '.tags[].tag' "$work/target.json" > "$work/tags"
    while IFS= read -r tag; do
        observed=$(docker buildx imagetools inspect "$reference:$tag" --format '{{json .Manifest}}' | jq -er '.digest')
        test "$observed" = "$digest"
    done < "$work/tags"
done
