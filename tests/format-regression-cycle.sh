#!/usr/bin/env bash
set -euo pipefail
ROOT="$HOME/.cache/wp-seed-pixel-m3-environment"
SRC=/mnt/c/Dev/git-worktrees/wp-seed-pixel-png-jpeg-explicit-conversion
test "$(realpath "$ROOT")" = /home/warzy/.cache/wp-seed-pixel-m3-environment
export LD_LIBRARY_PATH="$ROOT/root/usr/lib/x86_64-linux-gnu"
"$ROOT/root/usr/bin/mariadb" --no-defaults --socket="$ROOT/mysql.sock" -u root -e 'DROP DATABASE pixel_m3; CREATE DATABASE pixel_m3 CHARACTER SET utf8mb4;'
for TARGET in "$ROOT/recovery" "$ROOT/project/.runtime/wordpress/wp-content/uploads"; do
    test ! -L "$TARGET"
    [[ "$(realpath -m "$TARGET")" = "$ROOT/"* ]]
    rm -rf -- "$TARGET"
done
mkdir -m 0700 "$ROOT/recovery"
bash "$SRC/tests/format-sync.sh"
bash "$SRC/tests/productization-php.sh" "$ROOT/project/tests/productization-install.php"
bash "$SRC/tests/productization-php.sh" "$ROOT/project/tests/m5.php"
