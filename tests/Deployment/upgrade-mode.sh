#!/bin/sh
# Check installer/upgrade file selection without starting production services.
set -eu
root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
fresh=$(mktemp -d)
export COMPOSE_PROJECT_NAME=upgrade-mode-test
REAL_DOCKER=$(command -v docker)
export REAL_DOCKER
mkdir "$fresh/scripts" "$fresh/bin"
cp "$root/scripts/install.sh" "$root/scripts/upgrade.sh" "$root/scripts/check-disk.sh" "$root/scripts/rollback.sh" "$root/scripts/lifecycle-lock.sh" "$root/scripts/release-image.sh" "$root/scripts/image-reference.sh" "$root/scripts/retain-diagnostics.sh" "$root/scripts/diagnostics-filter.jq" "$root/scripts/upgrade-backup-retention.sh" "$root/scripts/upgrade-backup-retention.php" "$root/scripts/worker-memory-preflight.sh" "$fresh/scripts/"
cp "$root/compose.prod.yaml" "$root/compose.proxy.yaml" "$root/.env.prod.example" "$fresh/"
cat > "$fresh/compose.rc.yaml" <<'YAML'
services:
    app:
        labels:
            upgrade-mode-test: rc
YAML
cat > "$fresh/bin/docker" <<'SH'
#!/bin/sh
if [ "$1" = info ]; then exec "$REAL_DOCKER" "$@"; fi
operation_phase=none
if [ -f .heptapod/upgrade/phase ]; then operation_phase=$(cat .heptapod/upgrade/phase); fi
printf '%s | %s | phase=%s | pin=%s\n' "${COMPOSE_FILE:-unset}" "$*" "$operation_phase" "$(if [ -f .env.prod ]; then sed -n 's/^APP_IMAGE=//p' .env.prod; fi)" >> all-operations.log
case "$*" in
    'pull '*) [ "${FAIL_PULL:-false}" = false ] || exit 42 ;;
    'image inspect --format {{range .RepoDigests}}{{println .}}{{end}} '*) printf '%s@sha256:%064d\n' "${5%:*}" 1 ;;
    'image inspect --format {{index .Config.Labels "org.opencontainers.image.revision"}} '*) if [ "${STUB_BAD_REVISION:-false}" = true ]; then printf '%040d\n' 2; else printf '%040d\n' 1; fi ;;
    'inspect --format {{index .Config.Labels "org.opencontainers.image.revision"}} '*) echo "${STUB_RUNNING_REVISION:-}" ;;
    'inspect --format {{index .Config.Labels "com.docker.compose.project"}} '*) echo upgrade-mode-test ;;
    'image inspect --format {{.Id}} '*) printf 'sha256:%064d\n' 2 ;;
    'ps -q') [ -z "${STUB_BIND_SOURCE:-}" ] || echo bind-container ;;
    'inspect --format {{range .Mounts}}'*) printf '%s\n' "${STUB_BIND_SOURCE:-}" ;;
    'inspect --format {{.Image}} '*) printf 'sha256:%064d\n' 2 ;;
esac
if [ "$1" = compose ]; then
    shift
    for arg in "$@"; do
        if [ "$arg" = config ]; then exec "$REAL_DOCKER" compose "$@"; fi
        if [ "$arg" = ps ]; then [ "${STUB_RUNNING:-true}" = false ] || echo stub-container; exit 0; fi
    done
    printf '%s | %s\n' "${COMPOSE_FILE:-unset}" "$*" >> docker-operations.log
    case "$*" in
        *'php artisan backup:run'*) [ "${FAIL_BACKUP:-false}" = false ] || exit 42 ;;
        *'run --rm --no-deps -T --entrypoint sh horizon'*'heptapod:worker-memory'*)
            [ "${STUB_WORKER_MEMORY_FAIL:-false}" = false ] || { echo 'HORIZON_MEMORY_LIMIT is 832m; HEPTAPOD_CRAWL_CONCURRENCY=1 with HEPTAPOD_CRAWL_MEMORY_LIMIT_MB=768 needs at least 1990m.' >&2; exit 1; } ;;
        *'printf "HEPTAPOD_STORAGE_DISK='*)
            if [ "${STUB_MISSING_BACKUP:-false}" = true ]; then echo 'OPS missing recorded archive' >&2; exit 42; fi
            printf 'HEPTAPOD_STORAGE_DISK=local\nBACKUP_DISK=backups\nBACKUP_PATH=/backups\nRECORDED_BACKUP_SHA256=%064d\n' 3 ;;
        *pg_database_size*) echo 1024 ;;
        *'from migrations'*) echo 100:100 ;;
        *'find /backups/upgrade-lifecycle'*) echo /backups/upgrade-lifecycle/Heptapod/stub-backup.zip ;;
        *'sha256sum '*) printf '%064d  /backups/upgrade-lifecycle/Heptapod/stub-backup.zip\n' 3 ;;
        *'php -- plan '*) printf '{"migrations":[],"archives":[]}\n' ;;
    esac
fi
SH
cat > "$fresh/bin/git" <<'SH'
#!/bin/sh
[ "${STUB_NO_GIT:-false}" = false ] || exit 128
case "$1" in
    rev-parse) printf '%040d\n' 1 ;;
    describe) echo fixture ;;
    status) [ "${STUB_DIRTY:-false}" = false ] || echo ' M tracked-file' ;;
esac
SH
REAL_DF=$(command -v df)
export REAL_DF
cat > "$fresh/bin/df" <<'SH'
#!/bin/sh
if [ -n "${STUB_FREE_KB:-}" ]; then
    printf 'Filesystem 1024-blocks Used Available Capacity Mounted-on\nfixture 999999999 0 %s 0%% /\n' "$STUB_FREE_KB"
else
    exec "$REAL_DF" "$@"
