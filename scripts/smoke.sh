#!/usr/bin/env bash
# HTTP smoke for the running local stack: logs in as the standard demo user through the login form,
# re-confirms the password for this session, then requires 200 from the main GET pages. The only redirects
# accepted are the exact 301 aliases from / and /dashboard to /sites and from /settings/appearance to
# /settings/profile. Fails on any other status, on a login
# page served in place of a page, or on a new error entry
# in the Laravel log written during the run. Run after scripts/dev-up.sh.
# Deliberately excluded: side-effecting GETs (cron pings, signed accept links, auth callbacks), downloads
# (incident export, client logo), JSON data endpoints (settings/*/data) and guest pages.
# Only the origin validated in scripts/dev-stack-lib.sh is contacted; the demo password never goes elsewhere.
set -euo pipefail

started=$(date +%s)

[ "$#" -eq 0 ] || { echo 'smoke: takes no arguments' >&2; exit 1; }
checkout=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)
cd "$checkout"
# shellcheck source=scripts/dev-stack-lib.sh
. "$checkout/scripts/dev-stack-lib.sh"

base=$(validate_app_origin "$(env_value APP_URL)" "$(env_value APP_PORT)")
[ "$(env_value APP_ENV)" = local ] || die 'APP_ENV in .env must be local'
[ "$(env_value LOG_CHANNEL)" = stack ] && [ "$(env_value LOG_STACK)" = single ] ||
    die 'smoke reads storage/logs/laravel.log; .env must set LOG_CHANNEL=stack and LOG_STACK=single'
require_owned_stack
require_checkout_source
require_local_targets

log=storage/logs/laravel.log
log_start=0
if [ -e "$log" ]; then
    [ -r "$log" ] || die "$log exists but is unreadable"
    log_start=$(wc -c < "$log")
fi

workdir=$(mktemp -d)
trap 'rm -rf "$workdir"' EXIT
jar="$workdir/jar"
body="$workdir/body"

# Scenario entities come from the database, so coverage follows whatever the scenario seeded.
entities=$(docker compose exec -T app php artisan tinker --no-interaction --execute '$site = \Heptapod\Models\Site::query()->orderBy("id")->first(); echo json_encode(["client" => \Heptapod\Models\Client::query()->where("is_internal", false)->orderBy("id")->value("id"), "site" => $site?->id, "check" => $site?->checks()->orderBy("id")->value("id"), "incident" => \Heptapod\Models\Incident::query()->orderBy("id")->value("id")]);')
client=$(jq -r '.client // empty' <<<"$entities")
site=$(jq -r '.site // empty' <<<"$entities")
check=$(jq -r '.check // empty' <<<"$entities")
incident=$(jq -r '.incident // empty' <<<"$entities")

pages=(/upcoming /activity /runs '/search?q=demo' /clients /clients/create /maintenance /incidents /sites /sites/create
    /settings/profile /settings/security /settings/notifications /settings/instance /settings/system
    /settings/updates /settings/members /settings/api-tokens /settings/notifications/team /settings/alerts)
not_applicable=()
if [ -n "$client" ]; then
    pages+=("/clients/$client" "/clients/$client/notifications" "/clients/$client/settings")
else
    not_applicable+=('clients/{client}: no non-internal client in this scenario')
fi
if [ -n "$site" ]; then
    pages+=("/sites/$site" "/sites/$site/settings" "/sites/$site/maintenance")
else
    not_applicable+=('sites/{site}: no sites in this scenario')
fi
if [ -n "$site" ] && [ -n "$check" ]; then
    pages+=("/sites/$site/checks/$check")
else
    not_applicable+=('sites/{site}/checks/{check}: no checks in this scenario')
fi
if [ -n "$incident" ]; then
    pages+=("/incidents/$incident")
else
    not_applicable+=('incidents/{incident}: no incidents in this scenario')
fi

# Laravel's XSRF-TOKEN cookie is URL-encoded; the header needs the decoded value.
xsrf_token() {
    local raw
    raw=$(awk '$6 == "XSRF-TOKEN" {value = $7} END {print value}' "$jar")
    [ -n "$raw" ] || die 'no XSRF-TOKEN cookie in the session jar'
    printf '%b' "${raw//%/\\x}"
}

