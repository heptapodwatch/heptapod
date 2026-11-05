#!/bin/sh
# Shared names are consumed by the scripts that source this file.
# Container shell fragments expand in the container.
# shellcheck disable=SC2034,SC2016
# All host lifecycle entry points use the same kernel-held lock, including after a crash.
lifecycle_prefix=heptapod
lifecycle_dir=.$lifecycle_prefix
component_label=com.$lifecycle_prefix.component
restore_volume_label=com.$lifecycle_prefix.restore-run
rollback_repository=$lifecycle_prefix-rollback
local_repository=$lifecycle_prefix-local
prepare_stop_seconds=1245

# Flysystem resolves separators and dot segments. Reserve case/space variants as well so a later name normalisation cannot expose lifecycle archives to daily cleanup.
normalize_backup_path() {
    printf '%s' "$1" | tr '\\' '/' | awk -F/ '
        { for (i=1; i<=NF; i++) {
            if ($i == "" || $i == ".") continue;
            if ($i == "..") { if (n>0) n--; continue; }
            part=tolower($i); gsub(/[[:space:]_]+/, "-", part); components[++n]=part;
        }}
        END { for (i=1; i<=n; i++) printf "%s%s", (i>1?"/":""), components[i]; }'
}

validate_backup_name() {
    backup_namespace=$(normalize_backup_path "$1")
    if [ -z "$backup_namespace" ]; then
        echo 'APP_NAME must resolve to a non-empty backup name; choose another APP_NAME.' >&2
        return 1
    fi
    if [ "$backup_namespace" = upgrade-lifecycle ]; then
        echo 'APP_NAME resolves to reserved backup name upgrade-lifecycle; choose another APP_NAME.' >&2
        return 1
    fi
    backup_root=$(printf '%s' "${2-/backups}" | tr '\\' '/')
    # Production app processes resolve relative local disks from /app, not from the host checkout.
    case "$backup_root" in /*) ;; *) backup_root="/app/$backup_root" ;; esac
    daily_backup_directory="/$(normalize_backup_path "$backup_root/$backup_namespace")"
    lifecycle_backup_directory=/backups/upgrade-lifecycle
    backup_overlap=false
    case "$daily_backup_directory/" in "$lifecycle_backup_directory/"*) backup_overlap=true ;; esac
    case "$lifecycle_backup_directory/" in "$daily_backup_directory/"*) backup_overlap=true ;; esac
    if [ "$backup_overlap" = true ]; then
        echo 'Daily backup directory overlaps reserved /backups/upgrade-lifecycle; choose another APP_NAME or BACKUP_PATH.' >&2
        return 1
    fi
}

acquire_lifecycle_lock() {
    command -v flock >/dev/null 2>&1 || { echo 'flock (util-linux) is required for install/upgrade/rollback.' >&2; exit 1; }
    umask 077
    mkdir -p "$lifecycle_dir"
    chmod 700 "$lifecycle_dir"
    exec 9>"$lifecycle_dir/lifecycle.lock"
    flock -n 9 || { echo 'Another install/upgrade/rollback is running; lifecycle lock held.' >&2; exit 1; }
}

shell_quote() {
    printf "'%s'" "$(printf '%s' "$1" | sed "s/'/'\\\\''/g")"
}

# Fsync the file before rename, then its directory before a data-changing step.
persist_lifecycle_file() {
    command -v sync >/dev/null 2>&1 || { echo 'sync (coreutils) is required for durable lifecycle state.' >&2; return 1; }
    sync "$1.tmp" || return 1
    mv "$1.tmp" "$1" || return 1
    sync "$(dirname -- "$1")" || return 1
}

print_artisan_up_command() {
    printf 'cd %s && COMPOSE_PROJECT_NAME=%s docker compose --env-file %s exec -T app php artisan up\n' "$(shell_quote "$PWD")" "$(shell_quote "$1")" "$(shell_quote "$PWD/.env.prod")" >&2
}

# A killed host CLI can leave its one-shot container writing. Never race it with recovery.
guard_preparation_container() {
    guard_state=$1
    guard_script=$2
    guard_flag=$3
    [ -f "$guard_state/prepare-container" ] || return 0
    prepare_container=$(cat "$guard_state/prepare-container")
    container_names=$(docker ps -a --format '{{.Names}}')
    prepare_exists=false
    if printf '%s\n' "$container_names" | grep -qxF "$prepare_container"; then
        prepare_exists=true
        prepare_running=$(docker inspect --format '{{.State.Running}}' "$prepare_container")
        if [ "$prepare_running" = true ]; then
            echo "Preparation container $prepare_container is still running. Refusing concurrent prepare/restore. Stop it gracefully, then rerun:" >&2
            printf "docker stop --time %s '%s'\n" "$prepare_stop_seconds" "$prepare_container" >&2
            printf "cd '%s' && '%s' %s\n" "$PWD" "$guard_script" "$guard_flag" >&2
            exit 1
        fi
    fi
    case "$prepare_container" in ''|*[!a-zA-Z0-9_.-]*) echo 'Invalid recorded preparation name.' >&2; exit 1 ;; esac
    # PostgreSQL can finish a statement after its client/container exits, retaining the advisory lock.
    owned_pids=$(dc exec -T postgres sh -ec 'exec psql -X -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d postgres -Atc "$1"' sh "SELECT pid FROM pg_stat_activity WHERE application_name = '$prepare_container' AND pid <> pg_backend_pid()")
    if [ -n "$owned_pids" ]; then
        pids_csv=$(printf '%s\n' "$owned_pids" | paste -sd,)
        printf '%s\n' "$pids_csv" | grep -Eq '^[0-9]+(,[0-9]+)*$' || { echo 'Invalid owned PostgreSQL PID list.' >&2; exit 1; }
        guard_project=$(cat "$guard_state/previous/project")
        guard_files=$(paste -sd: "$guard_state/previous/compose.files")
        terminate_sql="SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE pid IN ($pids_csv) AND application_name = '$prepare_container'"
        echo "Owned PostgreSQL preparation sessions still exist: $pids_csv ($prepare_container). No automatic termination. Explicitly terminate only those sessions, then rerun:" >&2
        printf 'cd %s && COMPOSE_PROJECT_NAME=%s COMPOSE_FILE=%s docker compose --project-directory %s --env-file %s exec -T postgres sh -ec %s sh %s\n' "$(shell_quote "$PWD")" "$(shell_quote "$guard_project")" "$(shell_quote "$guard_files")" "$(shell_quote "$PWD")" "$(shell_quote "$guard_state/previous/.env.prod")" "$(shell_quote 'exec psql -X -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d postgres -c "$1"')" "$(shell_quote "$terminate_sql")" >&2
        printf "cd '%s' && '%s' %s\n" "$PWD" "$guard_script" "$guard_flag" >&2
        exit 1
    fi
    if [ "$prepare_exists" = true ]; then docker rm "$prepare_container"; fi
}
