#!/usr/bin/env bash
# One entry point per production-image acceptance suite, shared by the inline build job and the
# parallel image-acceptance jobs, so both paths run exactly the same commands on heptapod-app:ci.
set -euo pipefail
case "${1:-}" in
    config)
        COMPOSE_PROJECT_NAME=ops-env-ci sh tests/Deployment/install-env.sh
        sh tests/Deployment/upgrade-mode.sh
        ;;
    ops)
        npm ci --ignore-scripts
        npx playwright install --with-deps --only-shell chromium
        APP_IMAGE=heptapod-app:ci OPS_PORT=10680 sh tests/Deployment/ops.sh
        ;;
    rollback)
        APP_IMAGE=heptapod-app:ci sh tests/Deployment/rollback.sh
        ;;
    *)
        echo 'Usage: tests/Deployment/acceptance.sh <config|ops|rollback>' >&2
        exit 2
        ;;
esac