fi
SH
chmod +x "$fresh/bin/docker" "$fresh/bin/git" "$fresh/bin/df"
export PATH="$fresh/bin:$PATH"
cd "$fresh"
# shellcheck source=scripts/lifecycle-lock.sh
. "$fresh/scripts/lifecycle-lock.sh"
lifecycle_state="$fresh/$lifecycle_dir/upgrade"
trap 'rm -rf "$fresh"' EXIT HUP INT TERM
model() { "$REAL_DOCKER" compose --env-file .env.prod config --format json; }
# Reserve the resolved daily/lifecycle directories before any installer write or upgrade maintenance.
for invalid_backup in \
    'upgrade-lifecycle|/backups|reserved backup name upgrade-lifecycle' \
    'Upgrade-Lifecycle|/backups|reserved backup name upgrade-lifecycle' \
    'upgrade lifecycle|/backups|reserved backup name upgrade-lifecycle' \
    'nested/../upgrade-lifecycle|/backups|reserved backup name upgrade-lifecycle' \
    '|/backups|non-empty backup name' \
    '/|/backups|non-empty backup name' \
    '.|/backups|non-empty backup name' \
    'nested/..|/backups|non-empty backup name' \
    'Heptapod|/backups/upgrade-lifecycle|overlaps reserved /backups/upgrade-lifecycle' \
    'backups/upgrade-lifecycle|/|overlaps reserved /backups/upgrade-lifecycle' \
    'backups|/|overlaps reserved /backups/upgrade-lifecycle' \
    'Heptapod|/backups/scratch/../upgrade-lifecycle|overlaps reserved /backups/upgrade-lifecycle' \
    'Heptapod|/backups/upgrade-lifecycle/nested|overlaps reserved /backups/upgrade-lifecycle' \
    'Heptapod|/backups//upgrade-lifecycle/|overlaps reserved /backups/upgrade-lifecycle'; do
    invalid_name=${invalid_backup%%|*}
    invalid_remainder=${invalid_backup#*|}
    invalid_root=${invalid_remainder%%|*}
    expected_error=${invalid_remainder#*|}
    sed "s|^APP_NAME=.*|APP_NAME=\"$invalid_name\"|" "$root/.env.prod.example" > .env.prod
    printf '\nBACKUP_PATH="%s"\n' "$invalid_root" >> .env.prod
    cp .env.prod reserved-before.env
    if ./scripts/install.sh --domain own.example.com > reserved-install.log 2>&1; then
        echo "Installer accepted unsafe daily destination: APP_NAME=$invalid_name BACKUP_PATH=$invalid_root" >&2; exit 1
    fi
    grep -Fq "$expected_error" reserved-install.log || { cat reserved-install.log >&2; exit 1; }
    cmp reserved-before.env .env.prod
    [ ! -d .heptapod ]
    : > docker-operations.log
    if env -u COMPOSE_FILE ./scripts/upgrade.sh > reserved-upgrade.log 2>&1; then
        echo "Upgrade accepted unsafe daily destination: APP_NAME=$invalid_name BACKUP_PATH=$invalid_root" >&2; exit 1
    fi
    grep -Fq "$expected_error" reserved-upgrade.log || { cat reserved-upgrade.log >&2; exit 1; }
    cmp reserved-before.env .env.prod
    [ ! -s docker-operations.log ]
    printf 'PASS: installer/upgrade refuse APP_NAME="%s" BACKUP_PATH="%s" without writes/maintenance\n' "$invalid_name" "$invalid_root"
    rm -rf .heptapod
    rm .env.prod
done
validate_backup_name Heptapod /backups/upgrade-lifecycle-archive
printf 'PASS: similar sibling prefix is allowed\n'
# No shell selection is required after installation, including the own-TLS mode.
./scripts/install.sh --domain own.example.com
[ "$(sed -n 's/^COMPOSE_FILE=//p' .env.prod)" = compose.prod.yaml ] || { echo 'Installer did not persist own-TLS COMPOSE_FILE' >&2; exit 1; }
model | jq -e '.services.app.ports | any(.[]; .published == "443" and .target == 8443)' >/dev/null
cp .env.prod before.env
chmod 644 .env.prod
cp .env.prod private-before.env
: > docker-operations.log
for invalid_files in 'compose.prod.yaml:compose rc.yaml' 'compose.prod.yaml;exit'; do
    if COMPOSE_FILE="$invalid_files" ./scripts/install.sh --domain own.example.com > upgrade-error.log 2>&1; then exit 1; fi
    grep -q 'COMPOSE_FILE contains unsupported characters' upgrade-error.log
    cmp private-before.env .env.prod
    [ "$(find .env.prod -perm 644 | wc -l | tr -d ' ')" = 1 ]
    [ ! -s docker-operations.log ]
done
chmod 600 .env.prod
: > docker-operations.log
env -u COMPOSE_FILE ./scripts/upgrade.sh
cmp before.env .env.prod
grep -q '^compose.prod.yaml | --env-file .env.prod exec -T app php artisan down$' docker-operations.log
# Optional jq is not an install dependency. Every upgrade warns and still completes.
mkdir "$fresh/no-jq"
for command in bash dirname env sed grep chmod openssl cat date df awk flock mkdir mv cp rm wc tr mktemp printenv sort xargs sync paste sha256sum find; do
    ln -s "$(command -v "$command")" "$fresh/no-jq/$command"
done
: > docker-operations.log
PATH="$fresh/no-jq:$fresh/bin" env -u COMPOSE_FILE ./scripts/upgrade.sh > missing-jq.log 2>&1
[ "$(grep -c 'optional host jq missing; worker stderr and container exit/OOM diagnostics are NOT captured' missing-jq.log)" = 2 ]
grep -q '^compose.prod.yaml | --env-file .env.prod up -d --remove-orphans --wait --wait-timeout 300 app reverb postgres redis gotenberg lighthouse lighthouse-proxy$' docker-operations.log
cmp before.env .env.prod
printf 'PASS: missing optional jq warns at both capture points; upgrade completes without configuration changes\n'
# An RC installation lacks every Reverb setting; upgrade must supply the
# private credentials before maintenance and keep them byte-identical on reruns.
sed '/^REVERB_/d; /^BROADCAST_CONNECTION=/d' before.env > .env.prod
cp .env.prod rc.env
: > docker-operations.log
env -u COMPOSE_FILE ./scripts/upgrade.sh
head -c "$(wc -c < rc.env | tr -d ' ')" .env.prod | cmp rc.env -
for key in REVERB_APP_ID REVERB_APP_KEY REVERB_APP_SECRET; do
    value=$(sed -n "s/^$key=//p" .env.prod)
    [ "${#value}" = 64 ]
done
model | jq -e '.services.app.environment | .BROADCAST_CONNECTION == "reverb" and .REVERB_HOST == "reverb" and .REVERB_PORT == "8080" and .REVERB_SCHEME == "http"' >/dev/null
cp .env.prod before.env
env -u COMPOSE_FILE ./scripts/upgrade.sh
cmp before.env .env.prod
sed '/^REVERB_APP_SECRET=/d' before.env > .env.prod
existing_id=$(sed -n 's/^REVERB_APP_ID=//p' .env.prod)
existing_key=$(sed -n 's/^REVERB_APP_KEY=//p' .env.prod)
env -u COMPOSE_FILE ./scripts/upgrade.sh
[ "$(sed -n 's/^REVERB_APP_ID=//p' .env.prod)" = "$existing_id" ]
[ "$(sed -n 's/^REVERB_APP_KEY=//p' .env.prod)" = "$existing_key" ]
[ "${#existing_id}" = 64 ]
[ "$(sed -n 's/^REVERB_APP_SECRET=//p' .env.prod | wc -c | tr -d ' ')" = 65 ]
# Legacy upgrade must fail before maintenance. Rerunning installation appends only the missing key.
sed '/^COMPOSE_FILE=/d' before.env > .env.prod
cp .env.prod legacy.env
: > docker-operations.log
if env -u COMPOSE_FILE ./scripts/upgrade.sh > upgrade-error.log 2>&1; then exit 1; fi
grep -q 'rerun ./scripts/install.sh with your original flags' upgrade-error.log
[ ! -s docker-operations.log ]
notice=$(STUB_RUNNING=false ./scripts/install.sh --domain own.example.com --owner-email owner@example.com)
printf '%s\n' "$notice" | grep -Fq 'docker compose --env-file .env.prod up -d --wait'
if printf '%s\n' "$notice" | grep -q 'Owner created'; then exit 1; fi
[ ! -s docker-operations.log ] || { echo 'Legacy installer started services before upgrade' >&2; exit 1; }
head -c "$(wc -c < legacy.env | tr -d ' ')" .env.prod | cmp legacy.env -
[ "$(sed -n 's/^COMPOSE_FILE=//p' .env.prod)" = compose.prod.yaml ]
# Proxy mode never publishes TLS ports, and preserves the loopback-only binding.
rm .env.prod
./scripts/install.sh --domain proxy.example.com --behind-proxy --port 8386 --without-browser
[ "$(sed -n 's/^COMPOSE_FILE=//p' .env.prod)" = compose.prod.yaml:compose.proxy.yaml ]
grep -q '^COMPOSE_PROFILES=disabled$' .env.prod
grep -q '^HEPTAPOD_BROWSER_ENABLED=false$' .env.prod
model | jq -e '.services.app.ports | length == 1 and .[0].host_ip == "127.0.0.1" and .[0].published == "8386" and .[0].target == 8080' >/dev/null
model | jq -e '.services | has("lighthouse") | not' >/dev/null
: > docker-operations.log
env -u COMPOSE_FILE ./scripts/upgrade.sh
grep -q '^compose.prod.yaml:compose.proxy.yaml | --env-file .env.prod exec -T app php artisan down$' docker-operations.log
grep -q '^compose.prod.yaml:compose.proxy.yaml | --env-file .env.prod --profile browser rm -s -f lighthouse lighthouse-proxy$' docker-operations.log
# Ordered extra overrides survive installation and upgrade unchanged.
rm .env.prod
COMPOSE_FILE=compose.prod.yaml:compose.rc.yaml:compose.proxy.yaml ./scripts/install.sh --domain extra.example.com --behind-proxy --port 8387
model | jq -e '.services.app.labels."upgrade-mode-test" == "rc" and (.services.app.ports | length == 1 and .[0].host_ip == "127.0.0.1")' >/dev/null
cp .env.prod before.env
COMPOSE_FILE=compose.prod.yaml:compose.rc.yaml:compose.proxy.yaml ./scripts/install.sh --domain extra.example.com --behind-proxy --port 8387
cmp before.env .env.prod
sed '/^COMPOSE_FILE=/d' before.env > .env.prod
cp .env.prod legacy.env
: > docker-operations.log
if COMPOSE_FILE=compose.prod.yaml:compose.rc.yaml:compose.proxy.yaml ./scripts/upgrade.sh > upgrade-error.log 2>&1; then exit 1; fi
grep -q 'rerun ./scripts/install.sh with your original flags' upgrade-error.log
[ ! -s docker-operations.log ]
notice=$(STUB_RUNNING=false COMPOSE_FILE=compose.prod.yaml:compose.rc.yaml:compose.proxy.yaml ./scripts/install.sh --domain extra.example.com --behind-proxy --port 8387)
printf '%s\n' "$notice" | grep -Fq 'docker compose --env-file .env.prod up -d --wait'
[ ! -s docker-operations.log ] || { echo 'Legacy installer started services before upgrade' >&2; exit 1; }
head -c "$(wc -c < legacy.env | tr -d ' ')" .env.prod | cmp legacy.env -
cp .env.prod before.env
: > docker-operations.log
env -u COMPOSE_FILE ./scripts/upgrade.sh
grep -q '^compose.prod.yaml:compose.rc.yaml:compose.proxy.yaml | --env-file .env.prod exec -T app php artisan down$' docker-operations.log
cmp before.env .env.prod
: > docker-operations.log
if COMPOSE_FILE=compose.prod.yaml:compose.proxy.yaml ./scripts/install.sh --domain extra.example.com --behind-proxy --port 8387 > upgrade-error.log 2>&1; then exit 1; fi
grep -q 'COMPOSE_FILE differs from .env.prod; export the original file list or edit .env.prod explicitly' upgrade-error.log
[ ! -s docker-operations.log ]
cmp before.env .env.prod
: > docker-operations.log
if COMPOSE_FILE=compose.prod.yaml ./scripts/upgrade.sh > upgrade-error.log 2>&1; then exit 1; fi
grep -q 'COMPOSE_FILE conflicts with .env.prod' upgrade-error.log
[ ! -s docker-operations.log ]
printf 'PASS: own-TLS, proxy, ordered overrides, legacy recovery and shell conflict before maintenance\n'
# Pull/inspect happen while serving; the backup sees the old pin; preparation precedes both ups.
sed 's|^APP_IMAGE=.*|APP_IMAGE=old-app:retained|' .env.prod > before-pin.env
mv before-pin.env .env.prod
: > all-operations.log
: > docker-operations.log
env -u COMPOSE_FILE ./scripts/upgrade.sh
awk '
    / \| pull ghcr.io/ { if (down) exit 1; pulled=1 }
    / \| image inspect / { if (down) exit 1; inspected=1 }
    /heptapod:worker-memory/ { if (down || !pulled) exit 1; budget=1 }
    /php artisan down/ { if (!pulled || !inspected || !budget) exit 1; down=1 }
    /php artisan backup:run/ { if (!down || $0 !~ /pin=old-app:retained$/) exit 1; backup=1 }
    /run --rm --no-deps -T -e RUN_MIGRATIONS=1 -e PGAPPNAME=[^ ]+ --name [^ ]+ app true/ { if (!backup || up) exit 1; prepared=1 }
    /up -d --remove-orphans/ { if (!prepared) exit 1; up=1 }
    /up -d --wait --wait-timeout 300 horizon scheduler dispatcher/ { if (!up) exit 1; writers=1 }
    END { if (!writers) exit 1 }
' all-operations.log
cp .env.prod failure-before.env
: > all-operations.log
: > docker-operations.log
# An undersized worker cap refuses with the candidate image before maintenance, backup or migrations.
if STUB_WORKER_MEMORY_FAIL=true env -u COMPOSE_FILE ./scripts/upgrade.sh > worker-memory.log 2>&1; then cat worker-memory.log >&2; exit 1; fi
grep -q 'HORIZON_MEMORY_LIMIT is 832m' worker-memory.log
grep -q 'Worker memory preflight did not pass' worker-memory.log
grep -q 'heptapod:worker-memory' docker-operations.log
if grep -q 'php artisan down\|backup:run\|RUN_MIGRATIONS=1' docker-operations.log; then echo 'Worker memory refusal reached maintenance' >&2; exit 1; fi
cmp failure-before.env .env.prod
[ ! -f "$lifecycle_state/phase" ]
printf 'PASS: undersized HORIZON_MEMORY_LIMIT refuses before php artisan down, backup and migrations\n'
# Configured caps above host RAM minus the reserve refuse before maintenance too. MemTotal is faked; the real host is not read.
printf 'MemTotal:        3145728 kB\n' > small-host.meminfo
: > all-operations.log
: > docker-operations.log
if HEPTAPOD_HOST_MEMINFO="$PWD/small-host.meminfo" env -u COMPOSE_FILE ./scripts/upgrade.sh > host-memory.log 2>&1; then cat host-memory.log >&2; exit 1; fi
grep -q "Memory preflight failed: the enabled services' caps total [0-9]* MiB, but this host has 3072 MiB RAM minus a 512 MiB host reserve (2560 MiB)" host-memory.log
if grep -q 'php artisan down\|backup:run\|RUN_MIGRATIONS=1' docker-operations.log; then echo 'Host memory refusal reached maintenance' >&2; exit 1; fi
cmp failure-before.env .env.prod
[ ! -f "$lifecycle_state/phase" ]
printf 'PASS: caps above host RAM minus reserve refuse before php artisan down, backup and migrations\n'
: > all-operations.log
: > docker-operations.log
if FAIL_PULL=true env -u COMPOSE_FILE ./scripts/upgrade.sh > failed-pull.log 2>&1; then exit 1; fi
cat failed-pull.log
grep -q 'Cannot pull' failed-pull.log
if grep -q 'php artisan down\|backup:run' docker-operations.log; then echo 'Failed pull interrupted the old stack' >&2; exit 1; fi
cmp failure-before.env .env.prod
[ ! -f "$lifecycle_state/phase" ]
: > docker-operations.log
if STUB_BAD_REVISION=true env -u COMPOSE_FILE ./scripts/upgrade.sh > bad-revision.log 2>&1; then exit 1; fi
grep -q 'Revision label mismatch' bad-revision.log
if grep -q 'php artisan down\|backup:run' docker-operations.log; then exit 1; fi
cmp failure-before.env .env.prod
: > docker-operations.log
if STUB_FREE_KB=12582912 env -u COMPOSE_FILE ./scripts/upgrade.sh > restore-headroom.log 2>&1; then exit 1; fi
grep -q 'require 12582914 KiB' restore-headroom.log
if grep -q 'php artisan down\|backup:run' docker-operations.log; then exit 1; fi
cmp failure-before.env .env.prod
cat bad-revision.log restore-headroom.log
: > all-operations.log
if FAIL_BACKUP=true env -u COMPOSE_FILE ./scripts/upgrade.sh > failed-backup.log 2>&1; then exit 1; fi
grep -q 'Upgrade aborted before any data change; old version resumed' failed-backup.log
grep -q 'php artisan up | phase=resumed |' all-operations.log
[ ! -f "$lifecycle_state/phase" ]
cmp failure-before.env .env.prod
cat failed-backup.log
: > docker-operations.log
if APP_IMAGE='' env -u COMPOSE_FILE ./scripts/upgrade.sh > empty-export.log 2>&1; then exit 1; fi
grep -q 'Unset exported APP_IMAGE' empty-export.log
if LIGHTHOUSE_IMAGE='' env -u COMPOSE_FILE ./scripts/upgrade.sh > empty-lighthouse-export.log 2>&1; then exit 1; fi
grep -q 'Unset exported LIGHTHOUSE_IMAGE' empty-lighthouse-export.log
if grep -q 'php artisan down\|backup:run' docker-operations.log; then exit 1; fi
cmp failure-before.env .env.prod
if STUB_DIRTY=true env -u COMPOSE_FILE ./scripts/upgrade.sh > dirty.log 2>&1; then exit 1; fi
grep -q 'Dirty checkout has no published image; use --build' dirty.log
# All lifecycle entry points share the lock.
flock "$fresh/$lifecycle_dir/lifecycle.lock" sh -c 'touch lock-held; sleep 5' &
lock_pid=$!
while [ ! -f lock-held ]; do sleep 0.1; done
if env -u COMPOSE_FILE ./scripts/upgrade.sh > locked.log 2>&1; then exit 1; fi
grep -q 'Another install/upgrade/rollback is running' locked.log
if env -u COMPOSE_FILE ./scripts/install.sh --domain extra.example.com > locked.log 2>&1; then exit 1; fi
grep -q 'Another install/upgrade/rollback is running' locked.log
if env -u COMPOSE_FILE ./scripts/rollback.sh > locked.log 2>&1; then exit 1; fi
grep -q 'Another install/upgrade/rollback is running' locked.log
cat locked.log
wait "$lock_pid"
printf 'PASS: image failure never freezes; immutable pins, one-shot preparation, writer ordering and lifecycle lock\n'
: > docker-operations.log
if env -u COMPOSE_FILE ./scripts/upgrade.sh --to v9.9.9 > bundle-git.log 2>&1; then exit 1; fi
grep -Fq 'This is a Git checkout: run git fetch --tags && git checkout <tag> first' bundle-git.log
[ ! -s docker-operations.log ]
# A bundle install: the instance root keeps .env.prod, .heptapod and the operator's compose.rc.yaml; each release
# is releases/<tag>, current names one, and every top-level bundle entry is the root symlink current/<entry>.
# Each fake release's upgrade.sh records its starts, its helpers record which release's script sourced them, and
# its operations.md carries a marker line. The stub images carry revision 000...001; STUB_RUNNING_REVISION is the
# running app's label.
bundle_revision=$(printf '%040d' 1)
installed_revision=$(printf '%040d' 9)
# build_bundle TAG REVISION [SHELL]: the branch's bundle with those markers; SHELL changes it before packing.
build_bundle() {
    sh "$root/scripts/build-release-bundle.sh" "$1" "$2" "$fresh/build" > /dev/null
    tar -xzf "$fresh/build/heptapod-$1.tar.gz" -C "$fresh/build"
    bundle_dir=$fresh/build/heptapod-$1
    sed -i "2i printf 'bundle upgrade.sh %s %s\\\\n' '$1' \"\$*\" >> '$fresh/reexec.log'" "$bundle_dir/scripts/upgrade.sh"
    for helper in lifecycle-lock.sh release-image.sh; do
        sed -i "2i printf '%s of %s from %s\\\\n' '$helper' '$1' \"\${release_dir##*/}\" >> '$fresh/sourced.log'" "$bundle_dir/scripts/$helper"
    done
    printf '\nfake %s\n' "$1" >> "$bundle_dir/docs/deploy/operations.md"
    if [ -n "${3:-}" ]; then (cd "$bundle_dir" && eval "$3"); fi
    mkdir -p "$fresh/release/$1"
    tar -C "$fresh/build" -czf "$fresh/release/$1/heptapod-$1.tar.gz" "heptapod-$1"
    (cd "$fresh/release/$1" && sha256sum "heptapod-$1.tar.gz" > "heptapod-$1.tar.gz.sha256")
}
# Rewrites the MANIFEST so that it lists every file in the bundle directory, REVISION last.
relist() {
    { find . -type f ! -name REVISION | LC_ALL=C sort; echo ./REVISION; } > ../MANIFEST.new
    mv ../MANIFEST.new MANIFEST
}
root_entries() { sed 's|^\./||; s|/.*||' "$1/MANIFEST" | LC_ALL=C sort -u; }
build_bundle v9.9.8 "$installed_revision"
build_bundle v9.9.9 "$bundle_revision"
# v9.9.10 drops LICENSE and ships a new top-level entry.
build_bundle v9.9.10 "$bundle_revision" 'rm LICENSE && printf new > NEW_ENTRY && relist'
build_bundle v9.9.20 "$bundle_revision"
mkdir -p "$fresh/api/releases" git-layout
printf '{"tag_name": "v9.9.9"}' > "$fresh/api/releases/latest"
export HEPTAPOD_RELEASE_API="file://$fresh/api" HEPTAPOD_RELEASE_DOWNLOAD="file://$fresh/release"
mv scripts compose.prod.yaml compose.proxy.yaml .env.prod.example git-layout/
mkdir releases
mv "$fresh/build/heptapod-v9.9.8" releases/v9.9.8
ln -s releases/v9.9.8 current
for entry in $(root_entries current); do ln -s "current/$entry" "$entry"; done
# An older release folder that pruning removes.
cp -pR releases/v9.9.8 releases/v9.9.7
cp .env.prod bundle-before.env
cp compose.rc.yaml compose.rc.before
installed_entries=$(root_entries current)
: > reexec.log
: > sourced.log
# The installed release is unchanged: current, every root symlink, the release folders, configuration and the override.
installed_unchanged() {
    [ "$(readlink current)" = releases/v9.9.8 ]
    [ "$(cat RELEASE)" = v9.9.8 ]
    [ "$(cat REVISION)" = "$installed_revision" ]
    for entry in $installed_entries; do [ "$(readlink "$entry")" = "current/$entry" ]; done
    [ "$(LC_ALL=C ls -A releases | tr '\n' ' ')" = 'v9.9.7 v9.9.8 ' ]
    [ ! -e .current.tmp ]
    cmp bundle-before.env .env.prod
    cmp compose.rc.before compose.rc.yaml
    if grep -v '^bundle upgrade.sh v9.9.8 ' reexec.log; then echo 'Another release ran' >&2; exit 1; fi
}
# Compose's project identity from the instance root: name, project directory (build context), volume names and
# the env_file .env.prod. Unchanged by a current swap.
compose_identity() {
    env -u COMPOSE_PROJECT_NAME "$REAL_DOCKER" compose --env-file .env.prod config --format json |
        jq -c '{name, context: .services.app.build.context, volumes: [.volumes[].name] | sort, app_key: .services.app.environment.APP_KEY}'
}
identity_before=$(compose_identity)
printf '%s\n' "$identity_before" | jq -e --arg root "$fresh" --arg key "$(sed -n 's/^APP_KEY=//p' .env.prod)" \
    '.name == "heptapod" and .context == $root and .app_key == $key and (.volumes | index("heptapod_postgres") != null)' > /dev/null
: > docker-operations.log
if STUB_NO_GIT=true env -u COMPOSE_FILE ./scripts/upgrade.sh --staged "$bundle_revision" > bundle-staged.log 2>&1; then exit 1; fi
grep -Fq "does not match REVISION $installed_revision" bundle-staged.log
if STUB_NO_GIT=true env -u COMPOSE_FILE ./scripts/upgrade.sh --build > bundle-build.log 2>&1; then exit 1; fi
grep -Fq -- '--build needs a Git checkout' bundle-build.log
cp "$fresh/release/v9.9.9/heptapod-v9.9.9.tar.gz.sha256" bundle.sha256
printf '%064d  heptapod-v9.9.9.tar.gz\n' 0 > "$fresh/release/v9.9.9/heptapod-v9.9.9.tar.gz.sha256"
if STUB_NO_GIT=true env -u COMPOSE_FILE ./scripts/upgrade.sh --to v9.9.9 > bundle-mismatch.log 2>&1; then exit 1; fi
grep -Fq 'Checksum mismatch for heptapod-v9.9.9.tar.gz' bundle-mismatch.log
mv bundle.sha256 "$fresh/release/v9.9.9/heptapod-v9.9.9.tar.gz.sha256"
installed_unchanged
# Checksum-valid hostile bundles: an unlisted or listed .env.prod, a listed .heptapod, current or releases path,
# an unterminated MANIFEST, an unlisted symlink, and a new top-level entry that an operator file at the root
# already uses.
build_bundle v9.9.11 "$bundle_revision" "printf 'APP_KEY=planted\\n' > .env.prod"
build_bundle v9.9.12 "$bundle_revision" "printf 'APP_KEY=planted\\n' > .env.prod && relist"
build_bundle v9.9.13 "$bundle_revision" 'mkdir .heptapod && printf planted > .heptapod/lifecycle.lock && relist'
build_bundle v9.9.14 "$bundle_revision" 'truncate -s -1 MANIFEST'
build_bundle v9.9.15 "$bundle_revision" 'mkdir current && printf planted > current/x && relist'
build_bundle v9.9.16 "$bundle_revision" 'mkdir -p releases/v9.9.8 && printf planted > releases/v9.9.8/x && relist'
build_bundle v9.9.17 "$bundle_revision" 'printf new > notes.txt && relist'
build_bundle v9.9.18 "$bundle_revision" 'ln -s /etc/passwd scripts/passwd'
# The fixtures are what they claim.
grep -Fxq ./.env.prod "$fresh/build/heptapod-v9.9.12/MANIFEST"
grep -Fxq ./.heptapod/lifecycle.lock "$fresh/build/heptapod-v9.9.13/MANIFEST"
[ -n "$(tail -c 1 "$fresh/build/heptapod-v9.9.14/MANIFEST")" ]
grep -Fxq ./current/x "$fresh/build/heptapod-v9.9.15/MANIFEST"
grep -Fxq ./releases/v9.9.8/x "$fresh/build/heptapod-v9.9.16/MANIFEST"
[ -L "$fresh/build/heptapod-v9.9.18/scripts/passwd" ]
for hostile in v9.9.11 v9.9.12 v9.9.13 v9.9.14 v9.9.15 v9.9.16 v9.9.18; do
    if STUB_NO_GIT=true env -u COMPOSE_FILE ./scripts/upgrade.sh --to "$hostile" > bundle-manifest.log 2>&1; then exit 1; fi
    grep -Fq "heptapod-$hostile.tar.gz does not match its MANIFEST. Nothing was changed." bundle-manifest.log
    installed_unchanged
    [ "$(cat "$lifecycle_dir/lifecycle.lock")" = '' ]
done
printf 'operator notes\n' > notes.txt
if STUB_NO_GIT=true env -u COMPOSE_FILE ./scripts/upgrade.sh --to v9.9.17 > bundle-entry.log 2>&1; then exit 1; fi
grep -Fq "Refusing $fresh/notes.txt: expected a symlink to current/notes.txt owned by root or you. current is unchanged." bundle-entry.log
[ "$(cat notes.txt)" = 'operator notes' ]
rm notes.txt
installed_unchanged
[ ! -s docker-operations.log ]
printf 'PASS: a stale --staged, --build, a checksum mismatch, a state or reserved path, an unterminated MANIFEST, an unlisted symlink and a conflicting root entry refuse before current or a service changes\n'
# Only vX.Y.Z releases exist; fields compare as numbers. The fixture has no older bundle: refusals precede any download.
for invalid in v9.9.10-rc.1 v1.0.0-alpha10 v1.0.0-alpha2; do
    if STUB_NO_GIT=true env -u COMPOSE_FILE ./scripts/upgrade.sh --to "$invalid" > bundle-tag.log 2>&1; then exit 1; fi
    grep -Fq "Invalid release tag: $invalid. Heptapod publishes only vX.Y.Z release tags" bundle-tag.log
done
printf '{"tag_name": "v9.9.11-rc.1"}' > "$fresh/api/releases/latest"
if STUB_NO_GIT=true env -u COMPOSE_FILE ./scripts/upgrade.sh > bundle-tag.log 2>&1; then exit 1; fi
grep -Fq 'Invalid release tag: v9.9.11-rc.1. Heptapod publishes only vX.Y.Z release tags' bundle-tag.log
printf '{"tag_name": "v9.9.9"}' > "$fresh/api/releases/latest"
for older in v9.9.7 v0.9.99; do
    if STUB_NO_GIT=true env -u COMPOSE_FILE ./scripts/upgrade.sh --to "$older" > bundle-downgrade.log 2>&1; then cat bundle-downgrade.log >&2; exit 1; fi
    grep -Fq "Refusing to downgrade from v9.9.8 to $older. Returning to an older release means restoring a backup taken with it" bundle-downgrade.log
done
# The installed tag is read from RELEASE: compare v0.10.0 and v0.9.0 without another release folder.
printf 'v0.10.0\n' > releases/v9.9.8/RELEASE
if STUB_NO_GIT=true env -u COMPOSE_FILE ./scripts/upgrade.sh --to v0.9.0 > bundle-downgrade.log 2>&1; then exit 1; fi
grep -Fq 'Refusing to downgrade from v0.10.0 to v0.9.0' bundle-downgrade.log
printf 'v0.9.0\n' > releases/v9.9.8/RELEASE
if STUB_NO_GIT=true env -u COMPOSE_FILE ./scripts/upgrade.sh --to v0.10.0 > bundle-newer.log 2>&1; then exit 1; fi
grep -Fq "Cannot download file://$fresh/release/v0.10.0/heptapod-v0.10.0.tar.gz" bundle-newer.log
if grep -q Refusing bundle-newer.log; then exit 1; fi
printf 'v9.9.8\n' > releases/v9.9.8/RELEASE
STUB_RUNNING_REVISION=$installed_revision STUB_NO_GIT=true env -u COMPOSE_FILE ./scripts/upgrade.sh --to v9.9.8 > bundle-same.log
grep -Fxq 'Release v9.9.8 is already deployed; nothing to upgrade.' bundle-same.log
installed_unchanged
[ ! -s docker-operations.log ]
printf 'PASS: pre-release tags are refused, v0.10.0 is newer than v0.9.0, older targets are refused and the deployed release is a no-op\n'
# Seams: STUB_KILL_MV_TO stops the host (SIGKILL of the upgrade's process group) just before mv -T onto that
# path; STUB_FOREIGN_PATH reports another owner for that one path; STUB_SWAP_BEFORE_LOCK points current there
# just before this run takes the lock, as another lifecycle run would after this run's first check.
REAL_MV=$(command -v mv)
REAL_STAT=$(command -v stat)
REAL_FLOCK=$(command -v flock)
export REAL_MV REAL_STAT REAL_FLOCK
cat > "$fresh/bin/mv" <<'SH'
#!/bin/sh
if [ -n "${STUB_KILL_MV_TO:-}" ] && [ "$1" = -T ] && [ "$3" = "$STUB_KILL_MV_TO" ]; then kill -KILL 0; fi
exec "$REAL_MV" "$@"
SH
cat > "$fresh/bin/stat" <<'SH'
#!/bin/sh
if [ "$1 $2" = '-c %u' ] && [ "$3" = "${STUB_FOREIGN_PATH:-}" ]; then echo 1234; else exec "$REAL_STAT" "$@"; fi
SH
cat > "$fresh/bin/flock" <<'SH'
#!/bin/sh
if [ -n "${STUB_SWAP_BEFORE_LOCK:-}" ]; then ln -sfn "$STUB_SWAP_BEFORE_LOCK" current; fi
exec "$REAL_FLOCK" "$@"
SH
chmod +x "$fresh/bin/mv" "$fresh/bin/stat" "$fresh/bin/flock"
# A symlinked lock file, lifecycle state, current or root entry owned by another user, current naming another
# release, a root entry that is no symlink, a symlinked releases/ and a Git checkout beside current: refused.
unsafe_refused() {
    if STUB_NO_GIT=true env -u COMPOSE_FILE ./scripts/upgrade.sh > bundle-state.log 2>&1; then cat bundle-state.log >&2; exit 1; fi
    grep -Fq "$1" bundle-state.log || { cat bundle-state.log >&2; exit 1; }
}
mkdir -p "$fresh/outside"
printf 'keep\n' > "$fresh/outside/lifecycle.lock"
chmod 644 "$fresh/outside/lifecycle.lock"
rm "$lifecycle_dir/lifecycle.lock"
ln -s "$fresh/outside/lifecycle.lock" "$lifecycle_dir/lifecycle.lock"
unsafe_refused "Refusing $fresh/.heptapod/lifecycle.lock: it is a symlink or owned by another user. Nothing was changed."
[ "$(cat "$fresh/outside/lifecycle.lock")" = keep ]
[ "$(stat -c %a "$fresh/outside/lifecycle.lock")" = 644 ]
rm "$lifecycle_dir/lifecycle.lock"
STUB_FOREIGN_PATH=.heptapod unsafe_refused "Refusing $fresh/.heptapod: it is a symlink or owned by another user. Nothing was changed."
STUB_FOREIGN_PATH=releases unsafe_refused "Refusing $fresh/releases: it is a symlink or owned by another user. Nothing was changed."
STUB_FOREIGN_PATH=current unsafe_refused "Refusing $fresh/current: expected a symlink to releases/v9.9.8, the release of this script, owned by root or you."
STUB_FOREIGN_PATH=scripts unsafe_refused "Refusing $fresh/scripts: expected a symlink to current/scripts owned by root or you."
ln -sfn releases/v9.9.7 current
if STUB_NO_GIT=true env -u COMPOSE_FILE releases/v9.9.8/scripts/upgrade.sh > bundle-state.log 2>&1; then exit 1; fi
grep -Fq "Refusing $fresh/current: expected a symlink to releases/v9.9.8, the release of this script" bundle-state.log
ln -sfn releases/v9.9.8 current
STUB_SWAP_BEFORE_LOCK=releases/v9.9.7 unsafe_refused "Refusing $fresh/current: expected a symlink to releases/v9.9.8, the release of this script"
ln -sfn releases/v9.9.8 current
mv compose.proxy.yaml compose.proxy.link
cp current/compose.proxy.yaml compose.proxy.yaml
unsafe_refused "Refusing $fresh/compose.proxy.yaml: expected a symlink to current/compose.proxy.yaml owned by root or you."
mv compose.proxy.link compose.proxy.yaml
mv releases moved-releases
ln -s moved-releases releases
unsafe_refused "Refusing $fresh/moved-releases/v9.9.8: a release bundle runs from releases/<tag> below its installation directory"
[ ! -e moved-releases/v9.9.8/.heptapod ]
rm releases
mv moved-releases releases
mkdir .git
unsafe_refused "Refusing $fresh: it holds both a Git checkout (.git) and a release folder link (current)."
rmdir .git
installed_unchanged
[ ! -s docker-operations.log ]
printf 'PASS: symlinked or foreign-owned state, current or root entries, another release in current (also swapped just before the lock), a moved releases/ and .git beside current refuse before any change\n'
# The host stops just before the release folder is published, then just before current is swapped: neither run
# changes current, a service or .env.prod; the second run deletes the first run's unpacked .tmp folder.
for kill_at in releases/v9.9.9 current; do
    if STUB_KILL_MV_TO=$kill_at STUB_NO_GIT=true setsid -w env -u COMPOSE_FILE ./scripts/upgrade.sh --to v9.9.9 > bundle-killed.log 2>&1; then exit 1; fi
    [ "$(readlink current)" = releases/v9.9.8 ]
    cmp bundle-before.env .env.prod
    [ ! -s docker-operations.log ]
    if grep -q 'upgrade.sh v9.9.9' reexec.log; then exit 1; fi
    case "$kill_at" in
        releases/v9.9.9) [ -d releases/.v9.9.9.tmp/heptapod-v9.9.9 ]; [ ! -e releases/v9.9.9 ] ;;
        current) [ ! -e releases/.v9.9.9.tmp/heptapod-v9.9.9 ]; [ -d releases/v9.9.9 ]; [ "$(readlink .current.tmp)" = releases/v9.9.9 ] ;;
    esac
