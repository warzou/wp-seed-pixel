#!/usr/bin/env bash
set -euo pipefail
ROOT="$HOME/.cache/wp-seed-pixel-m3-environment"
SRC=/mnt/c/Dev/git/wp-seed-pixel
test "$(realpath "$ROOT")" = /home/warzy/.cache/wp-seed-pixel-m3-environment
UPLOADS="$ROOT/project/.runtime/wordpress/wp-content/regression-uploads"
if [ -e "$UPLOADS" ]; then
    test ! -L "$UPLOADS"
    test "$(realpath "$UPLOADS")" = "$UPLOADS"
    rm -rf -- "$UPLOADS"
fi
export LD_LIBRARY_PATH="$ROOT/root/usr/lib/x86_64-linux-gnu"
"$ROOT/root/usr/bin/mariadb" --no-defaults --socket="$ROOT/mysql.sock" -u root -e 'DROP DATABASE IF EXISTS pixel_m3_final_regression;'
bash "$SRC/tests/m3-final-regression.sh"
OUT="$ROOT/project/reports/storage-m4/regression"
mkdir -p "$OUT"
cp -a "$ROOT/project/reports/storage-m1" "$ROOT/project/reports/storage-m2" "$ROOT/project/reports/i18n" "$ROOT/project/reports/adaptive" "$ROOT/project/reports/color" "$OUT/"
