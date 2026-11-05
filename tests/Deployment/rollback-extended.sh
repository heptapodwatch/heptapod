#!/bin/sh
# Sourced only by rollback.sh --extended. Slow/crash evidence stays out of CI.
# Shell/PHP fragments below expand in their target process.
# shellcheck disable=SC2016,SC2154
REAL_DOCKER=$(command -v docker)
export REAL_DOCKER
OPS_FIXTURE_ROOT=$fresh
export OPS_FIXTURE_ROOT
mkdir "$fresh/bin"
cat > "$fresh/bin/docker" <<'SH'
#!/bin/sh
set -eu
case "$*" in
    *'up -d --wait --wait-timeout 300 horizon scheduler dispatcher')
        if [ "${OPS_PAUSE_WRITERS:-0}" = 1 ]; then
            touch "$OPS_FIXTURE_ROOT/writers-starting"
            while [ ! -f "$OPS_FIXTURE_ROOT/release-writers" ]; do sleep 0.1; done
        fi ;;
    *'run --rm --no-deps -T -e RUN_MIGRATIONS=1'*)
        if [ "${OPS_PAUSE_PREPARE:-0}" = 1 ]; then
            touch "$OPS_FIXTURE_ROOT/resume-preparing"
            while [ ! -f "$OPS_FIXTURE_ROOT/release-resume-prepare" ]; do sleep 0.1; done
        fi ;;
    *'exec -T app php artisan up')
        case "${OPS_PAUSE_RELEASE:-}" in
            before) touch "$OPS_FIXTURE_ROOT/release-before-up"; sleep 600; exit 0 ;;
            after) "$REAL_DOCKER" "$@"; touch "$OPS_FIXTURE_ROOT/release-after-up"; sleep 600; exit 0 ;;
        esac ;;
    *'ALTER DATABASE "heptapod" RENAME TO "heptapod_failed_'*)
        if [ "${OPS_PAUSE_RENAME:-0}" = 1 ]; then
            "$REAL_DOCKER" "$@"
            touch "$OPS_FIXTURE_ROOT/renamed-old"
            sleep 600
            exit 0
        fi ;;
esac
exec "$REAL_DOCKER" "$@"
SH
chmod +x "$fresh/bin/docker"
wait_for_marker() {
    marker=$1
    marker_deadline=$(($(date +%s) + 180))
    while [ ! -f "$marker" ]; do
        [ "$(date +%s)" -lt "$marker_deadline" ] || { echo "Timed out waiting for $marker" >&2; return 1; }
        sleep 0.2
    done
}
# Step 10 failure: observe real Horizon/dispatcher while the failed scheduler holds the health wait.
cat > writer-failure.yaml <<'YAML'
services:
    scheduler:
        healthcheck:
            test: ['CMD', 'sh', '-c', 'exit 1']
            interval: 1s
            timeout: 1s
            start_period: 15s
            retries: 1
