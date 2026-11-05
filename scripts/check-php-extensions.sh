#!/bin/sh
set -eu

cd "$(dirname "$0")/.."
export LC_ALL=C
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT

awk 'NF && $1 !~ /^#/ { print $1 }' docker/php-extensions.txt | sort > "$work/expected"
php -m > "$work/modules"
awk '
    /^\[Zend Modules\]/ { exit }
    /^\[/ || !NF { next }
    { sub(/^Zend /, ""); print tolower($0) }
' "$work/modules" | sort > "$work/actual"

diff -u "$work/expected" "$work/actual"
printf 'PHP extensions match docker/php-extensions.txt\n'
