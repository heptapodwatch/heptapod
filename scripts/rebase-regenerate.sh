#!/usr/bin/env bash
set -euo pipefail

if [[ $(git rev-parse --is-inside-work-tree) != true || ! -d "$(git rev-parse --git-path rebase-merge)" && ! -d "$(git rev-parse --git-path rebase-apply)" ]]; then
    echo 'Run this script during an active rebase.' >&2
    exit 1
fi

mapfile -d '' conflicts < <(git diff --name-only --diff-filter=U -z)
if (( ${#conflicts[@]} == 0 )); then
    echo 'No unresolved conflicts.' >&2
    exit 1
fi

for path in "${conflicts[@]}"; do
    case "$path" in
        resources/js/actions/*|resources/js/routes/*|resources/js/wayfinder/*|resources/js/types/generated.d.ts) ;;
        composer.lock)
            if ! jq -e -s 'map(del(."content-hash")) | .[0] == .[1]' \
                <(git show :2:composer.lock) <(git show :3:composer.lock) >/dev/null; then
                echo 'composer.lock differs beyond content-hash; resolve it manually.' >&2
                exit 1
            fi
            ;;
        *) echo "Non-generated conflict: $path (resolve it manually)." >&2; exit 1 ;;
    esac
done

for path in "${conflicts[@]}"; do
    git checkout --ours -- "$path"
done

if printf '%s\n' "${conflicts[@]}" | grep -qx composer.lock; then
    docker compose exec app composer update --lock --no-interaction
    git add -- composer.lock
fi
if printf '%s\n' "${conflicts[@]}" | grep -Eq '^resources/js/'; then
    docker compose exec app php artisan wayfinder:generate --with-form --no-interaction
    docker compose exec app composer types:generate
    for directory in resources/js/actions resources/js/routes resources/js/wayfinder; do
        if [[ -d $directory ]]; then
            git add -A -- "$directory"
        fi
    done
    if [[ -e resources/js/types/generated.d.ts ]]; then
        git add -A -- resources/js/types/generated.d.ts
    fi
fi

if git diff --name-only --diff-filter=U | grep -q .; then
    echo 'Unresolved conflicts remain; rebase not continued.' >&2
    exit 1
fi
GIT_EDITOR=true git rebase --continue
