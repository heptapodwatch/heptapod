#!/usr/bin/env bash
# Collect only configured platforms from the native jobs' digest/config outputs.
set -euo pipefail
configuration=$(bash "$(dirname "$0")/architectures.sh")
platforms='[]'
while IFS= read -r architecture; do
  digest_key="${architecture^^}_DIGEST"
  config_key="${architecture^^}_SCANNED_CONFIG"
  digest=${!digest_key-}
  config=${!config_key-}
  if [[ ! $digest =~ ^sha256:[a-f0-9]{64}$ || ! $config =~ ^sha256:[a-f0-9]{64}$ ]]; then
    echo "::error::Missing or invalid validated digest/scanned config for $architecture" >&2
    exit 1
  fi
  platforms=$(jq -c --arg architecture "$architecture" --arg digest "$digest" --arg config "$config" \
    '. + [{architecture: $architecture, digest: $digest, scanned_config: $config}]' <<< "$platforms")
done < <(jq -r '.architectures[]' <<< "$configuration")
echo "$platforms"
