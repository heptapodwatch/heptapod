#!/usr/bin/env bash
# Read-only collection. A failed manifest is retained as unreadable so plan.sh can report it.
set -euo pipefail
if [ "$#" -ne 2 ]; then
    echo 'Usage: collect.sh <app|lighthouse> <output-root>' >&2
    exit 2
fi
: "${GITHUB_ACTOR:?GITHUB_ACTOR is required}"
: "${GITHUB_TOKEN:?GITHUB_TOKEN is required}"
reference=$(bash scripts/image-reference.sh "$1")
name=${reference#ghcr.io/}
owner=${name%%/*}
package=${name#*/}
owner_type=$(gh api "/users/$owner" | jq -er '.type')
case "$owner_type" in
    Organization) owner_endpoint=orgs ;;
    User) owner_endpoint=users ;;
    *) echo "Unknown package owner type for $owner: $owner_type" >&2; exit 1 ;;
esac
output="$2/$package"
mkdir -p "$output/manifests"
gh api --paginate --slurp "/$owner_endpoint/$owner/packages/container/$package/versions?per_page=100" \
    | jq 'add' > "$output/versions.json"
git rev-list --first-parent origin/main > "$output/main-shas.txt"
accept='application/vnd.oci.image.index.v1+json, application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.docker.distribution.manifest.v2+json'
registry_url=${RETENTION_REGISTRY_URL:-https://ghcr.io}
token_url=${RETENTION_TOKEN_URL:-https://ghcr.io/token}
token=$(curl --fail-with-body --silent --show-error --user "$GITHUB_ACTOR:$GITHUB_TOKEN" \
    --get --data-urlencode "scope=repository:$name:pull" "$token_url" | jq -er '.token')
headers=$(curl --fail --silent --show-error --head -H "Authorization: Bearer $token" \
    -H "Accept: $accept" "$registry_url/v2/$name/manifests/main")
registry_main=$(printf '%s\n' "$headers" | awk 'tolower($1) == "docker-content-digest:" {gsub("\r", "", $2); print $2}')
api_main=$(jq -er '[.[] | select(.metadata.container.tags | index("main")) | .name] |
    if length == 1 then .[0] else error("expected exactly one main version") end' "$output/versions.json")
if [ "$registry_main" != "$api_main" ]; then
    echo "main digest mismatch for $name: registry=$registry_main API=$api_main" >&2
    exit 1
fi
jq -er '.[].name' "$output/versions.json" > "$output/digests.txt"
: > "$output/unreadable.txt"
while IFS= read -r digest; do
    if [[ ! "$digest" =~ ^sha256:[0-9a-f]{64}$ ]]; then
        echo "Invalid package version digest: $digest" >&2
        exit 1
    fi
    file="$output/manifests/$digest.json"
    # Never reuse a body from an earlier collection, including on a failed fetch.
    rm -f "$file" "$file.partial"
    if curl --fail --silent --show-error -H "Authorization: Bearer $token" -H "Accept: $accept" \
        "$registry_url/v2/$name/manifests/$digest" -o "$file.partial"; then
        actual="sha256:$(sha256sum "$file.partial" | cut -d ' ' -f 1)"
        if [ "$actual" = "$digest" ]; then
            mv "$file.partial" "$file"
        else
            echo "::error::Manifest digest mismatch for $name@$digest: got $actual"
            printf '%s\n' "$digest" >> "$output/unreadable.txt"
            rm -f "$file.partial"
        fi
    else
        echo "::error::Unreadable manifest $name@$digest"
        printf '%s\n' "$digest" >> "$output/unreadable.txt"
        rm -f "$file.partial"
    fi
done < "$output/digests.txt"
