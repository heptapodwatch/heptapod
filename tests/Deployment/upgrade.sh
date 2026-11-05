#!/bin/sh
# PHP fragments expand in the container.
# shellcheck disable=SC2016
# Recreate the real Gotenberg service through the production upgrade entry point.
set -eu
root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
: "${APP_IMAGE:?Set APP_IMAGE to the app image under test}"
: "${LIGHTHOUSE_IMAGE:?Set LIGHTHOUSE_IMAGE to the Lighthouse image under test}"
# Optional: an app image from before the notification channel conversion (e.g. built at f906f3a9). The fixture then
# installs that release, seeds rules, follows and a client contact, and upgrades to APP_IMAGE through the real preview.
legacy_app=${LEGACY_APP_IMAGE:-}
candidate_base=$APP_IMAGE
fresh=$(mktemp -d)
export COMPOSE_PROJECT_NAME=prod-upgrade-test
export HTTP_PORT=127.0.0.1:8384 HTTPS_PORT=127.0.0.1:8447
export COMPOSE_FILE="$fresh/compose.prod.yaml:$fresh/test.yaml"
unset APP_KEY DB_PASSWORD BACKUP_ARCHIVE_PASSWORD APP_URL SERVER_NAME
mkdir "$fresh/scripts"
cp "$root/compose.prod.yaml" "$root/.env.prod.example" "$fresh/"
cp "$root/scripts/install.sh" "$root/scripts/upgrade.sh" "$root/scripts/check-disk.sh" "$root/scripts/lifecycle-lock.sh" "$root/scripts/release-image.sh" "$root/scripts/image-reference.sh" "$root/scripts/upgrade-backup-retention.sh" "$root/scripts/upgrade-backup-retention.php" "$root/scripts/worker-memory-preflight.sh" "$fresh/scripts/"
# shellcheck source=tests/Deployment/release-fixture.sh
. "$root/tests/Deployment/release-fixture.sh"
cat > "$fresh/test.yaml" <<YAML
services:
    app:
        build:
            context: $root
        environment:
            SERVER_NAME: http://localhost:8080
    lighthouse:
        build:
            context: $root
    gotenberg:
        labels:
            deployment-recreation-test: before
YAML
cd "$fresh"
dc() { docker compose --env-file .env.prod "$@"; }
events=
cleanup() {
    if [ -n "$events" ]; then
        kill "$events"
        wait "$events" || [ "$?" = 143 ]
    fi
    dc down -v
    if [ -n "${fixture_registry_name:-}" ]; then stop_release_fixture; fi
    rm -rf "$fresh"
}
trap cleanup EXIT HUP INT TERM
start_release_fixture "$fresh" "${legacy_app:-$APP_IMAGE}" "$LIGHTHOUSE_IMAGE"
if [ -n "$legacy_app" ]; then
    publish_fixture_release legacy
    ./scripts/install.sh --domain monitor.example.com
    dc exec -T app php artisan heptapod:user:create-owner --email=owner@legacy.test --no-interaction
    # The old schema's own shapes: an Owner with the default rules, a team address behind a site rule with a time
    # window, a follow, and a client contact without a name.
    dc exec -T app php <<'PHP'
<?php
use Illuminate\Support\Facades\DB;
require '/app/vendor/autoload.php';
$app = require '/app/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$now = now();
$client = DB::table('clients')->insertGetId(['name' => 'Legacy client', 'slug' => 'legacy-client', 'contact_email' => 'office@legacy.test', 'created_at' => $now, 'updated_at' => $now]);
$site = DB::table('sites')->insertGetId(['client_id' => $client, 'url' => 'https://legacy.example.test', 'badge_id' => (string) Illuminate\Support\Str::uuid(), 'created_at' => $now, 'updated_at' => $now]);
$team = app(Heptapod\Actions\CreateEmailDestination::class)(['address' => 'team@legacy.test', 'label' => 'Legacy team', 'locale' => 'en']);
$rule = DB::table('notification_rules')->insertGetId(['event_type' => 'site.down', 'scope_type' => 'site', 'scope_id' => $site, 'mute_inherited' => false, 'digest' => false,
    'weekdays' => json_encode([1, 2, 3, 4, 5]), 'window_start' => '08:00', 'window_end' => '18:00', 'created_at' => $now, 'updated_at' => $now]);
DB::table('notification_rule_destination')->insert(['notification_rule_id' => $rule, 'notification_destination_id' => $team->id]);
$owner = DB::table('users')->where('email', 'owner@legacy.test')->value('id');
DB::table('site_notification_follows')->insert(['site_id' => $site, 'user_id' => $owner]);
printf("Seeded legacy notifications: %d rules, %d destinations, %d follows\n", DB::table('notification_rules')->count(), DB::table('notification_destinations')->count(), DB::table('site_notification_follows')->count());
PHP
    fixture_app_base=$candidate_base
    publish_fixture_release upgrade