# Real login over HTTP with the demo credentials, then an authenticated page as proof of the session.
login_page=$(curl -sS -c "$jar" -o /dev/null -w '%{http_code}' --max-time 30 "$base/login")
[ "$login_page" = 200 ] || die "GET /login returned $login_page"
login=$(curl -sS -b "$jar" -c "$jar" -o /dev/null -w '%{http_code} %{redirect_url}' --max-time 30 \
    -H "X-XSRF-TOKEN: $(xsrf_token)" -H 'Accept: text/html' \
    --data-urlencode 'email=test@example.test' --data-urlencode 'password=password' "$base/login")
[ "${login%% *}" = 302 ] || die "POST /login returned ${login%% *}; the demo login failed or is throttled"
# Fortify answers a rejected login with a redirect too, so the session is proven by an authenticated page.
session=$(curl -sS -b "$jar" -c "$jar" -o /dev/null -w '%{http_code} %{redirect_url}' --max-time 30 "$base/sites")
[ "${session%% *}" = 200 ] || die "no authenticated session after login: GET /sites returned $session"

# Fortify's password.confirm gate sends /settings/security to a password prompt; confirming with the demo
# password lets that page render, so it is checked for 200 like every other page.
confirm=$(curl -sS -b "$jar" -c "$jar" -o /dev/null -w '%{http_code} %{redirect_url}' --max-time 30 \
    -H "X-XSRF-TOKEN: $(xsrf_token)" -H 'Accept: text/html' \
    --data-urlencode 'password=password' "$base/user/confirm-password")
[ "${confirm%% *}" = 302 ] || die "POST /user/confirm-password returned ${confirm%% *}; password confirmation failed"

checked=0
failures=0
check_page() {
    local path=$1 response code redirect
    checked=$((checked + 1))
    response=$(curl -sS -b "$jar" -c "$jar" -o "$body" -w '%{http_code} %{redirect_url}' --max-time 30 "$base$path")
    code=${response%% *}
    redirect=${response#* }
    if [ "$code" != 200 ]; then
        failures=$((failures + 1))
        echo "FAIL $code $path${redirect:+ -> $redirect}"
    elif grep -q 'auth/login' "$body"; then
        failures=$((failures + 1))
        echo "FAIL 200 $path rendered the login page"
    else
        echo "ok 200 $path"
    fi
}

# Compare the raw Location header, not curl's normalized redirect URL. A lost session (302 to login) fails.
check_alias() {
    local path=$1 expected_location=$2 code redirect
    checked=$((checked + 1))
    code=$(curl -sS -b "$jar" -c "$jar" -D "$workdir/alias.headers" -o /dev/null -w '%{http_code}' --max-time 30 "$base$path")
    redirect=$(awk 'tolower($1) == "location:" {sub(/^[^:]*:[[:space:]]*/, ""); sub(/\r$/, ""); print}' "$workdir/alias.headers")
    if [ "$code" = 301 ] && [ "$redirect" = "$expected_location" ]; then
        echo "ok 301 $path -> $redirect"
    else
        failures=$((failures + 1))
        echo "FAIL $code $path -> ${redirect:-no Location}; expected 301 -> $expected_location"
    fi
}

for path in "${pages[@]}"; do
    check_page "$path"
done
check_alias / /sites
check_alias /dashboard /sites
check_alias /settings/appearance "$base/settings/profile"

if [ "$failures" -gt 0 ]; then
    echo "smoke: $failures of $checked pages failed" >&2
    exit 1
fi
if [ -e "$log" ]; then
    log_size=$(wc -c < "$log")
    [ "$log_size" -ge "$log_start" ] || die "$log shrank during the run; rerun smoke"
    new_errors=$(tail -c +$((log_start + 1)) "$log" | grep -E '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' || true)
else
    [ "$log_start" = 0 ] || die "$log disappeared during the run"
    new_errors=''
fi
if [ -n "$new_errors" ]; then
    echo "smoke: $(wc -l <<<"$new_errors") new error entries in $log during the run (first 5, truncated):" >&2
    cut -c1-200 <<<"$new_errors" | head -n 5 >&2
    exit 1
fi

echo "smoke: $checked pages ok in $(( $(date +%s) - started ))s"
echo "coverage: static pages plus scenario entities; not applicable: ${not_applicable[*]:-none}"
