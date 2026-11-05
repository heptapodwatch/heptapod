#!/usr/bin/env bash
set -euo pipefail

result=unknown
reason='Build metadata has no usable build reference.'
log="$RUNNER_TEMP/build-cache-history.jsonl"

# Read this build's exporter vertex, not the successful build outcome: BuildKit records
# the exporter error before ignore-error=true suppresses it for the build itself.
if ref=$(jq -er '."buildx.build.ref" | strings | select(test("^[^/]+/[^/]+/[^/]+$"))' <<< "$BUILD_METADATA"); then
  builder=${ref%%/*}
  record=${ref##*/}
  if docker buildx --builder "$builder" history logs --progress=rawjson "$record" > "$log" 2>&1; then
    if result=$(jq -ser '
      [.[].vertexes[]? | select(.name | test("^exporting (to GitHub Actions Cache|cache to registry)$"))]
      | group_by(.digest) | map(last)
      | if length == 0 then "unknown"
        elif any(.error != null and .error != "") then "failure"
        elif all(.completed != null) then "success"
        else "unknown" end
    ' "$log"); then
      reason='BuildKit GitHub Actions cache exporter status.'
      if [ "$result" = unknown ]; then
        reason='Build history has no completed GitHub Actions cache exporter vertex.'
      elif [ "$result" = failure ]; then
        jq -r '.vertexes[]? | select((.name | test("^exporting (to GitHub Actions Cache|cache to registry)$")) and .error != null and .error != "") | .error' "$log"
      fi
    else
      result=unknown
      reason='Could not parse the BuildKit cache exporter history; see lookup output in the log.'
      cat "$log"
    fi
  else
    reason='Buildx history lookup failed; see lookup output in the log.'
    cat "$log"
  fi
fi

printf 'Build cache export (non-blocking): %s. %s\n' "$result" "$reason" | tee -a "$GITHUB_STEP_SUMMARY"
if [ "$result" != success ]; then
  echo "::warning title=Build cache not saved::Cache export result: $result. $reason The image build, scan and runtime tests remain gates."
fi
