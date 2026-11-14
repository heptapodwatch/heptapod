#!/bin/sh
# The hosted installer against a file:// release fixture: arguments, host checks, version resolution,
# checksum verification, release folder publication, directory safety, the lifecycle lock and the bundle
# installer hand-off. No network, no Docker, no sleeps: concurrency is staged with held locks and an
# injected race, host crashes with a SIGKILL of the installer's process group.
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
# STUB_KILL_MV_TO: the host stops (SIGKILL of the installer's process group) just before mv -T onto that path.
cat > "$work/bin/mv" <<'SH'
#!/bin/sh
if [ -n "${STUB_KILL_MV_TO:-}" ] && [ "$1" = -T ] && [ "$3" = "$STUB_KILL_MV_TO" ]; then kill -KILL 0; fi
exec "$REAL_MV" "$@"
SH
chmod +x "$work/bin/id" "$work/bin/uname" "$work/bin/docker" "$work/bin/flock" "$work/bin/stat" "$work/bin/mv"
printf 'ID=ubuntu\nVERSION_CODENAME=noble\n' > "$work/ubuntu"
printf 'ID=fedora\n' > "$work/fedora"
REAL_FLOCK=$(command -v flock)
REAL_STAT=$(command -v stat)
REAL_MV=$(command -v mv)
export REAL_FLOCK REAL_STAT REAL_MV PATH="$work/bin:$PATH" HEPTAPOD_OS_RELEASE="$work/ubuntu" INSTALL_LOG="$work/install.log"
export HEPTAPOD_RELEASE_API="file://$work/api" HEPTAPOD_RELEASE_DOWNLOAD="file://$work/release"

