#!/usr/bin/env bash
set -euo pipefail
busy=false
# Concurrency groups and gates also leave runs pending, waiting or requested.
for status in queued in_progress pending waiting requested; do
    count=$(gh api --paginate --slurp "repos/$GITHUB_REPOSITORY/actions/workflows/production-images.yml/runs?status=$status&per_page=100" \
        | jq '[.[].workflow_runs[]] | length')
    if [ "$count" -gt 0 ]; then busy=true; fi
done
echo "busy=$busy" >> "$GITHUB_OUTPUT"
if [ "$busy" = true ]; then
    echo '::notice::Skipping retention dry run: production-images.yml is queued or in progress.'
fi
