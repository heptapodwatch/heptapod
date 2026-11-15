#!/usr/bin/env bash
set -euo pipefail

source scripts/orphaned-test-servers.sh
trap sweep_orphaned_test_servers EXIT
trap 'exit 143' TERM

# Without a TTY (agents, hooks, CI) per-file progress lands as one line or walls of thousands of dots; show only results there.
if [ -t 1 ]; then
    collapse_progress() { "$@"; }
else
    # Pint and Peck have no flag for this, and their quiet modes also hide the findings.
    collapse_progress() { "$@" | sed -E 's/(.)\1{49,}/\1\1\1[…]/g'; }
fi

case "${1:-all}" in
    php)
        # CI runs these three groups as parallel jobs; the hook runs them in sequence.
        for group in php-static php-rector php-analyse; do
            bash "$0" "$group"
        done
        ;;
    php-static)
        php scripts/check-translations.php
        php scripts/check-finding-translations.php
        php scripts/check-engine-messages.php
        php scripts/check-mail-tones.php
        php scripts/check-import-notices.php
        php scripts/check-brand-name.php
        php scripts/check-private-info.php --all
        php scripts/check-gate-tests.php
        php scripts/check-clock-ownership.php
        php scripts/check-ui-primitives.php
        php scripts/check-docs.php
        composer normalize:check
        composer validate --strict
        composer deps:check
        collapse_progress composer fmt:check
        collapse_progress composer peck
        ;;
    php-rector)
        composer rector:check
        ;;
    php-analyse)
        if [ -t 1 ]; then
            composer analyse
            composer test:types
        else
            # No progress bar; list only files below 100 % instead of one dotted line per file.
            composer analyse -- --no-progress
            composer test:types -- --compact
        fi
        ;;
    frontend)
        npm run check
        npm run lint
        npm run types:check
        ;;
    generated)
        (
            baseline=$(mktemp)
            trap 'rm -f "$baseline" "$baseline.after"' EXIT
            paths=(AGENTS.md boost.json .agents/skills resources/js/actions resources/js/routes resources/js/wayfinder resources/js/types/generated.d.ts)
            # Compare regeneration with the submitted working tree, not HEAD: an uncommitted generated change can be commit-ready.
            # In clean CI this still requires zero generated changes. --full-index: abbreviated blob hashes can grow between the two
            # runs when the generators add objects, which would show a content-identical diff.
            git diff --binary --full-index HEAD -- "${paths[@]}" > "$baseline"
            php artisan wayfinder:generate --with-form --no-interaction
            composer types:generate
            php artisan boost:update --no-interaction
            php scripts/check-openapi.php
            git diff --binary --full-index HEAD -- "${paths[@]}" > "$baseline.after"
            diff -u "$baseline" "$baseline.after"
            test -z "$(git ls-files --others --exclude-standard AGENTS.md .agents boost.json)"
            test -z "$(git ls-files --others --exclude-standard resources/js/actions resources/js/routes resources/js/wayfinder resources/js/types)"
            npm run build
            node scripts/check-bundle-size.mjs
        )
        ;;
    all)
        for group in php frontend generated; do
            bash "$0" "$group"
        done
        ;;
    *)
        echo 'Usage: scripts/ci-checks.sh [all|php|php-static|php-rector|php-analyse|frontend|generated]' >&2
        exit 2
        ;;
esac
