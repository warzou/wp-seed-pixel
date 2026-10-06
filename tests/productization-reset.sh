#!/usr/bin/env bash
set -euo pipefail
ROOT=/home/warzy/.cache/wp-seed-pixel-m3-environment
test "$(realpath "$ROOT")" = "$ROOT"
test -S "$ROOT/mysql.sock"
for dir in "$ROOT/recovery" "$ROOT/project/.runtime/wordpress/wp-content/uploads"; do
 if [ -d "$dir" ]; then
  test ! -L "$dir" && test "$(realpath "$dir")" = "$dir"
  find "$dir" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
 fi
done
export LD_LIBRARY_PATH="$ROOT/root/usr/lib/x86_64-linux-gnu"
"$ROOT/root/usr/bin/mariadb" --no-defaults --socket="$ROOT/mysql.sock" -u root -e 'DROP DATABASE pixel_m3; CREATE DATABASE pixel_m3 CHARACTER SET utf8mb4;'
bash /mnt/c/Dev/git/wp-seed-pixel/tests/m4-sync.sh
exec bash /mnt/c/Dev/git/wp-seed-pixel/tests/productization-php.sh "$ROOT/project/tests/productization-install.php"
