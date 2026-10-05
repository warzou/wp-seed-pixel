#!/usr/bin/env bash
set -euo pipefail
SRC=/mnt/c/Dev/git/wp-seed-pixel
ROOT=/home/warzy/.cache/wp-seed-pixel-m3-environment
[ "$(realpath "$ROOT")" = "$ROOT" ] && [ ! -L "$ROOT" ]
RUN="$SRC/tests/m3-linux-php.sh"
export PIXEL_M3_NO_IMAGICK=1
export PIXEL_M3_DB=pixel_m3_regression
export LD_LIBRARY_PATH="$ROOT/root/usr/lib/x86_64-linux-gnu"
"$ROOT/root/usr/bin/mariadb" --no-defaults --socket="$ROOT/mysql.sock" -u root -e 'DROP DATABASE IF EXISTS pixel_m3_regression; CREATE DATABASE pixel_m3_regression CHARACTER SET utf8mb4;'
bash "$RUN" tests/storage-install.php
mkdir -p "$ROOT/project/reports/final"
for file in integration.php adversarial.php lifecycle.php; do bash "$RUN" "tests/$file"; done
# Swap only the disposable plugin tree, not the original archive or source checkout.
PLUGIN="$ROOT/project/.runtime/wordpress/wp-content/plugins/wp-seed-pixel"
test "$(realpath "$PLUGIN")" = "$PLUGIN" && test ! -L "$PLUGIN"
trap 'bash "$SRC/tests/m4-sync.sh"' EXIT
rm -rf -- "$PLUGIN"
python3 -m zipfile -e "$SRC/dist/wp-seed-pixel-0.3.2.zip" "$(dirname "$PLUGIN")"
bash "$RUN" tests/m5-upgrade.php seed
bash "$SRC/tests/m4-sync.sh"
bash "$RUN" tests/m5-upgrade.php verify
mkdir -p "$ROOT/project/reports/storage-m5/regression/stable"
cp "$ROOT/project/reports/final/"*.json "$ROOT/project/reports/storage-m5/regression/stable/"
