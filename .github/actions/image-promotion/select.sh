#!/usr/bin/env bash
# Read-only decision: registry preflight is performed before the workflow accepts reuse.
set -euo pipefail
repo=${GITHUB_REPOSITORY:?}
sha=${GITHUB_SHA:?}
destination=${1:?Usage: select.sh artifact-directory}
mkdir -p "$destination"
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
fallback() {
  echo "::warning::FALLBACK $sha: $1; full native build, OPS and security gates required"
  if [[ -n ${GITHUB_STEP_SUMMARY:-} ]]; then
    echo "FALLBACK \`$sha\`: $1; full native build, OPS and security gates required." >> "$GITHUB_STEP_SUMMARY"
  fi
  exit 0
}
configuration=$(bash "$(dirname "$0")/architectures.sh")
required=$(jq -c '.hard_jobs' <<< "$configuration")
architectures=$(jq -c '.architectures' <<< "$configuration")
if ! gh api --paginate --slurp "repos/$repo/actions/workflows/production-images.yml/runs?head_sha=$sha&event=push&per_page=100" > "$tmp/runs.json"; then
  fallback 'image workflow lookup failed'
fi
run=$(jq -c --arg sha "$sha" '[.[].workflow_runs[] | select(.head_sha == $sha and (.head_branch | startswith("ci/")))] | sort_by(.id) | last // empty' "$tmp/runs.json")
[[ -n $run ]] || fallback 'no candidate image workflow run for this exact SHA'
id=$(jq -r '.id' <<< "$run")
attempt=$(jq -r '.run_attempt' <<< "$run")
# Re-fetch: list responses can race a rerun; never select an older green attempt.
if ! gh api "repos/$repo/actions/runs/$id" > "$tmp/run.json"; then
  fallback "cannot read source run $id"
fi
jq -e --arg sha "$sha" --argjson attempt "$attempt" '.head_sha == $sha and .event == "push" and (.head_branch | startswith("ci/")) and .run_attempt == $attempt and .status == "completed" and .conclusion == "success"' "$tmp/run.json" >/dev/null || fallback "source run $id attempt $attempt is not completed success ($(jq -r '.status + "/" + .conclusion' "$tmp/run.json")), or changed during lookup"
if ! gh api --paginate --slurp "repos/$repo/actions/runs/$id/attempts/$attempt/jobs?per_page=100" > "$tmp/jobs.json"; then
  fallback "cannot read source run $id attempt $attempt jobs"
fi
hard_jobs=$(jq -c --argjson required "$required" '[.[].jobs[] | select(.name as $name | $required | index($name)) | {name, conclusion}] | sort_by(.name)' "$tmp/jobs.json")
jq -e --argjson required "$required" 'map(.name) == ($required | sort) and all(.conclusion == "success")' <<< "$hard_jobs" >/dev/null || fallback "source run $id has missing or non-green native image/OPS/security jobs"
if ! gh api --paginate --slurp "repos/$repo/actions/runs/$id/artifacts?per_page=100" > "$tmp/artifacts.json"; then
  fallback "cannot read source run $id artifacts"
