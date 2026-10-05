#!/usr/bin/env bash
set -euo pipefail
ROOT=/home/warzy/.cache/wp-seed-pixel-m3-environment
SRC=/mnt/c/Dev/git/wp-seed-pixel
[ "$(id -u)" = 0 ] && [ -d "$ROOT/project/.runtime/wordpress" ] && [ ! -L "$ROOT" ]
IMAGE="$ROOT/m5-low-disk.img"
VOLUME="$ROOT/volume"
PUBLIC="$ROOT/project/.runtime/wordpress/m5-lowdisk-uploads"
[ ! -e "$IMAGE" ] && [ ! -e "$VOLUME" ]
[ ! -e "$PUBLIC" ] && [ ! -L "$PUBLIC" ]
mkdir "$VOLUME"
mounted=0
cleanup() { if [ -L "$PUBLIC" ] && [ "$(readlink "$PUBLIC")" = "$VOLUME/uploads" ]; then rm -- "$PUBLIC"; fi; if [ "$mounted" = 1 ]; then umount "$VOLUME"; fi; rm -f -- "$IMAGE"; rmdir -- "$VOLUME"; }
trap cleanup EXIT
truncate -s 160M "$IMAGE"
mkfs.ext4 -q -F "$IMAGE"
mount -o loop,nodev,nosuid,noexec "$IMAGE" "$VOLUME"
mounted=1
mkdir "$VOLUME/private" "$VOLUME/uploads"
chmod 700 "$VOLUME/private"
chown -R warzy:warzy "$VOLUME/private" "$VOLUME/uploads"
ln -s "$VOLUME/uploads" "$PUBLIC"
runuser -u warzy -- env PIXEL_M3_LOW_DISK=1 bash "$SRC/tests/m4-linux-php.sh" tests/m5-lowdisk.php
