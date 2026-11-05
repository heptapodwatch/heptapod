#!/bin/sh
# Shell/PHP fragments expand in the target container.
# shellcheck disable=SC2016
# Real release pulls and recovery on one disposable stack. No application test runner.
set -eu
root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
: "${APP_IMAGE:?Set APP_IMAGE to the production image under test}"
fresh=$(mktemp -d)
evidence=${OPS_EVIDENCE_DIR:-$(mktemp -d)}
mkdir -p "$evidence"
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-ops-rollback-proof}"
port=${ROLLBACK_PORT:-21600}
base_app=$APP_IMAGE
unset APP_IMAGE LIGHTHOUSE_IMAGE APP_KEY DB_PASSWORD BACKUP_ARCHIVE_PASSWORD APP_URL SERVER_NAME COMPOSE_FILE COMPOSE_PROFILES HEPTAPOD_BROWSER_ENABLED HTTP_PORT HTTPS_PORT
mkdir "$fresh/scripts"
cp "$root/compose.prod.yaml" "$root/.env.prod.example" "$fresh/"
printf '\nBACKUP_PATH=/app/storage/operator-backups\n' >> "$fresh/.env.prod.example"
cp "$root/scripts/"*.sh "$root/scripts/diagnostics-filter.jq" "$root/scripts/upgrade-backup-retention.php" "$fresh/scripts/"
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
            HEPTAPOD_UPDATE_CHECK_ENABLED: 'false'
        healthcheck: *fast-health
    horizon:
        healthcheck: *fast-health
    dispatcher:
        healthcheck: *fast-health
    scheduler:
        healthcheck: *fast-health
    reverb:
        healthcheck: *fast-health
