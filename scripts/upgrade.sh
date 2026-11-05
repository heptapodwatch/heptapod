#!/bin/sh
# Container shell fragments expand their variables inside the container.
# shellcheck disable=SC2016
set -eu
cd "$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)"
dc() { docker compose --env-file .env.prod "$@"; }
# shellcheck source=scripts/lifecycle-lock.sh
. ./scripts/lifecycle-lock.sh
# shellcheck source=scripts/release-image.sh
. ./scripts/release-image.sh
build=false
resume=false
case "${1:-}" in
    --help|-h)
        cat <<'HELP'
Usage: ./scripts/upgrade.sh [--build|--resume]

Upgrade to the checkout's published release, or use --build for a source build.
--resume finishes the recorded interrupted upgrade without preparing it again.
Requires Docker Compose v5, flock, jq and Linux coreutils sync.
HELP
        exit 0 ;;
    '') ;;
    --build) build=true ;;
    --resume) resume=true ;;
    *) echo 'Usage: ./scripts/upgrade.sh [--build|--resume]' >&2; exit 1 ;;
esac
[ "$#" -le 1 ] || { echo 'Too many arguments' >&2; exit 1; }
acquire_lifecycle_lock
state="$PWD/$lifecycle_dir/upgrade"
previous="$state/previous"
phase=
write_phase() {
    printf '%s\n' "$1" > "$state/phase.tmp" || return 1
    # A durable phase must never point at unflushed pins, backup metadata or previous snapshots.
    find "$state" -type f -exec sync {} + || return 1
    find "$state" -type d -exec sync {} + || return 1
    persist_lifecycle_file "$state/phase" || return 1
    phase=$1
}
recovery_commands() {
    echo "Upgrade stopped in phase $phase. Writers remain fenced; data may have changed." >&2
    if [ -f "$state/backup.env" ]; then
        recovery_backup=$(sed -n 's/^BACKUP_PATH=//p' "$state/backup.env")
        echo "Recorded backup: $recovery_backup (checksum in $state/backup.env)" >&2
    else
        recovery_backup=
        echo 'Recorded backup metadata is missing; no automatic restore command is safe.' >&2
    fi
    if [ -f "$state/release-intent" ]; then
        echo 'Release acceptance was attempted. Restoring the pre-upgrade backup would lose every write accepted since release. That is a manual operator decision outside this tool; fix forward only.' >&2
        print_artisan_up_command "$(cat "$previous/project")"
    elif [ -n "$recovery_backup" ]; then
        printf 'cd %s && %s --restore-backup %s\n' "$(shell_quote "$PWD")" "$(shell_quote "$PWD/scripts/rollback.sh")" "$(shell_quote "$recovery_backup")" >&2
    fi
    printf "# Fix the cause, then continue forward:\ncd '%s' && '%s/scripts/upgrade.sh' --resume\n" "$PWD" "$PWD" >&2
}
if [ -f "$state/phase" ]; then
    phase=$(cat "$state/phase")
    case "$phase" in
        committed|resumed|rolled-back) [ "$resume" = false ] || { echo "Cannot resume phase $phase" >&2; exit 1; } ;;
        preparing|swapping|starting-writers|releasing)
            if [ "$resume" = false ]; then recovery_commands; exit 1; fi ;;
        *)
            echo "Unfinished upgrade in phase $phase; recover the recorded old stack first:" >&2
            printf "cd '%s' && '%s/scripts/rollback.sh'\n" "$PWD" "$PWD" >&2
            exit 1 ;;
    esac
elif [ "$resume" = true ]; then
    echo 'No interrupted upgrade to resume.' >&2; exit 1
fi
if [ "$resume" = true ]; then
    COMPOSE_PROJECT_NAME=$(cat "$previous/project")
    export COMPOSE_PROJECT_NAME
fi
compose_environment=$(printf 'services:\n  environment:\n    image: scratch\n' |
    env -u COMPOSE_FILE docker compose --env-file .env.prod -f - config --environment)
validate_backup_name \
    "$(printf '%s\n' "$compose_environment" | awk '/^APP_NAME=/ {sub(/^APP_NAME=/, ""); print; found=1} END {if (!found) print "Heptapod"}')" \
    "$(printf '%s\n' "$compose_environment" | awk '/^BACKUP_PATH=/ {sub(/^BACKUP_PATH=/, ""); print; found=1} END {if (!found) print "/backups"}')"
