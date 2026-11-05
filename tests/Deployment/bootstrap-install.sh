#!/bin/sh
# The hosted installer against a file:// release fixture: arguments, host checks, version resolution,
# checksum verification, directory safety and the bundle installer hand-off. No network, no Docker.
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
chmod +x "$work/bin/id" "$work/bin/uname" "$work/bin/docker"
printf 'ID=ubuntu\nVERSION_CODENAME=noble\n' > "$work/ubuntu"
printf 'ID=fedora\n' > "$work/fedora"
export PATH="$work/bin:$PATH" HEPTAPOD_OS_RELEASE="$work/ubuntu" INSTALL_LOG="$work/install.log"
export HEPTAPOD_RELEASE_API="file://$work/api" HEPTAPOD_RELEASE_DOWNLOAD="file://$work/release"

# A fake bundle in the release format; its installer records the hand-off.
publish() {
    tag=$1
    revision=$2
    mkdir -p "$work/build/heptapod-$tag/scripts" "$work/release/$tag"
    printf '%s\n' "$revision" > "$work/build/heptapod-$tag/REVISION"
    printf 'name: heptapod\n' > "$work/build/heptapod-$tag/compose.prod.yaml"
    cat > "$work/build/heptapod-$tag/scripts/install.sh" <<'SH'
#!/bin/sh
printf '%s %s\n' "$(basename "$(dirname "$(dirname "$0")")")" "$*" >> "$INSTALL_LOG"
SH
    chmod +x "$work/build/heptapod-$tag/scripts/install.sh"
    tar -C "$work/build" -czf "$work/release/$tag/heptapod-$tag.tar.gz" "heptapod-$tag"
    (cd "$work/release/$tag" && sha256sum "heptapod-$tag.tar.gz" > "heptapod-$tag.tar.gz.sha256")
}
publish v1.0.0 1111111111111111111111111111111111111111
publish v2.0.0 2222222222222222222222222222222222222222
printf '{\n  "url": "fixture",\n  "tag_name": "v2.0.0",\n  "name": "Heptapod v2.0.0"\n}\n' > "$work/api/releases/latest"

bootstrap() { bash "$root/scripts/bootstrap-install.sh" "$@"; }
refuses() {
    expected=$1
    shift
    if bootstrap "$@" > "$work/out.log" 2>&1; then echo "Bootstrap accepted: $*" >&2; cat "$work/out.log" >&2; exit 1; fi
    grep -Fq -- "$expected" "$work/out.log" || { echo "Expected '$expected' for: $*" >&2; cat "$work/out.log" >&2; exit 1; }
}

bootstrap --help | grep -Fq 'Usage: curl -fsSL https://heptapod.watch/install.sh | sudo bash -s -- --domain'
refuses 'Usage: curl -fsSL' --dir "$work/a"
refuses 'Unknown argument: --revision' --domain a.example.com --revision 1111111111111111111111111111111111111111
refuses '--owner-email requires a value' --domain a.example.com --owner-email
refuses 'Invalid --version main' --domain a.example.com --version main
refuses '--dir must be an absolute path' --domain a.example.com --dir relative
STUB_UID=1000 refuses 'Run as root' --domain a.example.com --dir "$work/a"
HEPTAPOD_OS_RELEASE="$work/fedora" refuses "Unsupported operating system 'fedora'" --domain a.example.com --dir "$work/a"
STUB_ARCH=aarch64 refuses 'Unsupported architecture aarch64: published Heptapod images are amd64-only' --domain a.example.com --dir "$work/a"
[ ! -e "$work/a" ] && [ ! -e "$work/install.log" ]
printf 'PASS: argument, root, OS and architecture errors stop before any download or write\n'

STUB_NO_DOCKER=true refuses "curl -fsSL https://heptapod.watch/install.sh | sudo bash -s -- --install-docker --domain a.example.com --owner-email you@example.com --dir $work/a" \
    --domain a.example.com --owner-email you@example.com --dir "$work/a"
[ ! -e "$work/a" ]
printf 'PASS: missing Docker prints the exact --install-docker rerun and changes nothing\n'

bootstrap --domain a.example.com --without-browser --owner-email you@example.com --dir "$work/a" < /dev/null > "$work/latest.log"
grep -Fxq 'a --revision 2222222222222222222222222222222222222222 --domain a.example.com --without-browser --owner-email you@example.com' "$work/install.log"
[ "$(cat "$work/a/REVISION")" = 2222222222222222222222222222222222222222 ]
grep -Fq 'Installing Heptapod v2.0.0' "$work/latest.log"
printf 'PASS: without --version the latest release is resolved, verified, unpacked and handed to its installer with --revision\n'

bootstrap --domain b.example.com --version v1.0.0 --dir "$work/b" < /dev/null > /dev/null
grep -Fxq 'b --revision 1111111111111111111111111111111111111111 --domain b.example.com' "$work/install.log"
printf 'PASS: --version selects that release\n'

# A rerun on an installation downloads nothing and hands over to the installed installer.
printf 'APP_KEY=kept\n' > "$work/a/.env.prod"
cp "$work/a/.env.prod" "$work/a.env"
HEPTAPOD_RELEASE_DOWNLOAD="file://$work/missing" bootstrap --domain a.example.com --version v1.0.0 --dir "$work/a" > "$work/rerun.log"
grep -Fq "Heptapod is already installed in $work/a (revision 2222222222222222222222222222222222222222). To upgrade, run: cd $work/a && sudo ./scripts/upgrade.sh" "$work/rerun.log"
[ "$(tail -n 1 "$work/install.log")" = 'a --revision 2222222222222222222222222222222222222222 --domain a.example.com' ]
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
printf 'PASS: an earlier bundle without configuration is replaced; foreign directories are refused untouched\n'

lines=$(wc -l < "$work/install.log")
printf 'tampered' >> "$work/release/v1.0.0/heptapod-v1.0.0.tar.gz"
refuses 'Checksum mismatch for heptapod-v1.0.0.tar.gz' --domain d.example.com --version v1.0.0 --dir "$work/d"
[ ! -e "$work/d" ] && [ "$(wc -l < "$work/install.log")" = "$lines" ]
refuses "Cannot download file://$work/release/v9.9.9/heptapod-v9.9.9.tar.gz" --domain d.example.com --version v9.9.9 --dir "$work/d"
printf '{"tag_name": "main"}' > "$work/api/releases/latest"
refuses "The latest release has an unexpected tag 'main'" --domain d.example.com --dir "$work/d"
[ ! -e "$work/d" ]
printf 'PASS: a checksum mismatch, a missing asset or an invalid latest tag installs nothing\n'
