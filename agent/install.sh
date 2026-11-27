#!/bin/sh
# Installs, upgrades or removes the Heptapod server agent. Run as root. Downloads come from the GitHub release over
# HTTPS, never from the Heptapod instance, which only hands out the endpoint and the token.
set -eu

repository=heptapodwatch/heptapod
release_url=https://github.com/$repository/releases/download
binary=/usr/local/bin/heptapod-agent
config=/etc/heptapod-agent
unit=/etc/systemd/system/heptapod-agent.service
service=heptapod-agent.service

usage() {
    cat >&2 <<'EOF'
Usage:
  install.sh --version vX.Y.Z --endpoint https://heptapod.example.com/agent/v1/report
  install.sh --uninstall

The script asks for the agent token (input hidden), or reads it from stdin when stdin is not a terminal.
Re-running it is an upgrade: without --endpoint the installed endpoint is kept, and the installed token is kept
unless you enter a new one.
EOF
    exit 2
}

fail() {
    echo "heptapod-agent install: $*" >&2
    exit 1
}

version='' endpoint='' token='' uninstall=''
while [ $# -gt 0 ]; do
    case $1 in
        --version) [ $# -ge 2 ] || usage; version=$2; shift 2 ;;
        --endpoint) [ $# -ge 2 ] || usage; endpoint=$2; shift 2 ;;
        --token)
            [ $# -ge 2 ] || usage
            token=$2
            shift 2
            echo "warning: --token puts the token into your shell history and sudo's log; leave it out and enter the token at the prompt instead" >&2
            ;;
        --uninstall) uninstall=1; shift ;;
        *) usage ;;
    esac
done

[ "$(id -u)" = 0 ] || fail "run as root"
[ -d /run/systemd/system ] || fail "systemd is not running on this host"

if [ -n "$uninstall" ]; then
    if [ -f "$unit" ]; then
        systemctl disable --now "$service"
    fi
    rm -f "$unit" "$binary" "$binary.new"
    rm -rf "$config"
    systemctl daemon-reload
    echo "Heptapod agent removed."
    exit 0
fi

printf '%s\n' "$version" | grep -Eq '^v[0-9]+\.[0-9]+\.[0-9]+$' || fail "--version must look like v1.2.3"
case $(uname -m) in
    x86_64 | amd64) arch=amd64 ;;
    aarch64 | arm64) arch=arm64 ;;
    *) fail "unsupported architecture $(uname -m) (amd64 and arm64 only)" ;;
esac
if [ -z "$endpoint" ] && [ -f "$config/endpoint" ]; then
    endpoint=$(cat "$config/endpoint")
fi
[ -n "$endpoint" ] || fail "--endpoint is required"

if [ -z "$token" ]; then
    if [ -t 0 ]; then
        if [ -f "$config/token" ]; then
            printf 'Agent token (press Enter to keep the installed one): ' >&2
        else
            printf 'Agent token: ' >&2
        fi
        trap 'stty echo' EXIT INT TERM
        stty -echo
        IFS= read -r token || true
        stty echo
        trap - EXIT INT TERM
        echo >&2
    else
        IFS= read -r token || true
    fi
fi
if [ -n "$token" ]; then
    case $token in
        *[[:space:]]*) fail "the token must not contain spaces" ;;
        hpa_?*) ;;
        *) fail "the token must be the hpa_... value shown in Heptapod" ;;
    esac
elif [ ! -f "$config/token" ]; then
    fail "no token entered"
fi

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT INT TERM
asset=heptapod-agent-linux-$arch

# Test-only: HEPTAPOD_AGENT_TEST_ASSET_DIR names a local directory with the release assets, so the script can be
# tested without publishing a release. It takes no URL, so it can never download over plain HTTP.
if [ -n "${HEPTAPOD_AGENT_TEST_ASSET_DIR:-}" ]; then
    case $HEPTAPOD_AGENT_TEST_ASSET_DIR in
        /*) [ -d "$HEPTAPOD_AGENT_TEST_ASSET_DIR" ] || fail "HEPTAPOD_AGENT_TEST_ASSET_DIR is not a directory" ;;
        *) fail "HEPTAPOD_AGENT_TEST_ASSET_DIR must be an absolute local directory" ;;
    esac
    echo "TEST MODE: using local assets from $HEPTAPOD_AGENT_TEST_ASSET_DIR instead of the GitHub release" >&2
    fetch() { cp "$HEPTAPOD_AGENT_TEST_ASSET_DIR/$1" "$tmp/$1"; }
else
    command -v curl > /dev/null 2>&1 || fail "curl is required"
    fetch() { curl -fsSL --proto '=https' --proto-redir '=https' -o "$tmp/$1" "$release_url/$version/$1"; }
    echo "Downloading $asset $version from $release_url/$version/"
fi
fetch "$asset" || fail "download of $asset failed"
fetch SHA256SUMS || fail "download of SHA256SUMS failed"
expected=$(awk -v name="$asset" '$2 == name || $2 == "*" name { print $1 }' "$tmp/SHA256SUMS")
actual=$(sha256sum "$tmp/$asset" | cut -d ' ' -f 1)
[ -n "$expected" ] && [ "$expected" = "$actual" ] || fail "checksum mismatch for $asset: expected '$expected', got '$actual'"
echo "Checksum verified: $actual"
chmod 0755 "$tmp/$asset"
[ "$("$tmp/$asset" --version)" = "${version#v}" ] || fail "$asset does not report version ${version#v}"

# Stage the new config and let the new binary validate it before any live file changes.
(umask 077 && mkdir "$tmp/config")
printf '%s\n' "$endpoint" > "$tmp/config/endpoint"
if [ -n "$token" ]; then
    (umask 077 && printf '%s\n' "$token" > "$tmp/config/token")
else
    cp "$config/token" "$tmp/config/token"
fi
"$tmp/$asset" --check-config "$tmp/config" || fail "the endpoint or token is invalid; nothing was changed"
"$tmp/$asset" --print-unit > "$tmp/unit"

install -m 0755 -o root -g root "$tmp/$asset" "$binary.new"
mv -f "$binary.new" "$binary"
install -d -m 0755 -o root -g root "$config"
install -m 0644 -o root -g root "$tmp/config/endpoint" "$config/endpoint"
install -m 0600 -o root -g root "$tmp/config/token" "$config/token"
install -m 0644 -o root -g root "$tmp/unit" "$unit"

echo "Sample this agent will send (dry run):"
"$binary" --once --dry-run

systemctl daemon-reload
systemctl enable "$service" > /dev/null 2>&1
systemctl restart "$service"
invocation=$(systemctl show -p InvocationID --value "$service")
echo "Waiting for the first report..."
waited=0
while [ "$waited" -lt 90 ]; do
    # The agent's log lines never contain the token or response bodies, so they are safe to print.
    log=$(journalctl "_SYSTEMD_INVOCATION_ID=$invocation" --output cat --no-pager 2> /dev/null || true)
    case $log in
        *'report accepted'*)
            echo "Heptapod agent $version installed and reporting to $endpoint."
            exit 0
            ;;
        *'heptapod-agent: '* | *'rejected'* | *'not found'* | *'send failed'* | *'collect failed'* | *'rate limited'* | *'instance error'*)
            printf '%s\n' "$log" >&2
            fail "installed, but the first report did not go through (see above); the service keeps retrying"
            ;;
    esac
    sleep 1
    waited=$((waited + 1))
done
journalctl "_SYSTEMD_INVOCATION_ID=$invocation" --output cat --no-pager >&2 || true
fail "installed, but no report within 90 seconds; check: journalctl -u $service"
