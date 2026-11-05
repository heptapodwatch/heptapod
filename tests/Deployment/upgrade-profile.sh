#!/bin/sh
# Real production upgrade entry point: no mocked artisan, Compose or dotenv parser.
set -eu
root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
: "${APP_IMAGE:?Set APP_IMAGE to the app image under test}"
: "${LIGHTHOUSE_IMAGE:?Set LIGHTHOUSE_IMAGE to the Lighthouse image under test}"
fresh=$(mktemp -d)
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-lighthouse-upgrade-profile-test}"
export HTTP_PORT=127.0.0.1:9584 HTTPS_PORT=127.0.0.1:9547
export COMPOSE_FILE="$fresh/compose.prod.yaml:$fresh/test.yaml"
unset APP_KEY DB_PASSWORD BACKUP_ARCHIVE_PASSWORD APP_URL SERVER_NAME HEPTAPOD_BROWSER_ENABLED COMPOSE_PROFILES
mkdir "$fresh/scripts"
cp "$root/compose.prod.yaml" "$root/.env.prod.example" "$fresh/"
cp "$root/scripts/install.sh" "$root/scripts/upgrade.sh" "$root/scripts/check-disk.sh" "$root/scripts/lifecycle-lock.sh" "$root/scripts/release-image.sh" "$root/scripts/image-reference.sh" "$root/scripts/upgrade-backup-retention.sh" "$root/scripts/upgrade-backup-retention.php" "$root/scripts/worker-memory-preflight.sh" "$fresh/scripts/"
# shellcheck source=tests/Deployment/release-fixture.sh
. "$root/tests/Deployment/release-fixture.sh"
cat > "$fresh/test.yaml" <<'YAML'
services:
    app:
        pull_policy: never
        environment:
            SERVER_NAME: http://localhost:8080
            SESSION_SECURE_COOKIE: 'false'
    horizon:
        pull_policy: never
    dispatcher:
        pull_policy: never
    scheduler:
        pull_policy: never
    reverb:
        pull_policy: never
    lighthouse:
        pull_policy: never
    lighthouse-proxy:
        pull_policy: never
YAML
cd "$fresh"
dc() { docker compose --env-file .env.prod "$@"; }
cleanup() {
    status=$?
    if [ "$status" != 0 ]; then dc logs --tail=30 >&2; fi
    dc --profile browser down -v --remove-orphans
    if [ -n "${fixture_registry_name:-}" ]; then stop_release_fixture; fi
    rm -rf "$fresh"
}
trap cleanup EXIT HUP INT TERM
start_release_fixture "$fresh" "$APP_IMAGE" "$LIGHTHOUSE_IMAGE"
publish_fixture_release upgrade-profile
./scripts/install.sh --domain monitor.example.com
[ -n "$(dc ps -q lighthouse)" ]
cp .env.prod complete.env
# Legacy installations had no deployment-mode keys but always ran Lighthouse.
grep -v -e HEPTAPOD_BROWSER_ENABLED -e COMPOSE_PROFILES complete.env > .env.prod
./scripts/upgrade.sh
grep -q '^HEPTAPOD_BROWSER_ENABLED=true$' .env.prod || { echo 'Legacy upgrade did not persist browser enabled' >&2; exit 1; }
grep -q '^COMPOSE_PROFILES=browser$' .env.prod || { echo 'Legacy upgrade did not persist browser profile' >&2; exit 1; }
[ -n "$(dc ps -q lighthouse)" ]
dc exec -T app sh -ec 'find /backups -name "*.zip" | grep -q .'
printf 'PASS: legacy upgrade adds both browser keys, completes backup and retains healthy Lighthouse\n'
cp .env.prod enabled.env
enabled_lighthouse=$(dc ps -q lighthouse)
enabled_proxy=$(dc ps -q lighthouse-proxy)
./scripts/upgrade.sh
cmp enabled.env .env.prod
[ "$(dc ps -q lighthouse)" = "$enabled_lighthouse" ]
[ "$(dc ps -q lighthouse-proxy)" = "$enabled_proxy" ]
printf 'PASS: complete enabled configuration stays byte-identical\n'
# Reject partial and contradictory selections before maintenance or environment writes.
for variant in true-without-profile false-with-profile missing-enabled missing-profile invalid-enabled; do
    case "$variant" in
        true-without-profile) sed 's/^COMPOSE_PROFILES=.*/COMPOSE_PROFILES=disabled/' enabled.env > .env.prod ;;
        false-with-profile) sed 's/^HEPTAPOD_BROWSER_ENABLED=.*/HEPTAPOD_BROWSER_ENABLED=false/' enabled.env > .env.prod ;;
        missing-enabled) grep -v HEPTAPOD_BROWSER_ENABLED enabled.env > .env.prod ;;
        missing-profile) grep -v COMPOSE_PROFILES enabled.env > .env.prod ;;
        invalid-enabled) sed 's/^HEPTAPOD_BROWSER_ENABLED=.*/HEPTAPOD_BROWSER_ENABLED=maybe/' enabled.env > .env.prod ;;
    esac
    cp .env.prod inconsistent.env
    if ./scripts/upgrade.sh > rejected.log 2>&1; then cat rejected.log >&2; exit 1; fi
    grep -q 'Inconsistent browser configuration' rejected.log
    cmp inconsistent.env .env.prod
    printf 'PASS: %s rejected before writes with explicit browser guidance\n' "$variant"
done
sed -e 's/^HEPTAPOD_BROWSER_ENABLED=.*/HEPTAPOD_BROWSER_ENABLED=false/' -e 's/^COMPOSE_PROFILES=.*/COMPOSE_PROFILES=disabled/' enabled.env > .env.prod
cp .env.prod disabled.env
./scripts/upgrade.sh
cmp disabled.env .env.prod
[ -z "$(dc ps -q lighthouse)" ] || { echo 'Disabled upgrade retained Lighthouse' >&2; exit 1; }
[ -z "$(dc ps -q lighthouse-proxy)" ] || { echo 'Disabled upgrade retained proxy egress' >&2; exit 1; }
[ -z "$(docker ps -q --filter label=com.docker.compose.project="$COMPOSE_PROJECT_NAME" --filter label=com.docker.compose.service=lighthouse)" ]
[ -z "$(docker ps -q --filter label=com.docker.compose.project="$COMPOSE_PROJECT_NAME" --filter label=com.docker.compose.service=lighthouse-proxy)" ]
printf 'PASS: disabled upgrade preserves explicit values and removes old Lighthouse/proxy containers\n'
curl -fsS http://localhost:9584/up >/dev/null
