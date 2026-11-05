#!/bin/sh
# Fix-round contracts against real app/Postgres/S3 services; only disk availability and injected failures are stubbed.
# shellcheck disable=SC2016
set -eu
root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
: "${APP_IMAGE:?Set APP_IMAGE to the production image under test}"
base_app=$APP_IMAGE
fresh=$(mktemp -d)
evidence=${OPS_EVIDENCE_DIR:-$(mktemp -d)}
mkdir -p "$evidence" "$fresh/scripts" "$fresh/bin"
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-ops-upgrade-recovery}"
port=${RECOVERY_PORT:-21700}
unset APP_IMAGE LIGHTHOUSE_IMAGE APP_KEY DB_PASSWORD BACKUP_ARCHIVE_PASSWORD APP_URL SERVER_NAME COMPOSE_FILE COMPOSE_PROFILES HEPTAPOD_BROWSER_ENABLED HTTP_PORT HTTPS_PORT
cp "$root/compose.prod.yaml" "$fresh/"
sed '/^BACKUP_DISK=/d; /^HEPTAPOD_STORAGE_DISK=/d; /^BACKUP_PATH=/d' "$root/.env.prod.example" > "$fresh/.env.prod.example"
cat >> "$fresh/.env.prod.example" <<'ENV'
HEPTAPOD_STORAGE_DISK=s3
BACKUP_DISK=s3
BACKUP_PATH=/app/storage/operator-backups
AWS_ACCESS_KEY_ID=ops-test-key
AWS_SECRET_ACCESS_KEY=ops-test-secret
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=heptapod
AWS_ENDPOINT=http://s3:8333
AWS_USE_PATH_STYLE_ENDPOINT=true
HEPTAPOD_UPDATE_CHECK_ENABLED=false
ENV
cp "$root/scripts/"*.sh "$root/scripts/diagnostics-filter.jq" "$root/scripts/upgrade-backup-retention.php" "$fresh/scripts/"
if [ -n "${OPS_BASELINE_UPGRADE:-}" ]; then cp "$OPS_BASELINE_UPGRADE" "$fresh/scripts/upgrade-before-fix1.sh"; fi
cat > "$fresh/test.yaml" <<YAML
x-fast-health: &fast-health
    interval: 2s
    start_period: 2s
    retries: 30
services:
    app:
        ports: !override ['127.0.0.1:$port:8080']
        environment:
            SERVER_NAME: http://localhost:8080
            SESSION_SECURE_COOKIE: 'false'
        healthcheck: *fast-health
    horizon:
        healthcheck: *fast-health
    dispatcher:
        healthcheck: *fast-health
    scheduler:
        healthcheck: *fast-health
    reverb:
        healthcheck: *fast-health
    s3:
        image: chrislusf/seaweedfs:4.17@sha256:186de7ef977a20343ee9a5544073f081976a29e2d29ecf8379891e7bf177fbe9
        command: ['server', '-s3', '-dir=/data', '-ip=s3', '-volume.max=8', '-master.volumeSizeLimitMB=32', '-s3.config=/etc/ops-s3.json']
        mem_limit: 192m
        networks: [backend]
        volumes: ['s3:/data', '$root/tests/Deployment/ops-s3.json:/etc/ops-s3.json:ro']
volumes:
    s3:
YAML
# shellcheck source=tests/Deployment/release-fixture.sh
. "$root/tests/Deployment/release-fixture.sh"
dc() { docker compose --env-file .env.prod "$@"; }
cleanup() {
    result=$?
    trap - EXIT
    if [ "$result" -ne 0 ] && [ -f .env.prod ]; then dc logs --tail=60 > "$evidence/failure-containers.log" 2>&1 || echo 'WARNING: cannot retain failure logs' >&2; fi
    if [ -f .env.prod ]; then dc down -v --remove-orphans; fi
    if [ -f "$fresh/fixture.refs" ]; then stop_release_fixture; fi
    rm -rf "$fresh"
    exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM HUP
start_release_fixture "$fresh" "$base_app"
cd "$fresh"
REAL_DOCKER=$(command -v docker)
REAL_DF=$(command -v df)
export REAL_DOCKER REAL_DF
cat > bin/docker <<'SH'
#!/bin/sh
if [ "${FIX_FAIL_PREPARE:-0}" = 1 ]; then
    case "$*" in *'run --rm --no-deps -T -e RUN_MIGRATIONS=1 '*) echo 'OPS injected prepare failure' >&2; exit 42 ;; esac
