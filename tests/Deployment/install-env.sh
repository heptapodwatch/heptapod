#!/bin/sh
# Exercise the installer with the real Compose dotenv parser, never a shell-sourced file.
set -eu
root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
fresh=$(mktemp -d)
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-prod-env-test}"
export COMPOSE_FILE="$fresh/compose.yaml"
unset APP_KEY DB_PASSWORD BACKUP_ARCHIVE_PASSWORD APP_URL SERVER_NAME HEPTAPOD_BROWSER_ENABLED COMPOSE_PROFILES
mkdir "$fresh/scripts"
cp "$root/scripts/install.sh" "$root/scripts/check-disk.sh" "$root/scripts/lifecycle-lock.sh" "$root/scripts/release-image.sh" "$root/scripts/image-reference.sh" "$root/scripts/worker-memory-preflight.sh" "$fresh/scripts/"
# shellcheck source=tests/Deployment/release-fixture.sh
. "$root/tests/Deployment/release-fixture.sh"
cp "$root/.env.prod.example" "$root/compose.prod.yaml" "$fresh/"
cd "$fresh"
cat > compose.yaml <<'YAML'
services:
    app:
        image: alpine:3
        command: ['sleep', '300']
        environment:
            APP_KEY: ${APP_KEY}
            DB_PASSWORD: ${DB_PASSWORD}
            BACKUP_ARCHIVE_PASSWORD: ${BACKUP_ARCHIVE_PASSWORD}
            APP_URL: ${APP_URL}
            SERVER_NAME: ${SERVER_NAME}
YAML
dc() { docker compose --env-file .env.prod "$@"; }
cleanup() {
    if [ -f .env.prod ]; then dc down -v; fi
    if [ -n "${fixture_registry_name:-}" ]; then stop_release_fixture; fi
    rm -rf "$fresh"
}
trap cleanup EXIT HUP INT TERM
resolved() {
    environment=$(dc config --environment) || return "$?"
    printf '%s\n' "$environment" | sed -n "s/^$1=//p"
}
# These cases assert unrelated keys stay byte-identical; real missing-credential generation is covered by install.sh.
configured_reverb() {
    printf 'REVERB_APP_ID=existing-id\nREVERB_APP_KEY=existing-key\nREVERB_APP_SECRET=existing-secret\n'
}
# Unsupported Compose must abort at the capability boundary, before parsing or writes.
mkdir unsupported-compose
cat > unsupported-compose/docker <<'SH'
#!/bin/sh
printf '%s\n' "$*" >> docker-calls.log
if [ "$*" = 'compose config --help' ]; then
    printf 'Usage: docker compose config [OPTIONS]\n  --services List services\n'
else
    echo 'Unexpected Docker invocation' >&2
    exit 95
