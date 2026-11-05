# shellcheck shell=bash
# shellcheck disable=SC2034 # paginate/slurp are consumed by the sourcing fixture shim.
# Validate with real gh before replacing the API response. An invalid URL port
# stops net/url before any DNS lookup or socket, but after gh's usage checks.
set -euo pipefail
endpoint=
filter=
paginate=false
slurp=false
for argument in "$@"; do
    case "$argument" in
        *://*|--hostname*)
            echo "Fixture gh refuses a host override: $argument" >&2
            exit 1
            ;;
        --jq*|-q|--template*|-t) filter=$argument ;;
        repos/*|/users/*|/orgs/*) endpoint=$argument ;;
        --paginate) paginate=true ;;
        --slurp) slurp=true ;;
    esac
done
unset GH_TOKEN GITHUB_TOKEN GH_ENTERPRISE_TOKEN GITHUB_ENTERPRISE_TOKEN GH_DEBUG DEBUG
export GH_ENTERPRISE_TOKEN=retention-fixture-token
export GH_HOST=offline.invalid:invalid-port
export GH_CONFIG_DIR="$RETENTION_TEST_TEMP/gh-config"
export GH_NO_UPDATE_NOTIFIER=1 GH_NO_EXTENSION_UPDATE_NOTIFIER=1 GH_PROMPT_DISABLED=1
export GH_TELEMETRY=0 DO_NOT_TRACK=1
if "$REAL_GH" "$@" > "$RETENTION_TEST_TEMP/gh.out" 2> "$RETENTION_TEST_TEMP/gh.err"; then
    result=0
else
    result=$?
fi
expected="parse \"https://offline.invalid:invalid-port/api/v3/${endpoint#/}\": invalid port \":invalid-port\" after host"
if [ "$result" -ne 1 ] || [ "$(cat "$RETENTION_TEST_TEMP/gh.err")" != "$expected" ]; then
    printf 'FAIL: real gh rejected the collection invocation (exit %s):' "$result" >&2
    printf ' %q' "$@" >&2
    printf '\n' >&2
    cat "$RETENTION_TEST_TEMP/gh.out" "$RETENTION_TEST_TEMP/gh.err" >&2
    exit 1
fi
printf 'Validated by real gh (offline invalid-port): ' >&2
cat "$RETENTION_TEST_TEMP/gh.err" >&2
# Checked after real gh, so gh's own usage errors keep priority. The fixture prints unfiltered pages, so a
# filtered call would pass or fail here for the wrong reason.
if [ -n "$filter" ]; then
    echo "FAIL: fixture gh does not emulate --jq/--template ($filter); pipe the output into jq instead" >&2
    exit 1
fi
