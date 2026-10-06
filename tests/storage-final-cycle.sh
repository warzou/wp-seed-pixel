#!/usr/bin/env bash
set -euo pipefail
ROOT=/home/warzy/.cache/wp-seed-pixel-m3-environment
PHP=/mnt/c/Dev/git/wp-seed-pixel/tests/productization-php.sh
OUT="${PIXEL_STORAGE_QA_OUT:?Explicit owned report directory required}"
case "$OUT" in /mnt/c/Dev/git/psychotherapiedeletre-site/reports/pixel-storage-self-configuration-20261006/cycle-*) ;; *) exit 2 ;; esac
mkdir -p "$OUT"
if [ "${1:-}" = first ]; then
 bash "$PHP" "$ROOT/project/tests/recovery-setup.php" > "$OUT/recovery.json"
 bash "$PHP" "$ROOT/project/tests/storage-ui-fixtures.php"
else
 bash "$PHP" "$ROOT/project/tests/storage-ui-after.php"
 bash "$PHP" "$ROOT/project/tests/productization-admin.php"
 cp "$ROOT/project/reports/productization/admin-native.json" "$OUT/"
 bash "$PHP" "$ROOT/project/tests/productization-formats.php"
 bash "$PHP" "$ROOT/project/tests/m4.php"
 cp "$ROOT/project/reports/storage-m4/integration.json" "$OUT/m4.json"
 bash "$PHP" "$ROOT/project/tests/m6.php"
 cp "$ROOT/project/reports/storage-v1/m6.json" "$OUT/"
 bash "$PHP" "$ROOT/project/tests/productization-old-version.php"
 export PIXEL_UPDATE_ZIP=/mnt/c/Dev/git/wp-seed-pixel/reports/storage-self-configuration/development-candidate-5/wp-seed-pixel-0.4.0-private.5.zip
 bash "$PHP" "$ROOT/project/tests/updater-native.php"
 cp "$ROOT/project/reports/productization/updater-native.json" "$OUT/"
 bash "$PHP" "$ROOT/project/tests/i18n.php"
 bash "$PHP" "$ROOT/project/tests/media-status.php"
 echo 'Scoped storage / M4 / PNG / JPEG / selected-batch / updater cycle PASS.'
fi
