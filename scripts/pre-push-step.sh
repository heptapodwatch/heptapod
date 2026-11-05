#!/usr/bin/env bash
set -euo pipefail

step=$1
seconds=$2
shift 2

# The reader stays alive while a detached child holds the command's output pipe.
if timeout --kill-after=10s "$seconds" bash -o pipefail -c '"$@" 2>&1 | cat' _ "$@"; then
    exit 0
else
    result=$?
fi

if [[ $result -eq 124 || $result -eq 137 ]]; then
    if (( seconds % 60 == 0 )); then
        limit="$((seconds / 60)) min"
    else
        limit="$seconds s"
    fi
    if [[ $result -eq 124 ]]; then
        printf 'pre-push step %s exceeded %s — orphaned process holding the pipe?\n' "$step" "$limit" >&2
    else
        printf 'pre-push step %s killed (137) — timeout grace or OOM; orphaned process holding the pipe?\n' "$step" >&2
    fi
    printf 'PID PPID PGID COMMAND\n' >&2
    for command_file in /proc/[0-9]*/cmdline; do
        [[ -r $command_file ]] || continue
        pid=${command_file#/proc/}
        pid=${pid%/cmdline}
        [[ -r /proc/$pid/stat ]] || continue
        process_ids=$(awk '{print $4, $5}' "/proc/$pid/stat" 2>/dev/null) || continue
        command_line=$(tr '\0' ' ' < "$command_file" 2>/dev/null) || continue
        printf '%s %s %s %s\n' "$pid" "$process_ids" "$command_line" >&2
    done
fi
exit "$result"
