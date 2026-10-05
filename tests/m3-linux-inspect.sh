#!/usr/bin/env bash
set -euo pipefail
R="$HOME/.cache/wp-seed-pixel-m3-environment/root"
export LD_LIBRARY_PATH="$R/usr/lib/x86_64-linux-gnu"
for f in "$R/usr/lib/php/20250925/imagick.so" "$R/usr/lib/php/20250925/gd.so" "$R/usr/sbin/mariadbd"; do
    ldd "$f" | grep 'not found' || true
done
php -d "extension=$R/usr/lib/php/20250925/gd.so" -d "extension=$R/usr/lib/php/20250925/mysqlnd.so" -d "extension=$R/usr/lib/php/20250925/mysqli.so" -d "extension=$R/usr/lib/php/20250925/imagick.so" -m
