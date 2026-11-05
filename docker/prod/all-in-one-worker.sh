#!/bin/sh
set -eu
marker=/tmp/heptapod/fatal
rm -f "$marker"
supervisord -c /app/docker/prod/supervisord.conf &
supervisor_pid=$!
# A signal can race with normal child exit; there is nothing to forward then.
interrupted=0
trap 'interrupted=1; kill -TERM "$supervisor_pid" 2>/dev/null || true' TERM INT
while true; do
    interrupted=0
    if wait "$supervisor_pid"; then
        status=0
        break
    else
        status=$?
        # Repeat an interrupted wait even if the child has already exited.
        [ "$interrupted" = 1 ] || break
    fi
done
[ ! -f "$marker" ] || exit 70
exit "$status"