fi
exec "$REAL_DOCKER" "$@"
SH
cat > bin/df <<'SH'
#!/bin/sh
if [ -n "${FIX_FREE_KB:-}" ]; then
    printf 'Filesystem 1024-blocks Used Available Capacity Mounted-on\nfixture 999999999 0 %s 0%% /\n' "$FIX_FREE_KB"
else
    exec "$REAL_DF" "$@"
fi
SH
chmod +x bin/docker bin/df
publish_fixture_release recovery-a > "$evidence/publish.log" 2>&1
export COMPOSE_FILE=compose.prod.yaml:test.yaml
./scripts/install.sh --domain monitor.example.test --without-browser --owner-email test@example.test > "$evidence/install.log" 2>&1
unset COMPOSE_FILE
dc up -d --wait s3 > "$evidence/s3-start.log" 2>&1
dc exec -T app php -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $s=Illuminate\Support\Facades\Storage::disk("s3"); $c=$s->getClient(); if (!$c->doesBucketExistV2("heptapod")) { $c->createBucket(["Bucket"=>"heptapod"]); } $s->put("fix1/probe.txt","remote-media-unchanged"); echo json_encode(config("backup.backup.destination.disks"),JSON_THROW_ON_ERROR).PHP_EOL;' > "$evidence/s3-policy-before.log"
# Reproduce the review finding with the original command (BACKUP_PATH alone does not select a destination).
if dc exec -T -e BACKUP_PATH=/backups app php artisan backup:run --no-interaction --disable-notifications --only-to-disk=backups --filename=before-fix.zip > "$evidence/s3-backup-before-fix.log" 2>&1; then echo 'Original backup command unexpectedly succeeded' >&2; exit 1; fi
grep -q 'There is no backup destination with a disk named' "$evidence/s3-backup-before-fix.log"
cp .env.prod policy.env
if FIX_FAIL_PREPARE=1 PATH="$fresh/bin:$PATH" ./scripts/upgrade.sh > "$evidence/s3-upgrade-backup.log" 2>&1; then exit 1; fi
grep -q 'OPS injected prepare failure' "$evidence/s3-upgrade-backup.log"
[ "$(cat "$lifecycle_state/phase")" = preparing ]
archive=$(sed -n 's/^BACKUP_PATH=//p' "$lifecycle_state/backup.env")
dc exec -T app test -f "$archive"
dc exec -T app sha256sum "$archive" > "$evidence/local-lifecycle-archive.log"
dc stop app > "$evidence/stopped-app.log" 2>&1
if [ -n "${OPS_BASELINE_UPGRADE:-}" ]; then
    if sh scripts/upgrade-before-fix1.sh --resume > "$evidence/stopped-app-before-fix.log" 2>&1; then exit 1; fi
    grep -q 'Upgrade requires a running app instance' "$evidence/stopped-app-before-fix.log"
fi
db_bytes=$(dc exec -T postgres sh -ec 'psql -X -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atc "select pg_database_size(current_database())"')
remaining_kb=$(awk -v bytes="$db_bytes" 'BEGIN { printf "%.0f", 2*bytes/1024 }')
printf 'simulated free=%s KiB; DB size=%s bytes; remaining need=%s KiB\n' "$remaining_kb" "$db_bytes" "$remaining_kb" > "$evidence/resume-space.log"
if [ -n "${OPS_BASELINE_UPGRADE:-}" ]; then
    if FIX_FREE_KB="$remaining_kb" PATH="$fresh/bin:$PATH" sh scripts/upgrade-before-fix1.sh --resume > "$evidence/low-space-before-fix.log" 2>&1; then exit 1; fi
    grep -q 'Insufficient disk space' "$evidence/low-space-before-fix.log"
