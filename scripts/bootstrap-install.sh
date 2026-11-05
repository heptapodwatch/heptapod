#!/usr/bin/env bash
# Hosted installer, served as https://heptapod.watch/install.sh:
#   curl -fsSL https://heptapod.watch/install.sh | sudo bash -s -- --domain monitor.example.com
# It downloads and verifies a release bundle, unpacks it and runs the bundle's scripts/install.sh.
# HEPTAPOD_RELEASE_API and HEPTAPOD_RELEASE_DOWNLOAD select a mirror (scripts/release-image.sh documents
# both); HEPTAPOD_OS_RELEASE replaces /etc/os-release for tests.
# Everything runs inside main so that bash has read the whole piped script before any command reads stdin.
set -euo pipefail

main() {
    local release_api=${HEPTAPOD_RELEASE_API:-https://api.github.com/repos/heptapodwatch/heptapod}
    local release_download=${HEPTAPOD_RELEASE_DOWNLOAD:-https://github.com/heptapodwatch/heptapod/releases/download}
    local os_release=${HEPTAPOD_OS_RELEASE:-/etc/os-release}
    local tag_pattern='^v[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$'
    local version='' dir=/opt/heptapod install_docker=false domain_given=false
    local -a install_args=() given_args=("$@")

    while [ "$#" -gt 0 ]; do
        case "$1" in
            -h|--help)
                cat <<'HELP'
Usage: curl -fsSL https://heptapod.watch/install.sh | sudo bash -s -- --domain monitor.example.com [options]

Installs a published Heptapod release on Ubuntu or Debian (amd64) without Git:
downloads the release bundle, verifies its SHA-256 checksum, unpacks it into
--dir and runs its scripts/install.sh. A rerun on an existing installation
starts nothing and prints the upgrade command.

Options:
  --domain NAME         Public DNS name (required).
  --owner-email EMAIL   Create the first Owner.
  --without-browser     Disable browser services (recommended for 4 GB hosts).
  --version TAG         Release tag, e.g. v0.1.0 (default: the latest release).
  --dir PATH            Installation directory (default: /opt/heptapod).
  --install-docker      Install Docker Engine and Compose from Docker's apt repository if missing.
  --app-url URL, --behind-proxy, --port NUMBER
                        Passed to scripts/install.sh; see docs/deploy/operations.md.
HELP
                return 0 ;;
            --version) [ "$#" -ge 2 ] || fail '--version requires a release tag'; version=$2; shift 2 ;;
            --dir) [ "$#" -ge 2 ] && [ -n "$2" ] || fail '--dir requires a path'; dir=$2; shift 2 ;;
            --install-docker) install_docker=true; shift ;;
            --without-browser|--behind-proxy) install_args+=("$1"); shift ;;
            --domain|--owner-email|--app-url|--port)
                [ "$#" -ge 2 ] || fail "$1 requires a value"
                [ "$1" != --domain ] || domain_given=true
                install_args+=("$1" "$2"); shift 2 ;;
            *) fail "Unknown argument: $1 (see --help)" ;;
        esac
    done
    [ "$domain_given" = true ] || fail 'Usage: curl -fsSL https://heptapod.watch/install.sh | sudo bash -s -- --domain monitor.example.com'
    [ -z "$version" ] || [[ "$version" =~ $tag_pattern ]] || fail "Invalid --version $version (expected e.g. v0.1.0)"
    case "$dir" in /*) ;; *) fail '--dir must be an absolute path' ;; esac

    [ "$(id -u)" -eq 0 ] || fail 'Run as root: curl -fsSL https://heptapod.watch/install.sh | sudo bash -s -- …'
    local os_id='' os_codename=''
    if [ -r "$os_release" ]; then
        # shellcheck source=/dev/null
        os_id=$(. "$os_release" && printf '%s' "${ID:-}")
        # shellcheck source=/dev/null
        os_codename=$(. "$os_release" && printf '%s' "${VERSION_CODENAME:-}")
    fi
    case "$os_id" in
        ubuntu|debian) ;;
        *) fail "Unsupported operating system '${os_id:-unknown}'. This installer supports Ubuntu and Debian; elsewhere install Docker Engine and Compose v5 yourself and follow the manual installation in docs/deploy/operations.md." ;;
    esac
    [ "$(uname -m)" = x86_64 ] || fail "Unsupported architecture $(uname -m): published Heptapod images are amd64-only. Source builds are described in docs/deploy/operations.md."

    local quoted_dir
    printf -v quoted_dir %q "$dir"
    # A read-only look first: a foreign directory gets no lock file. Rechecked under the lock below.
    check_destination "$dir"
    # An existing installation is never downloaded over. Its own installer validates it and prints the next step.
    if [ "$installed" = true ]; then
        lock_destination "$dir"
        check_destination "$dir"
        echo "Heptapod is already installed in $dir (revision $(cat "$dir/REVISION")). To upgrade, run: cd $quoted_dir && sudo ./scripts/upgrade.sh"
        echo 'Validating the existing configuration; no services are started:'
        HEPTAPOD_LIFECYCLE_LOCK_FD=9 exec "$dir/scripts/install.sh" --revision "$(cat "$dir/REVISION")" "${install_args[@]}" < /dev/null
    fi

    local missing='' tool
    for tool in curl tar gzip sha256sum flock openssl jq; do
        command -v "$tool" >/dev/null 2>&1 || missing="$missing $tool"
    done
    if ! { command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; }; then
        if [ "$install_docker" = true ]; then
            install_docker_engine "$os_id" "$os_codename"
            missing=''
        else
            fail "Docker Engine with the Compose plugin is required. Install it from Docker's apt repository with this installer:
  curl -fsSL https://heptapod.watch/install.sh | sudo bash -s --$(printf ' %q' --install-docker "${given_args[@]}")
or follow https://docs.docker.com/engine/install/$os_id/ and run this installer again."
        fi
    elif [ "$install_docker" = true ]; then
        echo 'Docker Engine and Compose are already installed; --install-docker changes nothing.'
    fi
    [ -z "$missing" ] || fail "Missing required tools:$missing. Install them with: apt-get install -y ca-certificates curl tar gzip coreutils util-linux openssl jq"

    if [ -z "$version" ]; then
        version=$(curl -fsSL "$release_api/releases/latest" | grep -o '"tag_name": *"[^"]*"' | head -n 1 | sed 's/.*"\([^"]*\)"$/\1/') ||
            fail "Cannot read the latest release from $release_api/releases/latest; pass --version."
        [[ "$version" =~ $tag_pattern ]] || fail "The latest release has an unexpected tag '$version'; pass --version."
    fi
    echo "Installing Heptapod $version into $dir"

    # Hold the destination's lifecycle lock from publication through the installer, which takes it over.
    lock_destination "$dir"
    check_destination "$dir"
    [ "$installed" = false ] || fail "$dir was installed while this installer waited; nothing was changed. Run the installer again to validate that installation."

    local name=heptapod-$version.tar.gz file
    # Staged on the destination filesystem so that publication is a rename. Global: the EXIT trap runs after main has returned.
    stage=$(mktemp -d "$dir/.heptapod/bundle.XXXXXX")
    trap 'rm -rf "$stage"' EXIT
    for file in "$name" "$name.sha256"; do
        curl -fsSL -o "$stage/$file" "$release_download/$version/$file" || fail "Cannot download $release_download/$version/$file"
    done
    local expected actual
    expected=$(awk 'NR == 1 { print $1 }' "$stage/$name.sha256")
    actual=$(sha256sum "$stage/$name" | awk '{ print $1 }')
    [[ "$expected" =~ ^[a-f0-9]{64}$ ]] && [ "$actual" = "$expected" ] ||
        fail "Checksum mismatch for $name: expected $expected, got $actual. Nothing was installed."
    tar -xzf "$stage/$name" -C "$stage" --no-same-owner
    local bundle=$stage/heptapod-$version revision
    revision=$(cat "$bundle/REVISION")
    [[ "$revision" =~ ^[a-f0-9]{40}$ ]] || fail "$name has no valid REVISION. Nothing was installed."
    [ "$(cat "$bundle/RELEASE")" = "$version" ] || fail "$name has no RELEASE file for $version. Nothing was installed."
    [ ! -e "$bundle/.env.prod" ] && [ ! -e "$bundle/.heptapod" ] || fail "$name contains installation state. Nothing was installed."
    # Renames, never writes into existing files; REVISION last, so a partial publication is not a release.
    local bundle_file
    while IFS= read -r bundle_file; do
        mkdir -p "$dir/$(dirname -- "$bundle_file")"
        mv "$bundle/$bundle_file" "$dir/$bundle_file"
    done < <(cd "$bundle" && find . -type f ! -name REVISION | LC_ALL=C sort && echo ./REVISION)

    # The owner prompt reads the terminal; stdin is this piped script.
    if (exec < /dev/tty) 2>/dev/null; then
        HEPTAPOD_LIFECYCLE_LOCK_FD=9 "$dir/scripts/install.sh" --revision "$revision" "${install_args[@]}" < /dev/tty
    else
        HEPTAPOD_LIFECYCLE_LOCK_FD=9 "$dir/scripts/install.sh" --revision "$revision" "${install_args[@]}" < /dev/null
    fi
    echo "Heptapod $version is installed in $dir. Upgrade later with: cd $quoted_dir && sudo ./scripts/upgrade.sh"
}

# Sets installed=true for an earlier bundle installation; refuses any directory that is neither empty nor ours.
check_destination() {
    installed=false
    [ -d "$1" ] || return 0
    if [ -f "$1/.env.prod" ]; then
        [ -f "$1/REVISION" ] && [ -f "$1/scripts/install.sh" ] || fail "$1 has a .env.prod but is not a release bundle installation; nothing was changed."
        installed=true
    elif [ -n "$(find "$1" -mindepth 1 -maxdepth 1 ! -name .heptapod -print -quit)" ] && { [ ! -f "$1/REVISION" ] || [ ! -f "$1/compose.prod.yaml" ]; }; then
        fail "$1 is not empty and is not an earlier Heptapod release bundle; choose an empty --dir. Nothing was changed."
    fi
}

# The lock file of scripts/lifecycle-lock.sh, on fd 9: install, upgrade and rollback of this directory wait for us.
lock_destination() {
    mkdir -p "$1/.heptapod"
    chmod 700 "$1/.heptapod"
    exec 9> "$1/.heptapod/lifecycle.lock"
    flock -n 9 || fail "Another Heptapod install, upgrade or rollback is running in $1; nothing was changed."
}

fail() {
    echo "Error: $1" >&2
    exit 1
}

# Docker's apt repository, as in docs/deploy/hetzner.md "Fresh VPS prerequisites"; never a piped get.docker.com.
install_docker_engine() {
    local os_id=$1 codename=$2
    [ -n "$codename" ] || fail 'VERSION_CODENAME is missing from /etc/os-release; cannot select the Docker apt repository.'
    echo "Installing Docker Engine and Compose from https://download.docker.com/linux/$os_id ($codename)"
    apt-get update
    apt-get install -y ca-certificates curl tar gzip coreutils util-linux openssl jq
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL "https://download.docker.com/linux/$os_id/gpg" -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc
    echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/$os_id $codename stable" > /etc/apt/sources.list.d/docker.list
    apt-get update
    apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
    systemctl enable --now docker
}

main "$@"
