#!/usr/bin/env bash
set -euo pipefail
ROOT="$HOME/.cache/wp-seed-pixel-m3-environment"
export LD_LIBRARY_PATH="$ROOT/root/usr/lib/x86_64-linux-gnu"
export PIXEL_M3_ROOT="$ROOT"
export MAGICK_CONFIGURE_PATH="$ROOT/root/etc/ImageMagick-7"
export MAGICK_CODER_MODULE_PATH="$ROOT/root/usr/lib/x86_64-linux-gnu/ImageMagick-7.1.2/modules-Q16/coders"
EXT="$ROOT/root/usr/lib/php/20250925"
exec php -d "extension=$EXT/gd.so" -d "extension=$EXT/mysqlnd.so" -d "extension=$EXT/mysqli.so" -d "extension=$EXT/imagick.so" -d "extension=$EXT/zip.so" -d memory_limit=512M "$@"
