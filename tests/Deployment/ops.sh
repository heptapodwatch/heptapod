#!/bin/sh
# Shell/PHP fragments expand in the target container.
# shellcheck disable=SC2016
# Fresh production boot, URL-only env, readonly rootfs, S3 and real nginx.
set -eu
root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$root"
: "${APP_IMAGE:?Set APP_IMAGE to the built production image}"
if [ "${1:-}" != --web-probes-only ] && [ "${1:-}" != --public-probe-only ]; then
    sh tests/Deployment/image-catalogs.sh
fi
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-heptapod-ops-proof}"
# Reject overlapping Docker subnets; never silently change the fixture's expected address.
network_ids=$(docker network ls -q)
if [ -n "$network_ids" ]; then
    # Engine IDs are deliberately passed as separate arguments.
    # shellcheck disable=SC2086
    docker network inspect $network_ids | jq -e --arg own "$COMPOSE_PROJECT_NAME" '
        def range4:
            split("/") as $cidr | ($cidr[0] | split(".") | map(tonumber) | .[0]*16777216 + .[1]*65536 + .[2]*256 + .[3]) as $ip |
            pow(2; 32-($cidr[1] | tonumber)) as $size | [($ip / $size | floor)*$size, ($ip / $size | floor)*$size+$size-1];
        all(.[] | select(.Labels["com.docker.compose.project"] != $own) | .IPAM.Config[]? | .Subnet | select(contains(":") | not);
            range4 as $range | ("11.123.197.0/29" | range4) as $fixture | $range[1] < $fixture[0] or $range[0] > $fixture[1])
    ' >/dev/null || { echo 'Outbound fixture subnet 11.123.197.0/29 overlaps an existing network' >&2; exit 1; }
fi
export COMPOSE_PROFILES=disabled
export SERVER_NAME=http://monitor.example.com:8080
export DB_PASSWORD=ops-test-password
export APP_KEY=base64:fHZ2dw8NiCy4LGNE44ZZpkPRnlm78efQLYoMCAktKL8=
envfile=$(mktemp)
# The upgrade entry point requires persistent Compose and browser selections, not shell-only overrides.
printf 'COMPOSE_PROFILES=disabled\nHEPTAPOD_BROWSER_ENABLED=false\nREVERB_APP_ID=ops-test\nREVERB_APP_KEY=ops-test-key\nREVERB_APP_SECRET=ops-test-secret\nCOMPOSE_FILE=%s:%s\n' "$root/compose.prod.yaml" "$root/tests/Deployment/ops.yaml" > "$envfile"
upgrade_root=$(mktemp -d)
# shellcheck source=tests/Deployment/release-fixture.sh
. "$root/tests/Deployment/release-fixture.sh"
# Resolve proof imports outside the checkout so local node_modules cannot hide undeclared dependencies.
cp tests/Deployment/live-socket.mjs "$upgrade_root/live-socket.mjs"
dc() { docker compose --env-file "$envfile" "$@"; }
assert_worker_cli_cache() {
    dc exec -T "$1" sh -eu -c '
        found=0
        for process in /proc/[0-9]*; do
            [ "$(cat "$process/comm")" = php ] || continue
            command=$(tr "\000" " " < "$process/cmdline")
            case "$command" in
                *"artisan horizon"*|*"artisan schedule:"*) ;;
                *) continue ;;
            esac
            scan_dir=$(tr "\000" "\n" < "$process/environ" | sed -n "s/^PHP_INI_SCAN_DIR=//p")
            [ -n "$scan_dir" ]
            PHP_INI_SCAN_DIR="$scan_dir" php -r '\''if (ini_get("opcache.enable_cli") !== "0" || ini_get("opcache.enable") !== "1" || ini_get("opcache.memory_consumption") !== "192") { throw new RuntimeException("Worker CLI cache scope is incorrect"); }'\''
            found=$((found + 1))
        done
        [ "$found" -gt 0 ]
        echo "PASS: $found Horizon/scheduler processes inherit disabled CLI OPcache; web cache settings retained"
    '
}
cleanup() {
    status=$?
    if [ "$status" -ne 0 ]; then
        # Diagnostics must not prevent teardown when a container has already exited.
        dc ps -a >&2 || true
        s3_container=$(dc ps -aq s3) || true
        if [ -n "$s3_container" ]; then
            docker inspect --format '{{json .State}} RestartCount={{.RestartCount}}' "$s3_container" >&2 || true
        fi
        dc logs --tail=100 s3 >&2 || true
        dc exec -T app sh -c 'cat /etc/resolv.conf; getent hosts s3' >&2 || true
        dc logs --tail=50 app reverb horizon dispatcher all-in-one-worker >&2 || true
    fi
    if docker inspect "$COMPOSE_PROJECT_NAME-standalone" >/dev/null 2>&1; then docker rm -f "$COMPOSE_PROJECT_NAME-standalone"; fi
    if docker inspect "$COMPOSE_PROJECT_NAME-standalone-undersized" >/dev/null 2>&1; then docker rm -f "$COMPOSE_PROJECT_NAME-standalone-undersized"; fi
    dc --profile worker-recovery down -v
    rm -f "$envfile"
    if [ -n "${fixture_registry_name:-}" ]; then stop_release_fixture; fi
    rm -rf "$upgrade_root"
}
trap cleanup EXIT HUP INT TERM
if [ "${1:-}" != --web-probes-only ] && [ "${1:-}" != --public-probe-only ]; then
    start_release_fixture "$upgrade_root" "$APP_IMAGE"
    publish_fixture_release ops
    APP_IMAGE=$fixture_app_ref
    printf 'APP_IMAGE=%s\n' "$APP_IMAGE" >> "$envfile"
