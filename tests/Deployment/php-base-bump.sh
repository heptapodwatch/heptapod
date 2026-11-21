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
prepare
change_json run.json '.head_branch = "feature/other"'
change_json php-base-record.json '.run.branch = "feature/other" | .build.branch = "feature/other"'
reject 'non-main branch'
