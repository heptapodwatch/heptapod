#!/usr/bin/env bash
# Real install/upgrade, stderr, SIGKILL and recreation on an isolated stack.
set -euo pipefail
root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
: "${APP_IMAGE:?Set APP_IMAGE to the production image under test}"
: "${DIAGNOSTICS_TEST_HTTP_PORT:?Reserve a disposable HTTP port}"
: "${DIAGNOSTICS_TEST_HTTPS_PORT:?Reserve a disposable HTTPS port}"
: "${COMPOSE_PROJECT_NAME:?Choose a disposable project name}"
fresh=$(mktemp -d)
export HEPTAPOD_DIAGNOSTICS_DIR=${HEPTAPOD_DIAGNOSTICS_DIR:-$(mktemp -d)}
export COMPOSE_FILE="$fresh/compose.prod.yaml:$fresh/test.yaml"
export HTTP_PORT="127.0.0.1:$DIAGNOSTICS_TEST_HTTP_PORT" HTTPS_PORT="127.0.0.1:$DIAGNOSTICS_TEST_HTTPS_PORT"
unset APP_KEY DB_PASSWORD BACKUP_ARCHIVE_PASSWORD APP_URL SERVER_NAME COMPOSE_PROFILES HEPTAPOD_BROWSER_ENABLED
mkdir "$fresh/scripts"
cp "$root/compose.prod.yaml" "$root/.env.prod.example" "$fresh/"
cp "$root/scripts/"{install,upgrade,check-disk,lifecycle-lock,release-image,image-reference,retain-diagnostics}.sh "$root/scripts/diagnostics-filter.jq" "$fresh/scripts/"
cat > "$fresh/test.yaml" <<'YAML'
services:
    app:
        pull_policy: never
        environment:
            SERVER_NAME: http://localhost:8080
    horizon:
        image: alpine:latest
        pull_policy: never
        restart: 'no'
        volumes: !reset []
        entrypoint: ['sh', '-c']
        command:
            - |
                printf '%s\n' '{"datetime":"2026-10-07T00:00:00+00:00","channel":"production","level":400,"level_name":"ERROR","message":"Allowed memory size of 123 bytes exhausted (tried to allocate 456 bytes)","context":{}}' >&2
                printf '%s\n' '{"datetime":"2026-10-07T00:00:00+00:00","channel":"production","level":400,"level_name":"ERROR","message":"SENTINEL_LOG_MESSAGE","context":{"exception":{"class":"RuntimeException","file":"/app/app/Fixture.php:7","message":"SENTINEL_EXCEPTION https://user:SENTINEL_URL@example.test Authorization: Bearer SENTINEL_HEADER"}}}' >&2
                printf '%s\n' 'https://user:SENTINEL_URL@example.test' 'Authorization: Bearer SENTINEL_HEADER' 'DB_PASSWORD=SENTINEL_ENV' >&2
                printf '%s\n' 'SENTINEL_STDOUT'
                exec sleep 600
        healthcheck:
            test: ['CMD', 'sh', '-c', 'kill -0 1']
        labels:
            diagnostics-test-generation: before
    dispatcher:
        pull_policy: never
        entrypoint: ['sleep', '600']
        healthcheck:
            test: ['CMD', 'sh', '-c', 'kill -0 1']
    scheduler:
        pull_policy: never
        entrypoint: ['sleep', '600']
        healthcheck:
            test: ['CMD', 'sh', '-c', 'kill -0 1']
    reverb:
        pull_policy: never
        entrypoint: ['sleep', '600']
        healthcheck:
            test: ['CMD', 'sh', '-c', 'kill -0 1']
    postgres:
        pull_policy: never
    redis:
        pull_policy: never
    gotenberg:
        pull_policy: never