compose_files=$(printf '%s\n' "$compose_environment" | sed -n 's/^COMPOSE_FILE=//p')
mentions=$(grep -cF COMPOSE_FILE .env.prod || [ "$?" = 1 ])
if [ "$mentions" -gt 1 ]; then
    echo 'Ambiguous COMPOSE_FILE in .env.prod; remove duplicate mentions' >&2
    exit 1
fi
if [ -z "$compose_files" ]; then
    echo 'Missing COMPOSE_FILE in .env.prod; rerun ./scripts/install.sh with your original flags (and COMPOSE_FILE exported if you use extra overrides) to persist the compose file list; it changes no other values' >&2
    exit 1
fi
if [ "${COMPOSE_FILE+x}" = x ] && [ "$COMPOSE_FILE" != "$compose_files" ]; then
    echo 'COMPOSE_FILE conflicts with .env.prod; unset it or export the identical file list' >&2
    exit 1
fi
export COMPOSE_FILE="$compose_files"

if [ "$build" = true ]; then
    HEPTAPOD_VERSION_BUILD=$(git describe --tags --always --dirty)
    export HEPTAPOD_VERSION_BUILD
fi
case "${COMPOSE_PROFILES:-}" in
    *'*'*) echo 'Wildcard COMPOSE_PROFILES is not supported; select browser or disabled explicitly.' >&2; exit 1 ;;
esac
# Resolve dotenv through Compose; browser services and dispatch must agree before freezing writers.
resolved=$(dc config --environment)
if [ "$build" = true ]; then
    expected_build_gb=$(printf '%s\n' "$resolved" | sed -n 's/^HEPTAPOD_BUILD_SIZE_GB=//p')
    expected_build_gb=${expected_build_gb:-6}
    case "$expected_build_gb" in
        *[!0-9]*|0*) echo 'HEPTAPOD_BUILD_SIZE_GB must be a positive integer (GiB)' >&2; exit 1 ;;
    esac
    build_cache_keep=$(printf '%s\n' "$resolved" | sed -n 's/^HEPTAPOD_BUILD_CACHE_KEEP=//p')
    build_cache_keep=${build_cache_keep:-5GB}
    required_kb=$(awk -v size="$expected_build_gb" 'BEGIN { printf "%.0f", 2 * size * 1024 * 1024 }')
else
    required_kb=12582912
fi
file_resolved=$(unset HEPTAPOD_BROWSER_ENABLED COMPOSE_PROFILES REVERB_APP_ID REVERB_APP_KEY REVERB_APP_SECRET; dc config --environment)
for key in HEPTAPOD_BROWSER_ENABLED COMPOSE_PROFILES; do
    value=$(printf '%s\n' "$resolved" | sed -n "s/^$key=//p")
    file_value=$(printf '%s\n' "$file_resolved" | sed -n "s/^$key=//p")
    mentions=$(grep -cF "$key" .env.prod || [ "$?" = 1 ])
    if [ "$value" != "$file_value" ] || [ "$mentions" -gt 1 ]; then
        echo "Ambiguous $key in .env.prod or shell environment; remove duplicate mentions/overrides" >&2
        exit 1
    fi
    case "$key" in
        HEPTAPOD_BROWSER_ENABLED) browser=$file_value; browser_mentions=$mentions ;;
        COMPOSE_PROFILES) profiles=$file_value; profile_mentions=$mentions ;;
    esac
done
case "$profiles" in
    *'*'*) echo 'Wildcard COMPOSE_PROFILES is not supported; select browser or disabled explicitly.' >&2; exit 1 ;;
esac
# shellcheck source=scripts/check-disk.sh
. ./scripts/check-disk.sh
if [ "$resume" = false ]; then require_deployment_disk "$required_kb"; fi
if [ "$browser_mentions" = 0 ] && [ "$profile_mentions" = 0 ]; then
    # Legacy installations always ran Lighthouse. Preserve that behavior explicitly.
    umask 077
    chmod 600 .env.prod
    printf '\nHEPTAPOD_BROWSER_ENABLED=true\nCOMPOSE_PROFILES=browser\n' >> .env.prod
    browser=true