YAML
# shellcheck source=tests/Deployment/release-fixture.sh
. "$root/tests/Deployment/release-fixture.sh"
start_release_fixture "$fresh" "$base_app"
cd "$fresh"
dc() { docker compose --env-file .env.prod "$@"; }
sql() { dc exec -T postgres sh -ec 'exec psql -X -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" "$@"' sh "$@"; }
fingerprint() { sql -Atc "select count(*)||':'||coalesce(max(id),0) from migrations"; }
cleanup() {
    status=$?
    trap - EXIT
    if [ -n "${upgrade_pid:-}" ] && kill -0 "$upgrade_pid" 2>/dev/null; then
        kill -TERM -- "-$upgrade_pid"
        wait "$upgrade_pid" || [ "$?" -ge 128 ]
    fi
    if [ -f "$lifecycle_state/prepare-container" ]; then
        cleanup_prepare=$(cat "$lifecycle_state/prepare-container")
        if docker ps --format '{{.Names}}' | grep -qxF "$cleanup_prepare"; then docker stop --time 1245 "$cleanup_prepare"; fi
    fi
    if [ "$status" -ne 0 ] && [ -f .env.prod ]; then dc logs --tail=60 app horizon dispatcher scheduler > "$evidence/failure-containers.log" 2>&1 || echo 'WARNING: could not retain failure logs' >&2; fi
    if docker container inspect "$COMPOSE_PROJECT_NAME-retention-guard" >/dev/null 2>&1; then docker rm "$COMPOSE_PROJECT_NAME-retention-guard"; fi
    if [ -f "$lifecycle_state/rollback.env" ]; then
        cleanup_volume=$(sed -n 's/^EXTRACT_VOLUME=//p' "$lifecycle_state/rollback.env")
        cleanup_stamp=$(sed -n 's/^STAMP=//p' "$lifecycle_state/rollback.env")
        if [ "$cleanup_volume" = "$lifecycle_prefix-$COMPOSE_PROJECT_NAME-restore-$cleanup_stamp" ] && docker volume inspect "$cleanup_volume" >/dev/null 2>&1; then
            cleanup_label=$(docker volume inspect --format "{{index .Labels \"$restore_volume_label\"}}" "$cleanup_volume")
            [ "$cleanup_label" = "$cleanup_stamp" ] || { echo 'Refusing fixture temporary-volume cleanup: ownership mismatch' >&2; exit 1; }
            docker volume rm "$cleanup_volume"
        fi
    fi
    if [ -f .env.prod ]; then dc --profile browser down -v --remove-orphans; fi
    stop_release_fixture
    rm -rf "$fresh"
    echo "Deployment proof output: $evidence"
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM HUP
run_ok() {
    proof_name=$1; shift
    ( "$@" ) > "$evidence/$proof_name.log" 2>&1 &
    proof_pid=$!
    if wait "$proof_pid"; then cat "$evidence/$proof_name.log"; else cat "$evidence/$proof_name.log" >&2; return 1; fi
}
run_fail() {
    proof_name=$1; shift
    if "$@" > "$evidence/$proof_name.log" 2>&1; then cat "$evidence/$proof_name.log" >&2; echo "Expected failure: $proof_name" >&2; return 1; fi
    cat "$evidence/$proof_name.log"
}
publish_fixture_release release-a > "$evidence/publish-a.log" 2>&1
cat "$evidence/publish-a.log"
sha_a=$fixture_sha
image_a=$(docker image inspect --format '{{.Id}}' "$fixture_app_ref")
export COMPOSE_FILE=compose.prod.yaml:test.yaml
# A first-install pull failure must provide a runnable recovery, never plain up against :main.
mkdir "$fresh/fail-pull-bin"
REAL_DOCKER=$(command -v docker)
export REAL_DOCKER
cat > "$fresh/fail-pull-bin/docker" <<'SH'
#!/bin/sh
if [ "$1" = pull ]; then echo 'OPS forced fresh-install pull failure' >&2; exit 42; fi
exec "$REAL_DOCKER" "$@"
SH
chmod +x "$fresh/fail-pull-bin/docker"
run_fail fresh-install-pull-failure env PATH="$fresh/fail-pull-bin:$PATH" ./scripts/install.sh --domain monitor.example.test --without-browser --owner-email test@example.test
sed -n '/^# BEGIN INSTALL RECOVERY$/,/^# END INSTALL RECOVERY$/p' "$evidence/fresh-install-pull-failure.log" > "$evidence/install-recovery.sh"
[ -s "$evidence/install-recovery.sh" ]
run_ok install-recovery sh "$evidence/install-recovery.sh"
[ "$(sql -Atc "select count(*) from users where email='test@example.test'")" = 1 ]
echo 'PASS fresh install recovery: forced pull failure, exact printed pin/start/Owner block completes installation'
unset COMPOSE_FILE
run_ok manual-custom-path dc exec -T app php artisan backup:run --no-interaction --disable-notifications --filename=ops-manual-custom.zip
dc exec -T app test -f /app/storage/operator-backups/Heptapod/ops-manual-custom.zip
[ "$(dc exec -T app printenv BACKUP_PATH)" = /app/storage/operator-backups ]
echo 'PASS configured manual backup path preserved; lifecycle backup must use /backups instead'
# Compose dotenv quoting must also work for the standalone old-image extractor.
sed 's/^BACKUP_ARCHIVE_PASSWORD=\(.*\)$/BACKUP_ARCHIVE_PASSWORD="\1"/' .env.prod > quoted.env
mv quoted.env .env.prod
# A real local row/file must survive restoration, not only migrations metadata.
sql -c "CREATE TABLE ops_keep (value text NOT NULL); INSERT INTO ops_keep VALUES ('before-upgrade')"
dc exec -T app sh -ec 'printf "retained-file-bytes\n" > /app/storage/app/ops-keep.txt'
file_before=$(dc exec -T app sha256sum /app/storage/app/ops-keep.txt)
fingerprint_a=$(fingerprint)
container_before=$(dc ps -q app)
backup_before=$(dc exec -T app sh -ec 'find /backups -type f -name "*.zip" | wc -l')
# Unpublished HEAD fails before maintenance, backup or any recreation.
printf 'unpublished\n' > release-marker
git add -f release-marker
git commit -qm unpublished
curl -fsS -D "$evidence/unpublished-up-before.headers" "http://localhost:$port/up" > "$evidence/unpublished-up-before.log"
(
    while [ ! -f "$fresh/stop-up-monitor" ]; do
        code=$(curl -sS -o /dev/null -w '%{http_code}' "http://localhost:$port/up")
        printf '%s %s\n' "$(date -u +%FT%TZ)" "$code"
        [ "$code" = 200 ] || exit 1
        sleep 0.1
    done
) > "$evidence/unpublished-up-throughout.log" &
up_monitor=$!
while [ ! -s "$evidence/unpublished-up-throughout.log" ]; do sleep 0.1; done
run_fail unpublished ./scripts/upgrade.sh
touch "$fresh/stop-up-monitor"
wait "$up_monitor"
curl -fsS -D "$evidence/unpublished-up-after.headers" "http://localhost:$port/up" > "$evidence/unpublished-up-after.log"
[ "$(dc ps -q app)" = "$container_before" ]
[ "$(dc exec -T app sh -ec 'find /backups -type f -name "*.zip" | wc -l')" = "$backup_before" ]
dc exec -T app php -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if ($a->isDownForMaintenance()) { exit(1); }'
printf 'container before=%s after=%s; backups before=%s after=%s\n' "$container_before" "$(dc ps -q app)" "$backup_before" "$(dc exec -T app sh -ec 'find /backups -type f -name "*.zip" | wc -l')" > "$evidence/unpublished-state.log"
echo 'PASS case 1: unpublished release leaves HTTP 200 throughout, container and backup count unchanged, maintenance off'
# Original case 2: a transactional failure leaves the fingerprint unchanged, allowing Mode A.
cat > release-layer/migrations/2099_01_01_000001_transactional_failure.php <<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void { \Illuminate\Support\Facades\DB::statement('CREATE TABLE ops_transaction_probe (id int)'); throw new \RuntimeException('OPS transactional migration failure'); }
};
PHP
publish_fixture_release transactional-failure > "$evidence/publish-transactional.log" 2>&1
cat "$evidence/publish-transactional.log"
transactional_image=$(docker image inspect --format '{{.Id}}' "$fixture_app_ref")
run_fail transactional-upgrade ./scripts/upgrade.sh
older_backup=$(sed -n 's/^BACKUP_PATH=//p' "$lifecycle_state/backup.env")
[ "$(fingerprint)" = "$fingerprint_a" ]
[ "$(sql -Atc "select to_regclass('public.ops_transaction_probe') is null")" = t ]
mkdir "$fresh/fail-image-rollback-bin"
cat > "$fresh/fail-image-rollback-bin/docker" <<'SH'
#!/bin/sh
case "$*" in *'up -d --remove-orphans'*) echo 'OPS injected previous-generation startup failure' >&2; exit 42 ;; esac
exec "$REAL_DOCKER" "$@"
SH
chmod +x "$fresh/fail-image-rollback-bin/docker"
run_fail image-only-start-failure env PATH="$fresh/fail-image-rollback-bin:$PATH" ./scripts/rollback.sh
grep -q 'HTTP is unavailable until recovery completes' "$evidence/image-only-start-failure.log"
awk '/^cd / && /scripts\/rollback.sh/ && !/--restore-backup/ {print}' "$evidence/image-only-start-failure.log" > "$evidence/generated-image-recovery.sh"
[ -s "$evidence/generated-image-recovery.sh" ]
run_ok image-only-rollback sh "$evidence/generated-image-recovery.sh"
[ "$(docker inspect --format '{{.Image}}' "$(dc ps -q app)")" = "$image_a" ]
curl -fsS "http://localhost:$port/login" > /dev/null
echo 'PASS original case 2: transactional failure is data-neutral; Mode A allowed with unchanged fingerprint'
rm release-layer/migrations/*.php
# A completed migration changes the fingerprint before a nontransactional failure writes a probe table.
cat > release-layer/migrations/2099_01_01_000001_completed.php <<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration { public function up(): void {} };
PHP
cat > release-layer/migrations/2099_01_01_000002_partial_failure.php <<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public $withinTransaction = false;
    public function up(): void { \Illuminate\Support\Facades\DB::statement('CREATE TABLE ops_rollback_probe (id int)'); throw new \RuntimeException('OPS nontransactional migration failure'); }
};
PHP
publish_fixture_release failing-release > "$evidence/publish-failing.log" 2>&1
cat "$evidence/publish-failing.log"
failing_image=$(docker image inspect --format '{{.Id}}' "$fixture_app_ref")
run_fail failing-upgrade ./scripts/upgrade.sh
[ "$(cat "$lifecycle_state/phase")" = preparing ]
[ "$(sql -Atc "select to_regclass('public.ops_rollback_probe') is not null")" = t ]
[ "$(curl -sS -o /dev/null -w '%{http_code}' "http://localhost:$port/login")" = 503 ]
run_fail image-only-refused ./scripts/rollback.sh
grep -q 'Migration fingerprint changed' "$evidence/image-only-refused.log"
current_backup=$(sed -n 's/^BACKUP_PATH=//p' "$lifecycle_state/backup.env")
case "$current_backup" in
    /backups/upgrade-lifecycle/*) ;;
    *) echo 'Lifecycle restore must use the separate recorded archive directory.' >&2; exit 1 ;;
esac
container_before_refusal=$(dc ps -q app)
run_fail stale-backup-refused ./scripts/rollback.sh --restore-backup "$older_backup"
grep -q 'Archive does not match the recorded upgrade' "$evidence/stale-backup-refused.log"
[ "$(dc ps -q app)" = "$container_before_refusal" ]
[ ! -f "$lifecycle_state/rollback.env" ]
cp "$lifecycle_state/backup.env" good-backup.env
sed 's/^BACKUP_SHA256=.*/BACKUP_SHA256=0000000000000000000000000000000000000000000000000000000000000000/' good-backup.env > "$lifecycle_state/backup.env"
run_fail checksum-refused ./scripts/rollback.sh --restore-backup
grep -q 'Backup checksum mismatch' "$evidence/checksum-refused.log"
[ "$(dc ps -q app)" = "$container_before_refusal" ]
[ ! -f "$lifecycle_state/rollback.env" ]
[ "$(sql -Atc "select to_regclass('public.ops_rollback_probe') is not null")" = t ]
mv good-backup.env "$lifecycle_state/backup.env"
docker volume ls --filter "label=com.docker.compose.project=$COMPOSE_PROJECT_NAME" --format '{{.Name}}' | sort > "$evidence/persistent-volumes-before.log"
# Fail only after the old extractor has written decrypted data, then prove retry/cleanup.
mkdir "$fresh/fail-extract-bin"
cat > "$fresh/fail-extract-bin/docker" <<'SH'
#!/bin/sh
case "$*" in
    *'php scripts/restore-backup.php '*) "$REAL_DOCKER" "$@" || exit "$?"; echo 'OPS forced failure after extraction' >&2; exit 42 ;;
