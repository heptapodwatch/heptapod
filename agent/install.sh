#!/bin/sh
# Installs, upgrades or removes the Heptapod server agent. Run as root. Downloads come from the GitHub release,
# never from the Heptapod instance, which only hands out the endpoint and the token.
set -eu

repository=heptapodwatch/heptapod
# Overridable for mirrors and tests; the checksum is verified against SHA256SUMS from the same place.
base_url=${HEPTAPOD_AGENT_BASE_URL:-https://github.com/$repository/releases/download}
binary=/usr/local/bin/heptapod-agent
config=/etc/heptapod-agent
unit=/etc/systemd/system/heptapod-agent.service
service=heptapod-agent.service

usage() {
    cat >&2 <<'EOF'
Usage:
  install.sh --version vX.Y.Z --endpoint https://heptapod.example.com/agent/v1/report [--token hpa_...]
  install.sh --uninstall

Without --token the token is read from stdin (or prompted for). Re-running is an upgrade: without
--endpoint and --token the installed endpoint and token are kept.
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
        --token) [ $# -ge 2 ] || usage; token=$2; shift 2 ;;
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
    rm -f "$unit" "$binary"
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
case $endpoint in
    https://?* | http://localhost[:/]* | http://127.0.0.1[:/]* | "http://[::1]"[:/]*) ;;
    '') fail "--endpoint is required" ;;
    *) fail "--endpoint must be an https:// URL" ;;
esac

if [ -z "$token" ] && [ ! -t 0 ]; then
    IFS= read -r token || true
fi
if [ -z "$token" ] && [ ! -f "$config/token" ] && [ -t 0 ]; then
    printf 'Agent token: ' >&2
    trap 'stty echo' EXIT INT TERM
    stty -echo
    IFS= read -r token || true
    stty echo
    trap - EXIT INT TERM
    echo >&2
fi
if [ -n "$token" ]; then
    case $token in
        *[[:space:]]*) fail "the token must not contain spaces" ;;
        hpa_?*) ;;
        *) fail "the token must be the hpa_... value from the server's install command" ;;
    esac
elif [ ! -f "$config/token" ]; then
    fail "no token: pass --token or pipe it on stdin"
fi

download() {
    if command -v curl > /dev/null 2>&1; then
        curl -fsSL --proto-redir '=https' -o "$2" "$1"
    elif command -v wget > /dev/null 2>&1; then
        wget -q -O "$2" "$1"
    else
        fail "curl or wget is required"
    fi
}

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT INT TERM
asset=heptapod-agent-linux-$arch
echo "Downloading $asset $version from $base_url/$version/"
download "$base_url/$version/$asset" "$tmp/$asset" || fail "download of $asset failed"
download "$base_url/$version/SHA256SUMS" "$tmp/SHA256SUMS" || fail "download of SHA256SUMS failed"
expected=$(awk -v name="$asset" '$2 == name || $2 == "*" name { print $1 }' "$tmp/SHA256SUMS")
actual=$(sha256sum "$tmp/$asset" | cut -d ' ' -f 1)
[ -n "$expected" ] && [ "$expected" = "$actual" ] || fail "checksum mismatch for $asset: expected '$expected', got '$actual'"
echo "Checksum verified: $actual"
chmod 0755 "$tmp/$asset"
[ "$("$tmp/$asset" --version)" = "${version#v}" ] || fail "$asset does not report version ${version#v}"

install -m 0755 -o root -g root "$tmp/$asset" "$binary.new"
mv -f "$binary.new" "$binary"

install -d -m 0755 -o root -g root "$config"
printf '%s\n' "$endpoint" > "$tmp/endpoint"
install -m 0644 -o root -g root "$tmp/endpoint" "$config/endpoint"
if [ -n "$token" ]; then
    (umask 077 && printf '%s\n' "$token" > "$tmp/token")
    install -m 0600 -o root -g root "$tmp/token" "$config/token"
fi
"$binary" --print-unit > "$tmp/unit"
install -m 0644 -o root -g root "$tmp/unit" "$unit"

echo "Sample this agent will send (dry run):"
"$binary" --once --dry-run

started=$(date +%s)
systemctl daemon-reload
systemctl enable "$service" > /dev/null 2>&1
systemctl restart "$service"
echo "Waiting for the first report..."
waited=0
while [ "$waited" -lt 90 ]; do
    log=$(journalctl --unit "$service" --since "@$started" --output cat --no-pager 2> /dev/null || true)
    case $log in
        *'report accepted'*)
            echo "Heptapod agent $version installed and reporting to $endpoint."
            echo "Optional provenance check (needs the GitHub CLI): gh attestation verify $binary --repo $repository"
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
journalctl --unit "$service" --since "@$started" --no-pager >&2 || true
fail "installed, but no report within 90 seconds; check: journalctl -u $service"