fi
FIX_FREE_KB="$remaining_kb" PATH="$fresh/bin:$PATH" ./scripts/upgrade.sh --resume > "$evidence/stopped-app-resume.log" 2>&1
[ "$(cat "$lifecycle_state/phase")" = committed ]
[ "$(curl -sS -o /dev/null -w '%{http_code}' "http://localhost:$port/login")" = 200 ]
cmp policy.env .env.prod
# A second interrupted upgrade covers a genuinely crash-looping web role and a missing archive.
if FIX_FAIL_PREPARE=1 PATH="$fresh/bin:$PATH" ./scripts/upgrade.sh > "$evidence/second-interruption.log" 2>&1; then exit 1; fi
archive=$(sed -n 's/^BACKUP_PATH=//p' "$lifecycle_state/backup.env")
dc exec -T app mv "$archive" "$archive.saved"
if FIX_FREE_KB="$remaining_kb" PATH="$fresh/bin:$PATH" ./scripts/upgrade.sh --resume > "$evidence/missing-archive-refused.log" 2>&1; then exit 1; fi
grep -q 'Cannot resume: cannot verify recorded lifecycle archive' "$evidence/missing-archive-refused.log"
[ "$(cat "$lifecycle_state/phase")" = preparing ]
dc exec -T app mv "$archive.saved" "$archive"
# A separate override reproduces a web startup failure without altering the recorded candidate image.
cat > crash.yaml <<'YAML'
services:
    app:
        command: ['sh', '-c', 'exit 23']
YAML
sed 's/^COMPOSE_FILE=.*/COMPOSE_FILE=compose.prod.yaml:test.yaml:crash.yaml/' .env.prod > crash.env
mv crash.env .env.prod
dc up -d --no-deps app > "$evidence/crash-loop-start.log" 2>&1
sleep 2
docker inspect --format 'restart-count={{.RestartCount}} status={{.State.Status}}' "$(dc ps -aq app)" > "$evidence/crash-loop-state.log"
# Fix the cause in the model, not by exec into the broken container.
sed 's/:crash.yaml//' .env.prod > fixed.env
mv fixed.env .env.prod
db_bytes=$(dc exec -T postgres sh -ec 'psql -X -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atc "select pg_database_size(current_database())"')
# Heartbeats/backup history can grow between resumes; allow 1 MiB above the fresh DB-only measurement.
crash_free_kb=$(awk -v bytes="$db_bytes" 'BEGIN { printf "%.0f", 2*bytes/1024 + 1024 }')
printf 'crash-loop simulated free=%s KiB; DB size=%s bytes\n' "$crash_free_kb" "$db_bytes" >> "$evidence/resume-space.log"
FIX_FREE_KB="$crash_free_kb" PATH="$fresh/bin:$PATH" ./scripts/upgrade.sh --resume > "$evidence/crash-loop-resume.log" 2>&1
[ "$(cat "$lifecycle_state/phase")" = committed ]
cmp policy.env .env.prod
dc exec -T app php -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if (config("backup.backup.destination.disks")!==["s3"]) { throw new RuntimeException("Backup policy changed"); } if (Illuminate\Support\Facades\Storage::disk("s3")->get("fix1/probe.txt")!=="remote-media-unchanged") { throw new RuntimeException("Remote media changed"); } echo json_encode(config("backup.backup.destination.disks"),JSON_THROW_ON_ERROR).PHP_EOL;' > "$evidence/s3-policy-after.log"
dc exec -T app php artisan backup:run --no-interaction --disable-notifications --filename=fix1-manual-s3.zip > "$evidence/manual-s3-backup.log" 2>&1
dc exec -T app php -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $f=Illuminate\Support\Facades\Storage::disk("s3")->allFiles(); if (!in_array("Heptapod/fix1-manual-s3.zip",$f,true)) { throw new RuntimeException("Manual backup did not use S3"); } echo json_encode($f,JSON_THROW_ON_ERROR).PHP_EOL;' > "$evidence/s3-files-after.log"
echo 'PASS: local lifecycle backup with S3 policy unchanged; manual backup still S3; low-disk resume handles stopped/crash-looping app; missing archive refused'