else
    valid=false
    case ",$profiles," in
        *,browser,*) [ "$browser" != true ] || valid=true ;;
        *) [ "$browser" != false ] || valid=true ;;
    esac
    if [ "$browser_mentions" != 1 ] || [ "$profile_mentions" != 1 ] || [ "$valid" != true ]; then
        echo 'Inconsistent browser configuration: set HEPTAPOD_BROWSER_ENABLED=true with browser in COMPOSE_PROFILES, or false without browser. Configure both keys in .env.prod.' >&2
        exit 1
    fi
fi
# Validate the old dotenv before changing it; legacy installs have no Reverb credentials.
missing_reverb_keys=
for key in REVERB_APP_ID REVERB_APP_KEY REVERB_APP_SECRET; do
    value=$(printf '%s\n' "$resolved" | sed -n "s/^$key=//p")
    file_value=$(printf '%s\n' "$file_resolved" | sed -n "s/^$key=//p")
    mentions=$(grep -cE "(^|[^[:alnum:]_])$key([^[:alnum:]_]|$)" .env.prod || [ "$?" = 1 ])
    if [ "$value" != "$file_value" ] || [ "$mentions" -gt 1 ]; then
        echo "Ambiguous $key in .env.prod or shell environment; remove duplicate mentions/overrides" >&2
        exit 1
    fi
    if [ -z "$file_value" ]; then
        if [ "$mentions" -ne 0 ]; then
            echo "Compose resolves $key empty but .env.prod mentions it; remove the definition to generate a value" >&2
            exit 1
        fi
        missing_reverb_keys="$missing_reverb_keys $key"
    fi
done
if [ -n "$missing_reverb_keys" ]; then
    command -v openssl >/dev/null
    umask 077
    chmod 600 .env.prod
    for key in $missing_reverb_keys; do
        printf '\n%s=%s\n' "$key" "$(openssl rand -hex 32)" >> .env.prod
    done
fi
# The EXIT handler resumes only while no candidate code has touched persistent data.
upgrade_exit() {
    status=$?
    trap - EXIT
    [ "$status" -ne 0 ] || return 0
    case "$phase" in
        recorded|candidate-ready)
            rm -f "$state/phase"
            echo 'Upgrade aborted before maintenance; old stack untouched.' >&2 ;;
        freezing|frozen|pinned)
            if cp "$previous/.env.prod" .env.prod.recovery && chmod 600 .env.prod.recovery && mv .env.prod.recovery .env.prod && sync .env.prod && sync . &&
                dc start horizon scheduler && dc start dispatcher && write_phase resumed && dc exec -T app php artisan up; then
                rm -f "$state/phase"
                echo 'Upgrade aborted before any data change; old version resumed.' >&2
            else
                echo 'Automatic resume failed; recover the recorded old stack:' >&2
                if [ "$phase" = resumed ]; then
                    print_artisan_up_command "$(cat "$previous/project")"
                else
                    printf "cd '%s' && '%s/scripts/rollback.sh'\n" "$PWD" "$PWD" >&2
                fi
            fi ;;
        preparing|swapping|starting-writers)
            if ! dc stop horizon dispatcher scheduler; then echo 'WARNING: could not stop all writers; inspect the Docker error above.' >&2; fi
            recovery_commands ;;
        releasing)
            recovery_commands ;;
    esac
    exit "$status"
}
trap upgrade_exit EXIT
# shellcheck source=scripts/worker-memory-preflight.sh
. ./scripts/worker-memory-preflight.sh
trap 'exit 130' INT
trap 'exit 143' TERM HUP
validate_image_keys
resume_run() {
    dc run --rm --no-deps -T -e RUN_MIGRATIONS=0 -e "PGAPPNAME=$prepare_container" --name "$prepare_container" --entrypoint sh app "$@"
}
if [ "$resume" = true ]; then
    for recorded in "$state/candidate.env" "$state/backup.env" "$state/prepare-container"; do
        [ -s "$recorded" ] || { echo "Cannot resume: missing verified upgrade record $recorded. Recover the recorded artifacts before retrying." >&2; exit 1; }
    done
    [ ! -f "$state/rollback.env" ] || { echo 'Backup restore already started; finish the recorded restore instead of resuming the candidate.' >&2; exit 1; }
    candidate_app=$(sed -n 's/^APP_IMAGE=//p' "$state/candidate.env")
    candidate_lighthouse=$(sed -n 's/^LIGHTHOUSE_IMAGE=//p' "$state/candidate.env")
    build=$(sed -n 's/^BUILD=//p' "$state/candidate.env")
    build_cache_keep=$(sed -n 's/^BUILD_CACHE_KEEP=//p' "$state/candidate.env")
    for candidate in "$candidate_app" "$candidate_lighthouse"; do
        [ -n "$candidate" ] || continue
        if ! docker image inspect "$candidate" >/dev/null; then
            echo "Cannot resume: recorded candidate $candidate is unavailable. Reacquire it with the first-run disk preflight; no acquisition is attempted during resume." >&2
            printf 'docker image inspect %s\n' "$(shell_quote "$candidate")" >&2
            exit 1
        fi
    done
    [ -n "$candidate_app" ] || { echo 'Cannot resume: no recorded app candidate.' >&2; exit 1; }
    guard_preparation_container "$state" "$PWD/scripts/upgrade.sh" --resume
    pin_release_images
    resume_backup=$(sed -n 's/^BACKUP_PATH=//p' "$state/backup.env")
    resume_checksum=$(sed -n 's/^BACKUP_SHA256=//p' "$state/backup.env")
    if ! runtime_environment=$(resume_run -ec 'printf "HEPTAPOD_STORAGE_DISK=%s\nBACKUP_DISK=%s\nBACKUP_PATH=%s\n" "${HEPTAPOD_STORAGE_DISK:-local}" "${BACKUP_DISK:-${HEPTAPOD_STORAGE_DISK:-backups}}" "${BACKUP_PATH:-/backups}"; checksum=$(sha256sum "$1"); printf "RECORDED_BACKUP_SHA256=%s\n" "${checksum%% *}"' sh "$resume_backup"); then
        echo "Cannot resume: cannot verify recorded lifecycle archive $resume_backup. Recover that exact archive before retrying; no replacement backup is safe after preparation." >&2
        exit 1
    fi
    actual_checksum=$(printf '%s\n' "$runtime_environment" | sed -n 's/^RECORDED_BACKUP_SHA256=//p')
    [ -n "$resume_checksum" ] && [ "$actual_checksum" = "$resume_checksum" ] || { echo "Cannot resume: checksum mismatch for recorded lifecycle archive $resume_backup." >&2; exit 1; }
