#!/bin/sh
# Shell/PHP fragments expand in the container.
# shellcheck disable=SC2016
# Real deployment check: requires locally built APP_IMAGE and LIGHTHOUSE_IMAGE.
set -eu
root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
: "${APP_IMAGE:?Set APP_IMAGE to the app image under test}"
: "${LIGHTHOUSE_IMAGE:?Set LIGHTHOUSE_IMAGE to the Lighthouse image under test}"
fresh=$(mktemp -d)
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-prod-install-test}"
export HTTP_PORT="127.0.0.1:${INSTALL_TEST_PORT:-8382}"
export COMPOSE_FILE="$fresh/compose.prod.yaml:$fresh/test.yaml${DEPLOY_TEST_OVERRIDE:+:$DEPLOY_TEST_OVERRIDE}"
cat > "$fresh/test.yaml" <<'YAML'
services:
    app:
        ports: !override
            - '${HTTP_PORT}:8080'
        environment:
            SERVER_NAME: http://localhost:8080
            SESSION_SECURE_COOKIE: 'false'
YAML
mkdir "$fresh/scripts"
cp "$root/compose.prod.yaml" "$root/compose.proxy.yaml" "$root/.env.prod.example" "$fresh/"
cp "$root/scripts/install.sh" "$root/scripts/check-disk.sh" "$root/scripts/lifecycle-lock.sh" "$root/scripts/release-image.sh" "$root/scripts/image-reference.sh" "$root/scripts/worker-memory-preflight.sh" "$fresh/scripts/"
# shellcheck source=tests/Deployment/release-fixture.sh
. "$root/tests/Deployment/release-fixture.sh"
cd "$fresh"
dc() { docker compose --env-file .env.prod "$@"; }
cleanup() {
    status=$?
    trap - EXIT HUP INT TERM
    cleanup_status=0
    if [ -f .env.prod ]; then
        if [ "$status" -ne 0 ]; then
            dc logs app gotenberg >&2 || cleanup_status=$?
        fi
        dc down -v || cleanup_status=$?
    fi
    if [ -n "${fixture_registry_name:-}" ]; then
        stop_release_fixture || cleanup_status=$?
    fi
    rm -rf "$fresh" || cleanup_status=$?
    [ "$status" -ne 0 ] || status=$cleanup_status
    exit "$status"
}
trap cleanup EXIT HUP INT TERM

