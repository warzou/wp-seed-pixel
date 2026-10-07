#!/usr/bin/env bash
set -euo pipefail
# Local extracted dependencies only: no host packages or system services installed.
ROOT=/home/warzy/.cache/wp-seed-pixel-051-updater-lab
SRC=$(cd "$(dirname "$0")/.." && pwd)
case "${1:-}" in
prepare)
  test ! -e "$ROOT"
  mkdir -p "$ROOT"/{apt/lists/partial,apt/cache/archives/partial,packages,root,project/.runtime,private,data}
  chmod 700 "$ROOT" "$ROOT/private"
  OPT=(-o "Dir::State::lists=$ROOT/apt/lists" -o "Dir::Cache=$ROOT/apt/cache" -o APT::Get::List-Cleanup=0)
  apt-get "${OPT[@]}" update > "$ROOT/apt.log" 2>&1
  cd "$ROOT/packages"
  apt-get "${OPT[@]}" download php8.5-mysql php8.5-zip php8.5-gd libgd3 libjpeg-turbo8 libpng16-16t64 libwebp7 libavif16 libxpm4 libaom3 libdav1d7 mariadb-server-core mariadb-client-core mariadb-server mariadb-common libzip5 libaio1t64 liburing2 libnuma1 libncurses6 libtinfo6 > "$ROOT/download.log" 2>&1
  for p in ./*.deb; do dpkg-deb -x "$p" "$ROOT/root"; done
  curl --fail --max-time 120 https://wordpress.org/wordpress-7.1.2.tar.gz -o "$ROOT/wordpress.tar.gz"
  tar -xzf "$ROOT/wordpress.tar.gz" -C "$ROOT/project/.runtime"
  cp -a "$SRC/tests" "$ROOT/project/"
  export LD_LIBRARY_PATH="$ROOT/root/usr/lib/x86_64-linux-gnu"
  { printf 'CREATE DATABASE IF NOT EXISTS mysql;\nUSE mysql;\n'; cat "$ROOT/root/usr/share/mariadb/mariadb_system_tables.sql"; } | "$ROOT/root/usr/sbin/mariadbd" --no-defaults --bootstrap --datadir="$ROOT/data" --basedir="$ROOT/root/usr" --lc-messages-dir="$ROOT/root/usr/share/mariadb" --user="$(id -un)" > "$ROOT/db-install.log" 2>&1
  echo 'Disposable runtime prepared.'
  ;;
dependencies)
  cd "$ROOT/packages"
  apt-get -o "Dir::State::lists=$ROOT/apt/lists" -o "Dir::Cache=$ROOT/apt/cache" download libncurses6 libtinfo6 libimagequant0 libtiff6 libjbig0 libdeflate0 liblerc4 libsharpyuv0 php8.5-imagick libmagickwand-7.q16-10 libmagickcore-7.q16-10 liblqr-1-0 libraw23t64 liblcms2-2 libheif1 libgomp1 libfftw3-double3 libltdl7
  for p in ./*.deb; do dpkg-deb -x "$p" "$ROOT/root"; done
  ;;
db)
  export LD_LIBRARY_PATH="$ROOT/root/usr/lib/x86_64-linux-gnu"
  exec "$ROOT/root/usr/sbin/mariadbd" --no-defaults --datadir="$ROOT/data" --basedir="$ROOT/root/usr" --lc-messages-dir="$ROOT/root/usr/share/mariadb" --socket="$ROOT/mysql.sock" --pid-file="$ROOT/db.pid" --skip-networking --skip-grant-tables --user="$(id -un)"
  ;;
php|server)
  export LD_LIBRARY_PATH="$ROOT/root/usr/lib/x86_64-linux-gnu" PIXEL_UPDATER_LAB="$ROOT"
  export MAGICK_CONFIGURE_PATH="$ROOT/root/etc/ImageMagick-7" MAGICK_CODER_MODULE_PATH="$ROOT/root/usr/lib/x86_64-linux-gnu/ImageMagick-7.1.2/modules-Q16/coders"
  EXT="$ROOT/root/usr/lib/php/20250925"
  shift
  exec php -d "extension=$EXT/mysqlnd.so" -d "extension=$EXT/mysqli.so" -d "extension=$EXT/zip.so" -d "extension=$EXT/gd.so" -d "extension=$EXT/imagick.so" -d memory_limit=512M "$@"
  ;;
cleanup)
  test "$(realpath "$ROOT")" = /home/warzy/.cache/wp-seed-pixel-051-updater-lab
  test ! -S "$ROOT/mysql.sock"
  rm -rf -- "$ROOT"
  ;;
*) exit 2 ;;
esac
