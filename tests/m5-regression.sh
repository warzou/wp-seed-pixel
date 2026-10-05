#!/usr/bin/env bash
set -euo pipefail
SRC=/mnt/c/Dev/git/wp-seed-pixel
ROOT=/home/warzy/.cache/wp-seed-pixel-m3-environment
[ "$(realpath "$ROOT")" = "$ROOT" ] && [ ! -L "$ROOT" ]
RUN="$SRC/tests/m4-linux-php.sh"
case "${1:-}" in
  '') bash "$SRC/tests/m4-regression.sh" ;;
  --after-base) test -d "$ROOT/project/reports/storage-m4/regression/storage-m2" ;;
  --stable-only) test -f "$ROOT/project/reports/storage-m4/native.json" ;;
  --collect-only) test -f "$ROOT/project/reports/storage-m5/regression/upgrade.json" ;;
  *) exit 1 ;;
esac
if [ "${1:-}" != --stable-only ] && [ "${1:-}" != --collect-only ]; then
  bash "$SRC/tests/m5-reset.sh"
  for file in m3.php m3-faults.php m3-native.php; do bash "$SRC/tests/m3-linux-php.sh" "tests/$file"; done
  bash "$SRC/tests/m4-cycle.sh" 1
fi
# Stable derivative/public API regressions, no consuming plugin or real media.
if [ "${1:-}" != --collect-only ]; then bash "$SRC/tests/m5-stable-regression.sh"; fi
OUT="$ROOT/project/reports/storage-m5/regression"
mkdir -p "$OUT"
for milestone in storage-m1 storage-m2 storage-m3 storage-m4 adaptive color i18n; do
  mkdir -p "$OUT/$milestone"
  find "$ROOT/project/reports/$milestone" -maxdepth 1 -name '*.json' -exec cp -- {} "$OUT/$milestone/" \;
  if [ "$milestone" = adaptive ]; then cp "$ROOT/project/reports/adaptive/data/"*.json "$OUT/adaptive/"; fi
done
