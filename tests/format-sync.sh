#!/usr/bin/env bash
set -euo pipefail
ROOT="$HOME/.cache/wp-seed-pixel-m3-environment"
SRC=/mnt/c/Dev/git-worktrees/wp-seed-pixel-png-jpeg-explicit-conversion
test "$(realpath "$ROOT")" = /home/warzy/.cache/wp-seed-pixel-m3-environment
for TARGET in "$ROOT/project" "$ROOT/project/.runtime/wordpress/wp-content/plugins/wp-seed-pixel"; do
    cp -a "$SRC/includes" "$SRC/assets" "$SRC/languages" "$TARGET/"
    cp "$SRC/wp-seed-pixel.php" "$SRC/uninstall.php" "$TARGET/"
done
cp -a "$SRC/tests" "$ROOT/project/"