done
[ "$identity_before" = "$(compose_identity)" ]
printf 'PASS: an upgrade killed before publishing or before the current swap leaves current, the stack and .env.prod unchanged\n'
# Then the image pull fails after the swap: the old stack keeps serving, current names v9.9.9, and a retry of the
# same target continues with the published revision instead of reporting it as deployed.
: > all-operations.log
if FAIL_PULL=true STUB_RUNNING_REVISION=$installed_revision STUB_NO_GIT=true env -u COMPOSE_FILE ./scripts/upgrade.sh --to v9.9.9 > bundle-pull-failed.log 2>&1; then exit 1; fi
grep -Fq 'Cannot pull' bundle-pull-failed.log
grep -Fq "Published v9.9.9 ($bundle_revision) as releases/v9.9.9; continuing with its upgrade.sh." bundle-pull-failed.log
[ "$(readlink current)" = releases/v9.9.9 ]
[ "$(cat RELEASE)" = v9.9.9 ]
[ ! -e .current.tmp ]
if grep -q 'php artisan down' all-operations.log; then exit 1; fi
cmp bundle-before.env .env.prod
[ "$identity_before" = "$(compose_identity)" ]
STUB_RUNNING_REVISION=$installed_revision STUB_NO_GIT=true env -u COMPOSE_FILE ./scripts/upgrade.sh --to v9.9.9 > bundle-upgrade.log
grep -Fq "Release v9.9.9 is in place but not deployed (running revision $installed_revision); continuing its upgrade." bundle-upgrade.log
[ "$(grep -c '^bundle upgrade.sh v9.9.9 --staged' reexec.log)" = 2 ]
grep -q "| pull ghcr.io/heptapodwatch/heptapod-app:$bundle_revision |" all-operations.log
[ "$(cat "$lifecycle_state/phase")" = committed ]
cmp bundle-before.env .env.prod
cmp compose.rc.before compose.rc.yaml
# Pruning after the committed upgrade keeps current and the previous release only.
[ "$(LC_ALL=C ls -A releases | tr '\n' ' ')" = 'v9.9.8 v9.9.9 ' ]
grep -Fxq 'fake v9.9.9' docs/deploy/operations.md
STUB_RUNNING_REVISION=$bundle_revision STUB_NO_GIT=true env -u COMPOSE_FILE ./scripts/upgrade.sh --to v9.9.9 > bundle-same.log
grep -Fxq 'Release v9.9.9 is already deployed; nothing to upgrade.' bundle-same.log
printf 'PASS: --to publishes releases/v9.9.9 and keeps .env.prod; after a failed image pull the same target continues to commit and prunes to current and previous, then is a no-op\n'
# Without arguments: the latest release. A container that bind-mounts a file through a root symlink keeps every
# release folder. The new release adds and removes root entries.
printf '{"tag_name": "v9.9.10"}' > "$fresh/api/releases/latest"
STUB_BIND_SOURCE=$fresh/docs/deploy/operations.md STUB_RUNNING_REVISION=$bundle_revision STUB_NO_GIT=true env -u COMPOSE_FILE ./scripts/upgrade.sh > bundle-latest.log 2>&1
grep -Fq "Published v9.9.10 ($bundle_revision) as releases/v9.9.10" bundle-latest.log
grep -Fq "WARNING: a running container bind-mounts $fresh/docs/deploy/operations.md from a release folder; no release folder was removed." bundle-latest.log
[ "$(tail -n 1 reexec.log)" = "bundle upgrade.sh v9.9.10 --staged $bundle_revision" ]
[ "$(cat RELEASE)" = v9.9.10 ]
[ "$(cat "$lifecycle_state/phase")" = committed ]
[ "$(readlink NEW_ENTRY)" = current/NEW_ENTRY ]
[ ! -e LICENSE ]
[ ! -L LICENSE ]
[ "$(LC_ALL=C ls -A releases | tr '\n' ' ')" = 'v9.9.10 v9.9.8 v9.9.9 ' ]
[ "$identity_before" = "$(compose_identity)" ]
STUB_RUNNING_REVISION=$bundle_revision STUB_NO_GIT=true env -u COMPOSE_FILE ./scripts/upgrade.sh > bundle-latest-again.log
grep -Fxq 'Release v9.9.10 is already deployed; nothing to upgrade.' bundle-latest-again.log
# The same running revision after an aborted upgrade that resumed the old stack is not a completed deployment.
printf 'resumed\n' > "$lifecycle_state/phase"
STUB_RUNNING_REVISION=$bundle_revision STUB_NO_GIT=true env -u COMPOSE_FILE ./scripts/upgrade.sh > bundle-resumed.log
grep -Fq 'Release v9.9.10 is in place but not deployed' bundle-resumed.log
[ "$(cat "$lifecycle_state/phase")" = committed ]
if STUB_NO_GIT=true env -u COMPOSE_FILE ./scripts/upgrade.sh --to v9.9.9 > bundle-downgrade.log 2>&1; then exit 1; fi
grep -Fq 'Refusing to downgrade from v9.9.10 to v9.9.9' bundle-downgrade.log
# The previous release's own scripts no longer run: current names v9.9.10.
if STUB_NO_GIT=true env -u COMPOSE_FILE releases/v9.9.9/scripts/upgrade.sh > bundle-previous.log 2>&1; then exit 1; fi
grep -Fq "Refusing $fresh/current: expected a symlink to releases/v9.9.9, the release of this script" bundle-previous.log
STUB_RUNNING_REVISION=$bundle_revision STUB_NO_GIT=true env -u COMPOSE_FILE ./scripts/upgrade.sh --to v9.9.20 > bundle-prune.log 2>&1
[ "$(readlink current)" = releases/v9.9.20 ]
[ "$(LC_ALL=C ls -A releases | tr '\n' ' ')" = 'v9.9.10 v9.9.20 ' ]
[ "$(readlink LICENSE)" = current/LICENSE ]
[ ! -L NEW_ENTRY ]
printf 'PASS: without arguments a bundle install upgrades to the latest release, then is a no-op; root entries follow the release; a bind-mounted release blocks pruning; v9.9.20 prunes to current and previous\n'
# No run mixed releases: every helper was sourced by a script of its own release, also right after a swap.
grep -Fxq 'lifecycle-lock.sh of v9.9.9 from v9.9.9' sourced.log
grep -Fxq 'release-image.sh of v9.9.20 from v9.9.20' sourced.log
awk '$3 != $5 { print "mixed generation: " $0; bad=1 } END { exit bad }' sourced.log
[ "$(grep -c '^bundle upgrade.sh v9.9.8 --to v9.9.9$' reexec.log)" -ge 1 ]
printf 'PASS: each run sources only its own release'"'"'s helpers; compose name, project directory, volumes and env_file stay the same across swaps\n'
for entry in $(root_entries current); do rm "$entry"; done
rm current compose.rc.before
rm -r releases reexec.log sourced.log
mv git-layout/* git-layout/.env.prod.example .
rmdir git-layout
rm "$fresh/bin/mv" "$fresh/bin/stat" "$fresh/bin/flock"
: > all-operations.log
STUB_DIRTY=true env -u COMPOSE_FILE ./scripts/upgrade.sh --build
awk '
    /pull postgres redis gotenberg/ { if (down) exit 1; official=1 }
    /build --pull app lighthouse/ { if (down) exit 1; built=1 }
    /php artisan down/ { if (!official || !built) exit 1; down=1 }
    /builder prune -f --keep-storage 5GB/ { pruned=1 }
    END { if (!down || !pruned) exit 1 }
' all-operations.log
grep -Eq "^APP_IMAGE=$local_repository/app:[a-f0-9]{40}-dirty$" .env.prod
grep -Eq "^LIGHTHOUSE_IMAGE=$local_repository/lighthouse:[a-f0-9]{40}-dirty$" .env.prod
printf 'PASS: explicit dirty source build and official pulls precede maintenance; local pins and build-cache retention preserved\n'
# Resume has already acquired images/archive. A stopped app and less than 12 GiB must not block the remaining DB work.
cp "$lifecycle_state/current.env" "$lifecycle_state/candidate.env"
printf 'preparing\n' > "$lifecycle_state/phase"
rm "$lifecycle_state/release-intent"
: > all-operations.log
STUB_RUNNING=false STUB_FREE_KB=2 env -u COMPOSE_FILE ./scripts/upgrade.sh --resume
[ "$(cat "$lifecycle_state/phase")" = committed ]
grep -q 'RUN_MIGRATIONS=0.*--name.*--entrypoint sh app' all-operations.log
if grep -q 'exec -T app php artisan down' all-operations.log; then echo 'Resume depended on a running app' >&2; exit 1; fi
cp "$lifecycle_state/current.env" "$lifecycle_state/candidate.env"
printf 'preparing\n' > "$lifecycle_state/phase"
rm "$lifecycle_state/release-intent"
: > all-operations.log
if STUB_MISSING_BACKUP=true STUB_RUNNING=false STUB_FREE_KB=1 env -u COMPOSE_FILE ./scripts/upgrade.sh --resume > missing-resume-backup.log 2>&1; then exit 1; fi
grep -q 'Cannot resume: cannot verify recorded lifecycle archive' missing-resume-backup.log
if grep -q 'RUN_MIGRATIONS=1' all-operations.log; then echo 'Resume ran preparation without its archive' >&2; exit 1; fi
cat missing-resume-backup.log
printf 'PASS: resume uses only DB headroom, works without the app, and refuses a missing archive before preparation\n'