YAML
# Enforcing SELinux hosts require the generated bind/build files to be readable.
if command -v selinuxenabled >/dev/null && selinuxenabled; then chcon -R -t container_file_t "$fresh"; fi
# shellcheck source=tests/Deployment/release-fixture.sh
. "$root/tests/Deployment/release-fixture.sh"
cd "$fresh"
dc() { docker compose --env-file .env.prod "$@"; }
watcher=
cleanup() {
    status=$?
    trap - EXIT
    if [ -n "$watcher" ]; then
        kill "$watcher"
        wait "$watcher" || [ "$?" = 143 ]
    fi
    dc down --remove-orphans >/dev/null 2>&1 || echo 'WARNING: disposable diagnostics stack cleanup failed' >&2
    if [ -n "${fixture_registry_name:-}" ]; then stop_release_fixture; fi
    echo "Evidence directory: $HEPTAPOD_DIAGNOSTICS_DIR"
    echo "Fixture directory: $fresh (private generated environment, not an artifact)"
    exit "$status"
}
trap cleanup EXIT
start_release_fixture "$fresh" "$APP_IMAGE"
publish_fixture_release diagnostics
./scripts/install.sh --domain diagnostics.example.test --without-browser
old=$(dc ps -q horizon)
[ -n "$old" ]
./scripts/retain-diagnostics.sh snapshot
if [ -r /proc/loadavg ] && [ -r /proc/meminfo ]; then
    jq -s -e 'any(.[]; .hostPressure != null and .hostPressure.load1 >= 0 and .hostPressure.memoryTotalBytes > 0 and .hostPressure.memoryAvailableBytes > 0 and .hostPressure.swapTotalBytes >= 0 and .hostPressure.swapFreeBytes >= 0 and .hostPressure.unavailable == {})' "$HEPTAPOD_DIAGNOSTICS_DIR/diagnostics.jsonl" >/dev/null
else
    jq -s -e 'any(.[]; .hostPressure != null)' "$HEPTAPOD_DIAGNOSTICS_DIR/diagnostics.jsonl" >/dev/null
fi
echo 'PASS: capture window retains host pressure facts without host identifiers or raw tool output'
if docker exec "$old" test -e /sys/fs/cgroup/memory.peak; then
    jq -s -e --arg id "$old" 'any(.[]; .container == $id and .memoryPeakStatus == "available" and .memoryPeakBytes > 0)' "$HEPTAPOD_DIAGNOSTICS_DIR/diagnostics.jsonl" >/dev/null
else
    jq -s -e --arg id "$old" 'any(.[]; .container == $id and .memoryPeakStatus == "unsupported" and .memoryPeakBytes == null)' "$HEPTAPOD_DIAGNOSTICS_DIR/diagnostics.jsonl" >/dev/null
fi
echo 'PASS: live cgroup peak is numeric when supported, otherwise explicitly unavailable'
sleep 2
docker kill --signal KILL "$old" >/dev/null
[ "$(docker inspect --format '{{.State.ExitCode}}' "$old")" = 137 ]
# No OOM is invented: SIGKILL is not evidence of an OOM kill.
[ "$(docker inspect --format '{{.State.OOMKilled}}' "$old")" = false ]
# Three identical captures intentionally do not use the loop index.
# shellcheck disable=SC2034
for capture in 1 2 3; do ./scripts/retain-diagnostics.sh snapshot; sleep 1; done
jq -s -e --arg id "$old" '([.[] | select(.container==$id and .imageRevision!=null and .status=="exited")] | length)==1 and ([.[] | select(.hostPressure!=null)] | length)==4 and all(.[]; .suppressedCount!=0)' "$HEPTAPOD_DIAGNOSTICS_DIR/diagnostics.jsonl" >/dev/null || { echo 'FAIL: unchanged state or empty summaries churn, or host windows missing' >&2; exit 1; }
echo 'PASS: repeated unchanged stopped state emits once, empty summaries absent, every host window retained'
sed 's/diagnostics-test-generation: before/diagnostics-test-generation: after/' test.yaml > changed.yaml
mv changed.yaml test.yaml
sleep 2
./scripts/upgrade.sh
new=$(dc ps -q horizon)
[ "$new" != "$old" ]
[ -f "$HEPTAPOD_DIAGNOSTICS_DIR/diagnostics.jsonl" ] || { echo 'FAIL: upgrade recreation discarded old worker diagnostics' >&2; exit 1; }
jq -s -e --arg old "$old" 'any(.[]; .container == $old and .exitCode == 137 and .oomKilled == false and .memoryPeakBytes == null and .memoryPeakStatus == "stopped") and any(.[]; .container == $old and .message_key == "php.memory_exhausted") and any(.[]; .container == $old and .exceptionClass == "RuntimeException" and .file == "/app/app/Fixture.php" and .line == 7) and any(.[]; .container == $old and .suppressedCount >= 3)' "$HEPTAPOD_DIAGNOSTICS_DIR/diagnostics.jsonl" >/dev/null || { echo 'FAIL: stopped worker facts missing after upgrade recreation' >&2; exit 1; }
if grep -Eq 'SENTINEL_|Authorization|https?://|DB_PASSWORD|\[stacktrace\]' "$HEPTAPOD_DIAGNOSTICS_DIR"/diagnostics.jsonl*; then echo 'FAIL: secret sentinel retained' >&2; exit 1; fi
echo 'PASS: real SIGKILL exit137/OOM=false, engine error and exception location survive upgrade recreation; planted exception/URL/header/env/stdout secrets absent'
./scripts/retain-diagnostics.sh watch > watcher.log 2>&1 &
watcher=$!
docker kill --signal KILL "$new" >/dev/null
found=false
# Bounded polling needs no index in the body.
# shellcheck disable=SC2034
for attempt in {1..15}; do
    if jq -s -e --arg id "$new" 'any(.[]; .container == $id and .exitCode == 137 and .oomKilled == false)' "$HEPTAPOD_DIAGNOSTICS_DIR/diagnostics.jsonl" >/dev/null; then found=true; break; fi
    sleep 1
