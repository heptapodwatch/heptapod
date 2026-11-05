#!/bin/sh
# Called only while the host lifecycle lock is held. The app sees archive paths, never host rollback state.
# shellcheck disable=SC2016
expire_upgrade_backups() (
    set -eu
    retention_mode=${1:---dry-run}
    case "$retention_mode" in --dry-run|--apply) ;; *) echo 'Expected --dry-run or --apply' >&2; exit 1 ;; esac
    command -v jq >/dev/null 2>&1 || { echo 'jq is required for lifecycle archive expiry.' >&2; exit 1; }
    retention_state="$PWD/$lifecycle_dir/upgrade"
    [ -s "$retention_state/phase" ] || { echo 'No completed upgrade state; expiry refused.' >&2; exit 1; }
    case "$(cat "$retention_state/phase")" in
        committed|resumed|rolled-back) ;;
        *) echo 'Unfinished upgrade; lifecycle archive expiry refused.' >&2; exit 1 ;;
    esac
    [ -s "$retention_state/backup.env" ] || { echo 'Missing current backup.env; expiry refused.' >&2; exit 1; }
    read_reference() {
        if [ ! -f "$1" ]; then printf '\n\n'; return; fi
        [ "$(grep -c '^BACKUP_PATH=' "$1")" = 1 ] && [ "$(grep -c '^BACKUP_SHA256=' "$1")" = 1 ] || {
            echo "Invalid archive reference: $1" >&2; exit 1;
        }
        sed -n 's/^BACKUP_PATH=//p; s/^BACKUP_SHA256=//p' "$1"
    }
    # Read keys independently: their order in backup.env is not part of the contract.
    load_references() {
        read_reference "$retention_state/backup.env" >/dev/null
        read_reference "$retention_state/previous/backup.env" >/dev/null
        retention_current=$(sed -n 's/^BACKUP_PATH=//p' "$retention_state/backup.env")
        retention_current_sha=$(sed -n 's/^BACKUP_SHA256=//p' "$retention_state/backup.env")
        retention_previous= retention_previous_sha=
        if [ -f "$retention_state/previous/backup.env" ]; then
            retention_previous=$(sed -n 's/^BACKUP_PATH=//p' "$retention_state/previous/backup.env")
            retention_previous_sha=$(sed -n 's/^BACKUP_SHA256=//p' "$retention_state/previous/backup.env")
        fi
        [ -n "$retention_current" ] && [ -n "$retention_current_sha" ] || { echo 'Empty current archive reference; expiry refused.' >&2; exit 1; }
        if [ -f "$retention_state/previous/backup.env" ]; then
            [ -n "$retention_previous" ] && [ -n "$retention_previous_sha" ] || { echo 'Empty previous archive reference; expiry refused.' >&2; exit 1; }
        fi
    }
    retention_php() {
        docker compose --env-file .env.prod exec -T app php -- "$@" < scripts/upgrade-backup-retention.php
    }
    load_references
    retention_plan=$(mktemp "$retention_state/expiry-plan.XXXXXX")
    trap 'rm -f "$retention_plan" "$retention_plan.moves"' EXIT HUP INT TERM
    retention_php plan "$retention_current" "$retention_current_sha" "$retention_previous" "$retention_previous_sha" > "$retention_plan"
    jq -r '.migrations[] | "MOVE \(.old) -> \(.new) — protect from daily backup:clean"' "$retention_plan"
    jq -r '.archives[] | "\(.action) \(.path) — \(.reason)"' "$retention_plan"
    [ "$retention_mode" = --apply ] || exit 0
    # Stage and verify every copy before rewriting either generation. Delete originals only after both records are durable.
    retention_php stage "$retention_current" "$retention_current_sha" "$retention_previous" "$retention_previous_sha"
    jq -c '.migrations[]' "$retention_plan" > "$retention_plan.moves"
    while IFS= read -r retention_move; do
        retention_new=$(printf '%s\n' "$retention_move" | jq -r .new)
        retention_generation=$(printf '%s\n' "$retention_move" | jq -r .generation)
        docker compose --env-file .env.prod exec -T app sync "$retention_new" "$(dirname "$retention_new")" /backups/upgrade-lifecycle /backups < /dev/null
        retention_record="$retention_state/backup.env"
        [ "$retention_generation" = current ] || retention_record="$retention_state/previous/backup.env"
        cp "$retention_record" "$retention_record.bak.tmp"
        persist_lifecycle_file "$retention_record.bak"
        awk -v replacement="$retention_new" '/^BACKUP_PATH=/ {$0="BACKUP_PATH=" replacement} {print}' "$retention_record" > "$retention_record.tmp"
        persist_lifecycle_file "$retention_record"
    done < "$retention_plan.moves"
    load_references
    retention_php plan "$retention_current" "$retention_current_sha" "$retention_previous" "$retention_previous_sha" > "$retention_plan"
    jq -e '.migrations | length == 0' "$retention_plan" > /dev/null
    # Multiple generations may reference the same archive; retire it once.
    jq -sc 'unique_by(.old)[]' "$retention_plan.moves" > "$retention_plan"
    while IFS= read -r retention_move; do
        retention_php retire "$(printf '%s\n' "$retention_move" | jq -r .old)" "$(printf '%s\n' "$retention_move" | jq -r .checksum)" "$(printf '%s\n' "$retention_move" | jq -r .new)"
    done < "$retention_plan"
    load_references
    retention_php apply "$retention_current" "$retention_current_sha" "$retention_previous" "$retention_previous_sha"
)