# A fake bundle in the release format with the real lifecycle and publication helpers. Its installer takes the
# real lifecycle lock, then records its instance root, whether the lock is still held against a second opener,
# and its arguments.
publish() {
    tag=$1
    revision=$2
    mkdir -p "$work/build/heptapod-$tag/scripts" "$work/release/$tag"
    printf '%s\n' "$revision" > "$work/build/heptapod-$tag/REVISION"
    printf '%s\n' "${3:-$tag}" > "$work/build/heptapod-$tag/RELEASE"
    printf 'name: heptapod\n' > "$work/build/heptapod-$tag/compose.prod.yaml"
    cp "$root/scripts/lifecycle-lock.sh" "$root/scripts/release-image.sh" "$work/build/heptapod-$tag/scripts/"
    cat > "$work/build/heptapod-$tag/scripts/install.sh" <<'SH'
#!/bin/sh
set -eu
release_dir=$(CDPATH='' cd -P -- "$(dirname -- "$0")/.." && pwd)
. "$release_dir/scripts/lifecycle-lock.sh"
enter_instance_root
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
# The first release with arm64 images.
publish v0.4.1 0401040104010401040104010401040104010401
# Rewrites the MANIFEST so that it lists every file in the bundle directory, REVISION last.
relist() {
    { find . -type f ! -name REVISION | LC_ALL=C sort; echo ./REVISION; } > ../MANIFEST.new
    mv ../MANIFEST.new MANIFEST
}
publish v4.0.0 4444444444444444444444444444444444444444 v4.0.0 "printf 'extra\n' > .env.prod"
publish v5.0.0 5555555555555555555555555555555555555555 v5.0.0 "printf 'APP_KEY=planted\n' > .env.prod && relist"
publish v6.0.0 6666666666666666666666666666666666666666 v6.0.0 'mkdir .heptapod && printf planted > .heptapod/lifecycle.lock && relist'
publish v7.0.0 7777777777777777777777777777777777777777 v7.0.0 'truncate -s -1 MANIFEST'
publish v8.0.0 8888888888888888888888888888888888888888 v8.0.0 'mkdir current && printf planted > current/x && relist'
publish v8.1.0 8181818181818181818181818181818181818181 v8.1.0 'mkdir -p releases/v1.0.0 && printf planted > releases/v1.0.0/x && relist'
# A release from before the release folder layout: its helpers cannot publish a release folder.
publish v0.1.1 0111011101110111011101110111011101110111 v0.1.1 "printf '%s\\n' 'validate_bundle_manifest() { true; }' > scripts/release-image.sh && relist"
# The fixtures are what they claim: v5, v6, v8 and v8.1 list a state or reserved path, v7's MANIFEST lacks its final newline.
grep -Fxq ./.env.prod "$work/build/heptapod-v5.0.0/MANIFEST"
grep -Fxq ./.heptapod/lifecycle.lock "$work/build/heptapod-v6.0.0/MANIFEST"
grep -Fxq ./current/x "$work/build/heptapod-v8.0.0/MANIFEST"
grep -Fxq ./releases/v1.0.0/x "$work/build/heptapod-v8.1.0/MANIFEST"
[ -n "$(tail -c 1 "$work/build/heptapod-v7.0.0/MANIFEST")" ]
[ "$(tail -n 1 "$work/build/heptapod-v7.0.0/MANIFEST")" = ./REVISION ]
if grep -q publish_release_dir "$work/build/heptapod-v0.1.1/scripts/release-image.sh"; then exit 1; fi
printf '{\n  "url": "fixture",\n  "tag_name": "v2.0.0",\n  "name": "Heptapod v2.0.0"\n}\n' > "$work/api/releases/latest"

bootstrap() { bash "$root/scripts/bootstrap-install.sh" "$@"; }
refuses() {
    expected=$1
    shift
    if bootstrap "$@" > "$work/out.log" 2>&1; then echo "Bootstrap accepted: $*" >&2; cat "$work/out.log" >&2; exit 1; fi
    grep -Fq -- "$expected" "$work/out.log" || { echo "Expected '$expected' for: $*" >&2; cat "$work/out.log" >&2; exit 1; }
}
# Nothing but the lock directory and an empty releases/: no release folder, stage, current or root symlink.
unpublished() {
    [ -z "$(find "$1" -mindepth 1 -maxdepth 1 ! -name .heptapod ! -name releases)" ]
    [ ! -e "$1/releases" ] || [ -z "$(ls -A "$1/releases")" ]
}
# The release folder layout: current -> releases/$2, one root symlink per top-level bundle entry, no stage.
published() {
    [ "$(readlink "$1/current")" = "releases/$2" ]
    for entry in MANIFEST RELEASE REVISION compose.prod.yaml scripts; do
        [ "$(readlink "$1/$entry")" = "current/$entry" ]
    done
    [ "$(cat "$1/RELEASE")" = "$2" ]
    [ -z "$(find "$1/releases" -mindepth 1 -maxdepth 1 -name '.*')" ]
    [ ! -e "$1/.current.tmp" ]
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
STUB_ARCH=armv7l refuses 'Unsupported architecture armv7l: Heptapod publishes images for amd64 (x86_64) and arm64 (aarch64).' --domain a.example.com --dir "$work/a"
STUB_ARCH=aarch64 refuses 'Heptapod v0.4.0 has no arm64 images; they exist from v0.4.1 on. Pass --version with a newer release' --domain a.example.com --version v0.4.0 --dir "$work/a"
printf '{"tag_name": "v0.4.0"}' > "$work/api/releases/latest"
STUB_ARCH=aarch64 refuses 'Heptapod v0.4.0 has no arm64 images' --domain a.example.com --dir "$work/a"
printf '{\n  "url": "fixture",\n  "tag_name": "v2.0.0",\n  "name": "Heptapod v2.0.0"\n}\n' > "$work/api/releases/latest"
[ ! -e "$work/a" ]
[ ! -e "$work/install.log" ]
printf 'PASS: argument, root, OS and architecture errors, and an arm64 host with a release before v0.4.1, stop before any download or write\n'

STUB_ARCH=aarch64 bootstrap --domain arm.example.com --version v0.4.1 --dir "$work/arm" < /dev/null > /dev/null
published "$work/arm" v0.4.1
grep -Fxq 'arm held --revision 0401040104010401040104010401040104010401 --domain arm.example.com' "$work/install.log"
STUB_ARCH=aarch64 bootstrap --domain arm2.example.com --dir "$work/arm2" < /dev/null > /dev/null
published "$work/arm2" v2.0.0
printf 'PASS: an arm64 host installs v0.4.1 and the latest release\n'

STUB_NO_DOCKER=true refuses "curl -fsSL https://heptapod.watch/install.sh | sudo bash -s -- --install-docker --domain a.example.com --owner-email you@example.com --dir $work/a" \
    --domain a.example.com --owner-email you@example.com --dir "$work/a"
[ ! -e "$work/a" ]
printf 'PASS: missing Docker prints the exact --install-docker rerun and changes nothing\n'

bootstrap --domain a.example.com --without-browser --owner-email you@example.com --dir "$work/a" < /dev/null > "$work/latest.log"
grep -Fxq 'a held --revision 2222222222222222222222222222222222222222 --domain a.example.com --without-browser --owner-email you@example.com' "$work/install.log"
[ "$(cat "$work/a/REVISION")" = 2222222222222222222222222222222222222222 ]
published "$work/a" v2.0.0
grep -Fq 'Installing Heptapod v2.0.0' "$work/latest.log"
printf 'PASS: without --version the latest release is resolved, verified, published as releases/v2.0.0 with current and the root symlinks, and handed to its installer with --revision and the held lock\n'

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

# The host stops between unpacking and publication, and between publication and the current swap: neither run
# reaches the installer, current never names an incomplete release, and a rerun converges to the same layout.
lines=$(wc -l < "$work/install.log")
for kill_at in releases/v2.0.0 current; do
    rm -rf "$work/k"
    if STUB_KILL_MV_TO=$kill_at setsid -w bash "$root/scripts/bootstrap-install.sh" --domain k.example.com --version v2.0.0 --dir "$work/k" < /dev/null > "$work/killed.log" 2>&1; then
        echo "Bootstrap survived its kill before mv -T onto $kill_at" >&2; exit 1
    fi
    [ "$(wc -l < "$work/install.log")" = "$lines" ]
    [ ! -e "$work/k/current" ]
    case "$kill_at" in
        releases/v2.0.0) [ -d "$work/k/releases/.v2.0.0.tmp/heptapod-v2.0.0" ]; [ ! -e "$work/k/releases/v2.0.0" ] ;;
        # A changed file in the unswapped release folder: the rerun unpacks the verified archive again.
        current) [ -d "$work/k/releases/v2.0.0" ]; [ -L "$work/k/.current.tmp" ]; printf 'tampered\n' >> "$work/k/releases/v2.0.0/scripts/install.sh" ;;
    esac
    bootstrap --domain k.example.com --version v2.0.0 --dir "$work/k" < /dev/null > /dev/null
    published "$work/k" v2.0.0
    diff -r "$work/build/heptapod-v2.0.0" "$work/k/releases/v2.0.0"
    [ "$(tail -n 1 "$work/install.log")" = 'k held --revision 2222222222222222222222222222222222222222 --domain k.example.com' ]
    lines=$((lines + 1))