fi
SH
chmod +x unsupported-compose/docker
printf 'APP_KEY=base64:b3JpZ2luYWw=\n' > .env.prod
chmod 644 .env.prod
cp .env.prod before.env
status=0
PATH="$fresh/unsupported-compose:$PATH" ./scripts/install.sh --domain monitor.example.com > output.log 2>&1 || status=$?
[ "$status" != 0 ]
cmp before.env .env.prod
[ "$(find .env.prod -perm 644 | wc -l | tr -d ' ')" = 1 ]
grep -q 'Docker Compose v5 (tested 5.1.2)' output.log
[ "$(cat docker-calls.log)" = 'compose config --help' ]
[ "$(resolved APP_KEY)" = base64:b3JpZ2luYWw= ]
printf 'PASS: unsupported Compose rejected before parsing; bytes and permissions unchanged\n'
for key in APP_KEY DB_PASSWORD BACKUP_ARCHIVE_PASSWORD APP_URL SERVER_NAME HEPTAPOD_BROWSER_ENABLED COMPOSE_PROFILES; do
    case "$key" in
        APP_KEY) original=base64:b3JpZ2luYWw= ;;
        APP_URL) original=https://monitor.example.com ;;
        SERVER_NAME) original=monitor.example.com ;;
        HEPTAPOD_BROWSER_ENABLED) original=true ;;
        COMPOSE_PROFILES) original=browser ;;
        *) original="original-password" ;;
    esac
    # Colon first: this must fail on the pre-fix installer because it replaces the value.
    for variant in colon canonical spaced exported quoted indented mixed-colon-equals mixed-equals-colon duplicate empty malformed comment; do
        printf 'APP_KEY=base64:b3JpZ2luYWw=\nDB_PASSWORD=original-password\nBACKUP_ARCHIVE_PASSWORD=original-password\nAPP_URL=https://monitor.example.com\nSERVER_NAME=monitor.example.com\nHEPTAPOD_BROWSER_ENABLED=true\nCOMPOSE_PROFILES=browser\nCOMPOSE_FILE=%s\n' "$COMPOSE_FILE" | grep -v "^$key=" > .env.prod
        configured_reverb >> .env.prod
        expected=success
        case "$variant" in
            colon) printf '%s: %s\n' "$key" "$original" >> .env.prod ;;
            canonical) printf '%s=%s\n' "$key" "$original" >> .env.prod ;;
            spaced) printf '%s = %s\n' "$key" "$original" >> .env.prod ;;
            exported) printf 'export %s=%s\n' "$key" "$original" >> .env.prod ;;
            quoted) printf '%s="%s"\n' "$key" "$original" >> .env.prod ;;
            indented) printf '  %s=%s\n' "$key" "$original" >> .env.prod ;;
            mixed-colon-equals) printf '%s: discarded\n%s=%s\n' "$key" "$key" "$original" >> .env.prod; expected=abort ;;
            mixed-equals-colon) printf '%s=discarded\n%s: %s\n' "$key" "$key" "$original" >> .env.prod; expected=abort ;;
            duplicate) printf '%s=discarded\n%s=%s\n' "$key" "$key" "$original" >> .env.prod; expected=abort ;;
            empty) printf '%s=\n' "$key" >> .env.prod; expected=abort ;;
            malformed) printf '"%s"=ignored\n' "$key" >> .env.prod; expected=abort ;;
            comment) printf '# %s intentionally not configured\n' "$key" >> .env.prod; expected=abort ;;
        esac
        if [ "$variant" = malformed ]; then
            if resolved "$key" > parser-before.log 2>&1; then
                echo 'Compose unexpectedly accepted a quoted key name' >&2
                exit 1
            fi
        else
            before=$(resolved "$key")
            case "$variant" in empty|comment) [ -z "$before" ] ;; *) [ "$before" = "$original" ] ;; esac
        fi
        chmod 644 .env.prod
        cp .env.prod before.env
        status=0
        ./scripts/install.sh --domain monitor.example.com > output.log 2>&1 || status=$?
        if [ "$expected" = success ]; then
            [ "$status" = 0 ] || { cat output.log >&2; exit 1; }
            [ "$(find .env.prod -perm 600 | wc -l | tr -d ' ')" = 1 ]
        else
            [ "$status" != 0 ] || { echo "Installer accepted $key ($variant)" >&2; exit 1; }
            [ "$(find .env.prod -perm 644 | wc -l | tr -d ' ')" = 1 ]
            grep -q "$key" output.log
        fi
        cmp before.env .env.prod
        if [ "$variant" = malformed ]; then
            if resolved "$key" > parser-after.log 2>&1; then
                echo 'Installer changed an invalid dotenv file into valid configuration' >&2
                exit 1
            fi
            cmp parser-before.log parser-after.log
        else
            [ "$(resolved "$key")" = "$before" ]
        fi
        printf 'PASS: %s %s %s; bytes preserved, mode checked, Compose resolution unchanged\n' "$key" "$variant" "$expected"
    done
