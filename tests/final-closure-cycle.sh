#!/usr/bin/env bash
set -euo pipefail
SRC=/mnt/c/Dev/git/wp-seed-pixel
ROOT=/home/warzy/.cache/wp-seed-pixel-m3-environment
CYCLE="${1:?cycle required}"
PHASE="${2:?phase required}"
[[ "$CYCLE" = 1 || "$CYCLE" = 2 ]]
OUT="$SRC/reports/final-closure-20261006/cycle-$CYCLE"
mkdir -p "$OUT"
python3 "$SRC/tests/final-closure-package.py" > "$OUT/package-before-$PHASE.json"
run() {
    bash "$SRC/tests/productization-php.sh" "$ROOT/project/tests/$1.php" > "$OUT/$1.log" 2>&1 || { cat "$OUT/$1.log"; exit 1; }
    echo "$1 PASS"
}
if [ "$PHASE" = core ]; then
    test "$(realpath "$ROOT/project/.runtime/wordpress")" = "$ROOT/project/.runtime/wordpress"
    cp "$SRC/tests/m3-config.php" "$ROOT/project/.runtime/wordpress/wp-config.php"
    bash "$SRC/tests/v1-cycle.sh" "$CYCLE" > "$OUT/core.log" 2>&1 || { tail -n 50 "$OUT/core.log"; exit 1; }
    echo "Full core cycle $CYCLE PASS"
elif [ "$PHASE" = ux ]; then
    bash "$SRC/tests/m4-sync.sh"
    PIXEL_RESET_ALLOW_ABSENT=1 run storage-lab-reset
    PIXEL_SELF_SETUP=1 bash "$SRC/tests/productization-reset.sh" > "$OUT/reset.log" 2>&1
    run recovery-setup
    run simplified-ux
    cp "$ROOT/project/reports/simplified-ux/workflow.json" "$OUT/workflow.json"
elif [ "$PHASE" = post ]; then
    bash "$SRC/tests/m4-sync.sh"
    for name in unstarted-review-claim dense-media-inventory unreplaced-cleanup m4 m6 m51-upload-budget m5-order-security m51-locks i18n media-status; do run "$name"; done
    run productization-old-version
    PIXEL_UPDATE_ZIP="$SRC/reports/final-closure-20261006/candidate/wp-seed-pixel-0.4.0.zip" run updater-native
    cp -a "$ROOT/project/reports/storage-m4" "$ROOT/project/reports/storage-m5" "$ROOT/project/reports/storage-m5.1" "$OUT/"
    cp "$ROOT/project/reports/storage-v1/m6.json" "$OUT/m6.json"
else exit 2; fi
python3 "$SRC/tests/final-closure-package.py" > "$OUT/package-after-$PHASE.json"
echo "Frozen $PHASE cycle $CYCLE PASS"
