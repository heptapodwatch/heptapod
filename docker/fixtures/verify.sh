#!/bin/sh
# HTTP contract smoke test. Run from a curl container on the Compose network.
set -eu
base=http://fixtures.test
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
request() {
    expected=$1
    url=$2
    shift 2
    actual=$(curl -sS --max-time 40 -o "$work/body" -w '%{http_code}' "$@" "$url")
    printf '%s %s (expected %s)\n' "$actual" "$url" "$expected"
    [ "$actual" = "$expected" ]
}
for path in / /docs/ /docs/level-two/ /docs/level-two/level-three/ /blocked/ \
    /robots.txt /mixed-content.html /assets/style.css /assets/script.js /assets/pixel.svg \
    /keyword-present.html /keyword-absent.html /response.json \
    /sitemap.xml /sitemap-index.xml /sitemap-broken.xml /sitemap-invalid.xml \
    /redirect/final /status/200; do
    request 200 "$base$path"
done
for path in /missing-internal /missing-nested /missing-blocked /status/404; do
    request 404 "$base$path"
done
request 200 http://external.fixtures.test/
request 404 http://external.fixtures.test/missing
request 500 "$base/status/500"
request 503 "$base/status/503"
request 301 "$base/redirect/start"
request 302 "$base/redirect/hop"
redirect=$(curl -sS -L -o /dev/null -w '%{http_code} %{num_redirects} %{url_effective}' "$base/redirect/start")
printf 'Redirect: %s\n' "$redirect"
[ "$redirect" = '200 2 http://fixtures.test/redirect/final' ]
for status in ok warning failed crashed skipped; do
    request 200 "$base/health/$status.json"
    grep -q "\"status\": \"$status\"" "$work/body"
done
request 200 "$base/health/malformed.json"
request 403 "$base/health/secret.json"
request 403 "$base/health/secret.json" -H 'oh-dear-health-check-secret: incorrect'
request 200 "$base/health/secret.json" -H 'oh-dear-health-check-secret: fixture-secret'
grep -q '"status": "ok"' "$work/body"
request 200 "$base/keyword-present.html"
grep -q HEPTAPOD_FIXTURE_READY "$work/body"
request 200 "$base/keyword-absent.html"
if grep -q HEPTAPOD_FIXTURE_READY "$work/body"; then exit 1; fi
request 200 "$base/response.json"
grep -q '"ready": true' "$work/body"
request 200 https://fixtures.test/ --cacert /fixtures/tls/ca.crt
request 200 https://fixtures.test/mixed-content.html --cacert /fixtures/tls/ca.crt
for asset in pixel.svg script.js style.css; do
    grep -q "http://fixtures.test/assets/$asset" "$work/body"
done
for variant in expired wrong-host; do
    code=0
    curl -sS --cacert /fixtures/tls/ca.crt "https://$variant.fixtures.test/" \
        -o /dev/null 2> "$work/tls-error" || code=$?
    cat "$work/tls-error"
    [ "$code" = 60 ]
    case "$variant" in
        expired) grep -q 'certificate has expired' "$work/tls-error" ;;
        wrong-host) grep -q 'no alternative certificate subject name matches target hostname' "$work/tls-error" ;;
    esac
    request 200 "https://$variant.fixtures.test/" --insecure
done
slow=$(curl -sS --max-time 10 -o /dev/null -w '%{http_code} %{time_total}' "$base/slow")
printf 'Slow: %s seconds\n' "$slow"
printf '%s\n' "$slow" | awk '$1 == 200 && $2 >= 2.9 && $2 < 6 {ok=1} END {exit !ok}'
code=0
curl -sS --max-time 30 "$base/timeout" -o /dev/null 2> "$work/timeout-error" || code=$?
cat "$work/timeout-error"
[ "$code" = 28 ]
timeout=$(curl -sS --max-time 40 -o /dev/null -w '%{http_code} %{time_total}' "$base/timeout")
printf 'Timeout completion: %s seconds\n' "$timeout"
printf '%s\n' "$timeout" | awk '$1 == 200 && $2 >= 34.9 && $2 < 40 {ok=1} END {exit !ok}'
printf 'PASS: every fixture endpoint, redirect chain, header, keyword, delay and TLS variant.\n'
