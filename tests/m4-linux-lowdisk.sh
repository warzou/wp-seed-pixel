#!/usr/bin/env bash
set -euo pipefail
ROOT=/home/warzy/.cache/wp-seed-pixel-m3-environment
SRC=/mnt/c/Dev/git/wp-seed-pixel
[ "$(id -u)" = 0 ] && [ -d "$ROOT/project/.runtime/wordpress" ] && [ ! -L "$ROOT" ]
IMAGE="$ROOT/m4-low-disk.img"
VOLUME="$ROOT/volume"
[ ! -e "$IMAGE" ] && [ ! -e "$VOLUME" ]
mkdir "$VOLUME"
mounted=0
cleanup() { if [ "$mounted" = 1 ]; then umount "$VOLUME"; fi; rm -f -- "$IMAGE"; rmdir -- "$VOLUME"; }
trap cleanup EXIT
truncate -s 96M "$IMAGE"
mkfs.ext4 -q -F "$IMAGE"
mount -o loop,nodev,nosuid,noexec "$IMAGE" "$VOLUME"
mounted=1
mkdir "$VOLUME/private" "$VOLUME/uploads"
chmod 700 "$VOLUME/private"
dd if=/dev/zero of="$VOLUME/owned-filler" bs=1M count=66 status=none
chown -R warzy:warzy "$VOLUME/private" "$VOLUME/uploads"
runuser -u warzy -- env PIXEL_M3_LOW_DISK=1 bash "$SRC/tests/m4-linux-php.sh" tests/m4-lowdisk.php
