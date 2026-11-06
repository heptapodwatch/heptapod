#!/bin/sh
# The hosted installer against a file:// release fixture: arguments, host checks, version resolution,
# checksum verification, directory safety, the lifecycle lock and the bundle installer hand-off.
# No network, no Docker, no sleeps: concurrency is staged with held locks and an injected race.
set -eu
root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT HUP INT TERM
mkdir -p "$work/bin" "$work/release" "$work/api/releases"
cat > "$work/bin/id" <<'SH'
#!/bin/sh
echo "${STUB_UID:-0}"
SH
cat > "$work/bin/uname" <<'SH'
#!/bin/sh
echo "${STUB_ARCH:-x86_64}"
SH
cat > "$work/bin/docker" <<'SH'
#!/bin/sh
[ "${STUB_NO_DOCKER:-false}" = false ] || exit 127
[ "$*" = 'compose version' ] || { echo "unexpected docker $*" >&2; exit 1; }
SH
# STUB_RACE_FILE: another process writes into the destination just before this installer's lock is granted.
cat > "$work/bin/flock" <<'SH'
#!/bin/sh
if [ -n "${STUB_RACE_FILE:-}" ]; then printf 'foreign\n' > "$STUB_RACE_FILE"; fi
exec "$REAL_FLOCK" "$@"
SH
# Ownership as the root installer sees it: root-owned, or STUB_STATE_OWNER.
cat > "$work/bin/stat" <<'SH'
#!/bin/sh
if [ "$1 $2" = '-c %u' ]; then echo "${STUB_STATE_OWNER:-0}"; else exec "$REAL_STAT" "$@"; fi
SH
chmod +x "$work/bin/id" "$work/bin/uname" "$work/bin/docker" "$work/bin/flock" "$work/bin/stat"
printf 'ID=ubuntu\nVERSION_CODENAME=noble\n' > "$work/ubuntu"
printf 'ID=fedora\n' > "$work/fedora"
REAL_FLOCK=$(command -v flock)
REAL_STAT=$(command -v stat)
export REAL_FLOCK REAL_STAT PATH="$work/bin:$PATH" HEPTAPOD_OS_RELEASE="$work/ubuntu" INSTALL_LOG="$work/install.log"
export HEPTAPOD_RELEASE_API="file://$work/api" HEPTAPOD_RELEASE_DOWNLOAD="file://$work/release"

# A fake bundle in the release format. Its installer takes the real lifecycle lock, then records
# whether the lock is still held against a second opener, and its arguments.
publish() {
    tag=$1
    revision=$2
    mkdir -p "$work/build/heptapod-$tag/scripts" "$work/release/$tag"
    printf '%s\n' "$revision" > "$work/build/heptapod-$tag/REVISION"
    printf '%s\n' "${3:-$tag}" > "$work/build/heptapod-$tag/RELEASE"
    printf 'name: heptapod\n' > "$work/build/heptapod-$tag/compose.prod.yaml"
    cp "$root/scripts/lifecycle-lock.sh" "$work/build/heptapod-$tag/scripts/"
    cat > "$work/build/heptapod-$tag/scripts/install.sh" <<'SH'
#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
. ./scripts/lifecycle-lock.sh
acquire_lifecycle_lock
if "$REAL_FLOCK" -n .heptapod/lifecycle.lock true; then lock=released; else lock=held; fi
# NESTED_BOOTSTRAP: a second hosted installer into this directory while this installer runs.
if [ -n "${NESTED_BOOTSTRAP:-}" ]; then
    if NESTED_BOOTSTRAP='' bash "$NESTED_BOOTSTRAP" --domain nested.example.com --dir "$PWD" < /dev/null >> "$INSTALL_LOG.nested" 2>&1; then
        echo 'nested bootstrap accepted' >> "$INSTALL_LOG.nested"
    fi
fi
printf '%s %s %s\n' "$(basename "$PWD")" "$lock" "$*" >> "$INSTALL_LOG"
SH
    chmod +x "$work/build/heptapod-$tag/scripts/install.sh"
    : > "$work/build/heptapod-$tag/MANIFEST"
    (cd "$work/build/heptapod-$tag" && find . -type f ! -name REVISION | LC_ALL=C sort && echo ./REVISION) > "$work/manifest"
    mv "$work/manifest" "$work/build/heptapod-$tag/MANIFEST"
    # A hostile bundle: this shell command changes the bundle directory after its MANIFEST is written.
    if [ -n "${4:-}" ]; then (cd "$work/build/heptapod-$tag" && eval "$4"); fi
    tar -C "$work/build" -czf "$work/release/$tag/heptapod-$tag.tar.gz" "heptapod-$tag"
    (cd "$work/release/$tag" && sha256sum "heptapod-$tag.tar.gz" > "heptapod-$tag.tar.gz.sha256")
}
publish v1.0.0 1111111111111111111111111111111111111111
publish v2.0.0 2222222222222222222222222222222222222222
publish v3.0.0 3333333333333333333333333333333333333333 v1.0.0
# Rewrites the MANIFEST so that it lists every file in the bundle directory, REVISION last.
relist() {
    { find . -type f ! -name REVISION | LC_ALL=C sort; echo ./REVISION; } > ../MANIFEST.new
    mv ../MANIFEST.new MANIFEST
}
publish v4.0.0 4444444444444444444444444444444444444444 v4.0.0 "printf 'extra\n' > .env.prod"
publish v5.0.0 5555555555555555555555555555555555555555 v5.0.0 "printf 'APP_KEY=planted\n' > .env.prod && relist"
publish v6.0.0 6666666666666666666666666666666666666666 v6.0.0 'mkdir .heptapod && printf planted > .heptapod/lifecycle.lock && relist'
publish v7.0.0 7777777777777777777777777777777777777777 v7.0.0 'truncate -s -1 MANIFEST'
# The fixtures are what they claim: v5 and v6 list their state file, v7's MANIFEST lacks its final newline.
grep -Fxq ./.env.prod "$work/build/heptapod-v5.0.0/MANIFEST"
grep -Fxq ./.heptapod/lifecycle.lock "$work/build/heptapod-v6.0.0/MANIFEST"
[ -n "$(tail -c 1 "$work/build/heptapod-v7.0.0/MANIFEST")" ]
[ "$(tail -n 1 "$work/build/heptapod-v7.0.0/MANIFEST")" = ./REVISION ]
printf '{\n  "url": "fixture",\n  "tag_name": "v2.0.0",\n  "name": "Heptapod v2.0.0"\n}\n' > "$work/api/releases/latest"

