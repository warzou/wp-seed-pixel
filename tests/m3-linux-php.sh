#!/usr/bin/env bash
set -euo pipefail
ROOT="$HOME/.cache/wp-seed-pixel-m3-environment"
export LD_LIBRARY_PATH="$ROOT/root/usr/lib/x86_64-linux-gnu"
export PIXEL_M3_ROOT="$ROOT"
export MAGICK_CONFIGURE_PATH="$ROOT/root/etc/ImageMagick-7"
export MAGICK_CODER_MODULE_PATH="$ROOT/root/usr/lib/x86_64-linux-gnu/ImageMagick-7.1.2/modules-Q16/coders"
EXT="$ROOT/root/usr/lib/php/20250925"
export PHPRC="$ROOT/child.ini"
cd "$ROOT/project"
extra=()
if [ "${PIXEL_M3_NO_IMAGICK:-}" != 1 ]; then extra+=( -d "extension=$EXT/imagick.so" ); fi
php -d "extension=$EXT/gd.so" -d "extension=$EXT/mysqli.so" "${extra[@]}" -d log_errors=1 -d error_log=/dev/stderr -d error_reporting=24575 -d memory_limit=512M "$@"