done
[ "$found" = true ]
kill "$watcher"
wait "$watcher" || [ "$?" = 143 ]
watcher=
[ ! -s watcher.log ]
echo 'PASS: optional host watcher retains a fresh native SIGKILL exit without systemd'
# Force native-size and native-age boundaries with disposable retained files.
cp "$HEPTAPOD_DIAGNOSTICS_DIR/diagnostics.jsonl" retained-before.jsonl
for n in {1..9}; do cp retained-before.jsonl "$HEPTAPOD_DIAGNOSTICS_DIR/diagnostics.jsonl.$n"; done
cp retained-before.jsonl "$HEPTAPOD_DIAGNOSTICS_DIR/diagnostics.jsonl.old"
touch -t 200001010000 "$HEPTAPOD_DIAGNOSTICS_DIR/diagnostics.jsonl.old"
# A valid retained JSON record exactly fills the 128 MiB active file.
awk 'BEGIN { printf "{\"padding\":\""; for (i=0;i<134217713;i++) printf "x"; printf "\"}\n" }' > "$HEPTAPOD_DIAGNOSTICS_DIR/diagnostics.jsonl"
./scripts/retain-diagnostics.sh snapshot
[ ! -f "$HEPTAPOD_DIAGNOSTICS_DIR/diagnostics.jsonl.old" ]
[ "$(find "$HEPTAPOD_DIAGNOSTICS_DIR" -maxdepth 1 -name 'diagnostics.jsonl*' | wc -l | tr -d ' ')" = 10 ]
[ "$(wc -c < "$HEPTAPOD_DIAGNOSTICS_DIR/diagnostics.jsonl.1" | tr -d ' ')" = 134217728 ]
[ "$(wc -c < "$HEPTAPOD_DIAGNOSTICS_DIR/diagnostics.jsonl" | tr -d ' ')" -le 134217728 ]
total=$(find "$HEPTAPOD_DIAGNOSTICS_DIR" -maxdepth 1 -type f -name 'diagnostics.jsonl*' -exec wc -c {} + | awk 'END {print $1}')
[ "$total" -le 1342177280 ]
[ "$(find "$HEPTAPOD_DIAGNOSTICS_DIR" -maxdepth 0 -perm 700 | wc -l | tr -d ' ')" = 1 ]
[ -z "$(find "$HEPTAPOD_DIAGNOSTICS_DIR" -maxdepth 1 -type f ! -perm 600)" ]
echo 'PASS: 128 MiB rotation, ten-file/1.25 GiB ceiling, 14-day expiry, private 0700 directory/0600 files'
