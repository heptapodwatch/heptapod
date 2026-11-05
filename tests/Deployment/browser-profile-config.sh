#!/bin/sh
# Exercise real entry points and Compose dotenv resolution without changing services.
set -eu
root=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
fresh=$(mktemp -d)
REAL_DOCKER=$(command -v docker)
export REAL_DOCKER
export COMPOSE_FILE="$fresh/compose.prod.yaml"
unset APP_KEY DB_PASSWORD BACKUP_ARCHIVE_PASSWORD APP_URL SERVER_NAME HEPTAPOD_BROWSER_ENABLED COMPOSE_PROFILES HTTP_PORT HTTPS_PORT
mkdir "$fresh/scripts" "$fresh/bin"
cp "$root/scripts/install.sh" "$root/scripts/upgrade.sh" "$root/scripts/check-disk.sh" "$root/scripts/lifecycle-lock.sh" "$root/scripts/release-image.sh" "$root/scripts/image-reference.sh" "$root/scripts/upgrade-backup-retention.sh" "$root/scripts/upgrade-backup-retention.php" "$root/scripts/worker-memory-preflight.sh" "$fresh/scripts/"
cp "$root/compose.prod.yaml" "$root/.env.prod.example" "$fresh/"
cat > "$fresh/bin/docker" <<'SH'
#!/bin/sh
if [ "$1" = info ]; then exec "$REAL_DOCKER" "$@"; fi
case " $* " in
    *' config '*) exec "$REAL_DOCKER" "$@" ;;
    *' ps '*) echo fixture-running-app ;;
    *) printf '%s\n' "$*" >> daemon-boundary.log; echo 'Test refuses daemon access' >&2; exit 95 ;;
esac
SH
chmod +x "$fresh/bin/docker"
export PATH="$fresh/bin:$PATH"
cd "$fresh"
trap 'rm -rf "$fresh"' EXIT HUP INT TERM
for entry in upgrade install; do
    for enabled in false true; do
        for profiles in '*' 'browser,*' '*,disabled'; do
            printf 'APP_URL=https://monitor.example.com\nSERVER_NAME=monitor.example.com\nDB_PASSWORD=original-password\nHEPTAPOD_BROWSER_ENABLED=%s\nCOMPOSE_PROFILES="%s"\nCOMPOSE_FILE=%s\n' "$enabled" "$profiles" "$COMPOSE_FILE" > .env.prod
            chmod 644 .env.prod
            cp .env.prod before.env
            rm -f daemon-boundary.log
            status=0
            if [ "$entry" = install ]; then
                if [ "$enabled" = false ]; then
                    ./scripts/install.sh --domain monitor.example.com --without-browser > output.log 2>&1 || status=$?
                else
                    ./scripts/install.sh --domain monitor.example.com > output.log 2>&1 || status=$?
                fi
            else
                ./scripts/upgrade.sh > output.log 2>&1 || status=$?
            fi
            [ "$status" != 0 ] || { echo "Accepted wildcard: $entry $enabled $profiles" >&2; exit 1; }
            grep -q 'Wildcard COMPOSE_PROFILES is not supported' output.log || { cat output.log >&2; exit 1; }
            cmp before.env .env.prod
            [ "$(find .env.prod -perm 644 | wc -l | tr -d ' ')" = 1 ]
            [ ! -f daemon-boundary.log ] || { cat daemon-boundary.log >&2; exit 1; }
            printf 'PASS: %s %s %s rejected before daemon access, writes or chmod\n' "$entry" "$enabled" "$profiles"
        done
    done
    if [ "$entry" = install ]; then
        # A wildcard shell override must fail before a fresh installer writes dotenv.
        rm .env.prod
        status=0
        COMPOSE_PROFILES='*' ./scripts/install.sh --domain monitor.example.com > output.log 2>&1 || status=$?
        [ "$status" != 0 ]
        grep -q 'Wildcard COMPOSE_PROFILES is not supported' output.log
        [ ! -f .env.prod ]
        [ ! -f daemon-boundary.log ]
        printf 'PASS: install wildcard shell override rejected before dotenv creation\n'
    else
        # A persisted Compose selection lets upgrade reach the wildcard guard before maintenance.
        printf 'COMPOSE_FILE=%s\n' "$COMPOSE_FILE" > .env.prod
        cp .env.prod before.env
        rm -f daemon-boundary.log
        status=0
        COMPOSE_PROFILES='*' ./scripts/upgrade.sh > output.log 2>&1 || status=$?
        [ "$status" != 0 ]
        grep -q 'Wildcard COMPOSE_PROFILES is not supported' output.log
        cmp before.env .env.prod
        [ ! -f daemon-boundary.log ]
        printf 'PASS: upgrade wildcard shell override rejected before maintenance or config writes\n'
    fi