done
# Interrupted after the swap, before install.sh wrote .env.prod: a rerun, also with a newer release, continues.
bootstrap --domain b.example.com --version v1.0.0 --dir "$work/b" < /dev/null > /dev/null
bootstrap --domain b.example.com --version v2.0.0 --dir "$work/b" < /dev/null > /dev/null
published "$work/b" v2.0.0
[ -d "$work/b/releases/v1.0.0" ]
printf 'PASS: a bootstrap killed before or after publishing its release folder leaves current unset; the rerun converges and unpacks the archive again\n'
mkdir "$work/other"
printf 'keep\n' > "$work/other/data"
refuses "$work/other is not empty and is not an earlier Heptapod installation" --domain c.example.com --dir "$work/other"
[ "$(ls -A "$work/other")" = data ]
mkdir -p "$work/flat/scripts"
printf '%s\n' 1111111111111111111111111111111111111111 > "$work/flat/REVISION"
printf 'name: heptapod\n' > "$work/flat/compose.prod.yaml"
refuses "$work/flat is not empty and is not an earlier Heptapod installation" --domain c.example.com --dir "$work/flat"
mkdir -p "$work/foreign-link"
ln -s /etc "$work/foreign-link/scripts"
refuses "$work/foreign-link is not empty and is not an earlier Heptapod installation" --domain c.example.com --dir "$work/foreign-link"
# With .env.prod and an installer: a Git checkout beside a complete release folder layout, and a flat file layout.
mkdir -p "$work/git-install/.git" "$work/git-install/releases" "$work/flat-install"
cp -R "$work/build/heptapod-v1.0.0" "$work/git-install/releases/v1.0.0"
ln -s releases/v1.0.0 "$work/git-install/current"
for entry in REVISION scripts; do ln -s "current/$entry" "$work/git-install/$entry"; done
cp "$work/build/heptapod-v1.0.0/REVISION" "$work/flat-install/"
cp -R "$work/build/heptapod-v1.0.0/scripts" "$work/flat-install/"
lines=$(wc -l < "$work/install.log")
for installation in git-install flat-install; do
    printf 'APP_KEY=kept\n' > "$work/$installation/.env.prod"
    before=$(cd "$work/$installation" && find . | LC_ALL=C sort)
    if bootstrap --domain c.example.com --dir "$work/$installation" > "$work/out.log" 2>&1; then cat "$work/out.log" >&2; exit 1; fi
    # What the refusal prevents first: the lock file and the installer run; then which refusal it was.
    [ "$(cd "$work/$installation" && find . | LC_ALL=C sort)" = "$before" ]
    [ "$(wc -l < "$work/install.log")" = "$lines" ]
    grep -Fq "$work/$installation has a .env.prod but is not a release folder installation" "$work/out.log"
