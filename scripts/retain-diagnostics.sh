#!/usr/bin/env bash
# Host-only collector: Docker access stays on the host; no raw output reaches disk.
set -euo pipefail
release_dir=$(CDPATH='' cd -P -- "$(dirname -- "$0")/.." && pwd)
# shellcheck source=scripts/lifecycle-lock.sh
. "$release_dir/scripts/lifecycle-lock.sh"
enter_instance_root
root=$PWD
mode=${1:-snapshot}
case "$mode" in snapshot|watch) ;; *) echo 'Usage: scripts/retain-diagnostics.sh [snapshot|watch]' >&2; exit 2 ;; esac
if ! command -v jq >/dev/null; then
    echo 'WARNING: optional host jq missing; worker stderr and container exit/OOM diagnostics are NOT captured; host pressure is NOT captured.' >&2
    exit 1
fi

dc() { docker compose --env-file .env.prod "$@"; }
# Resolve only the project name. Never persist Compose's environment/model.
project=$(dc config --format json 2>/dev/null | jq -er '.name | select(test("^[a-z0-9][a-z0-9_-]*$"))' 2>/dev/null) || {
    echo 'WARNING: cannot resolve diagnostics project; worker stderr and exit/OOM diagnostics are NOT captured.' >&2
    exit 1
}
dir=${HEPTAPOD_DIAGNOSTICS_DIR:-${XDG_STATE_HOME:-$HOME/.local/state}/heptapod/diagnostics/$project}
case "$dir" in /*) ;; *) echo 'Diagnostics directory must be absolute' >&2; exit 1 ;; esac
case "$dir/" in "$root/"*) echo 'Diagnostics directory must be outside the checkout' >&2; exit 1 ;; esac
umask 077
mkdir -p "$dir"
dir=$(CDPATH= cd -- "$dir" && pwd -P)
case "$dir" in /|"$HOME") echo 'Diagnostics require a dedicated host directory' >&2; exit 1 ;; esac
case "$dir/" in "$root/"*) echo 'Diagnostics directory must be outside the checkout' >&2; exit 1 ;; esac
chmod 700 "$dir"
if [ "$mode" = watch ]; then
    # Bash completes the foreground capture before handling these signals.
    trap 'exit 130' INT
    trap 'exit 143' TERM
    while true; do
        # Through the instance root: after an upgrade the watcher captures with the current release.
        "$root/scripts/retain-diagnostics.sh" snapshot || echo 'WARNING: diagnostics capture failed; this window may be incomplete.' >&2
        sleep 5
    done
fi
# A short capture owns the cursor and rotations. Concurrent upgrade/watch waits;
# a SIGKILL leaves a lock that operators must remove after checking no capture runs.
tries=0
until mkdir "$dir/.capture-lock" 2>/dev/null; do
    tries=$((tries + 1))
    if [ "$tries" -ge 10 ]; then
        echo 'WARNING: diagnostics capture lock unavailable; this window is NOT captured.' >&2
        exit 1
    fi
    sleep 1
done
trap 'rmdir "$dir/.capture-lock"' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
find "$dir" -type d ! -path "$dir" -prune -o -type f -name 'diagnostics.jsonl*' -mtime +13 -exec rm -f {} +
# Ten files, each <= 128 MiB: 1.25 GiB total; 14-day file-age expiry.
# The measured production-role workload and headroom are in operations.md.
append_records() {
    while IFS= read -r record; do
        bytes=$(printf '%s\n' "$record" | wc -c | tr -d ' ')
        [ "$bytes" -le 134217728 ] || { echo 'Diagnostics record exceeds file limit' >&2; return 1; }
        current=0
        if [ -f "$dir/diagnostics.jsonl" ]; then current=$(wc -c < "$dir/diagnostics.jsonl" | tr -d ' '); fi
        if [ "$((current + bytes))" -gt 134217728 ]; then
            rm -f "$dir/diagnostics.jsonl.9"
            n=8
            while [ "$n" -ge 1 ]; do
                if [ -f "$dir/diagnostics.jsonl.$n" ]; then mv "$dir/diagnostics.jsonl.$n" "$dir/diagnostics.jsonl.$((n + 1))"; fi
                n=$((n - 1))
            done
            mv "$dir/diagnostics.jsonl" "$dir/diagnostics.jsonl.1"
        fi
        printf '%s\n' "$record" >> "$dir/diagnostics.jsonl"
    done
}
# Five cache files hold only safe state. Changes (including peak/unknown
# transitions) and hourly refreshes survive without per-window heartbeat churn.
mkdir -p "$dir/state"
append_state() {
    while IFS= read -r record; do
        changed=true
        cache="$dir/state/$service.json"
        if [ -f "$cache" ]; then
            changed=$(jq -nr --argjson current "$record" --slurpfile previous "$cache" '
                ($previous[0]) as $old |
                (($current | del(.timestamp)) != ($old | del(.timestamp))) or
                ((($current.timestamp | fromdateiso8601) - ($old.timestamp | fromdateiso8601)) >= 3600)
            ' 2>/dev/null)
        fi
        if [ "$changed" = true ]; then
            printf '%s\n' "$record" | append_records
            printf '%s\n' "$record" > "$dir/.capture-lock/state.json"
            mv "$dir/.capture-lock/state.json" "$cache"
        fi
    done
}
# Bound the host CLI probe without a platform-specific timeout dependency.
probe_peak() {
    docker exec "$1" sh -c 'if [ ! -e /sys/fs/cgroup/memory.peak ]; then printf unsupported; elif [ ! -r /sys/fs/cgroup/memory.peak ]; then exit 2; else cat /sys/fs/cgroup/memory.peak; fi' 2>/dev/null &
    probe=$!
    # A completed probe can race timer cancellation; absence is expected here.
    (sleep 3; kill -TERM "$probe" 2>/dev/null || exit 0; sleep 1; kill -KILL "$probe" 2>/dev/null || true) >/dev/null 2>&1 &
    timer=$!
    result=0
    wait "$probe" || result=$?
    kill "$timer" 2>/dev/null || true
    wait "$timer" 2>/dev/null || true
    return "$result"
}
at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
if [ -f "$dir/cursor" ]; then
    since=$(cat "$dir/cursor")
else
    since=$(jq -nr 'now - 86400 | floor | todateiso8601')
fi
printf '%s\n' "$since" | grep -Eq '^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$' || { echo 'Invalid diagnostics cursor' >&2; exit 1; }
filter="$release_dir/scripts/diagnostics-filter.jq"
# No platform fingerprinting or guessed fallback: hosts without /proc report unknown.
load1=source_unavailable load5=source_unavailable load15=source_unavailable
if [ -e /proc/loadavg ]; then
    if [ ! -r /proc/loadavg ] || ! read -r load1 load5 load15 rest < /proc/loadavg; then
        load1=probe_failed load5=probe_failed load15=probe_failed
    fi
fi
memoryTotal=source_unavailable memoryAvailable=source_unavailable swapTotal=source_unavailable swapFree=source_unavailable
if [ -e /proc/meminfo ]; then
    if [ -r /proc/meminfo ] && memory=$(awk '
        $1 == "MemTotal:" {total=$3 == "kB" ? $2 : "invalid_counter"}
        $1 == "MemAvailable:" {available=$3 == "kB" ? $2 : "invalid_counter"}
        $1 == "SwapTotal:" {swap_total=$3 == "kB" ? $2 : "invalid_counter"}
        $1 == "SwapFree:" {swap_free=$3 == "kB" ? $2 : "invalid_counter"}
        END {printf "%s %s %s %s\n", total == "" ? "field_unavailable" : total, available == "" ? "field_unavailable" : available, swap_total == "" ? "field_unavailable" : swap_total, swap_free == "" ? "field_unavailable" : swap_free}
    ' /proc/meminfo 2>/dev/null); then
        read -r memoryTotal memoryAvailable swapTotal swapFree <<< "$memory"
    else
        memoryTotal=probe_failed memoryAvailable=probe_failed swapTotal=probe_failed swapFree=probe_failed
    fi
fi
jq -nc --arg mode host --arg at "$at" --arg since "$since" --arg id '' --arg service '' \
    --arg load1 "$load1" --arg load5 "$load5" --arg load15 "$load15" \
    --arg memoryTotal "$memoryTotal" --arg memoryAvailable "$memoryAvailable" --arg swapTotal "$swapTotal" --arg swapFree "$swapFree" \
    -f "$filter" 2>/dev/null | append_records
# Only application roles; no database, proxy, access logs, notifications or payloads.
ids=$(docker ps -aq --no-trunc --filter "label=com.docker.compose.project=$project" 2>/dev/null) || { echo 'Cannot list diagnostics containers' >&2; exit 1; }
for id in $ids; do
    service=$(docker inspect --format '{{index .Config.Labels "com.docker.compose.service"}}' "$id" 2>/dev/null) || { echo 'Cannot inspect diagnostics container' >&2; exit 1; }
    case "$service" in app|horizon|dispatcher|scheduler|reverb) ;; *) continue ;; esac
    peak=stopped
    running=$(docker inspect --format '{{.State.Running}}' "$id" 2>/dev/null) || { echo 'Cannot inspect diagnostics container state' >&2; exit 1; }
    if [ "$running" = true ]; then
        if ! peak=$(probe_peak "$id"); then peak=probe_failed; fi
    fi
    # Go templates select metadata before JSON filtering; never emit Config.Env.
    docker inspect --format '{"id":{{json .Id}},"service":{{json (index .Config.Labels "com.docker.compose.service")}},"image":{{json .Image}},"state":{{json .State}},"restarts":{{json .RestartCount}},"memory":{{json .HostConfig.Memory}}}' "$id" 2>/dev/null |
        jq -Rnc --arg mode state --arg at "$at" --arg id "$id" --arg service "$service" --arg since "$since" --arg peak "$peak" -f "$filter" 2>/dev/null | append_state
    # Keep stream identity: stdout contributes fixed engine/lifecycle facts and
    # Horizon's proven relayed exception descriptors, never general output.
    # Neither raw stream is stored.
    for stream in stderr stdout; do
        if ! {
            if [ "$stream" = stderr ]; then
                docker logs --tail 1000 --timestamps --since "$since" --until "$at" "$id" 2>&1 1>/dev/null
            else
                docker logs --tail 1000 --timestamps --since "$since" --until "$at" "$id" 2>/dev/null
            fi
        } | jq -Rnc --arg mode "$stream" --arg at "$at" --arg id "$id" --arg service "$service" --arg since "$since" -f "$filter" 2>/dev/null | append_records; then
            echo 'WARNING: worker diagnostic stream capture failed; diagnostics window is incomplete.' >&2
            exit 1
        fi
    done
done
if ! docker events --since "$since" --until "$at" --filter type=container --filter "label=com.docker.compose.project=$project" --format '{{json .}}' 2>/dev/null |
    jq -Rnc --arg mode events --arg at "$at" --arg id '' --arg service '' --arg since "$since" -f "$filter" 2>/dev/null | append_records; then
    echo 'WARNING: container exit/OOM event capture failed; diagnostics window is incomplete.' >&2
    exit 1
fi
printf '%s\n' "$at" > "$dir/.capture-lock/cursor"
mv "$dir/.capture-lock/cursor" "$dir/cursor"
# No containers/events is a valid empty window.
find "$dir" -type d ! -path "$dir" -prune -o -type f -name 'diagnostics.jsonl*' -exec chmod 600 {} +
chmod 600 "$dir/cursor"
