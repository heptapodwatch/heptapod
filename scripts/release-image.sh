#!/bin/sh
# Resolve a published commit, from Git or an explicit export revision. Never deploy a moving tag.
release_checkout() {
    release_revision=${1:-}
    if command -v git >/dev/null 2>&1 && release_sha=$(git rev-parse HEAD 2>/dev/null); then
        if [ -n "$release_revision" ] && [ "$release_revision" != "$release_sha" ]; then
            echo '--revision must match the Git checkout HEAD.' >&2
            exit 1
        fi
        release_dirty=$(git status --porcelain --untracked-files=no)
        if [ "${build:-false}" != true ] && [ -n "$release_dirty" ]; then
            echo 'Dirty checkout has no published image; use --build.' >&2
            exit 1
        fi
    else
        if ! printf '%s\n' "$release_revision" | grep -Eq '^[a-f0-9]{40}$'; then
            echo 'No Git revision found. Pass --revision with the full 40-character published commit SHA supplied with this release export, or fill it into the printed recovery block.' >&2
            exit 1
        fi
        release_sha=$release_revision
    fi
}

resolve_release_images() {
    release_browser=${1:?Pass the browser selection}
    default_app_repository=$(bash ./scripts/image-reference.sh app) || return 1
    registry=${HEPTAPOD_IMAGE_REGISTRY:-${default_app_repository%/*}}
    registry_host=${registry%%/*}
    case "$registry" in
        ''|*://*|/*|*/|*[!a-zA-Z0-9._:/-]*) echo 'Invalid HEPTAPOD_IMAGE_REGISTRY; use a registry/repository prefix without a scheme.' >&2; exit 1 ;;
    esac
    candidate_app=
    candidate_lighthouse=
    for component in app lighthouse; do
        [ "$component" != lighthouse ] || [ "$release_browser" = true ] || continue
        default_repository=$(bash ./scripts/image-reference.sh "$component") || return 1
        repository="$registry/${default_repository##*/}"
        if ! docker pull "$repository:$release_sha"; then
            echo "Cannot pull $repository:$release_sha: check registry access (docker login $registry_host for private packages), network/disk errors, and whether CI has published this main commit. Old stack untouched." >&2
            exit 1
        fi
        digests=$(docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' "$repository:$release_sha")
        digest=$(printf '%s\n' "$digests" | awk -v prefix="$repository@sha256:" 'index($0, prefix) == 1')
        if [ "$(printf '%s\n' "$digest" | wc -l | tr -d ' ')" != 1 ] || ! printf '%s\n' "${digest#"$repository@"}" | grep -Eq '^sha256:[a-f0-9]{64}$'; then
            echo "Expected exactly one release digest for $repository:$release_sha; got: $digests" >&2
            exit 1
        fi
        # Not "revision": this file is sourced by install.sh, whose global $revision holds the operator's --revision.
        image_revision=$(docker image inspect --format '{{index .Config.Labels "org.opencontainers.image.revision"}}' "$digest")
        [ "$image_revision" = "$release_sha" ] || { echo "Revision label mismatch for $digest: expected $release_sha, got $image_revision" >&2; exit 1; }
        case "$component" in app) candidate_app=$digest ;; lighthouse) candidate_lighthouse=$digest ;; esac
    done
}

validate_image_keys() {
    for image_key in APP_IMAGE LIGHTHOUSE_IMAGE; do
        image_mentions=$(grep -cE "(^|[^[:alnum:]_])$image_key([^[:alnum:]_]|$)" .env.prod || [ "$?" = 1 ])
        [ "$image_mentions" -le 1 ] || { echo "Ambiguous $image_key in .env.prod; remove duplicate mentions" >&2; exit 1; }
        case "$image_key" in
            APP_IMAGE) image_shell_set=${APP_IMAGE+x} ;;
            LIGHTHOUSE_IMAGE) image_shell_set=${LIGHTHOUSE_IMAGE+x} ;;
        esac
        [ "$image_shell_set" != x ] || { echo "Unset exported $image_key; release images are managed in .env.prod (empty exports also override pins)." >&2; exit 1; }
    done
}

pin_release_images() {
    # Compose accepts export, colon and whitespace dotenv spellings. Replace the whole managed definition.
    command -v sync >/dev/null 2>&1 || { echo 'sync (coreutils) is required to persist release pins.' >&2; exit 1; }
    pin_tmp=$(mktemp .env.prod.XXXXXX)
    awk -v app="$candidate_app" -v lighthouse="$candidate_lighthouse" '
        /^[[:space:]]*(export[[:space:]]+)?APP_IMAGE[[:space:]]*[=:]/ { print "APP_IMAGE=" app; app_seen=1; next }
        /^[[:space:]]*(export[[:space:]]+)?LIGHTHOUSE_IMAGE[[:space:]]*[=:]/ { if (lighthouse != "") { print "LIGHTHOUSE_IMAGE=" lighthouse; lighthouse_seen=1; next } }
        { print }
        END { if (!app_seen) print "APP_IMAGE=" app; if (lighthouse != "" && !lighthouse_seen) print "LIGHTHOUSE_IMAGE=" lighthouse }
    ' .env.prod > "$pin_tmp"
    chmod 600 "$pin_tmp"
    sync "$pin_tmp"
    mv "$pin_tmp" .env.prod
    sync .
}
