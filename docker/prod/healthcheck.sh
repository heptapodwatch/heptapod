#!/bin/sh
set -eu
case "${HEPTAPOD_ROLE:-web}" in
    web) exec curl -fsS http://localhost:8080/up ;;
    worker) exec pgrep -f '^php artisan horizon$' ;;
    dispatcher) exec pgrep -f '^php artisan heptapod:dispatch$' ;;
    scheduler) exec pgrep -f '^php artisan schedule:work$' ;;
    reverb) exec php -r 'exit((int) !@fsockopen("127.0.0.1", 8080));' ;;
    all-in-one-worker)
        states=$(supervisorctl -c /app/docker/prod/supervisord.conf status workers:horizon workers:dispatcher workers:scheduler) || exit 1
        printf '%s\n' "$states" | awk '$2 != "RUNNING" { failed = 1 } END { exit (failed || NR != 3) }'
        ;;
    *) echo "Unsupported healthcheck role: $HEPTAPOD_ROLE" >&2; exit 1 ;;
esac
