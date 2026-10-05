#!/usr/bin/env bash
set -euo pipefail
ROOT="$HOME/.cache/wp-seed-pixel-m3-environment"
test "$(realpath "$ROOT")" = /home/warzy/.cache/wp-seed-pixel-m3-environment
test -f "$ROOT/project/.runtime/wordpress/wp-config.php"
test ! -L "$ROOT/private"
test "$(realpath "$ROOT/private")" = "$ROOT/private"
# This owned disposable recovery tree and its database must reset together.
find "$ROOT/private" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
UPLOADS="$ROOT/project/.runtime/wordpress/wp-content/uploads"
if [ -d "$UPLOADS" ]; then
    test ! -L "$UPLOADS" && test "$(realpath "$UPLOADS")" = "$UPLOADS"
    find "$UPLOADS" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
fi
export LD_LIBRARY_PATH="$ROOT/root/usr/lib/x86_64-linux-gnu"
"$ROOT/root/usr/bin/mariadb" --no-defaults --socket="$ROOT/mysql.sock" -u root -e 'DROP DATABASE IF EXISTS pixel_m3; CREATE DATABASE pixel_m3 CHARACTER SET utf8mb4;'
exec bash /mnt/c/Dev/git/wp-seed-pixel/tests/m4-linux-php.sh tests/storage-install.php