esac
exec "$REAL_DOCKER" "$@"
SH
chmod +x "$fresh/fail-extract-bin/docker"
run_fail extracted-restore-failure env PATH="$fresh/fail-extract-bin:$PATH" ./scripts/rollback.sh --restore-backup "$current_backup"
grep -q 'holds\|hold decrypted data' "$evidence/extracted-restore-failure.log"
[ -z "$(dc ps -q app reverb horizon scheduler dispatcher)" ]
temp_volume=$(sed -n 's/^EXTRACT_VOLUME=//p' "$lifecycle_state/rollback.env")
docker volume ls --filter "name=$temp_volume" --format '{{.Name}}' > "$evidence/temporary-volume-after-failure.log"
grep -qxF "$temp_volume" "$evidence/temporary-volume-after-failure.log"
docker run --rm --network none --entrypoint sh -v "$temp_volume:/restore:ro" "$image_a" -ec 'find /restore/archive/db-dumps -type f -name "*.sql" -size +0c | grep -q .' > "$evidence/decrypted-extraction-retained.log"
cp "$lifecycle_state/rollback.env" good-rollback.env
sed "s/^EXTRACT_VOLUME=.*/EXTRACT_VOLUME=${COMPOSE_PROJECT_NAME}_storage/" good-rollback.env > "$lifecycle_state/rollback.env"
run_fail persistent-volume-refused ./scripts/rollback.sh --restore-backup "$current_backup"
grep -q 'Refusing temporary-volume access/removal' "$evidence/persistent-volume-refused.log"
if grep -q "docker volume rm '${COMPOSE_PROJECT_NAME}_storage'" "$evidence/persistent-volume-refused.log"; then exit 1; fi
mv good-rollback.env "$lifecycle_state/rollback.env"
mkdir "$fresh/fail-import-bin"
cat > "$fresh/fail-import-bin/docker" <<'SH'
#!/bin/sh
case "$*" in *'--single-transaction'*) echo 'OPS injected import failure' >&2; exit 42 ;; esac
exec "$REAL_DOCKER" "$@"
SH
chmod +x "$fresh/fail-import-bin/docker"
run_fail import-failure env PATH="$fresh/fail-import-bin:$PATH" ./scripts/rollback.sh --restore-backup "$current_backup"
grep -q 'Removed decrypted SQL dump after failed restore' "$evidence/import-failure.log"
[ ! -e "$lifecycle_state/restore.sql" ]
printf 'decrypted host dump absent after failed import: %s\n' "$lifecycle_state/restore.sql" > "$evidence/host-dump-cleanup.log"
sed -n '/^cd .*scripts\/rollback.sh.*--restore-backup /p' "$evidence/failing-upgrade.log" > "$evidence/generated-restore-command.sh"
[ -s "$evidence/generated-restore-command.sh" ]
run_ok backup-rollback env -u COMPOSE_PROJECT_NAME sh "$evidence/generated-restore-command.sh"
docker volume ls --filter "label=com.docker.compose.project=$COMPOSE_PROJECT_NAME" --format '{{.Name}}' | sort > "$evidence/persistent-volumes-after.log"
cmp "$evidence/persistent-volumes-before.log" "$evidence/persistent-volumes-after.log"
docker volume ls --filter "name=$temp_volume" --format '{{.Name}}' > "$evidence/temporary-volume-after-success.log"
[ ! -s "$evidence/temporary-volume-after-success.log" ]
echo 'PASS restore seams: stale archive/checksum refused before changes; failed extraction retained decrypted data; persistent-volume pointer refused; generated archive-bound command succeeds; only owned temporary volume removed'
[ "$(docker inspect --format '{{.Image}}' "$(dc ps -q app)")" = "$image_a" ]
[ "$(sql -Atc "select to_regclass('public.ops_rollback_probe') is null")" = t ]
[ "$(fingerprint)" = "$fingerprint_a" ]
[ "$(sql -Atc 'select value from ops_keep')" = before-upgrade ]
[ "$(dc exec -T app sha256sum /app/storage/app/ops-keep.txt)" = "$file_before" ]
failed_db=$(sed -n 's/^FAILED_DB=//p' "$lifecycle_state/rollback.env")
[ "$(sql -Atc "select count(*) from pg_database where datname='$failed_db'")" = 1 ]
curl -fsS "http://localhost:$port/login" > /dev/null
{
    docker inspect --format 'running image={{.Image}}' "$(dc ps -q app)"
    printf 'migration fingerprint=%s\n' "$(fingerprint)"
    sql -c "select to_regclass('public.ops_rollback_probe') as probe; select value from ops_keep"
    dc exec -T app sha256sum /app/storage/app/ops-keep.txt
    sql -c "select datname from pg_database where datname='$failed_db'"
    curl -sS -D - -o /dev/null "http://localhost:$port/login"
} > "$evidence/mode-b-restored-state.log"
echo "PASS case 2: Mode B restored image $image_a, fingerprint $fingerprint_a, row and byte-identical file; failed database retained: $failed_db; HTTP 200, maintenance off"
# Success and a same-SHA rerun use immutable digests and retain the previous image ID.
rm release-layer/migrations/*partial_failure.php
cat > release-layer/migrations/2099_01_01_000002_success.php <<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration { public function up(): void {} };
PHP
publish_fixture_release release-b > "$evidence/publish-b.log" 2>&1
cat "$evidence/publish-b.log"
docker create --name "$COMPOSE_PROJECT_NAME-retention-guard" --entrypoint true "$image_a" > "$evidence/retention-guard.log"
run_ok successful-upgrade ./scripts/upgrade.sh
grep -Eq '^APP_IMAGE=.+@sha256:[a-f0-9]{64}$' .env.prod
[ "$(docker image inspect --format '{{.Id}}' "$rollback_repository/app:previous")" = "$image_a" ]
for failed_image in "$transactional_image" "$failing_image"; do
    if docker image inspect "$failed_image" >/dev/null 2>&1; then echo "Failed candidate retained after successful B: $failed_image" >&2; exit 1; fi
done
printf 'failed transactional=%s absent\nfailed partial=%s absent\nA=%s previous\nB=%s current\n' "$transactional_image" "$failing_image" "$image_a" "$(docker inspect --format '{{.Image}}' "$(dc ps -q app)")" > "$evidence/failed-candidate-retention.log"
run_ok same-sha-upgrade ./scripts/upgrade.sh
[ "$(cat "$lifecycle_state/phase")" = committed ]
grep -qxF "$image_a" "$lifecycle_state/obsolete-images"
docker image inspect "$image_a" > /dev/null
echo "PASS: stopped container retains obsolete image $image_a and its recorded retry ID"
docker rm "$COMPOSE_PROJECT_NAME-retention-guard"
curl -fsS "http://localhost:$port/login" > /dev/null
echo "PASS case 3: release $fixture_sha pinned by digest; previous image retained; same-SHA upgrade succeeds"
# Slow/crash proofs are opt-in deployment evidence, not CI minutes.
if [ "${1:-}" = --extended ]; then
    # shellcheck source=tests/Deployment/rollback-extended.sh
    . "$root/tests/Deployment/rollback-extended.sh"
fi
printf 'PASS: release resolution and both rollback modes (A=%s)\n' "$sha_a"