fi
# One cold boot is the current bounded acceptance contract.
# shellcheck disable=SC2043
for cold in 1; do
    dc down -v
    if [ "${1:-}" = --web-probes-only ] || [ "${1:-}" = --public-probe-only ]; then
        dc up -d --wait --wait-timeout 180 app nginx outbound-fixture
    else
        dc up -d --wait --wait-timeout 180
    fi
    dc exec -T app php -r 'require "vendor/autoload.php"; $php = (new Symfony\Component\Process\PhpExecutableFinder)->find(false); if ($php === false || !is_file($php) || !is_executable($php)) { throw new RuntimeException("PHP CLI not available in production image"); } echo "PHP_CLI=".$php."\n"; passthru(escapeshellarg($php)." --version", $status); if ($status !== 0) { exit($status); }'
    fixture_network=$(docker inspect "$(dc ps -q outbound-fixture)" | jq -er '.[0].NetworkSettings.Networks | to_entries | if length == 1 then .[0].value.NetworkID else error("Fixture must have exactly one network") end')
    docker network inspect "$fixture_network" | jq -e '.[0] | {Internal, IPAM} | select(.Internal == true and .IPAM.Config[0].Subnet == "11.123.197.0/29")'
    docker inspect "$(dc ps -q outbound-fixture)" | jq -e '.[0].HostConfig.PortBindings | . == null or . == {}'
    if [ "${1:-}" = --web-probes-only ] || [ "${1:-}" = --public-probe-only ]; then
        dc exec -T app sh -c 'cat > /tmp/ops.php' < tests/Deployment/ops.php
        dc exec -T app sh -c 'cat > /tmp/notification-destination-pause.php' < tests/Deployment/notification-destination-pause.php
        dc exec -T app php /tmp/ops.php "$1"
        exit
    fi
    for service in app reverb horizon dispatcher scheduler; do
        dc exec -T "$service" sh /app/docker/prod/healthcheck.sh
    done
    echo 'PASS: web, Reverb, worker, dispatcher and scheduler image role healthchecks'
    assert_worker_cli_cache horizon
    assert_worker_cli_cache scheduler
    dc exec -T app php -r '
