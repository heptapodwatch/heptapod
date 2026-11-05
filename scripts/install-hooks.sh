#!/bin/sh
# Run inside app; Git itself continues to invoke these hooks on the host.
set -eu
if [ -f .git ]; then
    # Linked-worktree .git files contain host paths; use the shared metadata mount.
    host_git_dir=$(sed -n 's/^gitdir: //p' .git)
    export GIT_DIR="/git-common/worktrees/$(basename "$host_git_dir")"
    export GIT_WORK_TREE=/workspace
    if [ ! -f "$GIT_DIR/commondir" ]; then
        echo "Set GIT_COMMON_DIR in this worktree's .env, then recreate its stack: docker compose up -d" >&2
        exit 1
    fi
fi
node_modules/.bin/lefthook install
hooks=$(git rev-parse --git-path hooks)
for name in pre-commit pre-push commit-msg; do
    hook="$hooks/$name"
    # Older checkouts share these hooks before they contain the container tooling.
    awk 'NR == 1 {
        print
        print "if [ ! -f \"$(git rev-parse --show-toplevel)/lefthook.yml\" ]; then"
        print "    echo \"no lefthook.yml on this branch, hooks skipped\""
        print "    exit 0"
        print "fi"
        next
    } { print }' "$hook" > "$hook.tmp"
    mv "$hook.tmp" "$hook"
    chmod +x "$hook"
done
