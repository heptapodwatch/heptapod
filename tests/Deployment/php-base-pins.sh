#!/usr/bin/env bash
# The standalone base build must keep the app image's lint and security policy.
set -euo pipefail
cd "$(dirname "$0")/../.."
source_action=${1:-.github/actions/build-scan-image/action.yml}
base_workflow=${2:-.github/workflows/php-base.yml}
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

extract_policy() {
    awk '
        BEGIN {
            split("hadolint trivy-action TRIVY_IMAGE_SRC version scanners severity ignore-unfixed exit-code trivyignores format", keys, " ")
            for (i in keys) { required[keys[i]] = 1 }
        }
        /ghcr.io\/hadolint\/hadolint:/ {
            value = $0
            sub(/^[[:space:]]*/, "", value)
            sub(/[[:space:]].*$/, "", value)
            values["hadolint"] = value
            count["hadolint"]++
        }
        /^[[:space:]]*- (name|uses):/ { trivy = 0 }
        /uses: aquasecurity\/trivy-action@/ {
            trivy = 1
            value = $0
            sub(/^.*uses: /, "", value)
            sub(/[[:space:]].*$/, "", value)
            values["trivy-action"] = value
            count["trivy-action"]++
        }
        trivy && /^[[:space:]]*[A-Za-z_-]+:/ {
            key = $0
            sub(/^[[:space:]]*/, "", key)
            sub(/:.*/, "", key)
            if (key in required) {
                value = $0
                sub(/^[^:]*:[[:space:]]*/, "", value)
                sub(/[[:space:]]+#.*$/, "", value)
                gsub(/^["\047]|["\047]$/, "", value)
                values[key] = value
                count[key]++
            }
        }
        END {
            for (key in required) {
                if (count[key] != 1 || values[key] == "") {
                    printf "Expected exactly one nonempty %s policy value in %s\n", key, FILENAME > "/dev/stderr"
                    exit 1
                }
                print key "=" values[key]
            }
        }
    ' "$1" | LC_ALL=C sort
}

extract_policy "$source_action" > "$work/app"
extract_policy "$base_workflow" > "$work/base"
diff -u "$work/app" "$work/base"
printf 'PHP base lint and security pins match build-scan-image\n'
