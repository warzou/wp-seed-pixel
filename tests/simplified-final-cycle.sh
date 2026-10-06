#!/usr/bin/env bash
set -euo pipefail
ROOT=/home/warzy/.cache/wp-seed-pixel-m3-environment
SRC=/mnt/c/Dev/git/wp-seed-pixel
CYCLE="${1:?cycle required}"
PHASE="${2:?phase required}"
[[ "$CYCLE" = 1 || "$CYCLE" = 2 ]]
OUT="$SRC/reports/simplified-ux/cycle-$CYCLE"
mkdir -p "$OUT"
cd "$SRC"
sha256sum --quiet -c reports/simplified-ux/certification-candidate-6/runtime.sha256
cp reports/simplified-ux/certification-candidate-6/runtime.sha256 "$OUT/runtime.sha256"
run() {
 bash "$SRC/tests/productization-php.sh" "$ROOT/project/tests/$1.php" > "$OUT/$1.log" 2>&1 || { cat "$OUT/$1.log"; exit 1; }
 echo "$1 PASS"
}
collect() {
 cp -a "$ROOT/project/reports/storage-m4" "$ROOT/project/reports/storage-m5" "$ROOT/project/reports/storage-m5.1" "$OUT/"
 cp "$ROOT/project/reports/storage-v1/m6.json" "$OUT/m6.json"
 sha256sum --quiet -c reports/simplified-ux/certification-candidate-6/runtime.sha256
 echo "FROZEN CYCLE $CYCLE PASS"
}
if [ "$PHASE" = pre ]; then
 run storage-lab-reset
 PIXEL_SELF_SETUP=1 bash "$SRC/tests/productization-reset.sh" > "$OUT/reset.log" 2>&1
 run recovery-setup
 run simplified-ux
 cp "$ROOT/project/reports/simplified-ux/workflow.json" "$OUT/workflow.json"
elif [ "$PHASE" = post ]; then
 for name in m4 m6 m51-upload-budget m5-order-security m51-locks i18n media-status; do run "$name"; done
 run productization-old-version
 PIXEL_UPDATE_ZIP="$SRC/reports/simplified-ux/certification-candidate-6/wp-seed-pixel-0.4.0-private.6.zip" run updater-native
 collect
elif [ "$PHASE" = collect ]; then
 collect
else exit 2; fi
