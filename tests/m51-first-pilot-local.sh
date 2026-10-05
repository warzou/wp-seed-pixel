#!/usr/bin/env bash
set -euo pipefail
ROOT=/home/warzy/.cache/wp-seed-pixel-m3-environment
SRC=/mnt/c/Dev/git/wp-seed-pixel
OUT="$SRC/reports/storage-m5.1/first-real-pilot/local-gate"
mkdir -p "$OUT"
cp "$SRC/tests/m51-first-pilot-local.php" "$ROOT/project/tests/"
cp "$SRC/reports/storage-m5.1/first-real-pilot/IMAGE-A-ORIGINAL.jpg" "$ROOT/project/.runtime/fixtures/pilot-A.jpg"
RUN="$SRC/tests/m4-linux-php.sh"
bash "$RUN" tests/storage-install.php
for f in m51-db-gate.php m51-locks.php m51-upload-budget.php m51-quarantine.php m51-crash.php m51-first-pilot-local.php; do
    bash "$RUN" "tests/$f"
done
cp "$ROOT/project/reports/storage-m5.1/authority-gate/schema-cas.json" "$OUT/"
cp "$ROOT/project/reports/storage-m5.1/"{locks,uploads-budget,quarantine,crash}.json "$OUT/"
cp "$ROOT/project/reports/storage-m5.1/first-real-pilot/local-photo.json" "$OUT/"
cd "$ROOT/project"
sha256sum wp-seed-pixel.php includes/*.php > "$OUT/runtime.sha256"
