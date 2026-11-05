#!/usr/bin/env bash
# Update both base pins from one green Actions record; the host needs no package scope.
set -euo pipefail
if [ "$#" -gt 1 ] || { [ "$#" -eq 1 ] && [[ ! "$1" =~ ^[0-9]+$ ]]; }; then
    echo 'Usage: php-base-bump.sh [<run-id>]' >&2
    exit 2
fi
root=$(cd -- "$(dirname -- "$0")/.." && pwd)
# On the host, the repository comes from the checkout's own origin remote (no network, no hard-coded name).
repository=${GITHUB_REPOSITORY:-$(git -C "$root" remote get-url origin | sed -E 's#^(https://github\.com/|git@github\.com:)##; s#\.git$##')}
[[ "$repository" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] || { echo "Cannot determine the GitHub repository: '$repository'" >&2; exit 1; }
export GITHUB_REPOSITORY=$repository
work=$(mktemp -d)
replacement=
trap 'rm -rf "$work"; if [ -n "$replacement" ]; then rm -f "$replacement"; fi' EXIT
run_id=${1:-}
if [ -z "$run_id" ]; then
    gh api --paginate --slurp "repos/$repository/actions/workflows/php-base.yml/runs?branch=main&event=schedule&status=success&per_page=100" > "$work/runs.json"
    run_id=$(jq -er '[.[].workflow_runs[] | select(.head_branch == "main" and .event == "schedule" and .conclusion == "success")] |
        sort_by(.id) | last | .id // error("no successful scheduled php-base run on main")' "$work/runs.json")
fi
bash "$root/scripts/php-base-record.sh" "$run_id" "$work/record"
jq -e '.head_branch == "main" and (.event == "schedule" or .event == "workflow_dispatch")
    or error("Pin bumps require a scheduled or workflow_dispatch php-base run on main")' "$work/record/run.json" > /dev/null
pin="$root/docker/php-base/pin"
# Validate both keys before writing anything, including malformed/duplicate lines.
for target in runtime coverage; do
    count=$(awk -F= -v key="$target" '$1 == key { count++ } END { print count+0 }' "$pin")
    if [ "$count" -ne 1 ]; then
        echo "Expected exactly one $target= line in $pin; found $count" >&2
        exit 1
    fi
    grep -Eq "^$target=ghcr\.io/heptapodwatch/heptapod-php-base@sha256:[0-9a-f]{64}$" "$pin"
done
runtime=$(jq -er '.reference + "@" + (.indexes[] | select(.target == "runtime").index_digest)' "$work/record/php-base-record.json")
coverage=$(jq -er '.reference + "@" + (.indexes[] | select(.target == "coverage").index_digest)' "$work/record/php-base-record.json")
replacement=$(mktemp "$pin.XXXXXX")
awk -v runtime="$runtime" -v coverage="$coverage" '
    /^runtime=/ { print "runtime=" runtime; next }
    /^coverage=/ { print "coverage=" coverage; next }
    { print }
' "$pin" > "$replacement"
chmod --reference="$pin" "$replacement"
for target in runtime coverage; do
    printf '%s old: %s\n' "$target" "$(sed -n "s/^$target=//p" "$pin")"
    printf '%s new: %s\n' "$target" "$(sed -n "s/^$target=//p" "$replacement")"
done
mv "$replacement" "$pin"
replacement=