else
    publish_fixture_release upgrade
    ./scripts/install.sh --domain monitor.example.com
fi
sed '/^REVERB_/d; /^BROADCAST_CONNECTION=/d' .env.prod > legacy.env
mv legacy.env .env.prod
cp .env.prod before.env
before=$(dc ps -q gotenberg)
before_reverb=$(dc ps -q reverb)
[ -n "$before" ] && [ -n "$before_reverb" ]
# Record live: the daemon keeps only a small global history shared with other stacks.
docker events --filter type=container --filter "container=$before" --format '{{json .}}' > events.jsonl &
events=$!
sleep 1
sed 's/deployment-recreation-test: before/deployment-recreation-test: after/' test.yaml > changed.yaml
mv changed.yaml test.yaml
# Snapshot the phase and schema around the notification preflight's one-shot preview container.
REAL_DOCKER=$(command -v docker)
PREVIEW_FIXTURE=$fresh
PREVIEW_STATE=${lifecycle_state#"$fresh"/}
export REAL_DOCKER PREVIEW_FIXTURE PREVIEW_STATE
mkdir "$fresh/bin"
cat > "$fresh/schema.sql" <<'SQL'
SELECT count(*) FROM migrations;
SELECT string_agg(tablename, ',' ORDER BY tablename) FROM pg_tables WHERE schemaname = 'public';
SQL
cat > "$fresh/bin/docker" <<'SH'
#!/bin/sh
set -eu
schema() { "$REAL_DOCKER" compose --env-file .env.prod exec -T postgres sh -ec 'psql -X -At -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"' < "$PREVIEW_FIXTURE/schema.sql" > "$PREVIEW_FIXTURE/$1"; }
case "$*" in
    *'run --rm --no-deps -T -e RUN_MIGRATIONS=0 app php artisan heptapod:notifications:migration-preview')
        cp "$PREVIEW_FIXTURE/$PREVIEW_STATE/phase" "$PREVIEW_FIXTURE/preview-phase"
        schema schema-before
        status=0
        "$REAL_DOCKER" "$@" || status=$?
        schema schema-after
        exit "$status" ;;
esac
exec "$REAL_DOCKER" "$@"
SH
chmod +x "$fresh/bin/docker"
preview=$lifecycle_state/notification-migration-preview.txt
psql_value() { dc exec -T postgres sh -ec 'psql -X -At -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "$1"' sh "$1"; }
if [ -n "$legacy_app" ]; then
    # A conversion the preview cannot read (an undecryptable personal address) must stop the upgrade before freezing.
    psql_value "UPDATE notification_destinations SET config_secrets = 'not-a-ciphertext' WHERE user_id IS NOT NULL" > /dev/null
    app_before=$(dc ps -q app)
    if PATH="$fresh/bin:$PATH" ./scripts/upgrade.sh > failed-upgrade.log 2>&1; then cat failed-upgrade.log; echo 'Expected the failing preview to stop the upgrade' >&2; exit 1; fi
    cat failed-upgrade.log
    [ "$(cat preview-phase)" = candidate-ready ]
    cmp schema-before schema-after
    grep -q 'has an unreadable configuration' "$preview" failed-upgrade.log
    grep -qx 'Upgrade aborted before maintenance; old stack untouched.' failed-upgrade.log
    if grep -q 'maintenance mode' failed-upgrade.log; then exit 1; fi
    [ ! -f "$lifecycle_state/phase" ] && [ "$(dc ps -q app)" = "$app_before" ]
    dc exec -T app sh -ec 'if find /backups -name "pre-upgrade-*.zip" | grep -q .; then exit 1; fi'
    dc exec -T app php -r 'require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if ($a->isDownForMaintenance()) { exit(1); }'
    printf 'PASS: a failing notification preview stopped the upgrade in phase candidate-ready; no maintenance, no backup, same app container, schema unchanged (%s)\n' "$(head -n 1 schema-after)"
    psql_value 'UPDATE notification_destinations SET config_secrets = NULL WHERE user_id IS NOT NULL' > /dev/null
