#!/usr/bin/env bash
set -euo pipefail
ROOT="$HOME/.cache/wp-seed-pixel-m3-environment"
SRC=/mnt/c/Dev/git/wp-seed-pixel
export LD_LIBRARY_PATH="$ROOT/root/usr/lib/x86_64-linux-gnu"
export PIXEL_M3_DB="${PIXEL_M3_DB:-pixel_m3_regression}"
case "$PIXEL_M3_DB" in pixel_m3_regression|pixel_m3_final_regression) ;; *) exit 1 ;; esac
"$ROOT/root/usr/bin/mariadb" --no-defaults --socket="$ROOT/mysql.sock" -u root -e "CREATE DATABASE IF NOT EXISTS $PIXEL_M3_DB CHARACTER SET utf8mb4;"
cd "$ROOT/project"
RUN="$SRC/tests/m3-linux-php.sh"
if [ "${1:-}" != tail ]; then
"$RUN" tests/storage-install.php
"$RUN" tests/storage-analyzer.php
"$RUN" tests/storage-edge.php
UPLOADS="$ROOT/project/.runtime/wordpress/wp-content/uploads"
if [ "$PIXEL_M3_DB" = pixel_m3_final_regression ]; then UPLOADS="$ROOT/project/.runtime/wordpress/wp-content/regression-uploads"; fi
ln -s "$ROOT/project/.runtime/fixtures" "$UPLOADS/m1-link-test"
trap 'rm -f "$UPLOADS/m1-link-test"' EXIT
"$RUN" tests/storage-matrix.php
"$RUN" tests/storage-rescan.php
cp reports/storage-m1/integration.json .runtime/m1-integration.json
"$RUN" tests/jobs.php
"$RUN" tests/jobs-faults.php
fi
"$RUN" tests/i18n.php
"$RUN" tests/adaptive.php
"$RUN" tests/adaptive-adversarial.php
"$RUN" tests/color.php
echo 'Linux/MariaDB regression completed.'