else
    app_container=$(dc ps -q app)
    [ -n "$app_container" ] || { echo 'Upgrade requires a running app instance; use install.sh for a fresh installation.' >&2; exit 1; }
fi
# D5: new upgrades reserve image/backup space plus two database copies; resume only needs the remaining database headroom.
database_bytes=$(dc exec -T postgres sh -ec 'psql -X -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atc "select pg_database_size(current_database())"')
case "$database_bytes" in ''|*[!0-9]*) echo 'Cannot measure database size; upgrade refused.' >&2; exit 1 ;; esac
if [ "$resume" = true ]; then
    remaining_kb=$(awk -v db="$database_bytes" 'BEGIN { printf "%.0f", 2 * db / 1024 }')
    require_resume_disk "$remaining_kb"
else
    required_kb=$(awk -v base="$required_kb" -v db="$database_bytes" 'BEGIN { printf "%.0f", base + 2 * db / 1024 }')
    require_deployment_disk "$required_kb"
    runtime_environment=$(dc exec -T app sh -ec 'printf "HEPTAPOD_STORAGE_DISK=%s\nBACKUP_DISK=%s\nBACKUP_PATH=%s\n" "${HEPTAPOD_STORAGE_DISK:-local}" "${BACKUP_DISK:-${HEPTAPOD_STORAGE_DISK:-backups}}" "${BACKUP_PATH:-/backups}"')
fi
storage_disk=$(printf '%s\n' "$runtime_environment" | sed -n 's/^HEPTAPOD_STORAGE_DISK=//p')
backup_disk=$(printf '%s\n' "$runtime_environment" | sed -n 's/^BACKUP_DISK=//p')
backup_root=$(printf '%s\n' "$runtime_environment" | sed -n 's/^BACKUP_PATH=//p')
if [ "$storage_disk" != local ]; then
    echo "WARNING: $storage_disk media objects are NOT in the backup. rollback.sh --restore-backup restores the database only; protect remote media separately." >&2
