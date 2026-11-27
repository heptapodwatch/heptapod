#!/bin/sh
# Builds the static release assets and their SHA256SUMS. CI and the release workflow both use it.
# Usage: agent/build.sh <version without v> <output dir>
set -eu
[ $# -eq 2 ] || {
    echo "usage: $0 <version> <output dir>" >&2
    exit 2
}
version=$1
mkdir -p "$2"
out=$(cd "$2" && pwd)
cd "$(dirname "$0")"
for arch in amd64 arm64; do
    CGO_ENABLED=0 GOOS=linux GOARCH=$arch go build -trimpath -ldflags "-s -w -X main.version=$version" -o "$out/heptapod-agent-linux-$arch" .
done
cp install.sh "$out/install.sh"
(cd "$out" && sha256sum heptapod-agent-linux-amd64 heptapod-agent-linux-arm64 install.sh > SHA256SUMS)
