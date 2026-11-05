#!/bin/sh
# Docker images and the default named backup volume share the daemon's data filesystem.
# Run on the daemon host: a remote data-root path cannot be measured safely here.
require_deployment_disk() {
    # 12 GiB minimum: 6 GiB for image download/unpacking and 6 GiB for a backup.
    required_kb=${1:-12582912}
    [ "$required_kb" -ge 12582912 ] || required_kb=12582912
    docker_root=$(docker info --format '{{.DockerRootDir}}')
    [ -n "$docker_root" ] || { echo 'Cannot locate Docker data root; deployment refused.' >&2; exit 1; }
    disk_usage=$(df -Pk "$docker_root")
    free_kb=$(printf '%s\n' "$disk_usage" | awk 'END { print $4 }')
    case "$free_kb" in
        ''|*[!0-9]*) echo "Cannot measure free disk space on Docker data root: $docker_root; deployment refused." >&2; exit 1 ;;
    esac
    if ! awk -v free="$free_kb" -v required="$required_kb" 'BEGIN { exit !(free >= required) }'; then
        echo "Insufficient disk space on Docker data root $docker_root: ${free_kb} KiB free; require ${required_kb} KiB (minimum 12 GiB: 6 GiB image pull/unpack + 6 GiB backup headroom; source builds also require 2 x expected build size). Free disk space; deployment refused before service changes." >&2
        exit 1
    fi
}

# Candidate images and the archive already exist during resume; reserve only the remaining DB headroom.
require_resume_disk() {
    remaining_kb=$1
    docker_root=$(docker info --format '{{.DockerRootDir}}')
    [ -n "$docker_root" ] || { echo 'Cannot locate Docker data root; resume refused.' >&2; exit 1; }
    disk_usage=$(df -Pk "$docker_root")
    free_kb=$(printf '%s\n' "$disk_usage" | awk 'END { print $4 }')
    case "$free_kb" in ''|*[!0-9]*) echo 'Cannot measure remaining resume disk space.' >&2; exit 1 ;; esac
    if ! awk -v free="$free_kb" -v required="$remaining_kb" 'BEGIN { exit !(free >= required) }'; then
        echo "Insufficient remaining resume disk space on $docker_root: $free_kb KiB free; require $remaining_kb KiB (2 x current database size; images and verified archive are already present)." >&2
        exit 1
    fi
}
