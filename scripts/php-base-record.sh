#!/usr/bin/env bash
# Fetch and validate one immutable Actions publication record. No registry access.
set -euo pipefail
if [ "$#" -ne 2 ] || [[ ! "$1" =~ ^[0-9]+$ ]]; then
    echo 'Usage: php-base-record.sh <run-id> <output-directory>' >&2
    exit 2
fi
command -v unzip > /dev/null || { echo 'php-base-record.sh needs unzip (Actions artifacts are zip files)' >&2; exit 1; }
run_id=$1
output=$2
root=$(cd -- "$(dirname -- "$0")/.." && pwd)
# Callers (the bump script, the reuse job) set GITHUB_REPOSITORY; there is no hard-coded fallback.
repository=${GITHUB_REPOSITORY:?GITHUB_REPOSITORY must be set}
mkdir -p "$output"
gh api "repos/$repository/actions/runs/$run_id" > "$output/run.json"
jq -e --argjson id "$run_id" '
    .id == $id and .status == "completed" and .conclusion == "success" and
    .path == ".github/workflows/php-base.yml" and
    (.head_branch == "main" or (.head_branch | startswith("ci/")))
    or error("Expected a completed successful php-base run on main or ci/**")' "$output/run.json" > /dev/null
# There must be exactly one live record, including across reruns.
gh api --paginate --slurp "repos/$repository/actions/runs/$run_id/artifacts?per_page=100" > "$output/artifacts.json"
artifact=$(jq -er '[.[].artifacts[] | select(.name == "php-base-record" and .expired == false)] |
    if length == 1 then .[0].id else error("expected exactly one live php-base-record artifact") end' "$output/artifacts.json")
gh api "repos/$repository/actions/artifacts/$artifact/zip" > "$output/record.zip"
test "$(unzip -Z1 "$output/record.zip")" = php-base-record.json
unzip -p "$output/record.zip" php-base-record.json > "$output/php-base-record.json"
record="$output/php-base-record.json"
jq -e -f "$root/scripts/php-base-record.jq" "$record" > /dev/null
jq -e --slurpfile run "$output/run.json" '
    .run.id == $run[0].id and .run.attempt == $run[0].run_attempt and
    .run.sha == $run[0].head_sha and .run.event == $run[0].event and
    .run.branch == $run[0].head_branch
    or error("Record does not match the selected run/SHA/attempt/event/branch")' "$record" > /dev/null
build_id=$(jq -er '.build.id' "$record")
build_attempt=$(jq -er '.build.attempt' "$record")
gh api "repos/$repository/actions/runs/$build_id" > "$output/build-run.json"
jq -e --slurpfile record "$record" '
    .id == $record[0].build.id and .run_attempt == $record[0].build.attempt and
    .head_sha == $record[0].build.sha and .event == $record[0].build.event and
    .head_branch == $record[0].build.branch and .path == ".github/workflows/php-base.yml" and
    .status == "completed" and .conclusion == "success"
    or error("Native build is not the current successful php-base run/attempt")' "$output/build-run.json" > /dev/null
gh api --paginate --slurp "repos/$repository/actions/runs/$build_id/attempts/$build_attempt/jobs?per_page=100" > "$output/jobs.json"
for target in runtime coverage; do
    for arch in amd64 arm64; do
        jq -e --arg name "native ($target, $arch)" '
            [.[].jobs[] | select(.name == $name)] |
            length == 1 and all(.[]; .conclusion == "success" and
                ([.steps[] | select(.name == "PHP base vulnerability HARD gate")] |
                    length == 1 and all(.[]; .conclusion == "success")) and
                ([.steps[] | select(.name == "Push scanned bytes and verify config identity")] |
                    length == 1 and all(.[]; .conclusion == "success")))
            or error("Missing or non-green native HARD gate/push: " + $name)' "$output/jobs.json" > /dev/null
    done
done
printf 'Verified php-base record: run %s, native build %s attempt %s\n' "$run_id" "$build_id" "$build_attempt" >&2
