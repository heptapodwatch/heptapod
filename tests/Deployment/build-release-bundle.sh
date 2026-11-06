#!/bin/sh
# The release bundle carries every host file install/upgrade/rollback read, its REVISION and a matching checksum.
set -eu
root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT HUP INT TERM
revision=0123456789abcdef0123456789abcdef01234567
for invalid in 'main|0123456789abcdef0123456789abcdef01234567' 'v1.2.3-rc.1|0123456789abcdef0123456789abcdef01234567' 'v1.2.3|0123456' 'v1.2.3|0123456789ABCDEF0123456789ABCDEF01234567'; do
    if sh "$root/scripts/build-release-bundle.sh" "${invalid%%|*}" "${invalid#*|}" "$work/invalid" > "$work/invalid.log" 2>&1; then
        echo "Builder accepted tag/revision $invalid" >&2; exit 1
    fi
    [ ! -e "$work/invalid" ]
done
printf 'PASS: invalid and pre-release tags and abbreviated or uppercase revisions are refused\n'

sh "$root/scripts/build-release-bundle.sh" v1.2.3 "$revision" "$work/out"
cd "$work/out"
sha256sum -c heptapod-v1.2.3.tar.gz.sha256
printf 'PASS: the .sha256 asset verifies the archive by name\n'
tar -tzf heptapod-v1.2.3.tar.gz > members
if grep -v '^heptapod-v1.2.3/' members; then echo 'Bundle members escape the release directory' >&2; exit 1; fi
tar -xzf heptapod-v1.2.3.tar.gz
bundle=$work/out/heptapod-v1.2.3
[ "$(cat "$bundle/REVISION")" = "$revision" ]
[ "$(cat "$bundle/RELEASE")" = v1.2.3 ]
[ "$(cd "$bundle" && find . -type f | LC_ALL=C sort)" = "$(LC_ALL=C sort "$bundle/MANIFEST")" ]
[ "$(tail -n 1 "$bundle/MANIFEST")" = ./REVISION ]
grep -Fxq ./MANIFEST "$bundle/MANIFEST"
printf 'PASS: REVISION holds the full commit SHA, RELEASE the tag, MANIFEST every file with REVISION last\n'
for file in compose.prod.yaml compose.proxy.yaml .env.prod.example scripts/install.sh scripts/upgrade.sh scripts/rollback.sh scripts/retain-diagnostics.sh docs/deploy/operations.md docs/deploy/hetzner.md; do
    cmp "$root/$file" "$bundle/$file"
done
[ -x "$bundle/scripts/install.sh" ]
[ -x "$bundle/scripts/upgrade.sh" ]
[ -x "$bundle/scripts/rollback.sh" ]
printf 'PASS: Compose files, lifecycle scripts and deployment docs are bundled from the tree, executables intact\n'
# Every host script a bundled script calls or sources is bundled too. restore-backup.php runs inside the image.
referenced=$(grep -ohE 'scripts/[A-Za-z0-9_.-]+\.(sh|jq|php)' "$bundle"/scripts/*.sh | sort -u | grep -vx scripts/restore-backup.php)
for file in $referenced; do
    [ -f "$bundle/$file" ] || { echo "Bundled scripts reference $file, which the bundle lacks" >&2; exit 1; }
done
printf 'PASS: bundled scripts reference only bundled host scripts (%s)\n' "$(printf '%s\n' "$referenced" | wc -l | tr -d ' ')"
if grep -E '/(\.env\.prod$|\.heptapod/|app/|tests/|vendor/|node_modules/|resources/)' members; then echo 'Bundle contains state or application source' >&2; exit 1; fi
size=$(wc -c < heptapod-v1.2.3.tar.gz)
[ "$size" -lt 262144 ] || { echo "Bundle is $size bytes; expected under 256 KiB" >&2; exit 1; }
printf 'PASS: no installation state, tests or application source; %s bytes\n' "$size"
mkdir "$work/caller"
(cd "$work/caller" && sh "$root/scripts/build-release-bundle.sh" v1.2.4 "$revision" relative/out > /dev/null)
(cd "$work/caller/relative/out" && sha256sum -c heptapod-v1.2.4.tar.gz.sha256 > /dev/null)
printf 'PASS: a relative output directory resolves against the caller, not the repository\n'
