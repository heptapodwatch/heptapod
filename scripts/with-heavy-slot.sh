#!/usr/bin/env bash
set -euo pipefail
# Hard SIGKILL releases the slot, but cannot stop an already-running host command.
set -m
[ "$#" -gt 0 ] || { echo 'Usage: scripts/with-heavy-slot.sh command [args...]' >&2; exit 2; }
heavy_slots=${HEPTAPOD_HEAVY_SLOTS:-3}
case "$heavy_slots" in
    2|3|4) ;;
    *) echo 'HEPTAPOD_HEAVY_SLOTS must be 2, 3 or 4' >&2; exit 2 ;;
esac
docker volume create heptapod-heavy-locks >/dev/null
workdir=$(mktemp -d)
mkfifo "$workdir/keepalive"
: > "$workdir/holder.log"
holder=
command_pid=
stop_host_command() {
    if [[ -n $command_pid ]]; then
        kill -TERM -- "-$command_pid" 2>/dev/null || true
        sleep 1
        kill -KILL -- "-$command_pid" 2>/dev/null || true
        wait "$command_pid" 2>/dev/null || true
        command_pid=
    fi
}
cleanup() {
    result=$?
    trap - EXIT HUP INT TERM
    stop_host_command
    exec 9>&-
    if [[ -n $holder ]]; then wait "$holder" 2>/dev/null || true; fi
    rm -rf "$workdir"
    exit "$result"
}
exec 9<>"$workdir/keepalive"
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM
(
    exec 9>&-
    docker run -i --rm --init -e HEPTAPOD_HEAVY_SLOTS="$heavy_slots" -v heptapod-heavy-locks:/heavy-locks alpine:3 sh -ec '
        slot=1
        while [ "$slot" -le "$HEPTAPOD_HEAVY_SLOTS" ]; do
            exec 8>"/heavy-locks/heptapod-heavy.$slot.lock"
            if flock -n 8; then break; fi
            exec 8>&-
            slot=$((slot + 1))
        done
        if [ "$slot" -gt "$HEPTAPOD_HEAVY_SLOTS" ]; then
            echo "waiting for heavy slot …"
            exec 8>/heavy-locks/heptapod-heavy.1.lock
            flock 8
            slot=1
        fi
        echo "acquired heavy slot $slot"
        cat >/dev/null
    ' < "$workdir/keepalive" > "$workdir/holder.log" 2>&1
) &
holder=$!
announced=0
until grep -q '^acquired heavy slot ' "$workdir/holder.log"; do
    if [ "$announced" = 0 ] && grep -q '^waiting for heavy slot ' "$workdir/holder.log"; then
        echo 'waiting for heavy slot …' >&2
        announced=1
    fi
    if ! kill -0 "$holder" 2>/dev/null; then
        cat "$workdir/holder.log" >&2
        exit 1
    fi
    sleep 0.2
done
grep '^acquired heavy slot ' "$workdir/holder.log"
"$@" 9>&- &
command_pid=$!
result=0
wait "$command_pid" || result=$?
stop_host_command
exit "$result"
