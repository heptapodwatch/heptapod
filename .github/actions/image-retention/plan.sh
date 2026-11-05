#!/usr/bin/env bash
# Print a plan even when unreadable manifests make it unsafe; return non-zero in that case.
set -euo pipefail
if [ "$#" -ne 2 ]; then
    echo 'Usage: plan.sh <collected-package-directory> <now-unix-seconds>' >&2
    exit 2
fi
input=$1
now=$2
root=$(cd -- "$(dirname -- "$0")" && pwd)
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
jq -er '.[].name' "$input/versions.json" > "$tmp/digests"
while IFS= read -r digest; do
    if [[ ! "$digest" =~ ^sha256:[0-9a-f]{64}$ ]]; then
        echo "Invalid package version digest: $digest" >&2
        exit 1
    fi
    file="$input/manifests/$digest.json"
    if [ -f "$file" ] && jq -e '
        def digest: type == "string" and length > 0;
        def descriptor:
            type == "object" and (.digest | digest) and (.size | type == "number" and . >= 0);
        .schemaVersion == 2 and
        ((has("subject") | not) or (.subject.digest | digest)) and
        (if has("manifests") then (.manifests | type == "array" and all(.[]; descriptor))
         else (.config | descriptor) and (.layers | type == "array" and all(.[]; descriptor)) end)
    ' "$file" > /dev/null; then
        jq -c --arg digest "$digest" '{key: $digest, value: .}' "$file"
    else
        jq -nc --arg digest "$digest" '{key: $digest, value: null}'
    fi
done < "$tmp/digests" > "$tmp/entries"
jq -s 'from_entries' "$tmp/entries" > "$tmp/manifests.json"
jq -n --slurpfile versions "$input/versions.json" --slurpfile manifests "$tmp/manifests.json" \
    --rawfile main_shas "$input/main-shas.txt" --arg package "$(basename -- "$input")" \
    --argjson now "$now" -f "$root/plan.jq" > "$tmp/plan.json"
cat "$tmp/plan.json"
if ! jq -e '.safe' "$tmp/plan.json" > /dev/null; then
    echo "Unreadable manifests: plan blocked for $(basename -- "$input"); delete set is empty." >&2
    exit 1
fi