bootstrap() { bash "$root/scripts/bootstrap-install.sh" "$@"; }
refuses() {
    expected=$1
    shift
    if bootstrap "$@" > "$work/out.log" 2>&1; then echo "Bootstrap accepted: $*" >&2; cat "$work/out.log" >&2; exit 1; fi
    grep -Fq -- "$expected" "$work/out.log" || { echo "Expected '$expected' for: $*" >&2; cat "$work/out.log" >&2; exit 1; }
}
# Nothing but the lock directory, and no staged bundle left behind.
unpublished() {
    [ -z "$(find "$1" -mindepth 1 -maxdepth 1 ! -name .heptapod)" ]
    [ -z "$(find "$1" -name 'bundle.*')" ]
}

bootstrap --help | grep -Fq 'Usage: curl -fsSL https://heptapod.watch/install.sh | sudo bash -s -- --domain'
refuses 'Usage: curl -fsSL' --dir "$work/a"
refuses 'Unknown argument: --revision' --domain a.example.com --revision 1111111111111111111111111111111111111111
refuses '--owner-email requires a value' --domain a.example.com --owner-email
refuses 'Invalid --version main' --domain a.example.com --version main
refuses 'Invalid --version v2.0.0-rc.1: Heptapod publishes only vX.Y.Z release tags' --domain a.example.com --version v2.0.0-rc.1
refuses '--dir must be an absolute path' --domain a.example.com --dir relative
STUB_UID=1000 refuses 'Run as root' --domain a.example.com --dir "$work/a"
HEPTAPOD_OS_RELEASE="$work/fedora" refuses "Unsupported operating system 'fedora'" --domain a.example.com --dir "$work/a"
STUB_ARCH=aarch64 refuses 'Unsupported architecture aarch64: published Heptapod images are amd64-only' --domain a.example.com --dir "$work/a"
[ ! -e "$work/a" ]
[ ! -e "$work/install.log" ]
printf 'PASS: argument, root, OS and architecture errors stop before any download or write\n'

STUB_NO_DOCKER=true refuses "curl -fsSL https://heptapod.watch/install.sh | sudo bash -s -- --install-docker --domain a.example.com --owner-email you@example.com --dir $work/a" \
    --domain a.example.com --owner-email you@example.com --dir "$work/a"
[ ! -e "$work/a" ]
printf 'PASS: missing Docker prints the exact --install-docker rerun and changes nothing\n'