fi
# Diagnostics must not block recovery or print raw engine errors.
capture_diagnostics() {
    if ! bash ./scripts/retain-diagnostics.sh snapshot; then
        echo 'WARNING: retained worker stderr and container exit/OOM diagnostics are incomplete; upgrade continues.' >&2
    fi
}
if [ "$resume" = false ]; then
    guard_preparation_container "$state" "$PWD/scripts/upgrade.sh" --resume
    # release_checkout's export hint names install.sh's --revision, which upgrade.sh does not have.
    if ! command -v git >/dev/null 2>&1 || ! git rev-parse HEAD >/dev/null 2>&1; then
        echo 'Upgrades need a Git checkout of the release. See docs/deploy/operations.md.' >&2
        exit 1
    fi
    release_checkout
    # Replace the last completed generation only while holding the host lock.
    mkdir -p "$previous"
    for component in app lighthouse; do
        if [ -f "$previous/$component-image" ]; then cat "$previous/$component-image" >> "$state/obsolete-images"; fi
    done
    if [ -f "$state/backup.env" ]; then
        cp "$state/backup.env" "$previous/backup.env.tmp"
        persist_lifecycle_file "$previous/backup.env"
    fi
    rm -f "$state/backup.env" "$state/rollback.env"
    app_container=$(dc ps -q app)
    [ -n "$app_container" ] || { echo 'Upgrade requires a running app instance; use install.sh for a fresh installation.' >&2; exit 1; }
    old_app=$(docker inspect --format '{{.Image}}' "$app_container")
    docker tag "$old_app" "$rollback_repository/app:previous"
    project=$(docker inspect --format '{{index .Config.Labels "com.docker.compose.project"}}' "$app_container")
    printf '%s\n' "$project" > "$previous/project"
    # PostgreSQL application_name is limited to 63 bytes; container and session names must stay identical.
    project_hash=$(printf '%s' "$project" | sha256sum)
    project_key=$(printf '%.24s' "$project_hash")
    prepare_stamp=$(date -u +%Y%m%d%H%M%S)
    prepare_container="$lifecycle_prefix-$project_key-$prepare_stamp-prepare"
    printf '%s\n' "$prepare_container" > "$state/prepare-container"
    printf '%s\n' "$old_app" > "$previous/app-image"
    if [ "$browser" = true ]; then
        lighthouse_container=$(dc ps -q lighthouse)
        [ -n "$lighthouse_container" ] || { echo 'Running Lighthouse image missing; cannot record rollback generation.' >&2; exit 1; }
        old_lighthouse=$(docker inspect --format '{{.Image}}' "$lighthouse_container")
        docker tag "$old_lighthouse" "$rollback_repository/lighthouse:previous"
        printf '%s\n' "$old_lighthouse" > "$previous/lighthouse-image"
    fi
    cp .env.prod "$previous/.env.prod"
    printf '%s\n' "$browser" > "$previous/browser"
    printf '%s\n' "$release_sha" > "$previous/checkout-sha"
    dc exec -T postgres sh -ec 'psql -X -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atc "select count(*)||'"'"':'"'"'||coalesce(max(id),0) from migrations"' > "$previous/migrations"
    : > "$previous/compose.files"
    index=0
    old_ifs=$IFS
    IFS=:
    for compose_file in $compose_files; do
        index=$((index + 1))
        cp "$compose_file" "$previous/compose-$index.yaml"
        printf '%s\n' "$previous/compose-$index.yaml" >> "$previous/compose.files"
    done
    IFS=$old_ifs
    # Only recording a new generation may retire an earlier acceptance boundary.
    rm -f "$state/release-intent"
    write_phase recorded
    if [ "$build" = true ]; then
        candidate_app="$local_repository/app:$release_sha"
        candidate_lighthouse=
        [ -z "$release_dirty" ] || candidate_app="$candidate_app-dirty"
        APP_IMAGE=$candidate_app
        export APP_IMAGE
        APT_SNAPSHOT_DATE=$(date -u +%F)
        export APT_SNAPSHOT_DATE
        dc pull postgres redis gotenberg
        if [ "$browser" = true ]; then
            candidate_lighthouse="$local_repository/lighthouse:$release_sha"
            [ -z "$release_dirty" ] || candidate_lighthouse="$candidate_lighthouse-dirty"
            LIGHTHOUSE_IMAGE=$candidate_lighthouse
            export LIGHTHOUSE_IMAGE
            dc build --pull app lighthouse
        else
            dc build --pull app
        fi
        unset APP_IMAGE LIGHTHOUSE_IMAGE
    else
        resolve_release_images "$browser"
        dc pull postgres redis gotenberg
    fi
    # Fully obtained candidates also need retirement if they fail and a later generation succeeds.
    docker image inspect --format '{{.Id}}' "$candidate_app" >> "$state/obsolete-images"
    if [ -n "$candidate_lighthouse" ]; then docker image inspect --format '{{.Id}}' "$candidate_lighthouse" >> "$state/obsolete-images"; fi
    printf 'APP_IMAGE=%s\nLIGHTHOUSE_IMAGE=%s\nBUILD=%s\nBUILD_CACHE_KEEP=%s\nRELEASE_SHA=%s\n' "$candidate_app" "$candidate_lighthouse" "$build" "${build_cache_keep:-5GB}" "$release_sha" > "$state/candidate.env"
    write_phase candidate-ready
    # The candidate's worker budget check under the configured Horizon cap, before maintenance and migrations.
    (APP_IMAGE=$candidate_app; export APP_IMAGE; require_worker_memory) || exit 1
    # Read-only report against the old schema; RUN_MIGRATIONS=0 keeps the entrypoint from migrating first.
    (APP_IMAGE=$candidate_app; export APP_IMAGE; dc run --rm --no-deps -T -e RUN_MIGRATIONS=0 app php artisan heptapod:notifications:migration-preview) > "$state/notification-migration-preview.txt" || preview_status=$?
    cat "$state/notification-migration-preview.txt"
    [ "${preview_status:-0}" = 0 ] || exit 1

    capture_diagnostics
    # Record this before down: even a failed maintenance command needs an explicit resume.
    write_phase freezing
    dc exec -T app php artisan down
    dc stop horizon dispatcher scheduler
    backup_name="pre-upgrade-$release_sha-$(date -u +%Y%m%dT%H%M%SZ).zip"
    if [ "$backup_disk" != backups ] || [ "$backup_root" != /backups ]; then
        echo 'WARNING: lifecycle recovery backup is written locally to /backups/upgrade-lifecycle, not the configured backup disk/path. Scheduled/manual backup policy is unchanged.' >&2
    fi
    # Override only this backup's namespace; boot still validates the ordinary daily disk root.
    dc exec -T -e BACKUP_DISK=backups -e BACKUP_PATH=/backups app sh -ec 'exec php artisan backup:run --no-interaction --disable-notifications --only-to-disk=backups --destination-path="upgrade-lifecycle/${APP_NAME:-Heptapod}" --filename="$1"' sh "$backup_name"
    backup_path=$(dc exec -T app find /backups/upgrade-lifecycle -type f -name "$backup_name")
    [ -n "$backup_path" ] && [ "$(printf '%s\n' "$backup_path" | wc -l | tr -d ' ')" = 1 ] || { echo 'Expected one local backup archive in /backups; cannot record recovery backup.' >&2; exit 1; }
    dc exec -T app sync "$backup_path" "$(dirname -- "$backup_path")" /backups
    backup_checksum_output=$(dc exec -T app sha256sum "$backup_path")
    backup_checksum=$(printf '%s\n' "$backup_checksum_output" | awk '{print $1}')
    printf 'BACKUP_PATH=%s\nBACKUP_SHA256=%s\n' "$backup_path" "$backup_checksum" > "$state/backup.env"
    write_phase frozen
    pin_release_images
    write_phase pinned