fi
PATH="$fresh/bin:$PATH" ./scripts/upgrade.sh > upgrade.log 2>&1 || { cat upgrade.log; exit 1; }
cat upgrade.log
[ "$(cat preview-phase)" = candidate-ready ]
cmp schema-before schema-after
if [ -n "$legacy_app" ]; then
    preview_header='Notification channel conversion (read-only preview):'
    grep -qx "$preview_header" "$preview"
    grep -q 'site_notification_follows' schema-before && grep -q 'notification_rules' schema-before
    grep -q 'time window 1,2,3,4,5 08:00–18:00 dropped' "$preview"
    # The preview printed exactly the report the conversion then stored.
    sed -n 's/^- //p' "$preview" | jq -R . | jq -cs . > preview-lines.json
    psql_value "SELECT properties->'lines' FROM activity_log WHERE event = 'notifications.migrated'" | jq -c . > migrated-lines.json
    cmp preview-lines.json migrated-lines.json
    [ -z "$(psql_value "SELECT to_regclass('public.notification_rules')")" ]
    printf 'PASS: the preview printed the conversion report (%s lines) against the old tables, identical to the stored notifications.migrated report\n' "$(jq length preview-lines.json)"
else
    preview_header='Notification channels: already converted.'
    grep -qx "$preview_header" "$preview"
fi
# The entrypoint's RUN_MIGRATIONS block prints these; the preview container must not reach it.
if grep -Eiq 'migrat(ing|ed)|nothing to migrate|reclassif|notification destinations need attention' "$preview"; then exit 1; fi
preview_line=$(grep -nxF "$preview_header" upgrade.log | head -n 1 | cut -d: -f1)
maintenance_line=$(grep -n 'maintenance mode' upgrade.log | head -n 1 | cut -d: -f1)
[ -n "$preview_line" ] && [ -n "$maintenance_line" ] && [ "$preview_line" -lt "$maintenance_line" ]
printf 'PASS: notification preview ran in phase candidate-ready before maintenance; migrations count and public tables unchanged (%s)\n' "$(head -n 1 schema-after)"
if [ -n "$legacy_app" ]; then
    # The legacy fixture upgrades to another release, so only the image pins may differ.
    grep -Ev '^(APP|LIGHTHOUSE)_IMAGE=' before.env > before-unpinned.env
    grep -Ev '^(APP|LIGHTHOUSE)_IMAGE=' .env.prod | head -c "$(wc -c < before-unpinned.env | tr -d ' ')" | cmp before-unpinned.env -
else
    head -c "$(wc -c < before.env | tr -d ' ')" .env.prod | cmp before.env -
fi
for key in REVERB_APP_ID REVERB_APP_KEY REVERB_APP_SECRET; do
    value=$(sed -n "s/^$key=//p" .env.prod)
    [ "${#value}" = 64 ]
done
dc config --format json | jq -e '.services.app.environment | .BROADCAST_CONNECTION == "reverb" and .REVERB_HOST == "reverb" and .REVERB_PORT == "8080" and .REVERB_SCHEME == "http"' >/dev/null
dc exec -T app php artisan heptapod:system-health --no-interaction | jq -e '.reverb.status == "ok"' >/dev/null
dc exec -T app php -r '
$key = getenv("REVERB_APP_KEY");
$socket = stream_socket_client("tcp://app:8080", $errno, $error, 5);
if (! $socket) { throw new RuntimeException($error); }
$request = "GET /app/$key?protocol=7&client=js&version=8.6 HTTP/1.1\r\nHost: localhost:8080\r\nOrigin: https://monitor.example.com\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: ".base64_encode(random_bytes(16))."\r\nSec-WebSocket-Version: 13\r\n\r\n";
fwrite($socket, $request);
$status = fgets($socket);
fclose($socket);
if (! str_contains($status, "101 Switching Protocols")) { throw new RuntimeException("Legacy upgrade did not restore the live WebSocket: $status"); }
'
echo 'PASS: legacy upgrade generated Reverb credentials and restored the live WebSocket'
after=$(dc ps -q gotenberg)
[ -n "$after" ] && [ "$after" != "$before" ]
[ "$(docker inspect --format '{{index .Config.Labels "deployment-recreation-test"}}' "$after")" = after ]
[ "$(docker inspect --format '{{.State.Health.Status}}' "$after")" = healthy ]
after_reverb=$(dc ps -q reverb)
[ -n "$after_reverb" ] && [ "$after_reverb" != "$before_reverb" ]
dc exec -T reverb sh /app/docker/prod/healthcheck.sh
dc exec -T app test ! -f /app/storage/framework/down
curl -fsS http://localhost:8384/up >/dev/null
dc exec -T app sh -ec 'find /backups -name "*.zip" | grep -q .'
# Retain the old container's actual die event, not just a changed container ID.
kill "$events"
wait "$events" || [ "$?" = 143 ]
events=
cat events.jsonl
jq -s -e 'any(.[]; .Action == "die" and .Actor.Attributes.exitCode == "0")' events.jsonl
printf 'PASS: Gotenberg recreated %s -> %s; Reverb recreated %s -> %s; old process exited 0; replacement healthy; backup exists; maintenance disabled; environment including Reverb credentials unchanged\n' "$before" "$after" "$before_reverb" "$after_reverb"