$socket = stream_socket_client("tcp://nginx:80", $errno, $error, 5);
if (! $socket) { throw new RuntimeException($error); }
$request = "GET /app/ops-test-key?protocol=7&client=js&version=8.6 HTTP/1.1\r\nHost: monitor.example.com:8443\r\nOrigin: https://monitor.example.com:8443\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: ".base64_encode(random_bytes(16))."\r\nSec-WebSocket-Version: 13\r\n\r\n";
fwrite($socket, $request);
$status = fgets($socket);
fclose($socket);
if (! str_contains($status, "101 Switching Protocols")) { throw new RuntimeException("Expected nginx WebSocket upgrade, got $status"); }
'
    echo 'PASS: WebSocket upgrade through the production nginx proxy'
    dc exec -T app sh -c 'cat > /tmp/ops.php' < tests/Deployment/ops.php
    dc exec -T app sh -c 'cat > /tmp/notification-destination-pause.php' < tests/Deployment/notification-destination-pause.php
    proof=$(dc exec -T -e RUN_MIGRATIONS=0 app sh /app/docker/prod/entrypoint.sh php /tmp/ops.php)
    printf '%s\n' "$proof"
    printf '%s\n' "$proof" | grep -q '^PASS: nginx login/session, WebAuthn rpId, signed URL, mail host'
    LOGIN_TRANSLATIONS_ORIGIN="http://127.0.0.1:${OPS_PORT:-10680}" \
        node tests/Deployment/login-translations.mjs
    LOGIN_FAILURES_ORIGIN="http://127.0.0.1:${OPS_PORT:-10680}" \
        LOGIN_FAILURES_PASSWORD=password \
        node tests/Deployment/login-failures.mjs
    LIVE_SOCKET_ORIGIN="http://127.0.0.1:${OPS_PORT:-10680}" \
        LIVE_SOCKET_HOST='monitor.example.com:8443' \
        LIVE_SOCKET_BROWSER_ORIGIN='https://monitor.example.com:8443' \
        LIVE_SOCKET_EMAIL=test@example.test LIVE_SOCKET_PASSWORD=password \
        LIVE_SOCKET_APP_ID=ops-test LIVE_SOCKET_COMPOSE_ENV="$envfile" \
        node "$upgrade_root/live-socket.mjs"
    # A typo must fail before any server/process starts.
    if dc exec -T -e HEPTAPOD_ROLE=unknown app sh /app/docker/prod/entrypoint.sh > /tmp/ops-role.log 2>&1; then
        echo 'Unknown role was accepted' >&2; exit 1
    fi
    grep -q 'Unknown HEPTAPOD_ROLE' /tmp/ops-role.log
    echo "PASS: cold production boot $cold and unknown-role rejection"
done
# The remote backup contract remains independent of the local lifecycle recovery archive.
dc exec -T app php artisan backup:run --no-interaction --disable-notifications --only-to-disk=s3
# Run the upgrade entry point with real readonly/S3 roles and a local recovery volume.
mkdir "$upgrade_root/scripts"
cp scripts/upgrade.sh scripts/rollback.sh scripts/check-disk.sh scripts/lifecycle-lock.sh scripts/release-image.sh scripts/image-reference.sh scripts/retain-diagnostics.sh scripts/diagnostics-filter.jq scripts/upgrade-backup-retention.sh scripts/upgrade-backup-retention.php scripts/worker-memory-preflight.sh "$upgrade_root/scripts/"
cp "$envfile" "$upgrade_root/.env.prod"
# scripts/image-reference.sh reads the published-image defaults from the checkout's compose.prod.yaml.
cp compose.prod.yaml "$upgrade_root/compose.prod.yaml"
# An explicit undersized HORIZON_MEMORY_LIMIT refuses through the real candidate image before maintenance and migrations.
cp "$upgrade_root/.env.prod" "$upgrade_root/sized.env"
printf 'HORIZON_MEMORY_LIMIT=832m\n' >> "$upgrade_root/.env.prod"
cp "$upgrade_root/.env.prod" "$upgrade_root/undersized.env"
if COMPOSE_FILE="$root/compose.prod.yaml:$root/tests/Deployment/ops.yaml" sh "$upgrade_root/scripts/upgrade.sh" > "$upgrade_root/undersized-upgrade.log" 2>&1; then
    cat "$upgrade_root/undersized-upgrade.log" >&2; echo 'Undersized worker cap was accepted' >&2; exit 1
