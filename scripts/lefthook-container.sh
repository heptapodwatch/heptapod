#!/bin/sh
set -eu
root=$(git rev-parse --show-toplevel)
cd "$root"
if [ ! -f lefthook.yml ]; then
    echo 'no lefthook.yml on this branch, hooks skipped'
    exit 0
fi
# Git in the mounted app workspace already supplies container paths and the active index.
if [ "$root" = /workspace ] && { [ -f /.dockerenv ] || [ -f /run/.containerenv ] || [ -f /.containerenv ]; }; then
    if [ "${1:-}" = run ]; then
        set -- "$@" --no-auto-install
    fi
    exec node_modules/.bin/lefthook "$@"
fi
# Optional local Docker credential workaround; normal Docker installs need no override.
if [ -d "$HOME/.local/share/heptapod-docker" ]; then
    export DOCKER_CONFIG="$HOME/.local/share/heptapod-docker"
    export PATH="$DOCKER_CONFIG/bin:$PATH"
fi
if ! docker compose exec -T app true; then
    echo "Heptapod hooks need this checkout's app container; start the stack: docker compose up -d" >&2
    exit 1
fi
if [ "${1:-}" = run ] && [ "${2:-}" = pre-push ]; then
    docker compose exec -T app mkdir -p .phpunit.cache
    # A new test can still start between this check and the fixture recreation.
    if ! docker compose exec -T app flock -n .phpunit.cache/pest.lock true; then
        echo 'another Pest run is active in this checkout — wait or stop it' >&2
        exit 1
    fi
    docker compose --profile fixtures up -d --force-recreate --wait fixtures fixtures-alt
fi
git_dir=$(git rev-parse --absolute-git-dir)
common_dir=$(git rev-parse --path-format=absolute --git-common-dir)
case "$git_dir" in
    "$common_dir") container_git_dir=/git-common ;;
    "$common_dir"/*) container_git_dir="/git-common/${git_dir#"$common_dir"/}" ;;
    *) echo "Unsupported Git directory: $git_dir" >&2; exit 1 ;;
esac
# Commit modes such as --all and --only use a temporary index. Stage fixes there.
index=$(git rev-parse --path-format=absolute --git-path index)
case "$index" in
    "$common_dir"/*) container_index="/git-common/${index#"$common_dir"/}" ;;
    "$root"/*) container_index="/workspace/${index#"$root"/}" ;;
    *) echo "Unsupported Git index: $index" >&2; exit 1 ;;
esac
# commit-msg can pass a host absolute path outside the checkout in linked worktrees.
if [ "${1:-}" = run ] && [ "${2:-}" = commit-msg ]; then
    message=$3
    case "$message" in
        "$common_dir"/*) message="/git-common/${message#"$common_dir"/}" ;;
        "$root"/*) message="/workspace/${message#"$root"/}" ;;
    esac
    set -- run commit-msg "$message"
fi
# Explicit installation preserves the guard for older shared-hook checkouts.
if [ "${1:-}" = run ]; then
    set -- "$@" --no-auto-install
fi
exec docker compose exec -T -e GIT_DIR="$container_git_dir" -e GIT_WORK_TREE=/workspace -e GIT_INDEX_FILE="$container_index" app node_modules/.bin/lefthook "$@"
