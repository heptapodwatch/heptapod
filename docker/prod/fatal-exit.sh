#!/bin/sh
set -eu
# Supervisor reserves stdout for READY/RESULT; diagnostics belong on stderr.
printf 'READY\n'
IFS= read -r header
length=
for field in $header; do
    case "$field" in len:*) length=${field#len:} ;; esac
done
case "$length" in ''|*[!0-9]*) echo 'Invalid supervisor event length' >&2; exit 1 ;; esac
dd bs=1 count="$length" status=none > /dev/null
printf 'RESULT 2\nOK'
echo 'Fatal worker startup: draining supervisord for container restart' >&2
: > /tmp/heptapod/fatal
exec supervisorctl -c /app/docker/prod/supervisord.conf shutdown
