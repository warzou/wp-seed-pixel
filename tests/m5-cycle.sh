#!/usr/bin/env bash
set -euo pipefail
SRC=/mnt/c/Dev/git/wp-seed-pixel
ROOT=/home/warzy/.cache/wp-seed-pixel-m3-environment
[ "$(realpath "$ROOT")" = "$ROOT" ] && [ ! -L "$ROOT" ]
case "${1:-}" in
  1) files=(m5-mixed.php m5-systemic.php m5-original.php m5-accounting-unknown.php) ;;
  2) files=(m5-mixed.php m5-order-security.php) ;;
  *) exit 1 ;;
esac
for file in "${files[@]}"; do bash "$SRC/tests/m4-linux-php.sh" "tests/$file"; done
mkdir -p "$ROOT/project/reports/storage-m5/cycle-$1"
for file in "${files[@]}"; do name="${file#m5-}"; cp "$ROOT/project/reports/storage-m5/${name%.php}.json" "$ROOT/project/reports/storage-m5/cycle-$1/"; done
