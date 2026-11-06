#!/bin/sh
# Container shell/PHP fragments expand their variables in the container, not on the host.
# shellcheck disable=SC2016
# Restore the recorded generation, never run migration down() methods.
set -eu
# Rerun by the canonical path first: through current, a concurrent swap could mix this body with another release's helpers.
script=$(readlink -f -- "$0")
[ "$0" = "$script" ] || exec sh "$script" "$@"
release_dir=${script%/scripts/*}
# shellcheck source=scripts/lifecycle-lock.sh
. "$release_dir/scripts/lifecycle-lock.sh"
enter_instance_root
case "${1:-}" in
    --help|-h)
        cat <<'HELP'
Usage: ./scripts/rollback.sh [--restore-backup [recorded-archive-path]]

Without flags, restore the recorded app images/configuration only when the
migration fingerprint is unchanged. --restore-backup also restores the recorded
database and local storage. A supplied archive path must match this run; bare
--restore-backup prints the archive it will restore. Neither mode is allowed
after release acceptance intent. Later data recovery is a manual operator decision.

Snapshots contain the pre-pin TARGET Compose model, not a reconstruction of the
running release's old YAML. App/data rollback assumes backward-compatible Compose
changes. Postgres major upgrades and incompatible service, role or volume changes
require a separately planned backup and explicit procedure; rollback does not cover them.

Requires Docker Compose v5, flock and Linux coreutils sync; backup restore also
requires jq. No migration down() methods or persistent-volume removal are used.
HELP
        exit 0 ;;
esac
acquire_lifecycle_lock
restore=false
bound_backup=
case "${1:-}" in '') ;; --restore-backup) restore=true; bound_backup=${2:-} ;; *) echo 'Usage: ./scripts/rollback.sh [--restore-backup [recorded-archive-path]]' >&2; exit 1 ;; esac
if [ "$#" -gt 2 ] || { [ "$restore" = false ] && [ "$#" -ne 0 ]; }; then echo 'Too many arguments' >&2; exit 1; fi
if [ "$#" -eq 2 ] && [ -z "$bound_backup" ]; then echo 'The recorded archive path must not be empty.' >&2; exit 1; fi
state="$PWD/$lifecycle_dir/upgrade"
previous="$state/previous"
[ -f "$state/phase" ] && [ -f "$previous/compose.files" ] || { echo 'No recorded upgrade to roll back.' >&2; exit 1; }
phase=$(cat "$state/phase")
accepted=false
case "$phase" in committed|resumed|rolled-back) accepted=true ;; esac
if [ -f "$state/release-intent" ] || [ "$accepted" = true ]; then
    echo "Refusing rollback after release acceptance intent ($phase). Restoring the pre-upgrade backup would lose every write accepted since release. That is a manual operator decision outside this tool." >&2
    if [ -f "$state/backup.env" ]; then echo "Archive for that manual decision: $(sed -n 's/^BACKUP_PATH=//p' "$state/backup.env")" >&2; fi
    echo 'To finish releasing the healthy recorded stack instead:' >&2
    print_artisan_up_command "$(cat "$previous/project")"
    exit 1
fi
guard_flag=
if [ "$restore" = true ]; then
    [ -f "$state/backup.env" ] || { echo 'No recorded backup; cannot restore.' >&2; exit 1; }
    backup_path=$(sed -n 's/^BACKUP_PATH=//p' "$state/backup.env")
    backup_checksum=$(sed -n 's/^BACKUP_SHA256=//p' "$state/backup.env")
    if [ -n "$bound_backup" ] && [ "$bound_backup" != "$backup_path" ]; then
        echo "Archive does not match the recorded upgrade: $bound_backup. No services/data changed. Current run recovery:" >&2
        printf 'cd %s && %s --restore-backup %s\n' "$(shell_quote "$PWD")" "$(shell_quote "$PWD/scripts/rollback.sh")" "$(shell_quote "$backup_path")" >&2
        exit 1
    fi
    if [ -z "$backup_path" ] || ! printf '%s\n' "$backup_checksum" | grep -Eq '^[a-f0-9]{64}$'; then echo 'Invalid recorded backup path/checksum; restore refused.' >&2; exit 1; fi
    echo "Restoring recorded backup: $backup_path (SHA256 $backup_checksum)"
    guard_flag="--restore-backup $(shell_quote "$backup_path")"
fi
# Use the recorded model, with its original project directory for relative paths.
COMPOSE_FILE=$(paste -sd: "$previous/compose.files")
COMPOSE_PROJECT_NAME=$(cat "$previous/project")
export COMPOSE_FILE COMPOSE_PROJECT_NAME
unset APP_IMAGE LIGHTHOUSE_IMAGE COMPOSE_PROFILES HEPTAPOD_BROWSER_ENABLED
APP_IMAGE=$(cat "$previous/app-image")
export APP_IMAGE
browser=$(cat "$previous/browser")
if [ "$browser" = true ]; then LIGHTHOUSE_IMAGE=$(cat "$previous/lighthouse-image"); export LIGHTHOUSE_IMAGE; fi
export HEPTAPOD_APP_RUN_MIGRATIONS=0
dc() { docker compose --project-directory "$PWD" --env-file "$previous/.env.prod" "$@"; }
psql_db() { dc exec -T postgres sh -ec 'db=$1; shift; exec psql -X -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$db" "$@"' sh "$@"; }
step=validation
roles_stopped=false
extract_volume_validated=false
dump_file="$state/restore.sql"
rollback_exit() {
    status=$?
    trap - EXIT
    [ "$status" -ne 0 ] || return 0
    if [ -f "$dump_file" ]; then
        if rm -f -- "$dump_file"; then
            echo "Removed decrypted SQL dump after failed restore: $dump_file" >&2
        else
            echo "WARNING: decrypted SQL dump remains on the checkout filesystem: $dump_file" >&2
            printf 'rm -f -- %s\n' "$(shell_quote "$dump_file")" >&2
        fi
    fi
    if [ "$phase" = rolled-back ]; then
        echo 'Rollback restoration reached its durable release boundary. No further data restore is permitted; healthy roles remain running. Finish forward:' >&2
        print_artisan_up_command "$COMPOSE_PROJECT_NAME"
        if [ "$extract_volume_validated" = true ]; then
            echo "WARNING: temporary volume $extract_volume retains decrypted data. After release and ownership checks, remove it with: docker volume rm '$extract_volume'" >&2
        fi
        exit "$status"
    fi
    if [ "$roles_stopped" = true ]; then
        if ! dc stop app reverb horizon scheduler dispatcher; then echo 'WARNING: could not fence every app role; inspect the Docker error above.' >&2; fi
    fi
    echo "Rollback failed at $step." >&2
    if [ "$roles_stopped" = true ]; then echo 'Application roles are stopped; HTTP is unavailable until recovery completes.' >&2; fi
    if [ "$restore" = true ]; then
        echo "Restore state: $state/rollback.env; backup: ${backup_path:-not yet read}" >&2
        if [ "$extract_volume_validated" = true ]; then
            echo "WARNING: temporary restore volume $extract_volume may hold decrypted data. Keep it for retry, or explicitly remove it after abandoning recovery:" >&2
            printf "docker volume rm '%s'\n" "$extract_volume" >&2
        fi
        printf 'cd %s && %s --restore-backup %s\n' "$(shell_quote "$PWD")" "$(shell_quote "$PWD/scripts/rollback.sh")" "$(shell_quote "$backup_path")" >&2
    else
        echo 'After fixing the reported cause, retry image-only recovery:' >&2
        printf 'cd %s && %s\n' "$(shell_quote "$PWD")" "$(shell_quote "$PWD/scripts/rollback.sh")" >&2
    fi
    exit "$status"
}
trap rollback_exit EXIT
trap 'exit 130' INT
trap 'exit 143' TERM HUP
db=$(dc exec -T postgres printenv POSTGRES_DB)
case "$db" in ''|*[!a-zA-Z0-9_]*) echo 'Rollback requires an alphanumeric/underscore database name.' >&2; exit 1 ;; esac
fingerprint() { psql_db "$1" -Atc "select count(*)||':'||coalesce(max(id),0) from migrations"; }
expected=$(cat "$previous/migrations")
if [ "$restore" = false ]; then
    guard_preparation_container "$state" "$PWD/scripts/rollback.sh" "$guard_flag"
    if [ -f "$state/rollback.env" ]; then
        echo 'Backup restore already started; finish the recorded restore:' >&2
        printf 'cd %s && %s --restore-backup %s\n' "$(shell_quote "$PWD")" "$(shell_quote "$PWD/scripts/rollback.sh")" "$(shell_quote "$(sed -n 's/^BACKUP_PATH=//p' "$state/backup.env")")" >&2
        exit 1
    fi
    actual=$(fingerprint "$db")
    if [ "$actual" != "$expected" ]; then
        echo "Migration fingerprint changed ($expected -> $actual); image-only rollback refused. Restore the pre-upgrade backup:" >&2
        if [ -f "$state/backup.env" ]; then
            printf 'cd %s && %s --restore-backup %s\n' "$(shell_quote "$PWD")" "$(shell_quote "$PWD/scripts/rollback.sh")" "$(shell_quote "$(sed -n 's/^BACKUP_PATH=//p' "$state/backup.env")")" >&2
        else
            echo 'No backup was recorded in this run; manual recovery is required.' >&2
        fi
        exit 1
    fi
fi
freeze_roles() {
    # A running old web container can set Redis maintenance even when the candidate cannot boot.
    app_container=$(dc ps -q app)
    if [ -n "$app_container" ]; then
        dc exec -T app php artisan down
    else
        dc run --rm --no-deps -T -e RUN_MIGRATIONS=0 app php artisan down
    fi
    step=stop-roles
    dc stop app reverb horizon scheduler dispatcher
    roles_stopped=true
}
if [ "$restore" = true ]; then
    # Discover actual volume/network names; custom Compose names need not match the project name.
    command -v jq >/dev/null || { echo 'jq is required for backup restore.' >&2; exit 1; }
    model=$(dc config --format json)
    backup_volume=$(printf '%s\n' "$model" | jq -er '.volumes.backups.name')
    storage_volume=$(printf '%s\n' "$model" | jq -er '.volumes.storage.name')
    backend=$(printf '%s\n' "$model" | jq -er '.networks.backend.name')
    archive_password=$(printf '%s\n' "$model" | jq -er '.services.app.environment.BACKUP_ARCHIVE_PASSWORD')
    step=verify-backup
    checksum_output=$(docker run --rm --network none --read-only --entrypoint sha256sum -v "$backup_volume:/backups:ro" "$APP_IMAGE" "$backup_path")
    actual_checksum=$(printf '%s\n' "$checksum_output" | awk '{print $1}')
    [ "$actual_checksum" = "$backup_checksum" ] || { echo "Backup checksum mismatch: $backup_path; no services/data changed." >&2; exit 1; }
    guard_preparation_container "$state" "$PWD/scripts/rollback.sh" "$guard_flag"
    old_run() { docker run --rm --network "$backend" -e BACKUP_ARCHIVE_PASSWORD="$archive_password" -e RUN_MIGRATIONS=0 --user "${restore_user:-33:33}" --entrypoint sh -v "$backup_volume:/backups" -v "$extract_volume:/restore" -v "$storage_volume:/app/storage" "$APP_IMAGE" "$@"; }
    extracted_this_run=false
    extract_archive() {
        step=extract-backup
        restore_user=0 old_run -c 'rm -rf /restore/archive; chown 33:33 /restore'
        old_run -c 'php scripts/restore-backup.php "$1" /restore/archive' sh "$backup_path"
        extracted_this_run=true
    }
    if [ -f "$state/rollback.env" ]; then
        stamp=$(sed -n 's/^STAMP=//p' "$state/rollback.env")
        restore_db=$(sed -n 's/^RESTORE_DB=//p' "$state/rollback.env")
        failed_db=$(sed -n 's/^FAILED_DB=//p' "$state/rollback.env")
        restore_step=$(sed -n 's/^STEP=//p' "$state/rollback.env")
        extract_volume=$(sed -n 's/^EXTRACT_VOLUME=//p' "$state/rollback.env")
    else
        stamp=$(date -u +%Y%m%d%H%M%S)
        # PostgreSQL identifiers are limited to 63 bytes. Preserve the unique suffix.
        db_prefix=$(printf '%.30s' "$db")
        restore_db="${db_prefix}_restore_$stamp"
        failed_db="${db_prefix}_failed_$stamp"
        extract_volume="$lifecycle_prefix-$COMPOSE_PROJECT_NAME-restore-$stamp"
        docker volume create --label "$restore_volume_label=$stamp" "$extract_volume"
        restore_step=extracting
    fi
    validate_extract_volume() {
        extract_volume_validated=false
        [ "${#stamp}" = 14 ] && [ "$extract_volume" = "$lifecycle_prefix-$COMPOSE_PROJECT_NAME-restore-$stamp" ] || { echo "Refusing temporary-volume access/removal: invalid run name $extract_volume" >&2; return 1; }
        case "$stamp" in *[!0-9]*) echo 'Invalid restore timestamp.' >&2; return 1 ;; esac
        persistent_volumes=$(printf '%s\n' "$model" | jq -r '.volumes[] | .name') || return 1
        if printf '%s\n' "$persistent_volumes" | grep -qxF "$extract_volume"; then
            echo "Refusing temporary-volume access/removal: $extract_volume is a declared persistent volume." >&2
            return 1
        fi
        volume_run=$(docker volume inspect --format "{{index .Labels \"$restore_volume_label\"}}" "$extract_volume") || return 1
        [ "$volume_run" = "$stamp" ] || { echo "Refusing temporary-volume access/removal: $extract_volume does not belong to this restore run." >&2; return 1; }
        extract_volume_validated=true
    }
    validate_extract_volume
    save_restore_step() {
        restore_step=$1
        printf 'STAMP=%s\nRESTORE_DB=%s\nFAILED_DB=%s\nEXTRACT_VOLUME=%s\nSTEP=%s\n' "$stamp" "$restore_db" "$failed_db" "$extract_volume" "$restore_step" > "$state/rollback.env.tmp"
        persist_lifecycle_file "$state/rollback.env"
    }
    save_restore_step "$restore_step"
    # Include the archive's uncompressed size, not its compressed byte count, in the restore gate.
    archive_bytes=$(old_run -c 'php -r '\''$z=new ZipArchive; if ($z->open($argv[1]) !== true) { throw new RuntimeException("Cannot inspect backup"); } $n=0; for($i=0;$i<$z->numFiles;$i++) { $s=$z->statIndex($i); if($s===false) { throw new RuntimeException("Cannot stat entry"); } $n += $s["size"]; } echo $n;'\'' "$1"' sh "$backup_path")
    step=disk-preflight
    # Once the old DB was renamed, measure it instead of the temporarily absent live name.
    size_db=$db
    case "$restore_step" in rename-old|rename-restored|files|starting)
        if [ "$(psql_db postgres -Atc "select count(*) from pg_database where datname='$failed_db'")" = 1 ]; then size_db=$failed_db; fi ;;
    esac
    database_bytes=$(psql_db postgres -Atc "select pg_database_size('$size_db')")
    required_kb=$(awk -v db="$database_bytes" -v archive="$archive_bytes" 'BEGIN { printf "%.0f", int((2 * db + archive + 1023) / 1024) + 1048576 }')
    docker_root=$(docker info --format '{{.DockerRootDir}}')
    [ -n "$docker_root" ] || { echo 'Cannot locate Docker data root; restore refused.' >&2; exit 1; }
    disk_usage=$(df -Pk "$docker_root")
    free_kb=$(printf '%s\n' "$disk_usage" | awk 'END { print $4 }')
    case "$free_kb" in ''|*[!0-9]*) echo 'Cannot measure restore disk space.' >&2; exit 1 ;; esac
    if ! awk -v free="$free_kb" -v required="$required_kb" 'BEGIN { exit !(free >= required) }'; then
        echo "Insufficient restore disk space on $docker_root: $free_kb KiB free; require $required_kb KiB (2 x database + uncompressed archive + 1 GiB)." >&2
        exit 1
    fi
    freeze_roles
    case "$restore_step" in
        extracting|importing)
            # Only the temporary volume owned by this restore is cleared on retry.
            extract_archive
            save_restore_step importing
            step=host-dump-preflight
            dump_bytes=$(old_run -ec 'set -- /restore/archive/db-dumps/*.sql; [ "$#" = 1 ] && [ -f "$1" ]; stat -c %s "$1"')
            host_usage=$(df -Pk "$state")
            host_free_kb=$(printf '%s\n' "$host_usage" | awk 'END { print $4 }')
            case "$dump_bytes:$host_free_kb" in *[!0-9:]*|:*|*:) echo 'Cannot measure checkout space for the decrypted SQL dump.' >&2; exit 1 ;; esac
            if ! awk -v free="$host_free_kb" -v bytes="$dump_bytes" 'BEGIN { exit !(free * 1024 >= bytes) }'; then
                echo "Insufficient checkout filesystem space for decrypted SQL dump: $host_free_kb KiB free; require $dump_bytes bytes at $state." >&2
                exit 1
            fi
            step=import-database
            psql_db postgres -c "DROP DATABASE IF EXISTS \"$restore_db\" WITH (FORCE)"
            psql_db postgres -c "CREATE DATABASE \"$restore_db\""
            # Capture first, so extractor errors cannot be hidden by a successful psql pipeline.
            echo "WARNING: writing decrypted SQL dump on the checkout filesystem: $dump_file. If interrupted, remove it with:" >&2
            printf 'rm -f -- %s\n' "$(shell_quote "$dump_file")" >&2
            old_run -ec 'set -- /restore/archive/db-dumps/*.sql; [ "$#" = 1 ] && [ -f "$1" ]; cat "$1"' > "$dump_file"
            psql_db "$restore_db" --single-transaction < "$dump_file"
            rm "$dump_file"
            [ "$(fingerprint "$restore_db")" = "$expected" ] || { echo 'Restored migration fingerprint differs from the recorded old database.' >&2; exit 1; }
            save_restore_step rename-old ;;
    esac
    if [ "$restore_step" = rename-old ]; then
        step=rename-old-database
        # The intent and both names are durable before ALTER DATABASE. Inspect on retry.
        save_restore_step rename-old
        if [ "$(psql_db postgres -Atc "select count(*) from pg_database where datname='$failed_db'")" = 0 ]; then
            psql_db postgres -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname='$db' AND pid <> pg_backend_pid()"
            psql_db postgres -c "ALTER DATABASE \"$db\" RENAME TO \"$failed_db\""
        fi
        save_restore_step rename-restored
    fi
    if [ "$restore_step" = rename-restored ]; then
        step=rename-restored-database
        save_restore_step rename-restored
        if [ "$(psql_db postgres -Atc "select count(*) from pg_database where datname='$restore_db'")" = 1 ]; then
            psql_db postgres -c "ALTER DATABASE \"$restore_db\" RENAME TO \"$db\""
        fi
        [ "$(fingerprint "$db")" = "$expected" ] || { echo 'Switched database fingerprint mismatch.' >&2; exit 1; }
        save_restore_step files
    fi
    if [ "$restore_step" = files ]; then
        # Re-extract on a post-rename retry: a host crash may have lost unflushed extraction files.
        if [ "$extracted_this_run" = false ]; then extract_archive; fi
        step='restore-files'
        storage_disk=$(printf '%s\n' "$model" | jq -r '.services.app.environment.HEPTAPOD_STORAGE_DISK // "local"')
        if [ "$storage_disk" = local ]; then
            restore_user=0 old_run -c '
                set -eu
                stamp=$1
                if [ ! -d "/app/storage/app.failed-$stamp" ]; then
                    rm -rf "/app/storage/app.restore-$stamp"
                    mkdir "/app/storage/app.restore-$stamp"
                    if [ -d /restore/archive/storage/app ]; then cp -a /restore/archive/storage/app/. "/app/storage/app.restore-$stamp/"; fi
                    chown -R 33:33 "/app/storage/app.restore-$stamp"
                    find "/app/storage/app.restore-$stamp" -type f -exec sync {} +
                    find "/app/storage/app.restore-$stamp" -type d -exec sync {} +
                    mv /app/storage/app "/app/storage/app.failed-$stamp"
                fi
                if [ -d "/app/storage/app.restore-$stamp" ]; then mv "/app/storage/app.restore-$stamp" /app/storage/app; fi
                test -d /app/storage/app
                sync /app/storage
            ' sh "$stamp"
        else
            echo "WARNING: $storage_disk media objects were not restored; database only."
        fi
        save_restore_step starting
    fi
    echo "Failed database retained: $failed_db. After diagnosis, remove it with:"
    printf "cd '%s' && COMPOSE_PROJECT_NAME='%s' docker compose --env-file '%s/.env.prod' exec -T postgres dropdb -U '%s' '%s'\n" "$PWD" "$COMPOSE_PROJECT_NAME" "$PWD" "$(dc exec -T postgres printenv POSTGRES_USER)" "$failed_db"
    echo "Restore extraction temporary volume: $extract_volume; local failed files retained: /app/storage/app.failed-$stamp"
else
    freeze_roles
fi
step=start-previous-generation
cp "$previous/.env.prod" .env.prod.recovery
chmod 600 .env.prod.recovery
mv .env.prod.recovery .env.prod
sync .env.prod
sync .
if [ "$browser" = true ]; then
    dc up -d --remove-orphans --wait --wait-timeout 300 --pull never app reverb postgres redis gotenberg lighthouse lighthouse-proxy
else
    dc up -d --remove-orphans --wait --wait-timeout 300 --pull never app reverb postgres redis
fi
dc up -d --wait --wait-timeout 300 --pull never horizon scheduler dispatcher
# Terminal acceptance intent precedes release: no interruption may make a later restore re-import data.
printf 'rolled-back\n' > "$state/phase.tmp"
persist_lifecycle_file "$state/phase"
phase=rolled-back
dc exec -T app php artisan up
if [ "$restore" = true ]; then
    if validate_extract_volume && docker volume rm "$extract_volume"; then
        echo "Removed this run's temporary extraction volume: $extract_volume"
    else
        echo "WARNING: restore extraction volume retained with decrypted data. After checking ownership, remove it with: docker volume rm '$extract_volume'" >&2
    fi
fi
echo "Rollback complete; old image $(cat "$previous/app-image") healthy, maintenance disabled."