done
printf 'PASS: foreign directories, a flat file layout, a foreign root symlink and a Git checkout beside current are refused untouched, without a lock file or an installer run\n'

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
STUB_RACE_FILE="$work/f/data" refuses "$work/f is not empty and is not an earlier Heptapod installation" --domain f.example.com --dir "$work/f"
[ "$(cat "$work/f/data")" = foreign ]
[ ! -e "$work/f/REVISION" ]
[ ! -e "$work/f/releases" ]
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
# An unlisted .env.prod, a listed .env.prod, .heptapod, current or releases path, and a MANIFEST whose last line (REVISION) lacks its newline.
for hostile in v4.0.0 v5.0.0 v6.0.0 v7.0.0 v8.0.0 v8.1.0; do
    refuses "heptapod-$hostile.tar.gz does not match its MANIFEST" --domain d.example.com --version "$hostile" --dir "$work/d"
    unpublished "$work/d"
    [ ! -e "$work/d/.env.prod" ]
    [ ! -s "$work/d/.heptapod/lifecycle.lock" ]
done
refuses 'Publishing v0.1.1 failed (see above); a release older than the release folder layout cannot be installed with this installer.' --domain d.example.com --version v0.1.1 --dir "$work/d"
unpublished "$work/d"
refuses "Cannot download file://$work/release/v9.9.9/heptapod-v9.9.9.tar.gz" --domain d.example.com --version v9.9.9 --dir "$work/d"
printf '{"tag_name": "main"}' > "$work/api/releases/latest"
refuses "The latest release has an unexpected tag 'main'" --domain d.example.com --dir "$work/d"
printf '{"tag_name": "v2.0.1-alpha10"}' > "$work/api/releases/latest"
refuses "The latest release has an unexpected tag 'v2.0.1-alpha10' (expected vX.Y.Z)" --domain d.example.com --dir "$work/d"
unpublished "$work/d"
[ "$(wc -l < "$work/install.log")" = "$lines" ]
printf 'PASS: a checksum mismatch, a mismatched RELEASE or MANIFEST, a reserved path, a release without release folder support, a missing asset or an invalid or pre-release latest tag publishes nothing\n'

# A planted .heptapod, lock-file or releases symlink, or state owned by another user: refused before chmod, open or staging.
mkdir -p "$work/sentinel" "$work/g" "$work/h/.heptapod" "$work/i/.heptapod" "$work/j" "$work/l"
ln -s "$work/sentinel" "$work/j/releases"
ln -s releases/v2.0.0 "$work/l/current"
refuses "$work/j/releases is a symlink or owned by another user; nothing was changed." --domain j.example.com --dir "$work/j"
[ "$(ls -A "$work/j")" = releases ]
STUB_STATE_OWNER=1234 refuses "$work/l/current is a symlink or owned by another user; nothing was changed." --domain l.example.com --dir "$work/l"
[ "$(ls -A "$work/l")" = current ]
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
printf 'PASS: a symlinked .heptapod, lock file or releases and foreign-owned state or current are refused; the outside directory keeps its mode and contents\n'