fi
cat "$upgrade_root/undersized-upgrade.log"
grep -q 'HORIZON_MEMORY_LIMIT is 832m; HEPTAPOD_CRAWL_CONCURRENCY=1 with HEPTAPOD_CRAWL_MEMORY_LIMIT_MB=768 needs at least 1990m' "$upgrade_root/undersized-upgrade.log"
grep -q 'Upgrade aborted before maintenance; old stack untouched.' "$upgrade_root/undersized-upgrade.log"
cmp "$upgrade_root/undersized.env" "$upgrade_root/.env.prod"
[ "$(curl -sS -o /dev/null -w '%{http_code}' -H 'Host: monitor.example.com' "http://localhost:${OPS_PORT:-10680}/login")" = 200 ]
[ -n "$(dc ps -q --status running horizon)" ]
cp "$upgrade_root/sized.env" "$upgrade_root/.env.prod"
echo 'PASS: undersized HORIZON_MEMORY_LIMIT refuses before php artisan down; HTTP and the running worker untouched'
# Hold a real DB lock so pg_dump keeps the maintenance window observable.
dc exec -T postgres sh -ec 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "BEGIN; LOCK TABLE users IN ACCESS EXCLUSIVE MODE; SELECT pg_sleep(15); COMMIT"' > "$upgrade_root/lock.log" 2>&1 &
lock_pid=$!
for attempt in $(seq 1 30); do
    locked=$(dc exec -T postgres sh -ec 'psql -At -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT count(*) FROM pg_locks WHERE relation = '\''users'\''::regclass AND mode = '\''AccessExclusiveLock'\'' AND granted"')
    [ "$locked" = 0 ] || break
    sleep 1
done
[ "$locked" != 0 ]
COMPOSE_FILE="$root/compose.prod.yaml:$root/tests/Deployment/ops.yaml" sh "$upgrade_root/scripts/upgrade.sh" > "$upgrade_root/upgrade.log" 2>&1 &
upgrade_pid=$!
maintenance_seen=0
for attempt in $(seq 1 120); do
    status=$(curl -sS -o /dev/null -w '%{http_code}' -H 'Host: monitor.example.com' "http://localhost:${OPS_PORT:-10680}/login")
    if [ "$status" = 503 ]; then maintenance_seen=1; break; fi
    if ! kill -0 "$upgrade_pid" 2>/dev/null; then break; fi
    sleep 1
done
if [ "$maintenance_seen" != 1 ]; then
    cat "$upgrade_root/upgrade.log" >&2
    echo 'Upgrade failed to put HTTP in maintenance' >&2; exit 1
fi
# Other roles must observe the same Redis maintenance state through plain exec.
dc run --rm --no-deps -e HEPTAPOD_ROLE=web horizon php -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if (!$app->isDownForMaintenance()) { exit(1); }'
wait "$lock_pid"
if ! wait "$upgrade_pid"; then cat "$upgrade_root/upgrade.log" >&2; exit 1; fi
cat "$upgrade_root/upgrade.log"
grep -v '^APP_IMAGE=' "$envfile" > "$upgrade_root/before-unmanaged.env"
grep -v '^APP_IMAGE=' "$upgrade_root/.env.prod" > "$upgrade_root/after-unmanaged.env"
cmp "$upgrade_root/before-unmanaged.env" "$upgrade_root/after-unmanaged.env"
grep -Eq '^APP_IMAGE=.+@sha256:[a-f0-9]{64}$' "$upgrade_root/.env.prod"
grep -q 'media objects are NOT in the backup' "$upgrade_root/upgrade.log"
dc exec -T app sh -ec 'find /backups -type f -name "pre-upgrade-*.zip" | grep -q .'
[ "$(curl -sS -o /dev/null -w '%{http_code}' -H 'Host: monitor.example.com' "http://localhost:${OPS_PORT:-10680}/login")" = 200 ]
echo 'PASS: upgrade entry point sets shared maintenance, HTTP 503, independent S3 backup and local lifecycle archive, digest pin, HTTP restored'
# Read authoritative charges with one small helper, without RSS sampling overhead.
# Report-only: memory.peak already holds each cgroup's maximum since boot, so one read
# after the upgrade covers the boot, probe and upgrade load without a timed sampling loop.
total=0
for service in app reverb horizon dispatcher scheduler postgres redis nginx; do
    counters=$(dc exec -T "$service" cat /sys/fs/cgroup/memory.current /sys/fs/cgroup/memory.peak /sys/fs/cgroup/memory.max)
    # Three whitespace-separated numeric counters, not one argument.
    # shellcheck disable=SC2086
    set -- $counters
    total=$(($total + $1))
    printf 'MEMORY_BYTES %s current=%s peak=%s limit=%s\n' "$service" "$1" "$2" "$3"
done
printf 'TOTAL_CGROUP_CURRENT_BYTES %s\n' "$total"
# Consolidated platform role must launch all three real commands.
dc stop horizon dispatcher scheduler
dc up -d --no-deps all-in-one-worker
worker=$(dc ps -aq all-in-one-worker)
[ "$(docker inspect --format '{{.HostConfig.RestartPolicy.Name}}' "$worker")" = unless-stopped ]
wait_worker_health() {
    for attempt in $(seq 1 90); do
        if docker exec "$worker" sh /app/docker/prod/healthcheck.sh >/dev/null 2>&1; then return; fi
        sleep 1
    done
    docker logs "$worker" >&2
    echo 'Consolidated worker failed health' >&2; exit 1
}
wait_worker_health
# RUNNING only proves the process survived startsecs; await Horizon registration.
for attempt in $(seq 1 90); do
    if docker exec "$worker" php artisan horizon:status > "$upgrade_root/horizon-status" 2>&1; then break; fi
    sleep 1
done
cat "$upgrade_root/horizon-status"
docker exec "$worker" php artisan horizon:status
old_pid=$(docker exec "$worker" pgrep -f '^php /app/artisan horizon$')
# Horizon's normal termination exits zero; supervisord must restart it too.
docker exec "$worker" php artisan horizon:terminate
for attempt in $(seq 1 90); do
    new_pid=$(docker exec "$worker" pgrep -f '^php /app/artisan horizon$' || true)
    if [ -n "$new_pid" ] && [ "$new_pid" != "$old_pid" ]; then break; fi
    sleep 1
done
[ -n "$new_pid" ] && [ "$new_pid" != "$old_pid" ]
wait_worker_health
assert_worker_cli_cache all-in-one-worker
echo 'PASS: consolidated worker restarts Horizon after normal termination'
printf 'ALL_IN_ONE_CGROUP_BYTES current/peak/max\n'
docker exec "$worker" cat /sys/fs/cgroup/memory.current /sys/fs/cgroup/memory.peak /sys/fs/cgroup/memory.max
docker inspect --format '{{range .Config.Env}}{{println .}}{{end}}' "$worker" > "$upgrade_root/worker.env"
docker exec "$worker" cat /app/docker/prod/supervisord.conf > "$upgrade_root/supervisord.conf"
docker exec "$worker" cat /app/docker/prod/all-in-one-worker.sh > "$upgrade_root/wrapper.sh"
docker exec "$worker" test ! -f /tmp/heptapod/fatal
docker stop -t 375 "$worker"
[ "$(docker inspect --format '{{.State.ExitCode}}' "$worker")" = 0 ]
echo 'PASS: Compose init plus image tini forwards graceful stop without fatal marker'
standalone="$COMPOSE_PROJECT_NAME-standalone"
run_standalone() {
    docker run -d --name "$standalone" --network "${COMPOSE_PROJECT_NAME}_backend" --read-only --tmpfs /tmp:uid=33,gid=33,mode=1777 --memory=2200m --memory-swap=2200m --cap-drop=ALL --security-opt no-new-privileges --env-file "$upgrade_root/worker.env" "$@"
}
# The consolidated role budgets its own supervisord, dispatcher and scheduler; 1990m fits Horizon alone, not this role.
docker run --name "$standalone-undersized" --network "${COMPOSE_PROJECT_NAME}_backend" --read-only --tmpfs /tmp:uid=33,gid=33,mode=1777 --memory=1990m --memory-swap=1990m --cap-drop=ALL --security-opt no-new-privileges --env-file "$upgrade_root/worker.env" "$APP_IMAGE" > "$upgrade_root/undersized-worker.log" 2>&1 || true
cat "$upgrade_root/undersized-worker.log"
[ "$(docker inspect --format '{{.State.ExitCode}}' "$standalone-undersized")" = 1 ]
grep -q 'HORIZON_MEMORY_LIMIT is 1990m; HEPTAPOD_CRAWL_CONCURRENCY=1 with HEPTAPOD_CRAWL_MEMORY_LIMIT_MB=768 needs at least 2200m' "$upgrade_root/undersized-worker.log"
if grep -qi 'supervisord started' "$upgrade_root/undersized-worker.log"; then echo 'Undersized consolidated worker started supervisord' >&2; exit 1; fi
docker rm "$standalone-undersized"
echo 'PASS: undersized consolidated worker refuses before supervisord starts'
# No --init: the image must own PID 1 and forward shutdown itself.
run_standalone "$APP_IMAGE"
worker=$standalone
wait_worker_health
[ "$(docker exec "$worker" cat /proc/1/comm)" = tini ]
docker exec "$worker" test ! -f /tmp/heptapod/fatal
docker stop -t 375 "$worker"
[ "$(docker inspect --format '{{.State.ExitCode}}' "$worker")" = 0 ]
docker logs "$worker" > "$upgrade_root/standalone-stop.log" 2>&1
grep -q 'stopped: horizon' "$upgrade_root/standalone-stop.log"
docker rm "$worker"
echo 'PASS: standalone image without --init stops gracefully with exit0 and no fatal marker'
# External process stand-ins exercise supervisor's parallel drain, not domain jobs.
cat > "$upgrade_root/delayed-drain.sh" <<'SH'
#!/bin/sh
trap 'sleep 10; exit 0' TERM
while true; do sleep 1; done
SH
sed -e 's|command=php /app/artisan horizon|command=sleep 3600|' -e 's|command=php /app/artisan heptapod:dispatch|command=sh /tmp/delayed-drain.sh|' -e 's|command=php /app/artisan schedule:work|command=sh /tmp/delayed-drain.sh|' "$upgrade_root/supervisord.conf" > "$upgrade_root/delayed.conf"
sed '/^\[group:workers\]/,$d' "$upgrade_root/delayed.conf" > "$upgrade_root/sequential.conf"
run_standalone -v "$upgrade_root/sequential.conf:/app/docker/prod/supervisord.conf:ro" -v "$upgrade_root/delayed-drain.sh:/tmp/delayed-drain.sh:ro" "$APP_IMAGE"
worker=$standalone
for attempt in $(seq 1 90); do
    if docker exec "$worker" supervisorctl -c /app/docker/prod/supervisord.conf status horizon dispatcher scheduler > "$upgrade_root/sequential.status" 2>&1 && awk '$2 != "RUNNING" {failed=1} END {exit(failed || NR != 3)}' "$upgrade_root/sequential.status"; then break; fi
    sleep 1
done
awk '$2 != "RUNNING" {failed=1} END {exit(failed || NR != 3)}' "$upgrade_root/sequential.status"
started=$(date +%s)
docker stop -t 375 "$worker"
elapsed=$(($(date +%s) - started))
[ "$(docker inspect --format '{{.State.ExitCode}}' "$worker")" = 0 ]
[ "$elapsed" -ge 20 ]
docker rm "$worker"
printf 'RED reproduced: separate supervisor groups drain sequentially in %s seconds\n' "$elapsed"
run_standalone -v "$upgrade_root/delayed.conf:/app/docker/prod/supervisord.conf:ro" -v "$upgrade_root/delayed-drain.sh:/tmp/delayed-drain.sh:ro" "$APP_IMAGE"
worker=$standalone
wait_worker_health
started=$(date +%s)
docker stop -t 375 "$worker"
elapsed=$(($(date +%s) - started))
[ "$(docker inspect --format '{{.State.ExitCode}}' "$worker")" = 0 ]
[ "$elapsed" -ge 10 ] && [ "$elapsed" -lt 18 ]
docker rm "$worker"
printf 'PASS: two 10-second drains stop concurrently in %s seconds, not 20\n' "$elapsed"
# Pause only the test trap so the real supervisor exits before wait resumes.
sed "s/|| true' TERM INT/|| true; sleep 3' TERM INT/" "$upgrade_root/wrapper.sh" > "$upgrade_root/race-wrapper.sh"
sed 's|^command=php /app/artisan .*|command=sleep 3600|' "$upgrade_root/supervisord.conf" > "$upgrade_root/race.conf"
sed 's#\[ "$interrupted" = 1 \] || break#if ! kill -0 "$supervisor_pid" 2>/dev/null; then break; fi#' "$upgrade_root/race-wrapper.sh" > "$upgrade_root/legacy-race-wrapper.sh"
run_standalone -v "$upgrade_root/race.conf:/app/docker/prod/supervisord.conf:ro" -v "$upgrade_root/legacy-race-wrapper.sh:/app/docker/prod/all-in-one-worker.sh:ro" "$APP_IMAGE"
worker=$standalone
wait_worker_health
docker stop -t 375 "$worker"
[ "$(docker inspect --format '{{.State.ExitCode}}' "$worker")" = 143 ]
docker rm "$worker"
echo 'RED reproduced: liveness-based interrupted wait loses supervisor exit0 and returns143'
run_standalone -v "$upgrade_root/race.conf:/app/docker/prod/supervisord.conf:ro" -v "$upgrade_root/race-wrapper.sh:/app/docker/prod/all-in-one-worker.sh:ro" "$APP_IMAGE"
worker=$standalone
wait_worker_health
docker stop -t 375 "$worker"
[ "$(docker inspect --format '{{.State.ExitCode}}' "$worker")" = 0 ]
docker rm "$worker"
echo 'PASS: interrupted wait reaps actual supervisor exit0 after child-exit race'
# Reproduce the legacy descendant SIGKILL against a PID-1 supervisor.
cat > "$upgrade_root/legacy-listener.sh" <<'SH'
#!/bin/sh
printf 'READY\n'
IFS= read -r header
dd bs=1 count="${header##*len:}" status=none > /dev/null
printf 'RESULT 2\nOK'
kill -KILL "$(cat /tmp/heptapod/supervisord.pid)"
SH
dc stop redis
run_standalone --entrypoint sh -e HEPTAPOD_ROLE=web -v "$upgrade_root/legacy-listener.sh:/app/docker/prod/fatal-exit.sh:ro" "$APP_IMAGE" /app/docker/prod/entrypoint.sh /usr/bin/supervisord -c /app/docker/prod/supervisord.conf
for attempt in $(seq 1 90); do
    docker logs "$standalone" > "$upgrade_root/legacy.log" 2>&1
    if grep -q 'entered FATAL state' "$upgrade_root/legacy.log"; then break; fi
    sleep 1
done
grep -q 'entered FATAL state' "$upgrade_root/legacy.log"
[ "$(docker exec "$standalone" cat /proc/1/comm)" = supervisord ]
[ "$(docker inspect --format '{{.State.Running}}' "$standalone")" = true ]
echo 'RED reproduced: legacy descendant SIGKILL leaves PID-1 supervisor running after FATAL'
docker rm -f "$standalone"
# The normal standalone image must gracefully drain then exit70 on FATAL.
run_standalone "$APP_IMAGE"
for attempt in $(seq 1 90); do
    [ "$(docker inspect --format '{{.State.Running}}' "$standalone")" = false ] && break
    sleep 1
done
[ "$(docker inspect --format '{{.State.Running}}' "$standalone")" = false ]
[ "$(docker inspect --format '{{.State.ExitCode}}' "$standalone")" = 70 ]
docker logs "$standalone" > "$upgrade_root/standalone-fatal.log" 2>&1
grep -q 'entered FATAL state' "$upgrade_root/standalone-fatal.log"
grep -q 'draining supervisord' "$upgrade_root/standalone-fatal.log"
docker rm "$standalone"
echo 'PASS: standalone image without --init exits70 after graceful fatal shutdown'
# A persistent Compose service must exit nonzero on FATAL and recover automatically.
dc rm -f all-in-one-worker
dc stop redis
dc up -d --no-deps all-in-one-worker
worker=$(dc ps -aq all-in-one-worker)
docker wait "$worker" > "$upgrade_root/worker-exit" &
exit_waiter=$!
# Bounded polling needs no index in the body.
# shellcheck disable=SC2034
for attempt in $(seq 1 90); do
    [ ! -s "$upgrade_root/worker-exit" ] || break
    sleep 1
done
if [ ! -s "$upgrade_root/worker-exit" ]; then
    docker logs "$worker" >&2
    echo 'FATAL child left the container running' >&2; exit 1
fi
wait "$exit_waiter"
exit_code=$(cat "$upgrade_root/worker-exit")
[ "$exit_code" -ne 0 ]
docker logs "$worker" > "$upgrade_root/worker.log" 2>&1
grep -q 'entered FATAL state' "$upgrade_root/worker.log"
grep -q 'Fatal worker startup: draining supervisord' "$upgrade_root/worker.log"
dc start redis
wait_worker_health
[ "$(docker inspect --format '{{.RestartCount}}' "$worker")" -gt 0 ]
printf 'PASS: child FATAL exits container %s; restart policy recovers all three workers after Redis returns\n' "$exit_code"
docker stop -t 375 "$worker"
