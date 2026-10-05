#!/usr/bin/env bash
set -euo pipefail
ROOT="$HOME/.cache/wp-seed-pixel-m3-environment"
[ "$(realpath "$ROOT")" = "$ROOT" ] && [ ! -L "$ROOT" ]
export LD_LIBRARY_PATH="$ROOT/mysql84/root/usr/lib/x86_64-linux-gnu:$ROOT/root/usr/lib/x86_64-linux-gnu"
"$ROOT/mysql84/root/usr/sbin/mysqld" --no-defaults --basedir="$ROOT/mysql84/root/usr" --datadir="$ROOT/mysql84-data" --socket="$ROOT/mysql.sock" --pid-file="$ROOT/mysql84.pid" --skip-networking --skip-grant-tables --secure-file-priv=NULL --log-error="$ROOT/mysql84-server.log" &
DBPID=$!
trap 'kill "$DBPID" 2>/dev/null || true; wait "$DBPID" 2>/dev/null || true' EXIT
for i in $(seq 1 30); do [ -S "$ROOT/mysql.sock" ] && break; sleep 1; done
export PIXEL_M3_ROOT="$ROOT"
export MAGICK_CONFIGURE_PATH="$ROOT/root/etc/ImageMagick-7"
export MAGICK_CODER_MODULE_PATH="$ROOT/root/usr/lib/x86_64-linux-gnu/ImageMagick-7.1.2/modules-Q16/coders"
EXT="$ROOT/root/usr/lib/php/20250925"
php -d "extension=$EXT/mysqlnd.so" -d "extension=$EXT/mysqli.so" -r '$c = mysqli_connect("localhost", "root", "", null, 0, getenv("PIXEL_M3_ROOT") . "/mysql.sock"); if (!$c || !$c->query("CREATE DATABASE IF NOT EXISTS pixel_m3 CHARACTER SET utf8mb4")) { exit(1); } $c->close();'
php -d "extension=$EXT/gd.so" -d "extension=$EXT/mysqlnd.so" -d "extension=$EXT/mysqli.so" -d "extension=$EXT/imagick.so" -d memory_limit=512M -S 127.0.0.1:8877 -t "$ROOT/project/.runtime/wordpress"