YAML
sed 's/^COMPOSE_FILE=.*/COMPOSE_FILE=compose.prod.yaml:test.yaml:writer-failure.yaml/' .env.prod > writer.env
mv writer.env .env.prod
OPS_PAUSE_WRITERS=1 PATH="$fresh/bin:$PATH" setsid ./scripts/upgrade.sh > "$evidence/writer-failure.log" 2>&1 &
upgrade_pid=$!
wait_for_marker "$fresh/writers-starting"
# Seed only after the old Horizon is drained; this job must stay pending throughout maintenance.
dc exec -T app php -r '
require "vendor/autoload.php";
$a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$id=Illuminate\Support\Facades\DB::table("sites")->insertGetId(["client_id"=>Illuminate\Support\Facades\DB::table("clients")->where("is_internal",true)->value("id"),"url"=>"http://app:8080","badge_id"=>Illuminate\Support\Str::uuid()->toString()]);
Illuminate\Support\Facades\DB::table("checks")->insert(["site_id"=>$id,"type"=>"uptime","failure_threshold"=>2,"enabled"=>true,"interval_seconds"=>60,"next_run_at"=>now()->subMinute()]);
$payload=json_encode(["uuid"=>Illuminate\Support\Str::uuid()->toString(),"displayName"=>"OpsMaintenanceSentinel","job"=>"Illuminate\\Queue\\CallQueuedHandler@call","maxTries"=>1,"timeout"=>5,"data"=>["commandName"=>"stdClass","command"=>serialize(new stdClass)]],JSON_THROW_ON_ERROR);
Illuminate\Support\Facades\Queue::connection("redis")->pushRaw($payload,"checks-fast");
$uuid=json_decode($payload,true,512,JSON_THROW_ON_ERROR)["uuid"];
$queued=Illuminate\Support\Facades\Redis::connection()->lrange("queues:checks-fast",0,-1);
$matches=array_values(array_filter($queued,fn($entry)=>json_decode($entry,true,512,JSON_THROW_ON_ERROR)["uuid"]===$uuid));
if (count($matches)!==1) { throw new RuntimeException("Sentinel was not queued before writers started"); }
file_put_contents("/app/storage/app/ops-sentinel-payload",$matches[0]);
'
attempts_before=$(sql -Atc 'select count(*) from check_run_attempts')
claims_before=$(sql -Atc 'select count(*) from checks where claim_token is not null')
heartbeat_before=$(sql -Atc 'select max(last_seen_at) from dispatcher_heartbeats')
touch "$fresh/release-writers"
sleep 7
[ "$(sql -Atc 'select count(*) from check_run_attempts')" = "$attempts_before" ]
[ "$(sql -Atc 'select count(*) from checks where claim_token is not null')" = "$claims_before" ]
heartbeat_after=$(sql -Atc 'select max(last_seen_at) from dispatcher_heartbeats')
[ "$heartbeat_after" != "$heartbeat_before" ]
dc exec -T horizon php artisan horizon:status > "$evidence/horizon-maintenance-status.log"
dc exec -T app php -r '
require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$payload=file_get_contents("/app/storage/app/ops-sentinel-payload");
$pending=Illuminate\Support\Facades\Redis::connection()->lrange("queues:checks-fast",0,-1);
if (!in_array($payload,$pending,true)) { throw new RuntimeException("Maintenance sentinel was processed"); }
echo "pending=".count($pending)."; failed_jobs=".Illuminate\Support\Facades\DB::table("failed_jobs")->count()."; maintenance=".($a->isDownForMaintenance()?"on":"off").PHP_EOL;
' > "$evidence/writer-fence-counters.log"
{
    printf 'attempts before=%s after=%s\n' "$attempts_before" "$(sql -Atc 'select count(*) from check_run_attempts')"
    printf 'claims before=%s after=%s\n' "$claims_before" "$(sql -Atc 'select count(*) from checks where claim_token is not null')"
    printf 'dispatcher heartbeat before=%s after=%s\n' "$heartbeat_before" "$heartbeat_after"
} >> "$evidence/writer-fence-counters.log"
if wait "$upgrade_pid"; then echo 'Scheduler health failure did not stop upgrade' >&2; exit 1; fi
upgrade_pid=
[ "$(cat "$lifecycle_state/phase")" = starting-writers ]
cat "$evidence/writer-fence-counters.log"
# Remove exactly this synthetic payload, not the queue; disable the synthetic check before release.
dc exec -T app php -r '
require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$payload=file_get_contents("/app/storage/app/ops-sentinel-payload");
if (Illuminate\Support\Facades\Redis::connection()->lrem("queues:checks-fast",1,$payload)!==1) { throw new RuntimeException("Sentinel missing"); }
Illuminate\Support\Facades\DB::table("checks")->update(["enabled"=>false]);
'
sed 's/:writer-failure.yaml//' .env.prod > fixed.env
mv fixed.env .env.prod
run_ok writer-fix-forward ./scripts/upgrade.sh --resume
echo 'PASS case 4: failing writer health wait leaves maintenance on, zero new claims/attempts, fresh dispatcher heartbeat, Horizon running but synthetic job pending'
# A 200-second migration outlives the old web health start budget; it is supervised separately.
image_b=$(docker inspect --format '{{.Image}}' "$(dc ps -q app)")
cat > release-layer/migrations/2099_01_01_000003_long.php <<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void { \Illuminate\Support\Facades\DB::select('select pg_sleep(200)'); }
};
PHP
publish_fixture_release release-c-long > "$evidence/publish-c.log" 2>&1
image_c=$(docker image inspect --format '{{.Id}}' "$fixture_app_ref")
started=$(date +%s)
setsid ./scripts/upgrade.sh > "$evidence/long-migration.log" 2>&1 &
upgrade_pid=$!
long_deadline=$(($(date +%s) + 180))
while [ "$(cat "$lifecycle_state/phase")" != preparing ]; do
    [ "$(date +%s)" -lt "$long_deadline" ] || exit 1
    sleep 0.2
