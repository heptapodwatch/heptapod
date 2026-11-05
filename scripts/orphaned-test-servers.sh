#!/usr/bin/env bash

sweep_orphaned_test_servers() {
    local status process_status pid command_line line result
    local orphaned_servers=()
    for status in /proc/[0-9]*/status; do
        process_status=$(cat "$status" 2>/dev/null) || {
            result=$?
            [[ ! -d ${status%/status} ]] && continue
            printf 'Cannot read active process status %s (exit %s)\n' "$status" "$result" >&2
            return "$result"
        }
        while IFS= read -r line; do
            if [[ $line == PPid:* ]]; then
                [[ ${line#PPid:} =~ ^[[:space:]]*1[[:space:]]*$ ]] || break
                pid=${status#/proc/}
                pid=${pid%/status}
                { command_line=$(tr '\0' ' ' < "/proc/$pid/cmdline"); } 2>/dev/null || {
                    result=$?
                    [[ ! -d /proc/$pid ]] && break
                    printf 'Cannot read active process cmdline /proc/%s/cmdline (exit %s)\n' "$pid" "$result" >&2
                    return "$result"
                }
                case "$command_line" in
                    *'playwright run-server '*|*'php -S 127.0.0.1:'*) orphaned_servers+=("$pid") ;;
                esac
                break
            fi
        done <<< "$process_status"
    done
    if (( ${#orphaned_servers[@]} > 0 )); then
        printf 'WARNING: removing %s orphaned browser test servers (PPID 1): %s\n' "${#orphaned_servers[@]}" "${orphaned_servers[*]}" >&2
        kill -TERM "${orphaned_servers[@]}" 2>/dev/null || true
        sleep 1
        for pid in "${orphaned_servers[@]}"; do
            [[ -e /proc/$pid ]] && kill -KILL "$pid" 2>/dev/null || true
        done
    fi
}
