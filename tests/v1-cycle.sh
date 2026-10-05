#!/usr/bin/env bash
set -euo pipefail
SRC=/mnt/c/Dev/git/wp-seed-pixel
ROOT=/home/warzy/.cache/wp-seed-pixel-m3-environment
case "${1:-}" in 1|2) ;; *) exit 1 ;; esac
OUT="$SRC/reports/storage-v1/cycle-$1"
if [ -e "$OUT" ]; then
  test ! -L "$OUT" && test "$(realpath "$OUT")" = "$OUT"
  rm -rf -- "$OUT"
fi
mkdir -p "$OUT"
cd "$SRC"
sha256sum wp-seed-pixel.php uninstall.php includes/*.php assets/* languages/* > "$OUT/runtime-start.sha256"
EVIDENCE="$ROOT/project/reports"
if [ -d "$EVIDENCE" ]; then
  test ! -L "$EVIDENCE" && test "$(realpath "$EVIDENCE")" = "$EVIDENCE"
  find "$EVIDENCE" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
fi
bash "$SRC/tests/m4-sync.sh"
bash "$SRC/tests/m5-reset.sh"
RUN="$SRC/tests/m4-linux-php.sh"
for file in m51-db-gate.php m51-locks.php m51-upload-budget.php m51-quarantine.php m51-crash.php; do
  bash "$RUN" "tests/$file"
done
cp -a "$ROOT/project/reports/storage-m5.1" "$OUT/"
bash "$SRC/tests/m5-reset.sh"
for file in storage-analyzer.php storage-edge.php storage-rescan.php; do bash "$RUN" "tests/$file"; done
cp "$ROOT/project/reports/storage-m1/integration.json" "$ROOT/project/.runtime/m1-integration.json"
bash "$RUN" tests/m51-m2-fixtures.php
for file in jobs.php jobs-faults.php m3.php m3-faults.php m3-native.php; do bash "$RUN" "tests/$file"; done
bash "$SRC/tests/m4-cycle.sh" "$1"
for file in m5-mixed.php m5-order-security.php m5-systemic.php m5-accounting-unknown.php; do bash "$RUN" "tests/$file"; done
for dir in storage-m1 storage-m2 storage-m3 storage-m4 storage-m5; do cp -a "$ROOT/project/reports/$dir" "$OUT/"; done
bash "$SRC/tests/m5-reset.sh"
bash "$RUN" tests/m6.php
bash "$RUN" tests/v1-resources.php
cp "$ROOT/project/reports/storage-v1/m6.json" "$OUT/"
cp "$ROOT/project/reports/storage-v1/resources.json" "$OUT/"
bash "$SRC/tests/m5-stable-regression.sh"
for file in i18n.php adaptive.php adaptive-adversarial.php; do PIXEL_M3_NO_IMAGICK=1 bash "$SRC/tests/m3-linux-php.sh" "tests/$file" 2>"$OUT/${file%.php}-stderr.log"; done
for mode in 1 0; do PIXEL_M3_NO_IMAGICK="$mode" bash "$SRC/tests/m3-linux-php.sh" tests/color.php 2>"$OUT/color-$mode-stderr.log"; done
cp -a "$ROOT/project/reports/storage-m5/regression" "$OUT/stable-regression"
for dir in i18n adaptive color; do cp -a "$ROOT/project/reports/$dir" "$OUT/"; done
cd "$ROOT/project"
sha256sum wp-seed-pixel.php uninstall.php includes/*.php assets/* languages/* > "$OUT/runtime.sha256"
cmp "$OUT/runtime-start.sha256" "$OUT/runtime.sha256"
echo "V1 independent clean cycle $1 PASS"