done
run_fail install-locked ./scripts/install.sh --domain monitor.example.test --without-browser
run_fail rollback-locked ./scripts/rollback.sh
for lock_log in install-locked rollback-locked; do grep -q 'Another install/upgrade/rollback is running' "$evidence/$lock_log.log"; done
wait "$upgrade_pid"
upgrade_pid=
elapsed=$(($(date +%s) - started))
[ "$elapsed" -ge 200 ]
printf 'long migration upgrade elapsed=%ss; phase=%s\n' "$elapsed" "$(cat "$lifecycle_state/phase")" > "$evidence/long-migration-timing.log"
cat "$evidence/long-migration-timing.log"
[ "$(docker image inspect --format '{{.Id}}' "$rollback_repository/app:previous")" = "$image_b" ]
[ "$(docker inspect --format '{{.Image}}' "$(dc ps -q app)")" = "$image_c" ]
if docker image inspect "$image_a" > /dev/null 2>&1; then echo 'Obsolete generation A was not pruned' >&2; exit 1; fi
printf 'A=%s absent\nB=%s previous\nC=%s current\n' "$image_a" "$image_b" "$image_c" > "$evidence/retention.log"
docker images --filter "label=$component_label=app" --format '{{.ID}} {{.Repository}}:{{.Tag}}' >> "$evidence/retention.log"
cat "$evidence/retention.log"
echo 'PASS cases 5–7: 200s migration succeeds without healthcheck deadline; A removed, B previous/C current retained; install/rollback refused concurrent upgrade'
# Crash during prepare. The orphan container must block both resume and Mode B until explicitly stopped.
cat > release-layer/migrations/2099_01_01_000004_crash.php <<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        if (! \Illuminate\Support\Facades\DB::table('ops_keep')->where('value','crash-fixed')->exists()) {
            \Illuminate\Support\Facades\DB::select('select pg_sleep(200)');
        }
    }
};
PHP
publish_fixture_release release-d-crash > "$evidence/publish-d.log" 2>&1
setsid ./scripts/upgrade.sh > "$evidence/crash-upgrade.log" 2>&1 &
upgrade_pid=$!
crash_deadline=$(($(date +%s) + 180))
while :; do
    prepare_name=$(cat "$lifecycle_state/prepare-container")
    names=$(docker ps --format '{{.Names}}')
    if [ -n "$prepare_name" ] && printf '%s\n' "$names" | grep -qxF "$prepare_name"; then break; fi
    [ "$(date +%s)" -lt "$crash_deadline" ] || exit 1
    sleep 0.2
done
kill -KILL -- "-$upgrade_pid"
wait "$upgrade_pid" || [ "$?" = 137 ]
upgrade_pid=
sleep 2
run_fail crash-marker ./scripts/upgrade.sh
grep -q 'Upgrade stopped in phase preparing' "$evidence/crash-marker.log"
run_fail orphan-resume-refused ./scripts/upgrade.sh --resume
run_fail orphan-restore-refused ./scripts/rollback.sh --restore-backup
for orphan_log in orphan-resume-refused orphan-restore-refused; do
    grep -q "Preparation container $prepare_name is still running" "$evidence/$orphan_log.log"
    grep -q "docker stop --time 1245 '$prepare_name'" "$evidence/$orphan_log.log"
