#!/usr/bin/env bash
set -euo pipefail
export PIXEL_M3_DB=pixel_m3_final_regression
export PIXEL_M3_NO_IMAGICK=1
exec bash /mnt/c/Dev/git/wp-seed-pixel/tests/m3-linux-regression.sh
