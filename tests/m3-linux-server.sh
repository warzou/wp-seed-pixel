#!/usr/bin/env bash
set -euo pipefail
ROOT="$HOME/.cache/wp-seed-pixel-m3-environment"
export LD_LIBRARY_PATH="$ROOT/root/usr/lib/x86_64-linux-gnu"
"$ROOT/root/usr/sbin/mariadbd" --no-defaults --datadir="$ROOT/data" --basedir="$ROOT/root/usr" --lc-messages-dir="$ROOT/root/usr/share/mariadb" --socket="$ROOT/mysql.sock" --pid-file="$ROOT/mysql.pid" --skip-networking --skip-grant-tables --user="$(id -un)" --log-error="$ROOT/mysql.log" &
DBPID=$!
trap 'kill "$DBPID" 2>/dev/null || true; wait "$DBPID" 2>/dev/null || true' EXIT
for i in $(seq 1 30); do [ -S "$ROOT/mysql.sock" ] && break; sleep 1; done
"$ROOT/root/usr/bin/mariadb" --no-defaults --socket="$ROOT/mysql.sock" -u root -e 'CREATE DATABASE IF NOT EXISTS pixel_m3 CHARACTER SET utf8mb4;'
export PIXEL_M3_ROOT="$ROOT"
export PHP_CLI_SERVER_WORKERS=4
export MAGICK_CONFIGURE_PATH="$ROOT/root/etc/ImageMagick-7"
export MAGICK_CODER_MODULE_PATH="$ROOT/root/usr/lib/x86_64-linux-gnu/ImageMagick-7.1.2/modules-Q16/coders"
EXT="$ROOT/root/usr/lib/php/20250925"
php -d "extension=$EXT/gd.so" -d "extension=$EXT/mysqlnd.so" -d "extension=$EXT/mysqli.so" -d "extension=$EXT/imagick.so" -d "extension=$EXT/zip.so" -d memory_limit=512M -S 127.0.0.1:8877 -t "$ROOT/project/.runtime/wordpress"