done
# Matching shell/file selections are allowed; conflicting overrides must fail before side effects.
for entry in upgrade install; do
    for enabled in false true; do
        if [ "$enabled" = false ]; then profiles=disabled; conflicting=browser; else profiles=browser; conflicting=disabled; fi
        for selection in matching conflicting; do
            printf 'APP_KEY=base64:b3JpZ2luYWw=\nDB_PASSWORD=original-password\nBACKUP_ARCHIVE_PASSWORD=original-password\nREVERB_APP_ID=original-id\nREVERB_APP_KEY=original-key\nREVERB_APP_SECRET=original-secret\nAPP_URL=https://monitor.example.com\nSERVER_NAME=monitor.example.com\nHEPTAPOD_BROWSER_ENABLED=%s\nCOMPOSE_PROFILES=%s\nCOMPOSE_FILE=%s\n' "$enabled" "$profiles" "$COMPOSE_FILE" > .env.prod
            chmod 644 .env.prod
            cp .env.prod before.env
            rm -f daemon-boundary.log
            if [ "$selection" = matching ]; then shell_profiles=$profiles; else shell_profiles=$conflicting; fi
            status=0
            if [ "$entry" = upgrade ]; then
                COMPOSE_PROFILES="$shell_profiles" ./scripts/upgrade.sh > output.log 2>&1 || status=$?
            elif [ "$enabled" = false ]; then
                COMPOSE_PROFILES="$shell_profiles" ./scripts/install.sh --domain monitor.example.com --without-browser > output.log 2>&1 || status=$?
            else
                COMPOSE_PROFILES="$shell_profiles" ./scripts/install.sh --domain monitor.example.com > output.log 2>&1 || status=$?
            fi
            cmp before.env .env.prod
            if [ "$selection" = matching ]; then
                if [ "$entry" = upgrade ]; then
                    [ "$status" = 95 ] || { cat output.log >&2; exit 1; }
                    grep -q 'pg_database_size(current_database())' daemon-boundary.log
                else
                    [ "$status" = 0 ] || { cat output.log >&2; exit 1; }
                    [ ! -f daemon-boundary.log ]
                    grep -q 'No services started or restarted' output.log
                fi
            else
                [ "$status" = 1 ]
                grep -q 'Ambiguous COMPOSE_PROFILES' output.log
                [ ! -f daemon-boundary.log ]
                [ "$(find .env.prod -perm 644 | wc -l | tr -d ' ')" = 1 ]
            fi
            printf 'PASS: %s %s %s shell profile; persisted selection unchanged\n' "$entry" "$enabled" "$selection"
        done
    done
done
# Verify why '*' cannot represent a disabled browser deployment, using real Compose.
printf 'APP_URL=https://monitor.example.com\nSERVER_NAME=monitor.example.com\nDB_PASSWORD=original-password\nHEPTAPOD_BROWSER_ENABLED=false\nCOMPOSE_PROFILES="*"\n' > .env.prod
"$REAL_DOCKER" compose --env-file .env.prod config --services > services.log
for service in gotenberg lighthouse lighthouse-proxy; do grep -qx "$service" services.log; done
printf 'PASS: real Compose wildcard activates all browser services despite disabled dispatch\n'