fi
# Artifacts belong to their producing attempt, not a later partial rerun. Select
# one complete pair from this run, never an app/lighthouse pair across attempts.
artifact_attempt=$(jq -r --arg sha "$sha" --argjson id "$id" --argjson latest "$attempt" '
  [.[].artifacts[] | select(.expired == false and .workflow_run.id == $id and .workflow_run.head_sha == $sha)
    | .name | capture("^candidate-image-(?<image>app|lighthouse)-attempt-(?<attempt>[0-9]+)$")
    | .attempt |= tonumber | select(.attempt <= $latest)]
  | group_by(.attempt) | map(select((map(.image) | sort) == ["app", "lighthouse"]))
  | map(.[0].attempt) | max // empty' "$tmp/artifacts.json")
[[ -n $artifact_attempt ]] || fallback "source run $id missing a complete run-bound digest artifact pair"
if [[ $artifact_attempt != "$attempt" ]]; then
  if ! gh api --paginate --slurp "repos/$repo/actions/runs/$id/attempts/$artifact_attempt/jobs?per_page=100" > "$tmp/artifact-jobs.json"; then
    fallback "cannot read source run $id artifact attempt $artifact_attempt jobs"
  fi
  # GitHub assigns new job IDs/run_attempt even to carried jobs. Their execution
  # timestamps remain unchanged. Any rerun of a native gate or index publisher
  # invalidates older digests, including a producer rerun in an intermediate attempt.
  producers=$(jq -c --argjson required "$required" '[.[].jobs[] | select((.name as $name | $required | index($name)) or .name == "candidate-manifest (app)" or .name == "candidate-manifest (lighthouse)") | {name, conclusion, started_at, completed_at}] | sort_by(.name)' "$tmp/artifact-jobs.json")
  jq -e --argjson required "$required" 'length == (($required | length) + 2) and all(.conclusion == "success" and .started_at != null and .completed_at != null)' <<< "$producers" >/dev/null || fallback "source run $id artifact attempt has missing or non-green producers"
  for ((later=artifact_attempt+1; later<=attempt; later++)); do
    if [[ $later == "$attempt" ]]; then
      cp "$tmp/jobs.json" "$tmp/later-jobs.json"
    elif ! gh api --paginate --slurp "repos/$repo/actions/runs/$id/attempts/$later/jobs?per_page=100" > "$tmp/later-jobs.json"; then
      fallback "cannot read source run $id attempt $later jobs"
    fi
    jq -e --argjson required "$required" --argjson producers "$producers" '
      [.[].jobs[] | select((.name as $name | $required | index($name)) or .name == "candidate-manifest (app)" or .name == "candidate-manifest (lighthouse)")
        | {name, conclusion, started_at, completed_at}] | sort_by(.name) == $producers' "$tmp/later-jobs.json" >/dev/null || fallback "source run $id attempt $later reran candidate producers; older digests cannot be reused"
  done
fi
for image in app lighthouse; do
  artifact=$(jq -r --arg name "candidate-image-$image-attempt-$artifact_attempt" --arg sha "$sha" --argjson id "$id" '[.[].artifacts[] | select(.name == $name and .expired == false and .workflow_run.id == $id and .workflow_run.head_sha == $sha)] | if length == 1 then .[0].id else empty end' "$tmp/artifacts.json")
  [[ -n $artifact ]] || fallback "source run $id attempt $attempt missing run-bound digest artifact for $image"
  if ! gh api "repos/$repo/actions/artifacts/$artifact/zip" > "$tmp/artifact.zip" || ! unzip -p "$tmp/artifact.zip" "manifest-$image.json" > "$destination/manifest-$image.json"; then
    fallback "source run $id digest artifact for $image cannot be read"
  fi
  jq -e --arg sha "$sha" --arg image "$image" --argjson id "$id" --argjson attempt "$artifact_attempt" --argjson jobs "$hard_jobs" --argjson architectures "$architectures" '
    def digest: type == "string" and test("^sha256:[a-f0-9]{64}$");
    .sha == $sha and .image == $image and .run_id == $id and .run_attempt == $attempt and .hard_jobs == $jobs and
    (.index_digest | digest) and
    ([.platforms[].architecture] | sort) == $architectures and
    all(.platforms[]; (.digest | digest) and (.scanned_config | digest))' "$destination/manifest-$image.json" >/dev/null || fallback "source run $id digest artifact for $image is inconsistent"
done
jq -e '[.[].jobs[] | select(.name == "candidate-manifest (app)" or .name == "candidate-manifest (lighthouse)")] | length == 2 and all(.conclusion == "success")' "$tmp/jobs.json" >/dev/null || fallback "source run $id has missing or non-green index publication jobs"
# A rerun begun during artifact download invalidates the whole decision.
if ! gh api "repos/$repo/actions/runs/$id" > "$tmp/final-run.json"; then
  fallback "cannot recheck source run $id"
fi
jq -e --arg sha "$sha" --argjson attempt "$attempt" '.head_sha == $sha and .run_attempt == $attempt and .status == "completed" and .conclusion == "success"' "$tmp/final-run.json" >/dev/null || fallback "source run $id changed during artifact lookup"
for image in app lighthouse; do
  echo "PROMOTE candidate $sha: source run $id attempt $attempt (artifacts from attempt $artifact_attempt), $image $(jq -r '.index_digest' "$destination/manifest-$image.json") (registry preflight required)"
done
if [[ -n ${GITHUB_OUTPUT:-} ]]; then
  echo "source-run=$id" >> "$GITHUB_OUTPUT"
  echo "source-attempt=$attempt" >> "$GITHUB_OUTPUT"
fi
