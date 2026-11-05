#!/usr/bin/env bash
# Prints the dev image input hash for the working tree. Build with it, so scripts/dev-up.sh accepts the image:
#   DEV_IMAGE_INPUT_HASH=$(scripts/dev-image-hash.sh) docker compose build
set -euo pipefail

checkout=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)
cd "$checkout"
# shellcheck source=scripts/dev-stack-lib.sh
. "$checkout/scripts/dev-stack-lib.sh"
dev_image_input_hash