done
docker stop --time 1245 "$prepare_name" > "$evidence/orphan-stop.log"
run_fail owned-session-resume-refused ./scripts/upgrade.sh --resume
grep -q 'Owned PostgreSQL preparation sessions still exist' "$evidence/owned-session-resume-refused.log"
sed -n '/^cd .*pg_terminate_backend/p' "$evidence/owned-session-resume-refused.log" > "$evidence/terminate-owned-session.sh"
[ -s "$evidence/terminate-owned-session.sh" ]
run_ok terminate-owned-session sh "$evidence/terminate-owned-session.sh"
sql -c "INSERT INTO ops_keep VALUES ('crash-fixed')"
run_ok crash-resume ./scripts/upgrade.sh --resume
[ "$(cat "$lifecycle_state/phase")" = committed ]
[ "$(curl -sS -o /dev/null -w '%{http_code}' "http://localhost:$port/login")" = 200 ]
echo 'PASS case 8: crash marker/live orphan refuse recovery; explicit stop exposes owned SQL session; exact PID+application_name termination permits successful resume'
# Interrupt Mode B after the live DB was renamed but before the restored DB takes its place.
cat > release-layer/migrations/2099_01_01_000005_completed.php <<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration { public function up(): void {} };
PHP
cat > release-layer/migrations/2099_01_01_000006_restore_crash.php <<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public $withinTransaction = false;
    public function up(): void { \Illuminate\Support\Facades\DB::statement('CREATE TABLE ops_restore_crash_probe (id int)'); throw new \RuntimeException('OPS restore crash probe'); }
};
PHP
before_restore_fingerprint=$(fingerprint)
publish_fixture_release release-e-failure > "$evidence/publish-e.log" 2>&1
run_fail restore-crash-upgrade ./scripts/upgrade.sh
OPS_PAUSE_RENAME=1 PATH="$fresh/bin:$PATH" setsid ./scripts/rollback.sh --restore-backup > "$evidence/restore-interrupted.log" 2>&1 &
upgrade_pid=$!
wait_for_marker "$fresh/renamed-old"
kill -KILL -- "-$upgrade_pid"
wait "$upgrade_pid" || [ "$?" = 137 ]
upgrade_pid=
sleep 2
cp "$lifecycle_state/rollback.env" "$evidence/restore-interrupted-state.env"
dc exec -T postgres sh -ec 'psql -X -U "$POSTGRES_USER" -d postgres -c "select datname from pg_database order by datname"' > "$evidence/restore-interrupted-databases.log"
run_ok restore-interrupted-resume ./scripts/rollback.sh --restore-backup
[ "$(fingerprint)" = "$before_restore_fingerprint" ]
[ "$(sql -Atc "select to_regclass('public.ops_restore_crash_probe') is null")" = t ]
restore_name=$(sed -n 's/^RESTORE_DB=//p' "$lifecycle_state/rollback.env")
[ "$(sql -Atc "select count(*) from pg_database where datname='$restore_name'")" = 0 ]
[ "$(curl -sS -o /dev/null -w '%{http_code}' "http://localhost:$port/login")" = 200 ]
sql -c 'select datname from pg_database order by datname' > "$evidence/restore-resumed-databases.log"
echo 'PASS restore interruption: durable rename intent reconciles actual databases; live restored DB healthy, no _restore DB remains; failed DB retained with exact drop command'
# Acceptance intent must survive kills on either side of up, including re-entry into preparing.
rm release-layer/migrations/*restore_crash.php
cat > release-layer/migrations/2099_01_01_000006_success.php <<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration { public function up(): void {} };
PHP
publish_fixture_release release-f-acceptance > "$evidence/publish-f.log" 2>&1
OPS_PAUSE_RELEASE=before PATH="$fresh/bin:$PATH" setsid ./scripts/upgrade.sh > "$evidence/release-before-up.log" 2>&1 &
upgrade_pid=$!
wait_for_marker "$fresh/release-before-up"
[ "$(cat "$lifecycle_state/phase")" = releasing ]
[ -f "$lifecycle_state/release-intent" ]
kill -KILL -- "-$upgrade_pid"
wait "$upgrade_pid" || [ "$?" = 137 ]
upgrade_pid=
sleep 1
run_fail release-before-restore-refused ./scripts/rollback.sh --restore-backup
grep -q 'would lose every write accepted since release' "$evidence/release-before-restore-refused.log"
if grep -q -- '--restore-backup' "$evidence/release-before-restore-refused.log"; then echo 'Acceptance refusal offered a destructive tool flag' >&2; exit 1; fi
run_ok release-before-resume ./scripts/upgrade.sh --resume
run_fail release-before-resumed-restore-refused ./scripts/rollback.sh --restore-backup
# Same SHA, new generation: kill after up and write a row absent from the pre-upgrade archive.
OPS_PAUSE_RELEASE=after PATH="$fresh/bin:$PATH" setsid ./scripts/upgrade.sh > "$evidence/release-after-up.log" 2>&1 &
upgrade_pid=$!
wait_for_marker "$fresh/release-after-up"
[ "$(cat "$lifecycle_state/phase")" = releasing ]
[ "$(curl -sS -o /dev/null -w '%{http_code}' "http://localhost:$port/login")" = 200 ]
sql -c "INSERT INTO ops_keep VALUES ('accepted-after-up')"
kill -KILL -- "-$upgrade_pid"
wait "$upgrade_pid" || [ "$?" = 137 ]
upgrade_pid=
sleep 1
run_fail release-after-restore-refused ./scripts/rollback.sh --restore-backup
[ "$(sql -Atc "select count(*) from ops_keep where value='accepted-after-up'")" = 1 ]
OPS_PAUSE_PREPARE=1 PATH="$fresh/bin:$PATH" setsid ./scripts/upgrade.sh --resume > "$evidence/release-after-resume.log" 2>&1 &
upgrade_pid=$!
wait_for_marker "$fresh/resume-preparing"
[ "$(cat "$lifecycle_state/phase")" = preparing ]
[ -f "$lifecycle_state/release-intent" ]
# While resume holds the lock, release it only in a separate copied refusal check after a controlled kill.
kill -KILL -- "-$upgrade_pid"
wait "$upgrade_pid" || [ "$?" = 137 ]
upgrade_pid=
sleep 1
run_fail release-intent-preparing-restore-refused ./scripts/rollback.sh --restore-backup
grep -q 'Refusing rollback after release acceptance intent (preparing)' "$evidence/release-intent-preparing-restore-refused.log"
run_ok release-after-resume-complete ./scripts/upgrade.sh --resume
[ "$(sql -Atc "select count(*) from ops_keep where value='accepted-after-up'")" = 1 ]
sql -c "select value from ops_keep where value='accepted-after-up'" > "$evidence/accepted-write-preserved.log"
echo 'PASS release boundary: fsynced intent blocks restore before/after up and during resumed preparing; forward resume preserves post-up data'
# Image-only rollback shares the same final release block; its terminal intent must prevent re-import.
cat > release-layer/migrations/2099_01_01_000007_terminal_failure.php <<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void { throw new \RuntimeException('OPS terminal rollback release probe'); }
};
PHP
publish_fixture_release release-g-terminal > "$evidence/publish-g.log" 2>&1
run_fail rollback-terminal-upgrade ./scripts/upgrade.sh
rm "$fresh/release-after-up"
OPS_PAUSE_RELEASE=after PATH="$fresh/bin:$PATH" setsid ./scripts/rollback.sh > "$evidence/rollback-terminal-release.log" 2>&1 &
upgrade_pid=$!
wait_for_marker "$fresh/release-after-up"
[ "$(cat "$lifecycle_state/phase")" = rolled-back ]
sql -c "INSERT INTO ops_keep VALUES ('accepted-after-rollback-up')"
kill -KILL -- "-$upgrade_pid"
wait "$upgrade_pid" || [ "$?" = 137 ]
upgrade_pid=
sleep 1
run_fail rollback-terminal-restore-refused ./scripts/rollback.sh --restore-backup
[ "$(sql -Atc "select count(*) from ops_keep where value='accepted-after-rollback-up'")" = 1 ]
sed -n '/^cd .*php artisan up$/p' "$evidence/rollback-terminal-restore-refused.log" > "$evidence/finish-rollback-release.sh"
[ -s "$evidence/finish-rollback-release.sh" ]
run_ok finish-rollback-release sh "$evidence/finish-rollback-release.sh"
echo 'PASS rollback release boundary: terminal state precedes up; interrupted final release cannot re-import data; exact artisan-up command completes forward'
