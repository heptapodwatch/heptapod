#!/bin/sh
# Build the Git-less release bundle: what install, upgrade, rollback and diagnostics read on the host.
# The images hold the application; the bundle's REVISION selects them.
set -eu
[ "$#" -eq 3 ] || { echo 'Usage: scripts/build-release-bundle.sh <tag> <full-commit-sha> <output-dir>' >&2; exit 2; }
tag=$1
revision=$2
output=$3
printf '%s\n' "$tag" | grep -Eq '^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$' || { echo "Invalid release tag: $tag" >&2; exit 2; }
printf '%s\n' "$revision" | grep -Eq '^[a-f0-9]{40}$' || { echo 'The revision must be a full 40-character lowercase commit SHA' >&2; exit 2; }
root=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
name=heptapod-$tag
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/$name" "$output"
output=$(CDPATH='' cd -- "$output" && pwd)
cd "$root"
# Host-side files only. scripts/restore-backup.php runs inside the app image and is not needed here.
for file in LICENSE compose.prod.yaml compose.proxy.yaml .env.prod.example \
    scripts/install.sh scripts/upgrade.sh scripts/rollback.sh scripts/check-disk.sh scripts/lifecycle-lock.sh \
    scripts/release-image.sh scripts/image-reference.sh scripts/worker-memory-preflight.sh \
    scripts/retain-diagnostics.sh scripts/diagnostics-filter.jq scripts/expire-upgrade-backups.sh \
    scripts/upgrade-backup-retention.sh scripts/upgrade-backup-retention.php \
    docker/prod/heptapod-diagnostics.service docs/deploy/*.md; do
    mkdir -p "$stage/$name/$(dirname -- "$file")"
    cp -p "$file" "$stage/$name/$file"
done
printf '%s\n' "$revision" > "$stage/$name/REVISION"
# upgrade.sh compares the installed RELEASE with the target tag and refuses downgrades.
printf '%s\n' "$tag" > "$stage/$name/RELEASE"
tar -C "$stage" --sort=name --owner=0 --group=0 --numeric-owner -czf "$output/$name.tar.gz" "$name"
(cd "$output" && sha256sum "$name.tar.gz" > "$name.tar.gz.sha256")
printf '%s\n' "$output/$name.tar.gz"