done
# A different domain and a shell override must not silently disagree with the file.
for conflict in domain server shell; do
    printf 'APP_KEY=base64:b3JpZ2luYWw=\nDB_PASSWORD=original-password\nBACKUP_ARCHIVE_PASSWORD=original-password\nAPP_URL=https://monitor.example.com\n' > .env.prod
    if [ "$conflict" = server ]; then
        printf 'SERVER_NAME=different.example.com\n' >> .env.prod
    else
        printf 'SERVER_NAME=monitor.example.com\n' >> .env.prod
    fi
    printf 'HEPTAPOD_BROWSER_ENABLED=true\nCOMPOSE_PROFILES=browser\nCOMPOSE_FILE=%s\n' "$COMPOSE_FILE" >> .env.prod
    configured_reverb >> .env.prod
    original_server=$(resolved SERVER_NAME)
    chmod 644 .env.prod
    cp .env.prod before.env
    status=0
    case "$conflict" in
        domain) ./scripts/install.sh --domain different.example.com > output.log 2>&1 || status=$? ;;
        server) ./scripts/install.sh --domain monitor.example.com > output.log 2>&1 || status=$? ;;
        shell) APP_KEY=override ./scripts/install.sh --domain monitor.example.com > output.log 2>&1 || status=$? ;;
    esac
    [ "$status" != 0 ]
    cmp before.env .env.prod
    [ "$(find .env.prod -perm 644 | wc -l | tr -d ' ')" = 1 ]
    case "$conflict" in domain|server) grep -q 'domain already configured' output.log ;; shell) grep -q 'APP_KEY' output.log ;; esac
    [ "$(resolved APP_KEY)" = base64:b3JpZ2luYWw= ]
    [ "$(resolved APP_URL)" = https://monitor.example.com ]
    [ "$(resolved SERVER_NAME)" = "$original_server" ]
    printf 'PASS: %s conflict rejected; bytes, permissions and Compose values unchanged\n' "$conflict"
done
# Incompatible file selection must fail before generating mode keys or chmod.
printf 'APP_KEY=base64:b3JpZ2luYWw=\nDB_PASSWORD=original-password\nBACKUP_ARCHIVE_PASSWORD=original-password\nAPP_URL=https://monitor.example.com\n' > .env.prod
chmod 644 .env.prod
cp .env.prod before.env
if COMPOSE_FILE=compose.prod.yaml ./scripts/install.sh --domain monitor.example.com --behind-proxy --port 8380 --without-browser > output.log 2>&1; then
    echo 'Incompatible COMPOSE_FILE accepted' >&2; exit 1
fi
cmp before.env .env.prod
[ "$(find .env.prod -perm 644 | wc -l | tr -d ' ')" = 1 ]
grep -q 'requires compose.proxy.yaml as the final COMPOSE_FILE' output.log
for bad_port in 0 65536 abc 12:80 -1 999999999999999999999; do
    if ./scripts/install.sh --domain monitor.example.com --behind-proxy --port "$bad_port" > output.log 2>&1; then exit 1; fi
    grep -q 'Port must' output.log
    cmp before.env .env.prod
done
if ./scripts/install.sh --domain monitor.example.com --port 8380 > output.log 2>&1; then exit 1; fi
grep -q -- '--port requires --behind-proxy' output.log
if HTTPS_PORT=443 ./scripts/install.sh --domain monitor.example.com --behind-proxy > output.log 2>&1; then exit 1; fi
grep -q 'conflicts with HTTPS_PORT' output.log
cmp before.env .env.prod
printf 'PASS: incompatible COMPOSE_FILE, invalid ports and TLS conflicts rejected before writes/chmod\n'
# URL validation precedes even creation of a missing .env.prod.
rm .env.prod
for invalid_url in '' http://monitor.example.com https://other.example.com https://monitor.example.com/path 'https://monitor.example.com?x=1' 'https://monitor.example.com#fragment' https://user@monitor.example.com https://monitor.example.com:0 https://monitor.example.com:65536; do
    if ./scripts/install.sh --domain monitor.example.com --app-url "$invalid_url" > output.log 2>&1; then
        echo "Installer accepted $invalid_url" >&2; exit 1
    fi
    grep -q 'Invalid --app-url' output.log
    [ ! -e .env.prod ]
done
printf 'PASS: invalid public URLs rejected before creating the env file\n'
docker pull alpine:3
start_release_fixture "$fresh" alpine:3 alpine:3
publish_fixture_release install-env
# Fresh installs pick the memory profile from MemTotal; the file is faked, the real host is not read.
for memory in small default; do
    case "$memory" in
        small) printf 'MemTotal:        3985000 kB\n' > "$memory.meminfo" ;;
        default) printf 'MemTotal:        8000000 kB\n' > "$memory.meminfo" ;;
    esac
    HEPTAPOD_HOST_MEMINFO="$PWD/$memory.meminfo" ./scripts/install.sh --domain monitor.example.com > profile.log 2>&1 || { cat profile.log >&2; exit 1; }
    case "$memory" in
        small)
            grep -qx 'HORIZON_MEMORY_LIMIT=1670m' .env.prod
            grep -qx 'HEPTAPOD_CRAWL_MEMORY_LIMIT_MB=512' .env.prod
            [ "$(grep -c '^Small memory profile:' profile.log)" = 1 ]
            grep -q '^Small memory profile: this host has 3891 MiB RAM (below 6144 MiB), so crawls stop at 512 MiB; with more RAM set HEPTAPOD_CRAWL_MEMORY_LIMIT_MB=768 and HORIZON_MEMORY_LIMIT=1990m in .env.prod.$' profile.log ;;
        default)
            grep -qx 'HORIZON_MEMORY_LIMIT=1990m' .env.prod
            grep -qx 'HEPTAPOD_CRAWL_MEMORY_LIMIT_MB=768' .env.prod
            if grep -q 'Small memory profile' profile.log; then echo 'Default host got the small profile notice' >&2; exit 1; fi ;;
    esac
    # A rerun on an existing configuration never rewrites the memory values, whatever the host reports.
    cp .env.prod profile-before.env
    HEPTAPOD_HOST_MEMINFO="$PWD/small.meminfo" ./scripts/install.sh --domain monitor.example.com > profile-rerun.log 2>&1
    cmp profile-before.env .env.prod
    dc down -v
    rm .env.prod
    printf 'PASS: %s host memory installs the %s profile; rerun preserves it\n' "$memory" "$memory"
