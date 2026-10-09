#!/usr/bin/env bash
set -euo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
LAB=/home/warzy/.cache/wp-seed-pixel-060-metadata-lab
PROJECT="$LAB/project/wp-seed-pixel-m3-environment"
number="${1:?cycle number}"
test "$number" = 1 || test "$number" = 2
bash "$HERE/metadata-lab.sh" sync
mkdir -p "$LAB/evidence/runtime-$number"
for test in metadata-wp metadata-admission metadata-pipeline metadata-graph-wp metadata-runtime-review m3 format-test format-profiles m51-locks metadata-graph-crashes; do
    args=()
    if test "$test" = metadata-wp; then args=(transaction); fi
    bash "$HERE/metadata-lab.sh" php "$PROJECT/tests/$test.php" "${args[@]}" > "$LAB/evidence/runtime-$number/$test.log" 2>&1
    tail -n 1 "$LAB/evidence/runtime-$number/$test.log"
done
bash "$HERE/metadata-lab.sh" updater > "$LAB/evidence/runtime-$number/updater.log" 2>&1
tail -n 1 "$LAB/evidence/runtime-$number/updater.log"
for name in transaction admission pipeline native-graph runtime-review graph-crashes updater profiles-dev; do
    if test -f "$LAB/evidence/$name.json"; then cp "$LAB/evidence/$name.json" "$LAB/evidence/runtime-$number/"; fi
done
echo "Runtime gates $number passed; install/update, browser and legacy gates remain separate."
