#!/usr/bin/env bash
# Writes resources/fonts/: Geist and Geist Mono with stylistic set ss01 ("No tail a") frozen in,
# so every surface gets the tail-less a without CSS feature settings. Source: Vercel's geist
# package (Fontsource strips the stylistic sets). Needs npm and uv. Run: scripts/build-fonts.sh
set -euo pipefail

GEIST_VERSION=1.7.2
out="$(cd "$(dirname "$0")/.." && pwd)/resources/fonts"
work="$(mktemp -d)"
trap 'rm -r "$work"' EXIT

npm pack "geist@$GEIST_VERSION" --pack-destination "$work" --silent >/dev/null
tar -xzf "$work/geist-$GEIST_VERSION.tgz" -C "$work"
fonts="$work/package/dist/fonts"
mkdir -p "$out"
cp "$work/package/LICENSE.txt" "$out/LICENSE.txt"

for font in geist-sans/Geist-Variable geist-mono/GeistMono-Variable; do
  name="$(basename "$font")"
  uvx -q --from opentype-feature-freezer --with brotli \
    pyftfeatfreeze -f ss01 "$fonts/$font.woff2" "$work/$name.ttf"
  uvx -q --from fonttools --with brotli python -c \
    "import sys; from fontTools.ttLib import TTFont; f = TTFont(sys.argv[1]); f.flavor = 'woff2'; f.save(sys.argv[2])" \
    "$work/$name.ttf" "$out/$name.woff2"
done
echo "wrote $out"
