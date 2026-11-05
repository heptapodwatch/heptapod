#!/bin/sh
# Run on the host: this checks Git's real container-backed hook boundary.
set -eu
root=$(git rev-parse --show-toplevel)
cd "$root"
if [ "${1:-}" = --commit-modes ]; then
    # Real commits must never move a developer's branch or discard their changes.
    if git symbolic-ref -q HEAD >/dev/null || [ -n "$(git status --porcelain)" ]; then
        echo 'Commit-mode smoke test needs a clean detached disposable checkout with its own stack.' >&2
        exit 1
    fi
    original_head=$(git rev-parse HEAD)
    probe=tests/HookFormattingProbe.php
    unrelated=tests/HookIndexUnrelated.txt
    [ ! -e "$probe" ] && [ ! -e "$unrelated" ]
    cleanup_commits() {
        git reset --hard -q "$original_head"
        rm -f "$probe" "$unrelated"
    }
    trap cleanup_commits EXIT HUP INT TERM
    printf '<?php\n\nnamespace Tests;\n\nfinal class HookFormattingProbe{public string $name="baseline";}\n' > "$probe"
    git add "$probe"
    git commit -m 'test: seed hook commit modes'
    for mode in all only; do
        printf '<?php\n\nnamespace Tests;\n\nfinal class HookFormattingProbe{public string $name="%s";}\n' "$mode" > "$probe"
        before=$(git hash-object "$probe")
        if [ "$mode" = all ]; then
            git commit -am 'test: verify hook commit all'
        else
            printf 'unrelated staging\n' > "$unrelated"
            git add "$unrelated"
            staged=$(git rev-parse ":$unrelated")
            git commit --only "$probe" -m 'test: verify hook commit only'
            [ "$(git rev-parse ":$unrelated")" = "$staged" ]
            [ "$(git diff --cached --name-only -- "$unrelated")" = "$unrelated" ]
            if git cat-file -e "HEAD:$unrelated" 2>/dev/null; then
                echo 'FAIL --only committed unrelated staging' >&2
                exit 1
            fi
        fi
        after=$(git hash-object "$probe")
        committed=$(git rev-parse "HEAD:$probe")
        [ "$before" != "$after" ] && [ "$committed" = "$after" ] || {
            echo "FAIL --$mode committed an unformatted blob" >&2
            git show "HEAD:$probe"
            exit 1
        }
        git diff --exit-code HEAD -- "$probe"
        echo "PASS --$mode committed the formatted blob $committed"
    done
    echo 'PASS --only preserved unrelated staged content'
    exit 0
fi
git diff --cached --quiet || { echo 'Hook smoke test needs an empty index.' >&2; exit 1; }
probe=tests/HookFormattingProbe.php
frontend_probe=tests/HookFormattingProbe.json
message="$(git rev-parse --absolute-git-dir)/HOOK_TEST_MSG"
[ ! -e "$probe" ] && [ ! -e "$frontend_probe" ] && [ ! -e "$message" ]
legacy_root=$(mktemp -d)
legacy="$legacy_root/checkout"
cleanup() {
    git reset -q -- "$probe" "$frontend_probe"
    rm -f "$probe" "$frontend_probe" "$message"
    if [ -e "$legacy/.git" ]; then
        git worktree remove --force "$legacy"
    fi
    rmdir "$legacy_root"
}
trap cleanup EXIT HUP INT TERM
# A checkout without the tooling config must not attempt any container lookup.
git worktree add --quiet --detach --no-checkout "$legacy" HEAD
for hook in pre-commit commit-msg; do
    output=$(git -C "$legacy" hook run "$hook" -- /dev/null 2>&1)
    [ "$output" = 'no lefthook.yml on this branch, hooks skipped' ]
done
output=$(cd "$legacy" && "$root/scripts/lefthook-container.sh" run pre-commit 2>&1)
[ "$output" = 'no lefthook.yml on this branch, hooks skipped' ]
git worktree remove --force "$legacy"
echo 'PASS both hooks skip unconfigured shared-hook checkouts with one notice'
printf '<?php\n\nnamespace Tests;\n\nfinal class HookFormattingProbe{public string $name="probe";}\n' > "$probe"
printf '{"hook":"probe"}\n' > "$frontend_probe"
git add "$probe" "$frontend_probe"
before=$(git show ":$probe" | git hash-object --stdin)
frontend_before=$(git show ":$frontend_probe" | git hash-object --stdin)
git hook run pre-commit
after=$(git show ":$probe" | git hash-object --stdin)
frontend_after=$(git show ":$frontend_probe" | git hash-object --stdin)
[ "$before" != "$after" ]
[ "$frontend_before" != "$frontend_after" ]
git diff --exit-code -- "$probe" "$frontend_probe"
[ "$(git diff --cached --name-only | wc -l | tr -d ' ')" = 2 ]
echo 'PASS pre-commit formatted and staged only the PHP and frontend probes'
printf 'invalid message\n' > "$message"
if git hook run commit-msg -- "$message"; then
    echo 'FAIL commit-msg accepted invalid message' >&2
    exit 1
fi
printf 'chore: verify container hooks\n' > "$message"
git hook run commit-msg -- "$message"
echo 'PASS commit-msg rejected invalid and accepted Conventional Commit'
# Select a nonexistent project without stopping any running checkout.
output=$(COMPOSE_PROJECT_NAME=heptapod-hook-down-probe git hook run commit-msg -- "$message" 2>&1) && {
    echo 'FAIL stack-down hook succeeded' >&2
    exit 1
}
printf '%s\n' "$output" | grep -F 'start the stack: docker compose up -d'
echo 'PASS stack-down hook failed with startup command'
