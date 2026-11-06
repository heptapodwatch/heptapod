#!/bin/sh
# Dry-run is the default. --apply relocates referenced legacy archives and deletes only expired lifecycle archives.
set -eu
release_dir=$(CDPATH='' cd -P -- "$(dirname -- "$0")/.." && pwd)
case "${1:---dry-run}" in
    --help|-h) echo 'Usage: scripts/expire-upgrade-backups.sh [--dry-run|--apply]'; exit 0 ;;
    --dry-run|--apply) ;;
    *) echo 'Usage: scripts/expire-upgrade-backups.sh [--dry-run|--apply]' >&2; exit 1 ;;
esac
[ "$#" -le 1 ] || { echo 'Too many arguments' >&2; exit 1; }
# shellcheck source=scripts/lifecycle-lock.sh
. "$release_dir/scripts/lifecycle-lock.sh"
enter_instance_root
acquire_lifecycle_lock
# shellcheck source=scripts/upgrade-backup-retention.sh
. "$release_dir/scripts/upgrade-backup-retention.sh"
expire_upgrade_backups "${1:---dry-run}"
