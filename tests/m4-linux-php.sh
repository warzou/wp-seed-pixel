#!/usr/bin/env bash
set -euo pipefail
export PIXEL_M4_TESTING=1
exec bash /mnt/c/Dev/git/wp-seed-pixel/tests/m3-linux-php.sh "$@"
