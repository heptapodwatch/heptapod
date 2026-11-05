#!/bin/sh
# Dockerfile fragments expand at build time.
# shellcheck disable=SC2016
# Sourced by Deployment fixtures. All commits, images and registry data are disposable.
start_release_fixture() {
    fixture_root=$1
    fixture_app_base=$2
    fixture_lighthouse_base=${3:-}
    fixture_source=${root:?Caller must define the source checkout}
    # shellcheck source=scripts/lifecycle-lock.sh
    . "$fixture_source/scripts/lifecycle-lock.sh"
    # Shared with the calling fixture and its optional extended scenarios.
    # shellcheck disable=SC2034
    lifecycle_state="$fixture_root/$lifecycle_dir/upgrade"
    fixture_registry_name="${COMPOSE_PROJECT_NAME}-registry"
    fixture_registry_port=${DEPLOY_REGISTRY_PORT:-21500}
    HEPTAPOD_IMAGE_REGISTRY="127.0.0.1:$fixture_registry_port"
    export HEPTAPOD_IMAGE_REGISTRY
    # The compatibility API cannot configure a fixture-only HTTP registry. Keep the
    # exception local to these pull/push calls; production Docker commands stay unchanged.
    if docker version --format '{{range .Server.Components}}{{println .Name}}{{end}}' | grep -q '^Podman Engine$'; then
        command -v podman >/dev/null
        [ "$(podman info --format '{{.Store.GraphRoot}}')" = "$(docker info --format '{{.DockerRootDir}}')" ] || {
            echo 'Fixture Podman CLI and Docker endpoint must share the same image store.' >&2; return 1;
        }
        HEPTAPOD_FIXTURE_DOCKER=$(command -v docker)
        export HEPTAPOD_FIXTURE_DOCKER
        mkdir "$fixture_root/fixture-bin"
        cat > "$fixture_root/fixture-bin/docker" <<'SH'
#!/bin/sh
if [ "$#" = 2 ]; then
    case "$1:$2" in
        pull:"$HEPTAPOD_IMAGE_REGISTRY"/*|push:"$HEPTAPOD_IMAGE_REGISTRY"/*)
            exec podman "$1" --tls-verify=false "$2" ;;
    esac
fi
exec "$HEPTAPOD_FIXTURE_DOCKER" "$@"
SH
        chmod +x "$fixture_root/fixture-bin/docker"
        PATH="$fixture_root/fixture-bin:$PATH"
        export PATH
    fi
    docker run -d --name "$fixture_registry_name" -p "127.0.0.1:$fixture_registry_port:5000" \
        registry:2@sha256:a3d8aaa63ed8681a604f1dea0aa03f100d5895b6a58ace528858a7b332415373
    registry_attempt=0
    until curl -fsS "http://$HEPTAPOD_IMAGE_REGISTRY/v2/" >/dev/null 2>&1; do
        registry_attempt=$((registry_attempt + 1))
        [ "$registry_attempt" -lt 30 ] || break
        sleep 1
    done
    curl -fsS "http://$HEPTAPOD_IMAGE_REGISTRY/v2/" >/dev/null
    git -C "$fixture_root" init -q
    git -C "$fixture_root" config user.email fixture@example.test
    git -C "$fixture_root" config user.name 'Deployment fixture'
    printf '*\n' > "$fixture_root/.gitignore"
    mkdir -p "$fixture_root/release-layer/migrations"
    cp "$fixture_source/app/Console/Commands/DispatchChecksCommand.php" "$fixture_root/release-layer/DispatchChecksCommand.php"
    : > "$fixture_root/fixture.refs"
    unset APP_IMAGE LIGHTHOUSE_IMAGE
}

publish_fixture_release() {
    fixture_variant=$1
    printf '%s\n' "$fixture_variant" > "$fixture_root/release-marker"
    git -C "$fixture_root" add -f release-marker
    git -C "$fixture_root" commit -qm "$fixture_variant"
    fixture_sha=$(git -C "$fixture_root" rev-parse HEAD)
    cat > "$fixture_root/release-layer/Dockerfile" <<'DOCKERFILE'
ARG BASE=scratch
FROM ${BASE}
ARG REVISION
LABEL org.opencontainers.image.revision=$REVISION
COPY --chown=33:33 DispatchChecksCommand.php /app/app/Console/Commands/DispatchChecksCommand.php
COPY --chown=33:33 migrations/ /app/database/migrations/
DOCKERFILE
    fixture_app_repository=$(sh "$fixture_source/scripts/image-reference.sh" app)
    fixture_app_ref="$HEPTAPOD_IMAGE_REGISTRY/${fixture_app_repository##*/}:$fixture_sha"
    DOCKER_BUILDKIT=0 docker build --pull=false --build-arg "BASE=$fixture_app_base" --build-arg "REVISION=$fixture_sha" -t "$fixture_app_ref" "$fixture_root/release-layer"
    printf '%s\n' "$fixture_app_ref" >> "$fixture_root/fixture.refs"
    docker push "$fixture_app_ref"
    # Pull as a consumer, without build-time manifest aliases in RepoDigests (Podman converts OCI on push).
    docker image rm "$fixture_app_ref"
    docker pull "$fixture_app_ref"
    if [ -n "$fixture_lighthouse_base" ]; then
        printf 'ARG BASE=scratch\nFROM ${BASE}\nARG REVISION\nLABEL org.opencontainers.image.revision=$REVISION\n' > "$fixture_root/release-layer/Lighthouse.Dockerfile"
        fixture_lighthouse_repository=$(sh "$fixture_source/scripts/image-reference.sh" lighthouse)
        fixture_lighthouse_ref="$HEPTAPOD_IMAGE_REGISTRY/${fixture_lighthouse_repository##*/}:$fixture_sha"
        DOCKER_BUILDKIT=0 docker build --pull=false -f "$fixture_root/release-layer/Lighthouse.Dockerfile" --build-arg "BASE=$fixture_lighthouse_base" --build-arg "REVISION=$fixture_sha" -t "$fixture_lighthouse_ref" "$fixture_root/release-layer"
        printf '%s\n' "$fixture_lighthouse_ref" >> "$fixture_root/fixture.refs"
        docker push "$fixture_lighthouse_ref"
        docker image rm "$fixture_lighthouse_ref"
        docker pull "$fixture_lighthouse_ref"
    fi
}

stop_release_fixture() {
    docker rm -f -v "$fixture_registry_name" || return "$?"
    while IFS= read -r fixture_ref; do
        if docker image inspect "$fixture_ref" >/dev/null 2>&1; then
            if ! docker image rm "$fixture_ref"; then echo "WARNING: fixture image retained: $fixture_ref" >&2; fi
        else
            docker info >/dev/null
        fi
    done < "$fixture_root/fixture.refs"
}