done
./scripts/install.sh --domain monitor.example.com --app-url https://monitor.example.com:8443
[ "$(resolved APP_URL)" = https://monitor.example.com:8443 ]
cp .env.prod before.env
./scripts/install.sh --domain monitor.example.com --app-url https://monitor.example.com:8443
cmp before.env .env.prod
chmod 644 .env.prod
if ./scripts/install.sh --domain monitor.example.com > output.log 2>&1; then exit 1; fi
grep -q 'APP_URL: domain already configured' output.log
cmp before.env .env.prod
[ "$(find .env.prod -perm 644 | wc -l | tr -d ' ')" = 1 ]
printf 'PASS: HTTPS custom port, idempotent rerun and existing APP_URL conflict\n'
# Installation adds missing mode keys, preserves complete selections and rejects contradictions.
for selection in legacy disabled true-without-profile false-with-profile; do
    printf 'APP_KEY=base64:b3JpZ2luYWw=\nDB_PASSWORD=original-password\nBACKUP_ARCHIVE_PASSWORD=original-password\nAPP_URL=https://monitor.example.com\nSERVER_NAME=monitor.example.com\n' > .env.prod
    if [ "$selection" != legacy ]; then printf 'COMPOSE_FILE=%s\n' "$COMPOSE_FILE" >> .env.prod; fi
    case "$selection" in
        legacy) ;;
        disabled) printf 'HEPTAPOD_BROWSER_ENABLED=false\nCOMPOSE_PROFILES=disabled\n' >> .env.prod ;;
        true-without-profile) printf 'HEPTAPOD_BROWSER_ENABLED=true\nCOMPOSE_PROFILES=disabled\n' >> .env.prod ;;
        false-with-profile) printf 'HEPTAPOD_BROWSER_ENABLED=false\nCOMPOSE_PROFILES=browser\n' >> .env.prod ;;
    esac
    configured_reverb >> .env.prod
    cp .env.prod before.env
    status=0
    if [ "$selection" = disabled ] || [ "$selection" = false-with-profile ]; then
        ./scripts/install.sh --domain monitor.example.com --without-browser > output.log 2>&1 || status=$?
    else
        ./scripts/install.sh --domain monitor.example.com > output.log 2>&1 || status=$?
    fi
    case "$selection" in
        legacy)
            [ "$status" = 0 ] || { cat output.log >&2; exit 1; }
            grep -q '^HEPTAPOD_BROWSER_ENABLED=true$' .env.prod
            grep -q '^COMPOSE_PROFILES=browser$' .env.prod
            grep -qFx "COMPOSE_FILE=$COMPOSE_FILE" .env.prod
            grep -v -e HEPTAPOD_BROWSER_ENABLED -e COMPOSE_PROFILES -e COMPOSE_FILE .env.prod | sed '/^$/d' > keys.env
            cmp before.env keys.env ;;
        disabled) [ "$status" = 0 ]; cmp before.env .env.prod ;;
        *) [ "$status" != 0 ]; grep -q 'Inconsistent browser selection' output.log; cmp before.env .env.prod ;;
    esac
    printf 'PASS: install browser selection %s; existing explicit keys preserved\n' "$selection"
done