start_release_fixture "$fresh" "$APP_IMAGE" "$LIGHTHOUSE_IMAGE"
publish_fixture_release install
./scripts/install.sh --domain first.example.com --owner-email owner@example.com > first-install.log
[ "$(grep -c 'Password (shown once): ' first-install.log)" = 1 ]
password=$(sed -n 's/^Password (shown once): //p' first-install.log)
[ "${#password}" = 32 ]
[ "$(dc exec -T postgres sh -ec 'psql -At -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT count(*) FROM users JOIN model_has_roles ON users.id = model_has_roles.model_id JOIN roles ON roles.id = model_has_roles.role_id WHERE roles.name = '\''Owner'\'' AND users.email = '\''owner@example.com'\'' AND users.email_verified_at IS NOT NULL"')" = 1 ]
cp .env.prod first.env
./scripts/install.sh --domain first.example.com --owner-email owner@example.com > repeat-install.log
grep -q 'No services started or restarted; Owner creation skipped' repeat-install.log
if grep -q 'Password (shown once): ' repeat-install.log; then echo 'Installer rerun printed a new password' >&2; exit 1; fi
# Identical input must leave the entire env file byte-identical, including all secrets.
cmp first.env .env.prod
grep -q '^APP_URL=https://first.example.com$' .env.prod
grep -q '^SERVER_NAME=first.example.com$' .env.prod
for key in DB_PASSWORD BACKUP_ARCHIVE_PASSWORD REVERB_APP_ID REVERB_APP_KEY REVERB_APP_SECRET; do
    value=$(awk -v key="$key" 'index($0, key "=") == 1 { print substr($0, length(key)+2) }' .env.prod)
    [ "${#value}" = 64 ]
done
key=$(sed -n 's/^APP_KEY=base64://p' .env.prod)
[ "$(printf '%s' "$key" | openssl base64 -d -A | wc -c | tr -d ' ')" = 32 ]
[ "$(find .env.prod -perm 600 | wc -l | tr -d ' ')" = 1 ]
count=$(dc exec -T postgres sh -ec 'psql -At -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT count(*) FROM migrations"')
[ "$count" -gt 0 ]
curl -fsS "http://localhost:${INSTALL_TEST_PORT:-8382}/up" >/dev/null

# A successful PDF response alone does not prove resource filtering: check renderer warnings.
printf '<html><body><img src="https://example.com/policy-probe.png"><iframe src="file:///etc/passwd"></iframe></body></html>' |
    dc exec -T app sh -ec 'cat > /tmp/index.html; curl -fsS -F files=@/tmp/index.html http://gotenberg:3000/forms/chromium/convert/html -o /tmp/policy.pdf'
dc logs gotenberg > renderer.log
grep -q "https://example.com/policy-probe.png.*value filtered" renderer.log
grep -q "file:///etc/passwd.*value filtered" renderer.log
printf '<html><body><h1>Embedded report</h1><img src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciPjxyZWN0IHdpZHRoPSIxMCIgaGVpZ2h0PSIxMCIvPjwvc3ZnPg=="></body></html>' |
    dc exec -T app sh -ec 'cat > /tmp/index.html; curl -fsS -F files=@/tmp/index.html http://gotenberg:3000/forms/chromium/convert/html -o /tmp/embedded.pdf; test "$(head -c 5 /tmp/embedded.pdf)" = "%PDF-"'

# A concurrent app startup must wait for the PostgreSQL advisory lock.
dc exec -T postgres sh -ec 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT pg_advisory_lock(7646001); SELECT pg_sleep(5)"' > lock-holder.log &
holder=$!
sleep 1
dc exec -T app php /app/docker/prod/migrate.php > migration.log &
migration=$!
sleep 1
waiting=$(dc exec -T postgres sh -ec 'psql -At -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT count(*) FROM pg_stat_activity WHERE wait_event = '\''advisory'\''"')
[ "$waiting" -gt 0 ]
wait "$holder"
wait "$migration"
grep -q 'Nothing to migrate' migration.log
# A database/migration failure must never fall through to the server command.
if dc exec -T -e DB_HOST=127.0.0.1 app sh /app/docker/prod/entrypoint.sh php -r 'echo "SERVER_START_PROBE";' > failed-start.log 2>&1; then
    cat failed-start.log >&2
    echo 'Migration failure incorrectly started the server' >&2
    exit 1
fi
if grep -q SERVER_START_PROBE failed-start.log; then
    echo 'Server command ran after migration failure' >&2
    exit 1
fi
# Verify the real proxy installer after the own-TLS matrix, on the same own port.
dc down -v
rm .env.prod
unset HTTP_PORT HTTPS_PORT
export COMPOSE_FILE="$fresh/compose.prod.yaml:$fresh/compose.proxy.yaml"
./scripts/install.sh --domain proxy.example.com --behind-proxy --port "${INSTALL_TEST_PORT:-8382}" --app-url https://proxy.example.com:8443 --without-browser
cp .env.prod proxy.env
./scripts/install.sh --domain proxy.example.com --behind-proxy --port "${INSTALL_TEST_PORT:-8382}" --app-url https://proxy.example.com:8443 --without-browser
cmp proxy.env .env.prod
grep -q '^APP_URL=https://proxy.example.com:8443$' .env.prod
grep -q '^HEPTAPOD_BROWSER_ENABLED=false$' .env.prod
grep -q '^COMPOSE_PROFILES=disabled$' .env.prod
dc config --format json > proxy-model.json
jq -e --arg port "${INSTALL_TEST_PORT:-8382}" '.services.app.ports | length == 1 and .[0].host_ip == "127.0.0.1" and .[0].published == $port and .[0].target == 8080' proxy-model.json >/dev/null
[ "$(curl -sS -o /dev/null -w '%{http_code}' -H 'Host: proxy.example.com:8443' "http://localhost:${INSTALL_TEST_PORT:-8382}/login")" = 200 ]
dc exec -T reverb sh /app/docker/prod/healthcheck.sh
dc exec -T app php -r '
$key = getenv("REVERB_APP_KEY");
$socket = stream_socket_client("tcp://app:8080", $errno, $error, 5);
if (! $socket) { throw new RuntimeException($error); }
$request = "GET /app/$key?protocol=7&client=js&version=8.6 HTTP/1.1\r\nHost: proxy.example.com:8443\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: ".base64_encode(random_bytes(16))."\r\nSec-WebSocket-Version: 13\r\nOrigin: https://proxy.example.com:8443\r\n\r\n";
fwrite($socket, $request);
$status = fgets($socket);
if (! str_contains($status, "101 Switching Protocols")) { throw new RuntimeException("Expected WebSocket upgrade; got $status"); }
fclose($socket);
'
echo 'PASS: real proxy install repeats idempotently, browser off, one loopback HTTP port and WebSocket upgrade'

# Archive contents are the shipped installation boundary, not a hand-picked script fixture.
# Local callers can supply an archive made via their container's Git; CI makes it here.
export_archive=${INSTALL_EXPORT_ARCHIVE:-$fresh/release-export.tar}
if [ -z "${INSTALL_EXPORT_ARCHIVE:-}" ]; then
    git -C "$root" archive --worktree-attributes HEAD > "$export_archive"
fi
dc down -v
rm .env.prod
mv .git fixture.git
tar -xf "$export_archive" -C "$fresh"
# Exercise uncommitted installer changes too, exactly as the Git fixture above does.
cp "$root/scripts/install.sh" "$root/scripts/release-image.sh" "$root/scripts/worker-memory-preflight.sh" "$fresh/scripts/"
[ ! -e .git ]
for required in README.md LICENSE docs/deploy/hetzner.md docs/deploy/operations.md .env.prod.example compose.prod.yaml compose.proxy.yaml scripts/install.sh scripts/release-image.sh scripts/image-reference.sh scripts/check-disk.sh scripts/lifecycle-lock.sh; do
    [ -f "$required" ] || { echo "Release export missing $required" >&2; exit 1; }
done
./scripts/install.sh --domain proxy.example.com --behind-proxy --port "${INSTALL_TEST_PORT:-8382}" --without-browser --revision "$fixture_sha" --owner-email export@example.com > export-install.log
sed 's/^Password (shown once): .*/Password (shown once): [redacted]/' export-install.log
[ "$(dc exec -T postgres sh -ec 'psql -At -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT count(*) FROM users WHERE email = '\''export@example.com'\'' AND email_verified_at IS NOT NULL"')" = 1 ]
grep -q '^APP_IMAGE=.*@sha256:' .env.prod
[ "$(curl -sS -o /dev/null -w '%{http_code}' -H 'Host: proxy.example.com' "http://localhost:${INSTALL_TEST_PORT:-8382}/login")" = 200 ]
printf 'PASS: git archive export includes installer assets and installs a pinned release with --revision and no .git\n'

dc down -v
rm .env.prod
if ./scripts/install.sh --domain proxy.example.com --behind-proxy --port "${INSTALL_TEST_PORT:-8382}" --without-browser --owner-email recovery@example.com > export-recovery.log 2>&1; then
    echo 'Export without revision unexpectedly installed' >&2; exit 1
fi
grep -q 'No Git revision found. Pass --revision' export-recovery.log
[ -z "$(dc ps -q app)" ]
if grep -q '^APP_IMAGE=' .env.prod; then echo 'Missing revision pinned an image' >&2; exit 1; fi
sed -n '/^# BEGIN INSTALL RECOVERY$/,/^) # END INSTALL RECOVERY$/p' export-recovery.log |
    sed "s/REPLACE_WITH_FULL_PUBLISHED_COMMIT_SHA/$fixture_sha/" > export-recovery.sh
sh export-recovery.sh > export-recovered.log
sed 's/^Password (shown once): .*/Password (shown once): [redacted]/' export-recovered.log
[ "$(dc exec -T postgres sh -ec 'psql -At -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "SELECT count(*) FROM users WHERE email = '\''recovery@example.com'\'' AND email_verified_at IS NOT NULL"')" = 1 ]
[ "$(curl -sS -o /dev/null -w '%{http_code}' -H 'Host: proxy.example.com' "http://localhost:${INSTALL_TEST_PORT:-8382}/login")" = 200 ]
printf 'PASS: missing export revision fails loudly; complete printed recovery installs without Git after filling the SHA\n'
printf 'PASS: fresh install creates one verified Owner and prints password once; rerun preserves Owner and byte-identical env, configured domain, automatic migrations, advisory lock, migration failure and PDF policies\n'
