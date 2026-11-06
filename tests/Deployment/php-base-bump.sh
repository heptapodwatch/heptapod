#!/usr/bin/env bash
# Exercise the host CLI against Actions API records, with real gh usage validation.
set -euo pipefail
root=$(cd -- "$(dirname -- "$0")/../.." && pwd)
source_root=${1:-$root}
command -v zip > /dev/null || { echo 'php-base-bump.sh test needs zip to build artifact fixtures' >&2; exit 1; }
export REAL_GH
REAL_GH=$(command -v gh)
"$REAL_GH" --version
printf '%s\n' 'gh validation mode: GH_HOST=offline.invalid:invalid-port; only the exact invalid-port URL parse error is accepted.'
export RETENTION_TEST_TEMP
RETENTION_TEST_TEMP=$(mktemp -d)
trap 'rm -rf "$RETENTION_TEST_TEMP"' EXIT
mkdir -p "$RETENTION_TEST_TEMP/gh-config" "$RETENTION_TEST_TEMP/repo/scripts" "$RETENTION_TEST_TEMP/repo/docker/php-base"
cp "$source_root"/scripts/php-base-* "$RETENTION_TEST_TEMP/repo/scripts/"
cp "$root/tests/Deployment/fixtures/php-base-bump/pin" "$RETENTION_TEST_TEMP/original-pin"
export PHP_BASE_FIXTURE_RESPONSE="$RETENTION_TEST_TEMP/response"
export PATH="$root/tests/Deployment/fixtures/php-base-bump/bin:$PATH"
export GITHUB_REPOSITORY=fixture/php-base
export GH_TOKEN=synthetic-must-not-reach-gh GITHUB_TOKEN=synthetic-must-not-reach-gh
export GH_ENTERPRISE_TOKEN=synthetic-must-not-reach-gh GITHUB_ENTERPRISE_TOKEN=synthetic-must-not-reach-gh
pin="$RETENTION_TEST_TEMP/repo/docker/php-base/pin"
fixtures="$root/tests/Deployment/fixtures/php-base-bump"
prepare() {
    rm -rf "$PHP_BASE_FIXTURE_RESPONSE"
    mkdir -p "$PHP_BASE_FIXTURE_RESPONSE"
    cp "$fixtures"/*.json "$PHP_BASE_FIXTURE_RESPONSE/"
    cp "$RETENTION_TEST_TEMP/original-pin" "$pin"
}
change_json() {
    jq "$2" "$PHP_BASE_FIXTURE_RESPONSE/$1" > "$PHP_BASE_FIXTURE_RESPONSE/$1.tmp"
    mv "$PHP_BASE_FIXTURE_RESPONSE/$1.tmp" "$PHP_BASE_FIXTURE_RESPONSE/$1"
}
invoke() {
    zip -q -j "$PHP_BASE_FIXTURE_RESPONSE/record.zip" "$PHP_BASE_FIXTURE_RESPONSE/php-base-record.json"
    bash "$RETENTION_TEST_TEMP/repo/scripts/php-base-bump.sh" "$@"
}
reject() {
    local name=$1
    cp "$pin" "$RETENTION_TEST_TEMP/before"
    if invoke 202; then
        echo "FAIL: accepted $name" >&2
        exit 1
    fi
    cmp "$RETENTION_TEST_TEMP/before" "$pin"
    echo "PASS: $name rejected without changing either pin"
}
prepare
invoke
# These values are independent of the updater and the fixtures' JSON traversal.
cat > "$RETENTION_TEST_TEMP/expected-pin" <<'PIN'
# Fixture comment must survive the atomic update.
runtime=ghcr.io/heptapodwatch/heptapod-php-base@sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
coverage=ghcr.io/heptapodwatch/heptapod-php-base@sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc
PIN
cmp "$RETENTION_TEST_TEMP/expected-pin" "$pin"
echo 'PASS: latest scheduled green record atomically updates both pins (paginated run/artifact/job APIs)'
prepare
invoke 202
cmp "$RETENTION_TEST_TEMP/expected-pin" "$pin"
echo 'PASS: explicit green run updates both pins'
prepare
change_json run.json '.event = "workflow_dispatch"'
change_json php-base-record.json '.run.event = "workflow_dispatch" | .build.event = "workflow_dispatch"'
invoke 202
cmp "$RETENTION_TEST_TEMP/expected-pin" "$pin"
echo 'PASS: explicit green workflow_dispatch run on main updates both pins'
prepare
change_json php-base-record.json '.indexes[0].cells[0].hard_gate = "failure"'
reject 'red runtime gate record'
prepare
change_json php-base-record.json '.indexes[1].cells[1].hard_gate = "failure"'
reject 'red coverage gate record'
prepare
change_json artifacts.json '[{artifacts: []}]'
reject 'missing record'
prepare
printf '{malformed\n' > "$PHP_BASE_FIXTURE_RESPONSE/php-base-record.json"
reject 'malformed record'
prepare
change_json php-base-record.json '.indexes[0].tags[0].digest = "sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc"'
reject 'digest/tag mismatch'
prepare
sed -i '/^runtime=/d' "$pin"
reject 'missing runtime pin'
prepare
sed -i '/^coverage=/d' "$pin"
reject 'missing coverage pin'
prepare
cat "$RETENTION_TEST_TEMP/original-pin" >> "$pin"
reject 'duplicate pin keys'
prepare
printf '%s\n' 'coverage=ghcr.io/heptapodwatch/heptapod-php-base@sha256:7777777777777777777777777777777777777777777777777777777777777777' >> "$pin"
reject 'duplicate coverage pin'
prepare
change_json php-base-record.json '.indexes[1].cells |= .[0:1]'
reject 'missing native gate cell'
prepare
change_json run.json '.conclusion = "failure"'
reject 'unsuccessful run'
prepare
change_json run.json '.path = ".github/workflows/production-images.yml"'
reject 'wrong workflow'
prepare
change_json run.json '.event = "push"'
change_json php-base-record.json '.run.event = "push" | .build.event = "push"'
reject 'main push instead of schedule/dispatch'
prepare
change_json jobs.json '.[0].jobs[0].steps[0].conclusion = "failure"'
reject 'red Actions HARD gate despite green record'
prepare
change_json jobs.json '.[1].jobs[1].steps[1].conclusion = "skipped"'
reject 'skipped Actions push despite green record'
prepare
change_json php-base-record.json '.indexes[1].cells[0].push = "failure"'
reject 'red push record'
prepare
change_json php-base-record.json '.indexes[0].cells[0].pushed_config = "sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc"'
reject 'scanned/pushed config mismatch'
prepare
change_json php-base-record.json '.run.attempt = 2'
reject 'stale run attempt'

# Main is a second public entry point: every source must validate before the first retag.
export GITHUB_SHA=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
export GITHUB_RUN_ID=404 GITHUB_RUN_ATTEMPT=1 GITHUB_EVENT_NAME=push GITHUB_REF=refs/heads/main
export RUNNER_TEMP="$RETENTION_TEST_TEMP/runner" GITHUB_STEP_SUMMARY="$RETENTION_TEST_TEMP/summary" GITHUB_OUTPUT="$RETENTION_TEST_TEMP/output"
prepare_candidate() {
    prepare
    change_json run.json '.event = "push" | .head_branch = "ci/candidate"'
    change_json runs.json '[{workflow_runs: [{id: 202, event: "push", head_branch: "ci/candidate", head_sha: "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}]}]'
    change_json php-base-record.json '
        .run.event = "push" | .run.branch = "ci/candidate" | .build = .run |
        .indexes[].tags |= map(if .tag == "latest-main" then .tag = "ci-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
            elif .tag == "latest-main-coverage" then .tag = "ci-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-coverage" else . end)'
    : > "$PHP_BASE_FIXTURE_RESPONSE/retags"
    : > "$GITHUB_OUTPUT"
}
invoke_reuse() {
    zip -q -j "$PHP_BASE_FIXTURE_RESPONSE/record.zip" "$PHP_BASE_FIXTURE_RESPONSE/php-base-record.json"
    bash "$RETENTION_TEST_TEMP/repo/scripts/php-base-reuse.sh"
}
reject_reuse() {
    local name=$1
    cp "$PHP_BASE_FIXTURE_RESPONSE/registry.json" "$RETENTION_TEST_TEMP/before-registry"
    if invoke_reuse; then
        echo "FAIL: main accepted $name" >&2
        exit 1
    fi
    cmp "$RETENTION_TEST_TEMP/before-registry" "$PHP_BASE_FIXTURE_RESPONSE/registry.json"
    test ! -s "$PHP_BASE_FIXTURE_RESPONSE/retags"
    echo "PASS: main $name rejected without retagging either index"
}
prepare_candidate
invoke_reuse
cat > "$RETENTION_TEST_TEMP/expected-retags" <<'TAGS'
latest-main sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
latest-main-coverage sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc
TAGS
cmp "$RETENTION_TEST_TEMP/expected-retags" "$PHP_BASE_FIXTURE_RESPONSE/retags"
jq -e '.run.id == 404 and .run.attempt == 1 and .run.branch == "main" and
    .build.id == 202 and .build.branch == "ci/candidate" and
    .indexes[0].index_digest == "sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb" and
    .indexes[1].index_digest == "sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc" and
    .indexes[0].tags[-1].tag == "latest-main" and .indexes[1].tags[-1].tag == "latest-main-coverage"' \
    "$RUNNER_TEMP/php-base-record.json" > /dev/null
jq -e -f "$source_root/scripts/php-base-record.jq" "$RUNNER_TEMP/php-base-record.json" > /dev/null
test "$(cat "$GITHUB_OUTPUT")" = reuse=true
echo 'PASS: main reuses both exact candidate indexes and emits its own record without building'
prepare_candidate
change_json runs.json '[{workflow_runs: []}]'
rm -f "$RUNNER_TEMP/php-base-record.json"
invoke_reuse
test "$(cat "$GITHUB_OUTPUT")" = reuse=false
test ! -s "$PHP_BASE_FIXTURE_RESPONSE/retags"
test ! -e "$RUNNER_TEMP/php-base-record.json"
echo 'PASS: main without a same-SHA candidate falls back to the native build without retagging'
prepare_candidate
change_json run.json '.conclusion = "failure"'
reject_reuse 'red candidate'
prepare_candidate
change_json php-base-record.json '.indexes[1].cells[1].hard_gate = "failure"'
reject_reuse 'red coverage record'
prepare_candidate
change_json registry.json '.tags["ci-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa-coverage"] = "sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"'
reject_reuse 'coverage tag/digest mismatch'
prepare_candidate
change_json registry.json '.manifests["sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc"].manifests |= .[0:1]'
reject_reuse 'missing coverage architecture'
prepare_candidate
change_json registry.json '.manifests["sha256:3333333333333333333333333333333333333333333333333333333333333333"].config.digest = "sha256:ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff"'
reject_reuse 'changed coverage pushed config'
prepare_candidate
change_json run.json '.head_sha = "ffffffffffffffffffffffffffffffffffffffff"'
change_json php-base-record.json '.run.sha = "ffffffffffffffffffffffffffffffffffffffff" | .build.sha = .run.sha'
reject_reuse 'wrong source SHA'
prepare_candidate
export GITHUB_EVENT_NAME=schedule
reject_reuse 'non-push main invocation'
export GITHUB_EVENT_NAME=push