else
    if [ -f "$state/rollback.env" ]; then
        echo 'Rollback restore already started; finish that recorded restore instead of resuming the candidate.' >&2
        printf 'cd %s && %s --restore-backup %s\n' "$(shell_quote "$PWD")" "$(shell_quote "$PWD/scripts/rollback.sh")" "$(shell_quote "$(sed -n 's/^BACKUP_PATH=//p' "$state/backup.env")")" >&2
        exit 1
    fi
    resume_run -ec 'export HEPTAPOD_ROLE=web; exec sh /app/docker/prod/entrypoint.sh php artisan down'
    dc stop horizon dispatcher scheduler
fi
# Supervised preparation has no web healthcheck deadline. Mark before starting candidate code.
write_phase preparing
dc run --rm --no-deps -T -e RUN_MIGRATIONS=1 -e "PGAPPNAME=$prepare_container" --name "$prepare_container" app true
write_phase swapping
if [ "$browser" = false ]; then
    dc --profile browser rm -s -f lighthouse lighthouse-proxy
fi
capture_diagnostics
export HEPTAPOD_APP_RUN_MIGRATIONS=0
if [ "$browser" = true ]; then
    dc up -d --remove-orphans --wait --wait-timeout 300 app reverb postgres redis gotenberg lighthouse lighthouse-proxy
