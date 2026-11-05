#!/usr/bin/env bash
# Exercise the config-digest CLI with both Docker-save config layouts, without a daemon or network.
set -euo pipefail
root=$(cd -- "$(dirname -- "$0")/../.." && pwd)
temporary=$(mktemp -d)
trap 'rm -rf "$temporary"' EXIT
mkdir -p "$temporary/bin" "$temporary/archive" "$temporary/work"
export IMAGE_SAVE_ARCHIVE="$temporary/image.tar"
cat > "$temporary/bin/docker" <<'DOCKER'
#!/usr/bin/env bash
set -euo pipefail
test "$#" -eq 5
test "$1 $2 $3 $5" = 'image save --output fixture:scanned'
cp "$IMAGE_SAVE_ARCHIVE" "$4"
DOCKER
chmod +x "$temporary/bin/docker"
export PATH="$temporary/bin:$PATH" TMPDIR="$temporary/work"
invoke() {
    bash "$root/scripts/image-config-digest.sh" fixture:scanned
}
cleaned_up() {
    test -z "$(find "$temporary/work" -mindepth 1 -print -quit)"
}
reject() {
    local name=$1
    if invoke > "$temporary/output" 2> "$temporary/error"; then
        echo "FAIL: accepted $name" >&2
        exit 1
    fi
    test ! -s "$temporary/output"
    test -s "$temporary/error"
    cleaned_up
    echo "PASS: $name fails loud without a digest or temporary files"
}
# The filename is deliberately not a digest: identity must come from the bytes.
printf '{}' > "$temporary/archive/config.json"
expected=sha256:44136fa355b3678a1146ad16f7e8649e94fb4fc21fe77e8310c060f61caaff8a
for config in config.json blobs/sha256/arbitrary-name; do
    mkdir -p "$temporary/archive/$(dirname "$config")"
    printf '{}' > "$temporary/archive/$config"
    jq -n --arg config "$config" '[{Config: $config}]' > "$temporary/archive/manifest.json"
    # Name members exactly as Docker-save does (without a leading ./).
    tar -cf "$IMAGE_SAVE_ARCHIVE" -C "$temporary/archive" manifest.json "$config"
    test "$(invoke)" = "$expected"
    cleaned_up
    echo "PASS: $config hashes the raw config bytes and cleans up"
done
for manifest in '[]' '[{"Config":"config.json"},{"Config":"config.json"}]' '[{}]' '[{"Config":""}]' '[{"Config":[]}]' '{malformed'; do
    printf '%s' "$manifest" > "$temporary/archive/manifest.json"
    tar -cf "$IMAGE_SAVE_ARCHIVE" -C "$temporary/archive" manifest.json config.json
    reject "invalid manifest $manifest"
done
printf '[{"Config":"missing.json"}]' > "$temporary/archive/manifest.json"
tar -cf "$IMAGE_SAVE_ARCHIVE" -C "$temporary/archive" manifest.json config.json
reject 'missing config bytes'
printf 'not an archive' > "$IMAGE_SAVE_ARCHIVE"
reject 'invalid saved archive'
rm "$IMAGE_SAVE_ARCHIVE"
reject 'failed image save'
if bash "$root/scripts/image-config-digest.sh" '' > "$temporary/output" 2> "$temporary/error"; then
    echo 'FAIL: accepted an empty image reference' >&2
    exit 1
fi
test ! -s "$temporary/output"
cleaned_up
echo 'PASS: empty image reference rejected'
