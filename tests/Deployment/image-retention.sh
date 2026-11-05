#!/usr/bin/env bash
# Hand-built manifests exercise the production planner without registry or API access.
set -euo pipefail
root=$(cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$root"
fixture=tests/Deployment/fixtures/image-retention
planner=.github/actions/image-retention/plan.sh
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
now=1792540800 # 2026-10-21T00:00:00Z
bash "$planner" "$fixture" "$now" > "$tmp/plan.json"

assert_plan() {
    local label=$1 expression=$2
    if ! jq -e "$expression" "$tmp/plan.json" > /dev/null; then
        echo "FAIL: $label" >&2
        cat "$tmp/plan.json" >&2
        exit 1
    fi
    echo "PASS: $label"
}

assert_plan 'old unreachable legacy per-arch version is deleted without a deploy warning' '
    any(.delete[]; .id == 19 and .reason == "not reachable from kept tag") and
    all(.warnings[]; . != ("sha256:" + ("0" * 62) + "19"))'
assert_plan 'old legacy per-arch child stays kept through the main index closure' '
    any(.versions[]; .id == 20 and .action == "keep" and
        .reason == ("child-of sha256:" + ("0" * 63) + "1 (tag main)"))'
assert_plan 'young legacy per-arch version stays kept only through the age floor, not the deploy window' '
    any(.versions[]; .id == 21 and .action == "keep" and .reason == "age-floor (<3d)") and
    .kept_deploy_shas == [("a" * 40), ("c" * 40), ("d" * 40)]'
assert_plan 'legacy look-alikes with another architecture or a short SHA stay unknown' '
    any(.versions[]; .id == 22 and .action == "keep" and .reason == "unknown-tag") and
    [.unknown_tags[] | select(.digest == ("sha256:" + ("0" * 62) + "22")) | {tag, reason}] ==
        [{tag:(("b" * 40) + "-s390x"), reason:"unknown-tag"},
         {tag:(("b" * 39) + "-amd64"), reason:"unknown-tag"}]'

assert_plan 'reused ci/main/deploy index stays kept with tag main reason' '
    any(.versions[]; .id == 1 and .action == "keep" and .reason == "tag main" and (.tags | length) == 3)'
assert_plan 'both tagged per-arch children stay kept through index closure' '
    [.versions[] | select(.id == 2 or .id == 3) | select(.action == "keep" and (.reason | startswith("child-of "))) | .id] == [2,3]'
assert_plan 'cosign signature stays kept through its subject tag' '
    any(.versions[]; .id == 4 and .action == "keep" and (.reason | startswith("cosign-of ")))'
assert_plan 'unknown latest tag stays kept and reports unknown-tag' '
    any(.versions[]; .id == 5 and .action == "keep" and .reason == "unknown-tag") and
    [.unknown_tags[] | {tag, reason}] == [{tag:"latest", reason:"unknown-tag"}, {tag:"manual-backup", reason:"unknown-tag"},
        {tag:(("b" * 40) + "-s390x"), reason:"unknown-tag"}, {tag:(("b" * 39) + "-amd64"), reason:"unknown-tag"}] and
    all(.unknown_tags[]; .digest | startswith("sha256:"))'
assert_plan 'old unreachable referrer precedes its index and tagged image children' '
    [.delete[].id] == [9,6,7,8,19,14]'
assert_plan 'young unreachable, recently updated and exact age-floor versions stay kept' '
    [.versions[] | select(.id == 10 or .id == 17 or .id == 18) | select(.action == "keep" and .reason == "age-floor (<3d)") | .id] == [10,17,18]'
assert_plan 'referrer of a kept index child stays kept transitively' '
    any(.versions[]; .id == 11 and .action == "keep" and (.reason | startswith("referrer-of ")))'
assert_plan 'last three deploys use first-parent order, not creation time' '
    .kept_deploy_shas == [("a" * 40), ("c" * 40), ("d" * 40)] and
    ([.versions[] | select(.id == 12 or .id == 13) | .action] == ["keep", "keep"]) and
    any(.delete[]; .id == 14)'
assert_plan 'off-main deploy SHA warns and release tag stays protected' '
    any(.versions[]; .id == 15 and .action == "keep" and .reason == "deploy-tag-not-on-main") and
    (.warnings | length) == 1 and any(.versions[]; .id == 16 and .action == "keep" and .reason == "tag v1.0.0")'
assert_plan 'size estimates deduplicate shared config blobs and only free delete-exclusive layers' '
    .totals == {versions:22, estimated_unique_bytes:2010, kept:16, estimated_kept_bytes:1510,
                delete:6, estimated_freed_bytes:500, unreadable:0} and
    any(.versions[]; .id == 1 and .estimated_bytes == 310)'

cp -R "$fixture" "$tmp/unreadable"
digest=$(printf 'sha256:%064d' 1)
rm "$tmp/unreadable/manifests/$digest.json"
if bash "$planner" "$tmp/unreadable" "$now" > "$tmp/blocked.json" 2> "$tmp/blocked.err"; then
    echo 'FAIL: unreadable index must return non-zero' >&2
    exit 1
fi
jq -e '.safe == false and (.unreadable | length) == 1 and .delete == [] and .totals.estimated_freed_bytes == 0 and
    all(.versions[]; .action != "delete") and any(.versions[]; .id == 1 and .action == "keep" and .reason == "unreadable")' \
    "$tmp/blocked.json" > /dev/null || { echo 'FAIL: unreadable index must block the plan with an empty delete set' >&2; cat "$tmp/blocked.json" >&2; exit 1; }
cat "$tmp/blocked.err"
echo 'PASS: unreadable index prints blocked plan, fails and produces no delete set'

printf '{"schemaVersion":2,"manifests":[{}]}\n' > "$tmp/unreadable/manifests/$digest.json"
if bash "$planner" "$tmp/unreadable" "$now" > "$tmp/malformed.json" 2> "$tmp/malformed.err"; then
    echo 'FAIL: malformed manifest descriptors must return non-zero' >&2
    exit 1
fi
jq -e '.safe == false and (.unreadable | length) == 1 and .delete == [] and
    any(.versions[]; .id == 1 and .reason == "unreadable")' "$tmp/malformed.json" > /dev/null \
    || { echo 'FAIL: malformed manifest must block the plan with an empty delete set' >&2; cat "$tmp/malformed.json" >&2; exit 1; }
echo 'PASS: malformed manifest prints blocked plan and produces no delete set'

# The workflow's report step: the same script, run on two fixture plans, so its jq programs fail here and not on main.
mkdir -p "$tmp/report"
cp "$tmp/plan.json" "$tmp/report/app-plan.json"
cp "$tmp/plan.json" "$tmp/report/lighthouse-plan.json"
GITHUB_STEP_SUMMARY="$tmp/summary.md" bash .github/actions/image-retention/report.sh "$tmp/report" "$tmp/combined.json" > "$tmp/report.out"
jq -e '(.packages | length) == 2' "$tmp/combined.json" > /dev/null \
    || { echo 'FAIL: report must combine both package plans' >&2; cat "$tmp/combined.json" >&2; exit 1; }
grep -q '^::warning::deploy-tag-not-on-main sha256:' "$tmp/report.out" \
    || { echo 'FAIL: report must emit a ::warning:: per deploy tag not on main' >&2; cat "$tmp/report.out" >&2; exit 1; }
rows=$(grep -c '^| \(keep\|delete\) |' "$tmp/summary.md")
expected_rows=$(jq '[.packages[].versions[]] | length' "$tmp/combined.json")
[ "$rows" = "$expected_rows" ] \
    || { echo "FAIL: summary must have one row per version ($rows rows, $expected_rows versions)" >&2; cat "$tmp/summary.md" >&2; exit 1; }
if ! grep -q '(est.)' "$tmp/summary.md" || ! grep -q '^Unknown tag: ' "$tmp/summary.md"; then
    echo 'FAIL: summary must label estimates and list unknown tags' >&2
    cat "$tmp/summary.md" >&2
    exit 1
fi
echo 'PASS: report step combines the plans, warns per deploy tag and writes one summary row per version'