bootstrap --domain a.example.com --without-browser --owner-email you@example.com --dir "$work/a" < /dev/null > "$work/latest.log"
grep -Fxq 'a held --revision 2222222222222222222222222222222222222222 --domain a.example.com --without-browser --owner-email you@example.com' "$work/install.log"
[ "$(cat "$work/a/REVISION")" = 2222222222222222222222222222222222222222 ]
[ "$(cat "$work/a/RELEASE")" = v2.0.0 ]
grep -Fq 'Installing Heptapod v2.0.0' "$work/latest.log"
printf 'PASS: without --version the latest release is resolved, verified, published and handed to its installer with --revision and the held lock\n'

bootstrap --domain b.example.com --version v1.0.0 --dir "$work/b" < /dev/null > /dev/null
grep -Fxq 'b held --revision 1111111111111111111111111111111111111111 --domain b.example.com' "$work/install.log"
printf 'PASS: --version selects that release\n'

# A rerun on an installation downloads nothing and hands over to the installed installer, lock held.
printf 'APP_KEY=kept\n' > "$work/a/.env.prod"
cp "$work/a/.env.prod" "$work/a.env"
HEPTAPOD_RELEASE_DOWNLOAD="file://$work/missing" bootstrap --domain a.example.com --version v1.0.0 --dir "$work/a" > "$work/rerun.log"
grep -Fq "Heptapod is already installed in $work/a (revision 2222222222222222222222222222222222222222). To upgrade, run: cd $work/a && sudo ./scripts/upgrade.sh" "$work/rerun.log"
[ "$(tail -n 1 "$work/install.log")" = 'a held --revision 2222222222222222222222222222222222222222 --domain a.example.com' ]
cmp "$work/a.env" "$work/a/.env.prod"
[ "$(cat "$work/a/REVISION")" = 2222222222222222222222222222222222222222 ]
printf 'PASS: a rerun keeps .env.prod and the installed release and points to upgrade.sh\n'

# A failed earlier bundle unpack (no .env.prod yet) may be replaced; any other content is refused.
bootstrap --domain b.example.com --version v2.0.0 --dir "$work/b" < /dev/null > /dev/null
[ "$(cat "$work/b/REVISION")" = 2222222222222222222222222222222222222222 ]
mkdir "$work/other"
printf 'keep\n' > "$work/other/data"
refuses "$work/other is not empty and is not an earlier Heptapod release bundle" --domain c.example.com --dir "$work/other"
[ "$(ls -A "$work/other")" = data ]
mkdir "$work/git-install"
printf 'APP_KEY=kept\n' > "$work/git-install/.env.prod"
refuses 'has a .env.prod but is not a release bundle installation' --domain c.example.com --dir "$work/git-install"
[ "$(ls -A "$work/git-install")" = .env.prod ]
printf 'PASS: an earlier bundle without configuration is replaced; foreign directories are refused untouched, without a lock file\n'

# Overlap 1: an install, upgrade or rollback of the destination holds its lifecycle lock.
mkdir -p "$work/e/.heptapod"
exec 8> "$work/e/.heptapod/lifecycle.lock"
"$REAL_FLOCK" -n 8
lines=$(wc -l < "$work/install.log")
refuses "Another Heptapod install, upgrade or rollback is running in $work/e; nothing was changed." --domain e.example.com --dir "$work/e"
unpublished "$work/e"
[ "$(wc -l < "$work/install.log")" = "$lines" ]
exec 8>&-
# Overlap 2: a second hosted installer starts while the first one's installer runs.
NESTED_BOOTSTRAP="$root/scripts/bootstrap-install.sh" bootstrap --domain e.example.com --dir "$work/e" < /dev/null > /dev/null
grep -Fq "Another Heptapod install, upgrade or rollback is running in $work/e; nothing was changed." "$work/install.log.nested"
if grep -Fq 'nested bootstrap accepted' "$work/install.log.nested"; then echo 'A second bootstrap ran while the first installer held the lock' >&2; exit 1; fi
[ "$(tail -n 1 "$work/install.log")" = 'e held --revision 2222222222222222222222222222222222222222 --domain e.example.com' ]
# Overlap 3: the destination changes between the first look and the lock; the recheck under the lock refuses.
STUB_RACE_FILE="$work/f/data" refuses "$work/f is not empty and is not an earlier Heptapod release bundle" --domain f.example.com --dir "$work/f"
[ "$(cat "$work/f/data")" = foreign ]
[ ! -e "$work/f/REVISION" ]
[ -z "$(find "$work/f" -name 'bundle.*')" ]
STUB_RACE_FILE="$work/b/.env.prod" refuses "$work/b was installed while this installer waited; nothing was changed." --domain b.example.com --dir "$work/b"
[ "$(cat "$work/b/REVISION")" = 2222222222222222222222222222222222222222 ]
[ "$(cat "$work/b/.env.prod")" = foreign ]
[ "$(wc -l < "$work/install.log")" = "$((lines + 1))" ]
printf 'PASS: overlapping installers refuse under the destination lock, which the installer keeps; the destination is rechecked under the lock\n'

