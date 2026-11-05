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
. ./scripts/lifecycle-lock.sh
operation_phase=none
if [ -f "$lifecycle_dir/upgrade/phase" ]; then operation_phase=$(cat "$lifecycle_dir/upgrade/phase"); fi
printf '%s | %s | phase=%s | pin=%s\n' "${COMPOSE_FILE:-unset}" "$*" "$operation_phase" "$(if [ -f .env.prod ]; then sed -n 's/^APP_IMAGE=//p' .env.prod; fi)" >> all-operations.log
case "$*" in
    'pull '*) [ "${FAIL_PULL:-false}" = false ] || exit 42 ;;
    'image inspect --format {{range .RepoDigests}}{{println .}}{{end}} '*) printf '%s@sha256:%064d\n' "${5%:*}" 1 ;;
    'image inspect --format {{index .Config.Labels "org.opencontainers.image.revision"}} '*) if [ "${STUB_BAD_REVISION:-false}" = true ]; then printf '%040d\n' 2; else printf '%040d\n' 1; fi ;;
    'inspect --format {{index .Config.Labels "com.docker.compose.project"}} '*) echo upgrade-mode-test ;;
    'image inspect --format {{.Id}} '*) printf 'sha256:%064d\n' 2 ;;
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
grep -q 'HORIZON_MEMORY_LIMIT is 832m' worker-memory.log && grep -q 'Worker memory preflight did not pass' worker-memory.log
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
# A bundle install (no Git) stages the verified release bundle, then re-executes that bundle's upgrade.sh
# with --staged REVISION. The fake bundle's upgrade.sh records the hand-off; the stub images carry revision 000...001.
bundle_revision=$(printf '%040d' 1)
sh "$root/scripts/build-release-bundle.sh" v9.9.9 "$bundle_revision" "$fresh/build" > /dev/null
tar -xzf "$fresh/build/heptapod-v9.9.9.tar.gz" -C "$fresh/build"
sed -i "2i printf 'bundle upgrade.sh %s\\\\n' \"\$*\" >> '$fresh/reexec.log'" "$fresh/build/heptapod-v9.9.9/scripts/upgrade.sh"
mkdir -p "$fresh/release/v9.9.9" "$fresh/api/releases"
tar -C "$fresh/build" -czf "$fresh/release/v9.9.9/heptapod-v9.9.9.tar.gz" heptapod-v9.9.9
(cd "$fresh/release/v9.9.9" && sha256sum heptapod-v9.9.9.tar.gz > heptapod-v9.9.9.tar.gz.sha256)
printf '{"tag_name": "v9.9.9"}' > "$fresh/api/releases/latest"
export HEPTAPOD_RELEASE_API="file://$fresh/api" HEPTAPOD_RELEASE_DOWNLOAD="file://$fresh/release"
printf '%040d\n' 9 > REVISION
cp .env.prod bundle-before.env
: > docker-operations.log
if env -u COMPOSE_FILE ./scripts/upgrade.sh --to v9.9.9 > bundle-git.log 2>&1; then exit 1; fi
grep -Fq 'This is a Git checkout: run git fetch --tags && git checkout <tag> first' bundle-git.log
if STUB_NO_GIT=true env -u COMPOSE_FILE ./scripts/upgrade.sh --staged "$bundle_revision" > bundle-staged.log 2>&1; then exit 1; fi
grep -Fq "does not match REVISION $(printf '%040d' 9)" bundle-staged.log
if STUB_NO_GIT=true env -u COMPOSE_FILE ./scripts/upgrade.sh --build > bundle-build.log 2>&1; then exit 1; fi
grep -Fq -- '--build needs a Git checkout' bundle-build.log
cp "$fresh/release/v9.9.9/heptapod-v9.9.9.tar.gz.sha256" bundle.sha256
printf '%064d  heptapod-v9.9.9.tar.gz\n' 0 > "$fresh/release/v9.9.9/heptapod-v9.9.9.tar.gz.sha256"
if STUB_NO_GIT=true env -u COMPOSE_FILE ./scripts/upgrade.sh --to v9.9.9 > bundle-mismatch.log 2>&1; then exit 1; fi
grep -Fq 'Checksum mismatch for heptapod-v9.9.9.tar.gz' bundle-mismatch.log
mv bundle.sha256 "$fresh/release/v9.9.9/heptapod-v9.9.9.tar.gz.sha256"
[ "$(cat REVISION)" = "$(printf '%040d' 9)" ] && [ ! -e docs ] && [ ! -e reexec.log ] && [ ! -s docker-operations.log ]
cmp bundle-before.env .env.prod
[ -z "$(find "$lifecycle_dir" -name 'bundle.*')" ]
printf 'PASS: Git checkouts, a stale --staged, --build and a checksum mismatch refuse before any file or service change\n'
: > all-operations.log
STUB_NO_GIT=true env -u COMPOSE_FILE ./scripts/upgrade.sh --to v9.9.9 > bundle-upgrade.log
grep -Fq "Staged v9.9.9 ($bundle_revision); continuing with its upgrade.sh." bundle-upgrade.log
[ "$(cat reexec.log)" = "bundle upgrade.sh --staged $bundle_revision" ]
[ "$(cat REVISION)" = "$bundle_revision" ]
cmp "$fresh/build/heptapod-v9.9.9/scripts/upgrade.sh" scripts/upgrade.sh
cmp "$root/docs/deploy/operations.md" docs/deploy/operations.md
grep -q "| pull ghcr.io/heptapodwatch/heptapod-app:$bundle_revision |" all-operations.log
[ "$(cat "$lifecycle_state/phase")" = committed ]
cmp bundle-before.env .env.prod
[ -z "$(find "$lifecycle_dir" -name 'bundle.*')" ]
printf 'PASS: --to replaces the bundle files, keeps .env.prod and runs the new upgrade.sh --staged REVISION to commit\n'
: > reexec.log
STUB_NO_GIT=true env -u COMPOSE_FILE ./scripts/upgrade.sh > bundle-latest.log
grep -Fq "Staged v9.9.9 ($bundle_revision)" bundle-latest.log
[ "$(tail -n 1 reexec.log)" = "bundle upgrade.sh --staged $bundle_revision" ]
rm -r REVISION docs reexec.log
cp "$root/scripts/upgrade.sh" scripts/upgrade.sh
printf 'PASS: without arguments a bundle install upgrades to the latest release\n'
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
