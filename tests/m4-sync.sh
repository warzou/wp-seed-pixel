#!/usr/bin/env bash
set -euo pipefail
ROOT="$HOME/.cache/wp-seed-pixel-m3-environment"
SRC=/mnt/c/Dev/git/wp-seed-pixel
test -d "$ROOT/project/.runtime/wordpress"
mkdir -p "$ROOT/project/.runtime/wordpress/wp-content/plugins/wp-seed-pixel"
cp -a "$SRC/includes" "$SRC/tests" "$SRC/assets" "$SRC/languages" "$SRC/tools" "$ROOT/project/"
cp "$SRC/wp-seed-pixel.php" "$SRC/uninstall.php" "$ROOT/project/"
cp -a "$SRC/includes" "$SRC/assets" "$SRC/languages" "$ROOT/project/.runtime/wordpress/wp-content/plugins/wp-seed-pixel/"
cp "$SRC/wp-seed-pixel.php" "$SRC/uninstall.php" "$ROOT/project/.runtime/wordpress/wp-content/plugins/wp-seed-pixel/"
if [ -d "$SRC/.runtime/fixtures" ]; then cp -a "$SRC/.runtime/fixtures" "$ROOT/project/.runtime/"; fi