# Printed commands quote --dir: pasting them reaches the directory and runs nothing from its name.
odd="$work/odd dir;\$(touch pwned)'\"q"
bootstrap --domain odd.example.com --dir "$odd" < /dev/null > "$work/odd.log"
printf 'APP_KEY=kept\n' > "$odd/.env.prod"
bootstrap --domain odd.example.com --dir "$odd" >> "$work/odd.log"
for printed in 'Upgrade later with: ' 'To upgrade, run: '; do
    command=$(sed -n "s/.*$printed//p" "$work/odd.log")
    [ "$(cd "$work" && bash -c "${command% && sudo ./scripts/upgrade.sh} && pwd")" = "$odd" ]
done
[ ! -e "$work/pwned" ]
printf 'PASS: printed upgrade commands quote a --dir with spaces, quotes and shell syntax\n'

lines=$(wc -l < "$work/install.log")
printf 'tampered' >> "$work/release/v1.0.0/heptapod-v1.0.0.tar.gz"
refuses 'Checksum mismatch for heptapod-v1.0.0.tar.gz' --domain d.example.com --version v1.0.0 --dir "$work/d"
unpublished "$work/d"
refuses "heptapod-v3.0.0.tar.gz has no RELEASE file for v3.0.0" --domain d.example.com --version v3.0.0 --dir "$work/d"
unpublished "$work/d"
# An unlisted .env.prod, a listed .env.prod or .heptapod file, and a MANIFEST whose last line (REVISION) lacks its newline.
for hostile in v4.0.0 v5.0.0 v6.0.0 v7.0.0; do
    refuses "heptapod-$hostile.tar.gz does not match its MANIFEST" --domain d.example.com --version "$hostile" --dir "$work/d"
    unpublished "$work/d"
    [ ! -e "$work/d/.env.prod" ]
    [ ! -s "$work/d/.heptapod/lifecycle.lock" ]
done
refuses "Cannot download file://$work/release/v9.9.9/heptapod-v9.9.9.tar.gz" --domain d.example.com --version v9.9.9 --dir "$work/d"
printf '{"tag_name": "main"}' > "$work/api/releases/latest"
refuses "The latest release has an unexpected tag 'main'" --domain d.example.com --dir "$work/d"
printf '{"tag_name": "v2.0.1-alpha10"}' > "$work/api/releases/latest"
refuses "The latest release has an unexpected tag 'v2.0.1-alpha10' (expected vX.Y.Z)" --domain d.example.com --dir "$work/d"
unpublished "$work/d"
[ "$(wc -l < "$work/install.log")" = "$lines" ]
printf 'PASS: a checksum mismatch, a mismatched RELEASE or MANIFEST, a missing asset or an invalid or pre-release latest tag publishes nothing\n'

# A planted .heptapod or lock-file symlink, or state owned by another user: refused before chmod, open or staging.
mkdir -p "$work/sentinel" "$work/g" "$work/h/.heptapod" "$work/i/.heptapod"
printf 'keep\n' > "$work/sentinel/lifecycle.lock"
chmod 755 "$work/sentinel"
ln -s "$work/sentinel" "$work/g/.heptapod"
ln -s "$work/sentinel/lifecycle.lock" "$work/h/.heptapod/lifecycle.lock"
refuses "$work/g/.heptapod is a symlink or owned by another user; nothing was changed." --domain g.example.com --dir "$work/g"
refuses "$work/h/.heptapod/lifecycle.lock is a symlink or owned by another user; nothing was changed." --domain h.example.com --dir "$work/h"
STUB_STATE_OWNER=1234 refuses "$work/i/.heptapod is a symlink or owned by another user; nothing was changed." --domain i.example.com --dir "$work/i"
[ "$(stat -c %a "$work/sentinel")" = 755 ]
[ "$(cat "$work/sentinel/lifecycle.lock")" = keep ]
[ "$(ls -A "$work/sentinel")" = lifecycle.lock ]
[ "$(ls -A "$work/g")" = .heptapod ]
[ "$(ls -A "$work/h/.heptapod")" = lifecycle.lock ]
[ -z "$(ls -A "$work/i/.heptapod")" ]
printf 'PASS: a symlinked .heptapod or lock file and foreign-owned state are refused; the outside directory keeps its mode and contents\n'
