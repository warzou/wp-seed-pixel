#!/usr/bin/env bash
set -euo pipefail
ROOT=/home/warzy/.cache/wp-seed-pixel-m3-environment/project/reports
DEST=/mnt/c/Dev/git/wp-seed-pixel/reports/storage-m5.1
[ "$(realpath "$ROOT")" = "$ROOT" ] && [ ! -L "$ROOT" ]
[ "$(realpath "$DEST")" = "$DEST" ] && [ ! -L "$DEST" ]
cmp "$ROOT/storage-m5.1/cycle-1/runtime.sha256" "$ROOT/storage-m5.1/cycle-2/runtime.sha256"
for cycle in 1 2; do
    mkdir -p "$DEST/cycle-$cycle"
    cp "$ROOT/storage-m5.1/cycle-$cycle/"*.json "$ROOT/storage-m5.1/cycle-$cycle/runtime.sha256" "$DEST/cycle-$cycle/"
done
cp "$ROOT/storage-m5.1/"*.json "$DEST/"
for suite in storage-m1 storage-m2 storage-m3 storage-m4 storage-m5 i18n color final; do
    mkdir -p "$DEST/regression/$suite"
    for file in "$ROOT/$suite/"*.json; do
        [ ! -f "$file" ] || cp "$file" "$DEST/regression/$suite/"
    done
done
mkdir -p "$DEST/regression/adaptive" "$DEST/regression/stable-upgrade"
cp "$ROOT/adaptive/data/"*.json "$DEST/regression/adaptive/"
cp "$ROOT/storage-m5/regression/upgrade.json" "$DEST/regression/stable-upgrade/"
printf 'Synthetic JSON evidence exported; two runtime manifests identical.\n'
