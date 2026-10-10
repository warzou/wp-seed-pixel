#!/usr/bin/env bash
set -euo pipefail
ROOT=/home/warzy/.cache/wp-seed-pixel-060-metadata-lab
SOURCE=$(cd "$(dirname "$0")/.." && pwd)
test "$(realpath "$ROOT")" = /home/warzy/.cache/wp-seed-pixel-060-metadata-lab
test -S "$ROOT/mysql.sock"
TESTS="$ROOT/project/wp-seed-pixel-m3-environment/tests"
for name in metadata-alias-wp metadata-graph-wp metadata-admission metadata-pipeline metadata-runtime-review metadata-graph-crashes metadata-alias-crashes; do
    if ! bash "$SOURCE/tests/metadata-lab.sh" php "$TESTS/$name.php" > "$ROOT/evidence/$name-061.log" 2>&1; then
        tail -n 15 "$ROOT/evidence/$name-061.log"
        exit 1
    fi
    tail -n 1 "$ROOT/evidence/$name-061.log"
done
