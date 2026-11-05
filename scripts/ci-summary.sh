#!/usr/bin/env bash
set -euo pipefail

kind=$1
label=$2
outcome=$3
report=${4:-}

{
    printf '\n### %s\n\n| Gate | Result |\n| --- | --- |\n| %s | %s |\n' "$label" "$label" "$outcome"
    if [ "$kind" = status ]; then
        exit 0
    fi
    if [ ! -f "$report" ]; then
        printf '\nReport not produced. See step logs.\n'
        if [ "$outcome" = success ]; then
            echo "Successful $label step did not produce $report" >&2
            exit 1
        fi
        exit 0
    fi
    case "$kind" in
        tests)
            php scripts/ci-test-summary.php junit "$report" > "$report.json"
            jq -r '
                "\n| Total | Passed | Failed | Errors | Skipped |\n| ---: | ---: | ---: | ---: | ---: |",
                "| \(.total) | \(.passed) | \(.failures) | \(.errors) | \(.skipped) |"
            ' "$report.json"
            ;;
        trivy)
            jq -er '
                def cell: tostring | gsub("[\\r\\n|]"; " ") | gsub("<"; "&lt;") | gsub(">"; "&gt;") | .[0:300];
                [.Results[]? | .Vulnerabilities[]?] as $findings |
                "\n| Severity | Count |\n| --- | ---: |",
                (["CRITICAL", "HIGH", "MEDIUM", "LOW", "UNKNOWN"][] as $severity |
                    "| \($severity) | \([$findings[] | select(.Severity == $severity)] | length) |"),
                "\nShowing at most 50 of \($findings | length) findings.\n",
                "| Severity | CVE | Package | Installed | Fixed |\n| --- | --- | --- | --- | --- |",
                ($findings | sort_by(if .Severity == "CRITICAL" then 0 elif .Severity == "HIGH" then 1 else 2 end) | .[:50][] |
                    "| \([.Severity, .VulnerabilityID, .PkgName, .InstalledVersion, .FixedVersion] | map(cell) | join(" | ")) |")
            ' "$report"
            ;;
        hadolint)
            jq -er '
                def cell: tostring | gsub("[\\r\\n|]"; " ") | gsub("<"; "&lt;") | gsub(">"; "&gt;") | .[0:300];
                "\n| Severity | Count |\n| --- | ---: |",
                (group_by(.level)[] | "| \(.[0].level | cell) | \(length) |"),
                "\nShowing at most 50 of \(length) findings.\n",
                "| Severity | Rule | Line | Message |\n| --- | --- | ---: | --- |",
                (.[:50][] | "| \([.level, .code, .line, .message] | map(cell) | join(" | ")) |")
            ' "$report"
            ;;
        coverage)
            total=$(sed -E 's/\x1B\[[0-9;]*[[:alpha:]]//g' "$report" | grep -oE 'Total: [0-9]+([.][0-9]+)? %' | tail -1 || true)
            if [ -n "$total" ]; then
                printf '\n| Coverage | Value |\n| --- | ---: |\n| Executed lines | %s |\n' "${total#Total: }"
            elif [ "$outcome" = success ]; then
                echo 'Successful test run did not produce the expected Pest coverage total.' >&2
                exit 1
            else
                printf '\nCoverage unavailable: test run did not complete. See step logs.\n'
            fi
            ;;
        *)
            echo "Unknown CI summary kind: $kind" >&2
            exit 1
            ;;
    esac
} >> "$GITHUB_STEP_SUMMARY"