else
    dc up -d --remove-orphans --wait --wait-timeout 300 app reverb postgres redis
fi
write_phase starting-writers
dc up -d --wait --wait-timeout 300 horizon scheduler dispatcher
printf 'releasing\n' > "$state/release-intent.tmp"
persist_lifecycle_file "$state/release-intent"
write_phase releasing
dc exec -T app php artisan up
write_phase committed
mv "$state/candidate.env" "$state/current.env"
# Cleanup cannot turn an accepted, healthy upgrade into a failure.
# The host lock stays held: no upgrade or rollback can change the references during expiry.
if ! sh -ec '. ./scripts/lifecycle-lock.sh; . ./scripts/upgrade-backup-retention.sh; expire_upgrade_backups --dry-run; expire_upgrade_backups --apply'; then
    echo 'WARNING: lifecycle archive expiry failed; upgrade succeeded. Inspect the error above, then run scripts/expire-upgrade-backups.sh --dry-run.' >&2
fi
if ! docker image prune -f --filter "label=$component_label"; then
    echo 'WARNING: Heptapod dangling-image pruning failed (Docker error above); upgrade succeeded, but disk space was not fully reclaimed.' >&2
fi
# Pulling a SHA tag also retains that tag, so dangling-image prune alone cannot enforce two generations.
prune_recorded_generations() {
    [ -f "$state/obsolete-images" ] || return 0
    current_app=$(docker inspect --format '{{.Image}}' "$(dc ps -q app)") || return 1
    current_lighthouse=
    if [ "$browser" = true ]; then current_lighthouse=$(docker inspect --format '{{.Image}}' "$(dc ps -q lighthouse)") || return 1; fi
    sort -u "$state/obsolete-images" > "$state/obsolete-images.sorted" || return 1
    : > "$state/obsolete-images.remaining" || return 1
    available_images=$(docker image ls -q --no-trunc) || return 1
    while IFS= read -r obsolete; do
        if [ "$obsolete" = "$current_app" ] || [ "$obsolete" = "$current_lighthouse" ] || grep -qxF "$obsolete" "$previous/app-image" || { [ -f "$previous/lighthouse-image" ] && grep -qxF "$obsolete" "$previous/lighthouse-image"; }; then
            continue
        fi
        # An ID already removed by dangling-image prune has converged.
        if ! printf '%s\n' "$available_images" | grep -qxF "$obsolete"; then continue; fi
        label=$(docker image inspect --format "{{index .Config.Labels \"$component_label\"}}" "$obsolete") || return 1
        labelled=false
        case "$label" in app|lighthouse) labelled=true ;; esac
        container_ids=$(docker ps -aq) || return 1
        referenced_images=
        if [ -n "$container_ids" ]; then
            referenced_images=$(printf '%s\n' "$container_ids" | xargs docker inspect --format '{{.Image}}') || return 1
        fi
        if [ "$labelled" = false ] || printf '%s\n' "$referenced_images" | grep -qxF "$obsolete" || ! docker image rm -f "$obsolete"; then
            printf '%s\n' "$obsolete" >> "$state/obsolete-images.remaining" || return 1
            echo "WARNING: retained obsolete image $obsolete (unlabelled, referenced, or removal failed). After checking container references, remove manually: docker image rm -f '$obsolete'" >&2
        fi
    done < "$state/obsolete-images.sorted"
    mv "$state/obsolete-images.remaining" "$state/obsolete-images" || return 1
    rm "$state/obsolete-images.sorted" || return 1
}
if ! prune_recorded_generations; then
    echo "WARNING: recorded-image pruning failed; IDs remain in $state/obsolete-images. Inspect them, then rerun the scoped removal commands:"
    while IFS= read -r obsolete; do printf "docker image rm -f '%s'\n" "$obsolete"; done < "$state/obsolete-images"
fi
if [ "$build" = true ]; then
    if ! docker builder prune -f --keep-storage "$build_cache_keep"; then
        echo 'WARNING: build-cache pruning failed (Docker error above); upgrade succeeded, but disk space was not fully reclaimed.' >&2
    fi
fi
backup_path=$(sed -n 's/^BACKUP_PATH=//p' "$state/backup.env")
echo "Upgrade committed. Previous images/configuration retained in $previous; backup: $backup_path"
