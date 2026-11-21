#!/usr/bin/env bash
# The repository variable is optional; an explicitly empty value is invalid.
set -euo pipefail
value=${HEPTAPOD_IMAGE_ARCHES-amd64,arm64}
if [[ ! $value =~ ^(amd64|arm64)(,(amd64|arm64))*$ ]]; then
  echo "::error::Invalid HEPTAPOD_IMAGE_ARCHES '$value'; expected a nonempty comma list of amd64, arm64" >&2
  exit 1
fi
jq -en --arg value "$value" '
  ($value | split(",") | unique) as $arches |
  def runner: if . == "amd64" then "ubuntu-latest" else "ubuntu-24.04-arm" end;
  {architectures: $arches,
   image_matrix: {include: [ ["app", "lighthouse"][] as $image | $arches[] | {
     image: $image, architecture: ., runner: runner,
     dockerfile: (if $image == "app" then "docker/prod/Dockerfile" else "docker/lighthouse/Dockerfile" end)
   }]},
   runtime_matrix: {include: [ ["postgres", "redis"][] as $service | $arches[] | {
     service: $service, architecture: ., runner: runner
   }]}}'
