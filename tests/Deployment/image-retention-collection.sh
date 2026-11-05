#!/usr/bin/env bash
# Execute the production collection entry points with real gh argument checks,
# real jq, and synthetic API/registry responses. No external network is needed.
set -euo pipefail
root=$(cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$root"
export REAL_GH REAL_GIT
REAL_GH=$(command -v gh)
REAL_GIT=$(command -v git)
"$REAL_GH" --version
printf '%s\n' 'gh validation mode: GH_HOST=offline.invalid:invalid-port; only the exact invalid-port URL parse error is accepted.'
export RETENTION_FIXTURE="$root/tests/Deployment/fixtures/image-retention-collection"
export RETENTION_TEST_TEMP
RETENTION_TEST_TEMP=$(mktemp -d)
trap 'rm -rf "$RETENTION_TEST_TEMP"' EXIT
mkdir -p "$RETENTION_TEST_TEMP/gh-config" "$RETENTION_TEST_TEMP/runner"
(
    unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE GIT_COMMON_DIR
    export GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null
    export GIT_AUTHOR_DATE=2026-10-21T00:00:00Z GIT_COMMITTER_DATE=2026-10-21T00:00:00Z
    "$REAL_GIT" init --quiet "$RETENTION_TEST_TEMP/git"
    export GIT_DIR="$RETENTION_TEST_TEMP/git/.git" GIT_WORK_TREE="$RETENTION_TEST_TEMP/git"
    for message in first second; do
        "$REAL_GIT" -c user.name=Fixture -c user.email=fixture@example.test commit --quiet --allow-empty -m "$message"
    done
    "$REAL_GIT" update-ref refs/remotes/origin/main HEAD
)
export PATH="$RETENTION_FIXTURE/bin:$PATH"
export GITHUB_REPOSITORY=fixture/retention GITHUB_ACTOR=fixture GITHUB_TOKEN=synthetic-collection-token
export GH_TOKEN=synthetic-must-not-reach-gh GITHUB_ENTERPRISE_TOKEN=synthetic-must-not-reach-gh
export GITHUB_OUTPUT="$RETENTION_TEST_TEMP/outputs"
export RUNNER_TEMP="$RETENTION_TEST_TEMP/runner" GITHUB_STEP_SUMMARY="$RETENTION_TEST_TEMP/summary"
export RETENTION_REGISTRY_URL=fixture://registry RETENTION_TOKEN_URL=fixture://registry/token
export RETENTION_BUSY_STATUS=
bash .github/actions/image-retention/skip-check.sh
printf 'busy=false\n' > "$RETENTION_TEST_TEMP/expected-output"
cmp "$RETENTION_TEST_TEMP/expected-output" "$GITHUB_OUTPUT"
echo 'PASS: idle production workflow permits retention'
for status in queued in_progress pending waiting requested; do
    export RETENTION_BUSY_STATUS=$status
    : > "$GITHUB_OUTPUT"
    bash .github/actions/image-retention/skip-check.sh
    printf 'busy=true\n' > "$RETENTION_TEST_TEMP/expected-output"
    cmp "$RETENTION_TEST_TEMP/expected-output" "$GITHUB_OUTPUT"
    echo "PASS: $status production workflow blocks retention, including a run on page two"
done
bash .github/actions/image-retention/consumers.sh
printf '\nOther shared allowance consumers: Actions cache 512 bytes (est., API-reported); artifacts 579 bytes (est., API-reported).\n' \
    > "$RETENTION_TEST_TEMP/expected-summary"
cmp "$RETENTION_TEST_TEMP/expected-summary" "$GITHUB_STEP_SUMMARY"
echo 'PASS: cache and both artifact pages are counted, excluding the expired artifact'
for owner_type in Organization User; do
    export RETENTION_TEST_OWNER_TYPE=$owner_type
    case "$owner_type" in
        Organization) export RETENTION_TEST_OWNER_ENDPOINT=orgs ;;
        User) export RETENTION_TEST_OWNER_ENDPOINT=users ;;
    esac
    for image in app lighthouse; do
        reference=$(bash scripts/image-reference.sh "$image")
        name=${reference#ghcr.io/}
        export RETENTION_TEST_OWNER=${name%%/*} RETENTION_TEST_PACKAGE=${name#*/}
        bash .github/actions/image-retention/collect.sh "$image" "$RETENTION_TEST_TEMP/collection"
        output="$RETENTION_TEST_TEMP/collection/$RETENTION_TEST_PACKAGE"
        jq -e 'length == 2 and .[0].id == 101 and .[0].metadata.container.tags == ["main"] and
            .[1].id == 102 and .[1].metadata.container.tags == []' "$output/versions.json" > /dev/null
        cmp "$RETENTION_FIXTURE/main-shas.txt" "$output/main-shas.txt"
        test ! -s "$output/unreadable.txt"
        for manifest in "$RETENTION_FIXTURE"/manifests/*.json; do
            digest="sha256:$(sha256sum "$manifest" | cut -d ' ' -f 1)"
            cmp "$manifest" "$output/manifests/$digest.json"
        done
        test "$(find "$output/manifests" -type f | wc -l)" -eq 2
        echo "PASS: $owner_type $image collection uses /$RETENTION_TEST_OWNER_ENDPOINT/, flattens both version pages and verifies both local manifest digests"
    done
done
export RETENTION_TEST_OWNER_TYPE=Unknown
if bash .github/actions/image-retention/collect.sh app "$RETENTION_TEST_TEMP/unknown-owner" > "$RETENTION_TEST_TEMP/unknown-owner.log" 2>&1; then
    echo 'FAIL: collection accepted an unknown owner type' >&2
    exit 1
fi
cat "$RETENTION_TEST_TEMP/unknown-owner.log"
grep -Fx "Unknown package owner type for $RETENTION_TEST_OWNER: Unknown" "$RETENTION_TEST_TEMP/unknown-owner.log"
test ! -e "$RETENTION_TEST_TEMP/unknown-owner"
echo 'PASS: unknown owner type fails before package collection'
