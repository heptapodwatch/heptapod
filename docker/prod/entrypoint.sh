#!/bin/sh
set -eu
worker_ini_scan_dir="${PHP_INI_SCAN_DIR:-}:/app/docker/prod/worker-ini"
export HEPTAPOD_WORKER_PHP_INI_SCAN_DIR="$worker_ini_scan_dir"
# Artisan writes its package/service manifests below /tmp/heptapod; create it before the first Artisan call.
mkdir -p "$LARAVEL_STORAGE_PATH/framework/cache" "$LARAVEL_STORAGE_PATH/framework/sessions" "$LARAVEL_STORAGE_PATH/framework/views" "$LARAVEL_STORAGE_PATH/logs"
case "${HEPTAPOD_ROLE:-web}" in
    worker|scheduler) export PHP_INI_SCAN_DIR="$worker_ini_scan_dir" ;;
esac
case "${HEPTAPOD_ROLE:-web}" in
    web) ;;
    worker)
        # Fail before Horizon starts when the container cap is below the worker topology's memory budget.
        php artisan heptapod:worker-memory
        set -- php artisan horizon ;;
    dispatcher) set -- php artisan heptapod:dispatch ;;
    scheduler) set -- php artisan schedule:work ;;
    reverb) set -- php artisan reverb:start --host=0.0.0.0 --port=8080 ;;
    all-in-one-worker)
        # Supervisord, the dispatcher and the scheduler share this container; they count against the budget.
        php artisan heptapod:worker-memory --extra-processes=3
        set -- sh /app/docker/prod/all-in-one-worker.sh ;;
    *) echo "Unknown HEPTAPOD_ROLE: $HEPTAPOD_ROLE" >&2; exit 1 ;;
esac
if [ "${RUN_MIGRATIONS:-0}" = 1 ]; then
    php /app/docker/prod/migrate.php
    # Upgrade step: relabel stored findings under the current link policy before the app is healthy, so no worker evaluates legacy rows.
    php artisan heptapod:reclassify-links --policy=1 --no-interaction
    php artisan heptapod:notifications:attention --no-interaction
fi
exec docker-php-entrypoint "$@"
