# Git invokes hooks in the committing checkout, not the common Git directory.
export LEFTHOOK_BIN="$(git rev-parse --show-toplevel)/scripts/lefthook-container.sh"
