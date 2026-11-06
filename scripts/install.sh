#!/bin/sh
# Printed recovery fragments expand in the operator's recovery shell.
# shellcheck disable=SC2016
set -eu
# Rerun by the canonical path first: through current, a concurrent swap could mix this body with another release's helpers.
script=$(readlink -f -- "$0")
[ "$0" = "$script" ] || exec sh "$script" "$@"
release_dir=${script%/scripts/*}
# shellcheck source=scripts/lifecycle-lock.sh
. "$release_dir/scripts/lifecycle-lock.sh"
enter_instance_root
umask 077

domain=
app_url=
app_url_given=0
behind_proxy=0
port=8380
port_given=0
browser=true
owner_email=
revision=
while [ "$#" -gt 0 ]; do
    case "$1" in
        --help|-h)
            cat <<'HELP'
Usage: ./scripts/install.sh --domain monitor.example.com [options]

Install Heptapod with Docker Compose. An existing .env.prod is validated and
missing configuration is appended; services and Owner accounts are not changed.
An existing configuration prints explicit first-start or upgrade commands.

Options:
  --domain NAME       Public DNS name (required).
  --app-url URL       Public HTTPS URL on that name, optionally with a port.
  --behind-proxy      Bind HTTP to loopback; the upstream proxy terminates TLS.
  --port NUMBER       Loopback HTTP port with --behind-proxy (default: 8380).
  --without-browser   Disable browser services (recommended for 4 GB hosts).
  --owner-email EMAIL Create the first Owner after a fresh installation.
  --revision SHA      Full published commit SHA for an export without Git.
  -h, --help          Show this help without changing configuration or services.

Fresh installation requires a clean Git checkout of a published main commit,
or a release export with --revision set to that release's full commit SHA,
registry access, and at least 12 GiB free on the Docker data filesystem:
6 GiB for image download/unpacking and 6 GiB for backup headroom.
HELP
            exit 0
            ;;
        --domain)
            [ "$#" -ge 2 ] || { echo '--domain requires a DNS name' >&2; exit 1; }
            domain=$2
            shift 2
            ;;
        --app-url)
            [ "$#" -ge 2 ] || { echo '--app-url requires an HTTPS URL' >&2; exit 1; }
            app_url=$2
            app_url_given=1
            shift 2
            ;;
        --revision)
            if [ "$#" -lt 2 ] || ! printf '%s\n' "$2" | grep -Eq '^[a-f0-9]{40}$'; then
                echo '--revision requires a full 40-character lowercase commit SHA' >&2; exit 1
            fi
            revision=$2; shift 2 ;;
        --behind-proxy) behind_proxy=1; shift ;;
        --without-browser) browser=false; shift ;;
        --owner-email)
            [ "$#" -ge 2 ] && [ -n "$2" ] || { echo '--owner-email requires an email address' >&2; exit 1; }
            owner_email=$2; shift 2 ;;
        --port)
            [ "$#" -ge 2 ] || { echo '--port requires a number' >&2; exit 1; }
            port=$2; port_given=1; shift 2 ;;
        *) echo "Unknown argument: $1" >&2; exit 1 ;;
    esac
done
[ -n "$domain" ] || { echo 'Usage: ./scripts/install.sh --domain monitor.example.com' >&2; exit 1; }
case "$domain" in
    *[!a-zA-Z0-9.-]*|.*|*.|-*|*-) echo 'Use a DNS name without a scheme, port or path' >&2; exit 1 ;;
esac
if [ "$app_url_given" = 1 ]; then
    case "$app_url" in
        "https://$domain") ;;
        "https://$domain":*)
            app_url_port=${app_url#"https://$domain":}
            case "$app_url_port" in ''|*[!0-9]*) echo 'Invalid --app-url: use HTTPS with the --domain host and an optional port from 1 to 65535' >&2; exit 1 ;; esac
            [ "${#app_url_port}" -le 5 ] && [ "$app_url_port" -ge 1 ] && [ "$app_url_port" -le 65535 ] || { echo 'Invalid --app-url: port must be from 1 to 65535' >&2; exit 1; }
            ;;
        *) echo 'Invalid --app-url: use HTTPS with the --domain host and an optional port from 1 to 65535' >&2; exit 1 ;;
    esac
else
    app_url=https://$domain
fi
case "$port" in ''|*[!0-9]*) echo 'Port must be an integer from 1 to 65535' >&2; exit 1 ;; esac
[ "${#port}" -le 5 ] && [ "$port" -ge 1 ] && [ "$port" -le 65535 ] || { echo 'Port must be from 1 to 65535' >&2; exit 1; }
[ "$port_given" = 0 ] || [ "$behind_proxy" = 1 ] || { echo '--port requires --behind-proxy' >&2; exit 1; }
if [ "$behind_proxy" = 1 ] && [ -n "${HTTPS_PORT:-}" ]; then
    echo '--behind-proxy conflicts with HTTPS_PORT' >&2; exit 1
fi
if [ "$behind_proxy" = 1 ] && [ -n "${COMPOSE_FILE:-}" ]; then
    case "${COMPOSE_FILE##*:}" in
        compose.proxy.yaml|./compose.proxy.yaml|"$PWD/compose.proxy.yaml") ;;
        *) echo '--behind-proxy requires compose.proxy.yaml as the final COMPOSE_FILE; unset COMPOSE_FILE or add the override last' >&2; exit 1 ;;
    esac
fi
if [ "$behind_proxy" = 1 ]; then
    compose_files=${COMPOSE_FILE:-compose.prod.yaml:compose.proxy.yaml}
else
    compose_files=${COMPOSE_FILE:-compose.prod.yaml}
fi
case "$compose_files" in
    *[!A-Za-z0-9_./:+-]*) echo 'COMPOSE_FILE contains unsupported characters; use only letters, digits, _, ., /, :, + and -' >&2; exit 1 ;;
esac
if ! compose_help=$(docker compose config --help) || ! printf '%s\n' "$compose_help" | grep -q -- '--environment'; then
    echo 'Docker Compose v5 (tested 5.1.2) with config --environment is required; upgrade Compose before installation' >&2
    exit 1
fi
# Parse the existing file (or fresh template) before creating state or writing configuration.
name_env_file=.env.prod
[ -f "$name_env_file" ] || name_env_file=$release_dir/.env.prod.example
name_environment=$(printf 'services:\n  environment:\n    image: scratch\n' | docker compose --env-file "$name_env_file" -f - config --environment)
validate_backup_name \
    "$(printf '%s\n' "$name_environment" | awk '/^APP_NAME=/ {sub(/^APP_NAME=/, ""); print; found=1} END {if (!found) print "Heptapod"}')" \
    "$(printf '%s\n' "$name_environment" | awk '/^BACKUP_PATH=/ {sub(/^BACKUP_PATH=/, ""); print; found=1} END {if (!found) print "/backups"}')"
acquire_lifecycle_lock
# shellcheck source=scripts/worker-memory-preflight.sh
. "$release_dir/scripts/worker-memory-preflight.sh"
command -v openssl >/dev/null
case "${COMPOSE_PROFILES:-}" in
    *'*'*) echo 'Wildcard COMPOSE_PROFILES is not supported; select browser or disabled explicitly.' >&2; exit 1 ;;
esac
managed_keys='APP_KEY DB_PASSWORD BACKUP_ARCHIVE_PASSWORD REVERB_APP_ID REVERB_APP_KEY REVERB_APP_SECRET APP_URL SERVER_NAME HEPTAPOD_BROWSER_ENABLED COMPOSE_PROFILES COMPOSE_FILE'
if [ "$behind_proxy" = 1 ]; then
    managed_keys="$managed_keys HTTP_PORT"
fi
server_name=$domain
profiles=browser
[ "$behind_proxy" = 0 ] || server_name=http://$domain:8080
[ "$browser" = true ] || profiles=disabled
existing_config=0
if [ -f .env.prod ]; then
    existing_config=1
else
    # shellcheck source=scripts/check-disk.sh
    . "$release_dir/scripts/check-disk.sh"
    require_deployment_disk
    # Shipped placeholders are not operator configuration. Start without managed keys.
    grep -v -e APP_KEY -e DB_PASSWORD -e BACKUP_ARCHIVE_PASSWORD -e REVERB_APP_ID -e REVERB_APP_KEY -e REVERB_APP_SECRET -e APP_URL -e SERVER_NAME -e HTTP_PORT -e COMPOSE_PROFILES -e HEPTAPOD_BROWSER_ENABLED -e COMPOSE_FILE "$release_dir/.env.prod.example" > .env.prod
    # Fresh configuration only: an existing .env.prod keeps the operator's memory values.
    select_memory_profile
fi

# Let Compose parse dotenv. The scratch model avoids production's required-value guards
# while checking an uninitialized file; it never starts a container or sources the file.
compose_environment() {
    printf 'services:\n  environment:\n    image: scratch\n' |
        docker compose --env-file .env.prod -f - config --environment
}
resolved=$(compose_environment)
file_resolved=$(unset APP_KEY DB_PASSWORD BACKUP_ARCHIVE_PASSWORD REVERB_APP_ID REVERB_APP_KEY REVERB_APP_SECRET APP_URL SERVER_NAME HEPTAPOD_BROWSER_ENABLED COMPOSE_PROFILES HTTP_PORT COMPOSE_FILE; compose_environment)
resolved_value() {
    printf '%s\n' "$1" | sed -n "s/^$2=//p"
}
case "$(resolved_value "$resolved" COMPOSE_PROFILES)" in
    *'*'*) echo 'Wildcard COMPOSE_PROFILES is not supported; select browser or disabled explicitly.' >&2; exit 1 ;;
esac
missing_keys=
# Validate every key before chmod or append: failure must preserve the whole file.
for key in $managed_keys; do
    value=$(resolved_value "$resolved" "$key")
    file_value=$(resolved_value "$file_resolved" "$key")
    mentions=$(grep -cE "(^|[^[:alnum:]_])$key([^[:alnum:]_]|$)" .env.prod || [ "$?" = 1 ])
    if { [ "$key" != COMPOSE_FILE ] || [ -n "$file_value" ]; } && [ "$value" != "$file_value" ] || [ "$mentions" -gt 1 ]; then
        if [ "$key" = COMPOSE_FILE ] && [ "$value" != "$file_value" ]; then
            echo 'COMPOSE_FILE differs from .env.prod; export the original file list or edit .env.prod explicitly' >&2
        else
            echo "Ambiguous $key in .env.prod or shell environment; remove duplicate mentions/overrides" >&2
        fi
        exit 1
    fi
    if [ "$key" = COMPOSE_FILE ]; then
        value=$file_value
    fi
    if [ -z "$value" ]; then
        if [ "$mentions" -ne 0 ]; then
            echo "Compose resolves $key empty but .env.prod mentions it; remove the definition to generate a value" >&2
            exit 1
        fi
        missing_keys="$missing_keys $key"
    else
        case "$key" in
            APP_URL) expected=$app_url ;;
            SERVER_NAME) expected=$server_name ;;
            HEPTAPOD_BROWSER_ENABLED) expected=$browser ;;
            COMPOSE_PROFILES) expected=$profiles ;;
            COMPOSE_FILE) expected=$compose_files ;;
            HTTP_PORT) expected=$port ;;
            *) continue ;;
        esac
        if [ "$value" != "$expected" ]; then
            case "$key" in
                HEPTAPOD_BROWSER_ENABLED|COMPOSE_PROFILES)
                    echo 'Inconsistent browser selection: configure HEPTAPOD_BROWSER_ENABLED=true and COMPOSE_PROFILES=browser, or false and disabled; pass --without-browser for the disabled selection.' >&2 ;;
                COMPOSE_FILE)
                    echo 'COMPOSE_FILE differs from .env.prod; export the original file list or edit .env.prod explicitly' >&2 ;;
                *) echo "$key: domain already configured as $value; change it in .env.prod or pass the same --domain" >&2 ;;
            esac
            exit 1
        fi
    fi
done
chmod 600 .env.prod
# Existing nonempty values are never written, regardless of their dotenv spelling.
for key in $missing_keys; do
    case "$key" in
        APP_KEY) value="base64:$(openssl rand -base64 32)" ;;
        APP_URL) value=$app_url ;;
        SERVER_NAME) value=$server_name ;;
        HEPTAPOD_BROWSER_ENABLED) value=$browser ;;
        COMPOSE_PROFILES) value=$profiles ;;
        COMPOSE_FILE) value=$compose_files ;;
        HTTP_PORT) value=$port ;;
        *) value=$(openssl rand -hex 32) ;;
    esac
    printf '\n%s=%s\n' "$key" "$value" >> .env.prod
done
export COMPOSE_FILE="$compose_files"
print_install_recovery() {
    {
        echo '# BEGIN INSTALL RECOVERY'
        echo '('
        echo 'set -eu'
        printf 'cd %s\n' "$(shell_quote "$PWD")"
        echo 'unset APP_IMAGE LIGHTHOUSE_IMAGE COMPOSE_FILE'
        recovery_project=$(printf '%s\n' "$resolved" | sed -n 's/^COMPOSE_PROJECT_NAME=//p')
        printf 'export COMPOSE_PROJECT_NAME=%s\n' "$(shell_quote "$recovery_project")"
        printf 'release_dir=%s\n' "$(shell_quote "$release_dir")"
        echo '. "$release_dir/scripts/lifecycle-lock.sh"'
        echo 'acquire_lifecycle_lock'
        echo '. "$release_dir/scripts/release-image.sh"'
        recovery_app_repository=$(bash "$release_dir/scripts/image-reference.sh" app) || return 1
        printf 'browser=%s\nHEPTAPOD_IMAGE_REGISTRY=%s\n' "$browser" "$(shell_quote "${HEPTAPOD_IMAGE_REGISTRY:-${recovery_app_repository%/*}}")"
        echo 'validate_image_keys'
        if [ -n "$revision" ]; then
            printf 'release_checkout %s\n' "$(shell_quote "$revision")"
        elif command -v git >/dev/null 2>&1 && git rev-parse HEAD >/dev/null 2>&1; then
            echo 'release_checkout'
        else
            echo '# Replace this placeholder with the full published commit SHA supplied with the export.'
            echo "release_checkout 'REPLACE_WITH_FULL_PUBLISHED_COMMIT_SHA'"
        fi
        echo 'resolve_release_images "$browser"'
        echo 'pin_release_images'
        echo '. "$release_dir/scripts/worker-memory-preflight.sh"'
        echo 'require_worker_memory'
        printf 'docker compose --env-file %s up -d --wait --wait-timeout 300\n' "$(shell_quote "$PWD/.env.prod")"
        if [ -n "$owner_email" ]; then
            printf 'docker compose --env-file %s exec -T app php artisan heptapod:user:create-owner --email=%s --if-missing --no-interaction\n' "$(shell_quote "$PWD/.env.prod")" "$(shell_quote "$owner_email")"
        else
            echo 'printf "Owner email: " > /dev/tty; IFS= read -r owner_email < /dev/tty; [ -n "$owner_email" ]'
            printf 'docker compose --env-file %s exec -T app php artisan heptapod:user:create-owner --email="$owner_email" --if-missing --no-interaction\n' "$(shell_quote "$PWD/.env.prod")"
        fi
        echo ') # END INSTALL RECOVERY'
    }
}
if [ "$existing_config" = 1 ]; then
    echo 'Existing .env.prod found; configuration validated, existing secrets preserved. No services started or restarted; Owner creation skipped.'
    if [ -n "$missing_keys" ]; then
        echo "Appended missing configuration keys:$missing_keys"
    fi
    app_container=$(docker compose --env-file .env.prod ps -q app)
    if [ -n "$app_container" ]; then
        echo 'Run ./scripts/upgrade.sh (add --build only for source-build installs) to upgrade the running instance.'
    else
        configured_image=$(resolved_value "$resolved" APP_IMAGE)
        case "$configured_image" in
            ''|*:main)
                echo 'No pinned app image is configured. After fixing image access, use this explicit release-pin and first-start block; rerunning install itself starts nothing:'
                print_install_recovery
                exit 0 ;;
        esac
        echo 'No running app container found. Resolve any image-access or port errors, then check the memory budget and start the pinned instance explicitly:'
        echo "sh -ec '. ./scripts/worker-memory-preflight.sh; require_worker_memory'"
        echo 'docker compose --env-file .env.prod up -d --wait'
        echo 'After services are healthy, create the first Owner (replace the email):'
        echo 'docker compose --env-file .env.prod exec -T app php artisan heptapod:user:create-owner --email=you@example.com --if-missing --no-interaction'
    fi
    exit 0
fi
if git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    HEPTAPOD_VERSION_BUILD=$(git describe --tags --always --dirty)
    export HEPTAPOD_VERSION_BUILD
fi
case "$(resolved_value "$resolved" MAIL_MAILER)" in
    ''|log) echo 'Warning: MAIL_MAILER is log. Capability links are redacted. Configure SMTP in .env.prod (MAIL_MAILER=smtp and MAIL_*) or use heptapod:user:access-link for invitation and password-reset links.' >&2 ;;
esac
fresh_install_failed() {
    install_status=$?
    trap - EXIT
    [ "$install_status" -ne 0 ] || return 0
    echo 'Fresh install failed. Fix image access/configuration, then paste this complete recovery block (rerunning install alone starts no services):' >&2
    print_install_recovery >&2
    exit "$install_status"
}
trap fresh_install_failed EXIT
# shellcheck source=scripts/release-image.sh
. "$release_dir/scripts/release-image.sh"
validate_image_keys
release_checkout "$revision"
resolve_release_images "$browser"
pin_release_images
require_worker_memory
docker compose --env-file .env.prod up -d --wait --wait-timeout 300
if [ -z "$owner_email" ] && [ -t 0 ]; then
    printf 'Owner email (empty to create later): '
    IFS= read -r owner_email
fi
if [ -n "$owner_email" ]; then
    docker compose --env-file .env.prod exec -T app php artisan heptapod:user:create-owner --email="$owner_email" --if-missing --no-interaction
else
    echo 'Owner not created. Run: docker compose --env-file .env.prod exec -T app php artisan heptapod:user:create-owner --email=you@example.com --no-interaction'
fi
