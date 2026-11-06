#!/usr/bin/env bash
# Main promotes the candidate's exact scanned indexes. Without any ci/ candidate run for its exact SHA,
# it reports reuse=false so the workflow builds through the native gates; an existing candidate must validate.
set -euo pipefail
: "${GITHUB_SHA:?}" "${GITHUB_REPOSITORY:?}" "${GITHUB_RUN_ID:?}" "${GITHUB_RUN_ATTEMPT:?}" "${RUNNER_TEMP:?}"
: "${GITHUB_EVENT_NAME:?}" "${GITHUB_REF:?}" "${GITHUB_OUTPUT:?}" "${GITHUB_STEP_SUMMARY:?}"
if [ "$GITHUB_EVENT_NAME" != push ] || [ "$GITHUB_REF" != refs/heads/main ]; then
    echo 'PHP base reuse is only valid for a push to main' >&2
    exit 1
fi
root=$(cd -- "$(dirname -- "$0")/.." && pwd)
work="$RUNNER_TEMP/php-base-reuse"
mkdir -p "$work"
gh api --paginate --slurp "repos/$GITHUB_REPOSITORY/actions/workflows/php-base.yml/runs?head_sha=$GITHUB_SHA&event=push&per_page=100" > "$work/runs.json"
# Do not silently choose an older green attempt/run over a newer red candidate.
run_id=$(jq -r --arg sha "$GITHUB_SHA" '[.[].workflow_runs[] |
    select(.head_sha == $sha and .event == "push" and (.head_branch | startswith("ci/")))] |
    sort_by(.id) | last | .id // empty' "$work/runs.json")
if [ -z "$run_id" ]; then
    echo "::warning::FALLBACK: no php-base ci/ candidate run for $GITHUB_SHA; building through the native gates"
    echo "FALLBACK: no php-base ci/ candidate run for $GITHUB_SHA; building through the native gates." >> "$GITHUB_STEP_SUMMARY"
    echo 'reuse=false' >> "$GITHUB_OUTPUT"
    exit 0
fi
bash "$root/scripts/php-base-record.sh" "$run_id" "$work/source"
record="$work/source/php-base-record.json"
jq -e --arg sha "$GITHUB_SHA" '
    .run == .build and .run.sha == $sha and .run.event == "push" and
    (.run.branch | startswith("ci/")) and
    all(.indexes[]; .target as $target |
        any(.tags[]; .tag == ("ci-" + $sha + (if $target == "coverage" then "-coverage" else "" end))))' "$record" > /dev/null
bash "$root/scripts/php-base-verify-index.sh" "$record"
# Recheck run state after registry validation, before changing either moving tag.
gh api "repos/$GITHUB_REPOSITORY/actions/runs/$run_id" > "$work/rechecked-run.json"
jq -e --slurpfile record "$record" '
    .status == "completed" and .conclusion == "success" and
    .run_attempt == $record[0].run.attempt and .head_sha == $record[0].run.sha' "$work/rechecked-run.json" > /dev/null
reference=$(jq -er '.reference' "$record")
for target in runtime coverage; do
    suffix=
    if [ "$target" = coverage ]; then suffix=-coverage; fi
    digest=$(jq -er --arg target "$target" '.indexes[] | select(.target == $target).index_digest' "$record")
    docker buildx imagetools create --tag "$reference:latest-main$suffix" "$reference@$digest"
    observed=$(docker buildx imagetools inspect "$reference:latest-main$suffix" --format '{{json .Manifest}}' | jq -er '.digest')
    test "$observed" = "$digest"
    printf 'REUSE: candidate run %s, %s@%s (latest-main%s)\n' "$run_id" "$reference" "$digest" "$suffix" >> "$GITHUB_STEP_SUMMARY"
done
jq --argjson id "$GITHUB_RUN_ID" --argjson attempt "$GITHUB_RUN_ATTEMPT" '
    .run = {id: $id, attempt: $attempt, sha: .run.sha, event: "push", branch: "main"} |
    .indexes |= map(.tags += [{tag: ("latest-main" + (if .target == "coverage" then "-coverage" else "" end)), digest: .index_digest}])
' "$record" > "$RUNNER_TEMP/php-base-record.json"
echo 'reuse=true' >> "$GITHUB_OUTPUT"
