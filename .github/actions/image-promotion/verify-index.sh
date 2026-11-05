#!/usr/bin/env bash
set -euo pipefail
reference=$1
record=$2
configuration=$(bash "$(dirname "$0")/architectures.sh")
architectures=$(jq -c '.architectures' <<< "$configuration")
if ! jq -e --argjson architectures "$architectures" '
  def digest: type == "string" and test("^sha256:[a-f0-9]{64}$");
  ([.platforms[].architecture] | sort) == $architectures and
  (.index_digest | digest) and all(.platforms[]; (.digest | digest) and (.scanned_config | digest))' "$record" >/dev/null; then
  echo "::error::Candidate index must contain exactly the configured architectures $architectures with validated digests/configs" >&2
  exit 1
fi
digest=$(jq -er '.index_digest' "$record")
expected=$(jq -c '[.platforms[] | {platform: {os: "linux", architecture: .architecture}, digest}] | sort_by(.platform.architecture)' "$record")
docker buildx imagetools inspect "$reference@$digest" --raw | jq -e --argjson expected "$expected" '
  (.mediaType == "application/vnd.oci.image.index.v1+json" or .mediaType == "application/vnd.docker.distribution.manifest.list.v2+json") and
  ([.manifests[] | {platform: {os: .platform.os, architecture: .platform.architecture}, digest}] | sort_by(.platform.architecture)) == $expected'
while IFS=$'\t' read -r architecture platform_digest config; do
  observed=$(docker buildx imagetools inspect "$reference@$platform_digest" --raw | jq -er '.config.digest')
  test "$observed" = "$config"
  echo "$architecture: $platform_digest (scanned config $config)"
done < <(jq -r '.platforms[] | [.architecture, .digest, .scanned_config] | @tsv' "$record")
