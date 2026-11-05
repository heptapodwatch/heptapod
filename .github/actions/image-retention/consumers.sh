#!/usr/bin/env bash
set -euo pipefail
gh api "repos/$GITHUB_REPOSITORY/actions/cache/usage" > "$RUNNER_TEMP/cache-usage.json"
gh api --paginate --slurp "repos/$GITHUB_REPOSITORY/actions/artifacts?per_page=100" > "$RUNNER_TEMP/artifacts.json"
cache=$(jq -er '.active_caches_size_in_bytes' "$RUNNER_TEMP/cache-usage.json")
# Expired artifacts are still listed but no longer use storage.
artifacts=$(jq '[.[].artifacts[] | select(.expired | not) | .size_in_bytes] | add // 0' "$RUNNER_TEMP/artifacts.json")
printf '\nOther shared allowance consumers: Actions cache %s bytes (est., API-reported); artifacts %s bytes (est., API-reported).\n' \
    "$cache" "$artifacts" >> "$GITHUB_STEP_SUMMARY"
