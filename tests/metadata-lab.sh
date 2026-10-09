#!/usr/bin/env bash
set -euo pipefail
ROOT=/home/warzy/.cache/wp-seed-pixel-060-metadata-lab
SRC=${PIXEL_METADATA_SOURCE:-$(cd "$(dirname "$0")/.." && pwd)}
PROJECT="$ROOT/project/wp-seed-pixel-m3-environment"
case "${1:-}" in
freeze)
  test "$(realpath "$ROOT")" = /home/warzy/.cache/wp-seed-pixel-060-metadata-lab
  cp "$0" "$ROOT/frozen-lab.sh"
  ;;
prepare)
  test ! -e "$ROOT"
  mkdir -p "$ROOT"/{apt/lists/partial,apt/cache/archives/partial,packages,root,private,data,evidence}
  mkdir -p "$PROJECT/.runtime"
  chmod 700 "$ROOT" "$ROOT/private"
  OPT=(-o "Dir::State::lists=$ROOT/apt/lists" -o "Dir::Cache=$ROOT/apt/cache" -o APT::Get::List-Cleanup=0)
  apt-get "${OPT[@]}" update > "$ROOT/apt.log" 2>&1
  cd "$ROOT/packages"
  apt-get "${OPT[@]}" download php8.5-mysql php8.5-zip php8.5-gd php8.5-imagick libgd3 libjpeg-turbo8 libpng16-16t64 libwebp7 libavif16 libxpm4 libaom3 libdav1d7 mariadb-server-core mariadb-client-core mariadb-server mariadb-common libzip5 libaio1t64 liburing2 libnuma1 libncurses6 libtinfo6 libimagequant0 libtiff6 libjbig0 libdeflate0 liblerc4 libsharpyuv0 libmagickwand-7.q16-10 libmagickcore-7.q16-10 liblqr-1-0 libraw23t64 liblcms2-2 libheif1 libgomp1 libfftw3-double3 libltdl7 > "$ROOT/download.log" 2>&1
  for p in ./*.deb; do dpkg-deb -x "$p" "$ROOT/root"; done
  curl --fail --max-time 120 https://wordpress.org/wordpress-7.1.2.tar.gz -o "$ROOT/wordpress.tar.gz"
  tar -xzf "$ROOT/wordpress.tar.gz" -C "$PROJECT/.runtime"
  export LD_LIBRARY_PATH="$ROOT/root/usr/lib/x86_64-linux-gnu"
  { printf 'CREATE DATABASE IF NOT EXISTS mysql;\nUSE mysql;\n'; cat "$ROOT/root/usr/share/mariadb/mariadb_system_tables.sql"; } | "$ROOT/root/usr/sbin/mariadbd" --no-defaults --bootstrap --datadir="$ROOT/data" --basedir="$ROOT/root/usr" --lc-messages-dir="$ROOT/root/usr/share/mariadb" --user="$(id -un)" > "$ROOT/db-install.log" 2>&1
  echo 'Owned disposable WordPress/MariaDB runtime prepared.'
  ;;
db)
  export LD_LIBRARY_PATH="$ROOT/root/usr/lib/x86_64-linux-gnu"
  exec "$ROOT/root/usr/sbin/mariadbd" --no-defaults --datadir="$ROOT/data" --basedir="$ROOT/root/usr" --lc-messages-dir="$ROOT/root/usr/share/mariadb" --socket="$ROOT/mysql.sock" --pid-file="$ROOT/db.pid" --skip-networking --skip-grant-tables --user="$(id -un)"
  ;;
php|server)
  unset PIXEL_PRIVATE_PNG_FIXTURE
  export PIXEL_NO_PERMANENT_PURGE=1 PIXEL_FORMAT_REPORT_DIR="$ROOT/evidence"
  export LD_LIBRARY_PATH="$ROOT/root/usr/lib/x86_64-linux-gnu" PIXEL_METADATA_LAB="$ROOT" PIXEL_METADATA_SOURCE="$SRC" PIXEL_M3_ROOT="$ROOT"
  export MAGICK_CONFIGURE_PATH="$ROOT/root/etc/ImageMagick-7" MAGICK_CODER_MODULE_PATH="$ROOT/root/usr/lib/x86_64-linux-gnu/ImageMagick-7.1.2/modules-Q16/coders"
  EXT="$ROOT/root/usr/lib/php/20250925"; shift
  exec php -d "extension=$EXT/mysqlnd.so" -d "extension=$EXT/mysqli.so" -d "extension=$EXT/zip.so" -d "extension=$EXT/gd.so" -d "extension=$EXT/imagick.so" -d "extension=$EXT/dom.so" -d memory_limit=512M "$@"
  ;;
sync)
  test "$(realpath "$ROOT")" = /home/warzy/.cache/wp-seed-pixel-060-metadata-lab
  mkdir -p "$PROJECT/tests" "$PROJECT/.runtime/wordpress/wp-content/plugins/wp-seed-pixel"
  cp -a "$SRC/tests/." "$PROJECT/tests/"
  for name in assets includes languages; do cp -a "$SRC/$name" "$PROJECT/.runtime/wordpress/wp-content/plugins/wp-seed-pixel/"; done
  for name in wp-seed-pixel.php uninstall.php readme.txt README.md LICENSE; do cp "$SRC/$name" "$PROJECT/.runtime/wordpress/wp-content/plugins/wp-seed-pixel/"; done
  mkdir -p "$PROJECT/.runtime/fixtures" "$PROJECT/reports/storage-m3/visual"
  if test -d "$SRC/.runtime/fixtures"; then cp -a "$SRC/.runtime/fixtures/." "$PROJECT/.runtime/fixtures/"; fi
  ;;
sql)
  export LD_LIBRARY_PATH="$ROOT/root/usr/lib/x86_64-linux-gnu"; shift
  exec "$ROOT/root/usr/bin/mariadb" --no-defaults --socket="$ROOT/mysql.sock" -u root "$@"
  ;;
xml)
  cd "$ROOT/packages"
  apt-get -o "Dir::State::lists=$ROOT/apt/lists" -o "Dir::Cache=$ROOT/apt/cache" download php8.5-xml > "$ROOT/xml-download.log" 2>&1
  for p in ./php8.5-xml*.deb; do dpkg-deb -x "$p" "$ROOT/root"; done
  ;;
install)
  bash "$0" sync
  cp -a /mnt/c/Users/WaRZy/AppData/Local/Temp/codex-pixel-060-metadata-audit-20261007/fixtures "$ROOT/"
  cp /mnt/c/Users/WaRZy/AppData/Local/Temp/codex-pixel-060-metadata-audit-20261007/cases.json "$ROOT/"
  bash "$0" sql -e 'CREATE DATABASE pixel_metadata'
  bash "$0" php "$PROJECT/tests/metadata-wp.php" install
  ;;
test-gate)
  test "$(realpath "$ROOT")" = /home/warzy/.cache/wp-seed-pixel-060-metadata-lab
  sed -i 's/const WRITE_CERTIFIED = false/const WRITE_CERTIFIED = true/' "$PROJECT/.runtime/wordpress/wp-content/plugins/wp-seed-pixel/includes/class-metadata.php"
  ;;
activate|transaction)
  bash "$0" php "$PROJECT/tests/metadata-wp.php" "$1"
  ;;
updater)
  export PIXEL_UPDATER_LAB="$ROOT" PIXEL_UPDATER_SOURCE="$SRC" PIXEL_UPDATER_REPORT="$ROOT/evidence/updater.json"
  export PIXEL_UPDATE_ZIP="$SRC/reports/release-0.5.1-20261007/final/candidate/wp-seed-pixel-0.5.1.zip"
  export PIXEL_DUPLICATE_ZIP="$ROOT/duplicate.zip"
  bash "$0" php "$PROJECT/tests/updater-matrix.php"
  ;;
native-update)
  bash "$0" php "$PROJECT/tests/metadata-install.php" old
  bash "$0" php "$PROJECT/tests/metadata-install.php" update
  bash "$0" php "$PROJECT/tests/metadata-install.php" verify
  ;;
native-update-private4)
  for mode in old update verify; do
    bash "$0" php "$SRC/tests/metadata-private4-install.php" "$mode"
  done
  ;;
clean-install-private4)
  test ! -e "$ROOT/clean"
  mkdir -p "$ROOT/clean/wp-seed-pixel-m3-environment/.runtime" "$ROOT/clean/private"
  chmod 700 "$ROOT/clean/private"
  tar -xzf "$ROOT/wordpress.tar.gz" -C "$ROOT/clean/wp-seed-pixel-m3-environment/.runtime"
  bash "$0" sql -e 'CREATE DATABASE pixel_metadata_clean'
  bash "$0" php "$SRC/tests/metadata-private4-clean-install.php" install
  bash "$0" php "$SRC/tests/metadata-private4-clean-install.php" verify
  ;;
retry-clean-install)
  test "$(realpath "$ROOT/clean")" = "$ROOT/clean"
  test ! -e "$ROOT/failed-clean-install"
  mv -- "$ROOT/clean" "$ROOT/failed-clean-install"
  bash "$0" sql -e 'DROP DATABASE pixel_metadata_clean'
  bash "$0" clean-install
  ;;
clean-install)
  test ! -e "$ROOT/clean"
  mkdir -p "$ROOT/clean/wp-seed-pixel-m3-environment/.runtime" "$ROOT/clean/private"
  chmod 700 "$ROOT/clean/private"
  tar -xzf "$ROOT/wordpress.tar.gz" -C "$ROOT/clean/wp-seed-pixel-m3-environment/.runtime"
  bash "$0" sql -e 'CREATE DATABASE pixel_metadata_clean'
  bash "$0" php "$SRC/tests/metadata-clean-install.php" install
  bash "$0" php "$SRC/tests/metadata-clean-install.php" verify
  ;;
legacy)
  number="${2:?cycle number}"
  test "$number" = 1 || test "$number" = 2
  legacy="$ROOT/legacy-$number/wp-seed-pixel-m3-environment"
  test ! -e "$legacy"
  mkdir -p "$legacy/.runtime" "$ROOT/legacy-$number/private" "$legacy/tests"
  chmod 700 "$ROOT/legacy-$number/private"
  tar -xzf "$ROOT/wordpress.tar.gz" -C "$legacy/.runtime"
  cp -a "$SRC/tests/." "$legacy/tests/"
  cp -a "$SRC/.runtime/fixtures" "$legacy/.runtime/"
  cp -a "$PROJECT/.runtime/wordpress/wp-content/plugins/wp-seed-pixel" "$legacy/.runtime/wordpress/wp-content/plugins/"
  bash "$0" sql -e "CREATE DATABASE pixel_metadata_legacy_$number"
  bash "$0" php "$legacy/tests/metadata-legacy-install.php" "$number"
  mkdir -p "$legacy/.runtime/wordpress/wp-content/uploads" "$ROOT/legacy-$number/outside"
  cp "$SRC/.runtime/fixtures/small.jpg" "$ROOT/legacy-$number/outside/"
  ln -s "$ROOT/legacy-$number/outside" "$legacy/.runtime/wordpress/wp-content/uploads/m1-link-test"
  mkdir -p "$ROOT/evidence/cycle-$number"
  for test in storage-analyzer jobs storage-matrix; do
    bash "$0" php "$legacy/tests/$test.php" > "$ROOT/evidence/cycle-$number/$test.log" 2>&1
    tail -n 1 "$ROOT/evidence/cycle-$number/$test.log"
  done
  cp "$legacy/reports/storage-m1/integration.json" "$ROOT/evidence/cycle-$number/storage.json"
  cp "$legacy/reports/storage-m1/matrix.json" "$ROOT/evidence/cycle-$number/storage-matrix.json"
  cp "$legacy/reports/storage-m2/integration.json" "$ROOT/evidence/cycle-$number/jobs.json"
  ;;
retry-legacy)
  number="${2:?cycle number}"
  test "$number" = 1 || test "$number" = 2
  test "$(realpath "$ROOT")" = /home/warzy/.cache/wp-seed-pixel-060-metadata-lab
  legacy="$ROOT/legacy-$number"
  test "$(realpath "$legacy")" = "$legacy"
  suffix="${3:-failed}"
  [[ "$suffix" =~ ^[a-z0-9-]+$ ]]
  test ! -e "$ROOT/$suffix-legacy-$number"
  grep -Fq "define('DB_NAME','pixel_metadata_legacy_$number')" "$legacy/wp-seed-pixel-m3-environment/.runtime/wordpress/wp-config.php"
  mv -- "$legacy" "$ROOT/$suffix-legacy-$number"
  bash "$0" sql -e "DROP DATABASE pixel_metadata_legacy_$number"
  ;;
resume-legacy)
  number="${2:?cycle number}"
  test "$number" = 1 || test "$number" = 2
  legacy="$ROOT/legacy-$number/wp-seed-pixel-m3-environment"
  test "$(realpath "$legacy")" = "$legacy"
  cp -a "$SRC/tests/." "$legacy/tests/"
  for test in jobs storage-matrix; do
    bash "$0" php "$legacy/tests/$test.php" > "$ROOT/evidence/cycle-$number/$test.log" 2>&1
    tail -n 1 "$ROOT/evidence/cycle-$number/$test.log"
  done
  cp "$legacy/reports/storage-m1/integration.json" "$ROOT/evidence/cycle-$number/storage.json"
  cp "$legacy/reports/storage-m1/matrix.json" "$ROOT/evidence/cycle-$number/storage-matrix.json"
  cp "$legacy/reports/storage-m2/integration.json" "$ROOT/evidence/cycle-$number/jobs.json"
  ;;
cycle)
  number="${2:?cycle number}"
  test "$number" = 1 || test "$number" = 2
  bash "$0" sync
  mkdir -p "$ROOT/evidence/cycle-$number"
  for test in metadata-wp metadata-admission metadata-pipeline m3 format-test format-profiles m51-locks metadata-crashes; do
    args=()
    if test "$test" = metadata-wp; then args=(transaction); fi
    bash "$0" php "$PROJECT/tests/$test.php" "${args[@]}" > "$ROOT/evidence/cycle-$number/$test.log" 2>&1
    tail -n 1 "$ROOT/evidence/cycle-$number/$test.log"
  done
  bash "$0" updater
  bash "$0" native-update
  for name in transaction admission pipeline crashes updater install; do cp "$ROOT/evidence/$name.json" "$ROOT/evidence/cycle-$number/"; done
  cp "$ROOT/evidence/profiles-dev.json" "$ROOT/evidence/cycle-$number/" 2>/dev/null || true
  cp "$PROJECT/reports/storage-m3/integration.json" "$ROOT/evidence/cycle-$number/jpeg-regression.json"
  cp "$PROJECT/reports/storage-m5.1/locks.json" "$ROOT/evidence/cycle-$number/authority.json"
  bash "$0" legacy "$number"
  ;;
export-cycle)
  number="${2:?cycle number}"
  test "$number" = 1 || test "$number" = 2
  destination="$SRC/reports/metadata-private2-20261008/cycle-$number"
  mkdir -p "$destination"
  tar -cf "$destination/native-evidence.tar" -C "$ROOT/evidence/cycle-$number" .
  ;;
http)
  echo $$ > "$ROOT/server.pid"
  export PHP_CLI_SERVER_WORKERS=2
  exec bash "$0" server -S 127.0.0.1:8877 -t "$PROJECT/.runtime/wordpress"
  ;;
stop-http)
  pid=$(cat "$ROOT/server.pid")
  test -r "/proc/$pid/cmdline"
  tr '\0' ' ' < "/proc/$pid/cmdline" | grep -Fq -- "-t $PROJECT/.runtime/wordpress"
  children=$(pgrep -P "$pid" || true)
  if test -n "$children"; then kill -TERM $children; fi
  kill -TERM "$pid"
  rm -- "$ROOT/server.pid"
  ;;
shutdown-db)
  test "$(realpath "$ROOT")" = /home/warzy/.cache/wp-seed-pixel-060-metadata-lab
  bash "$0" sql -e SHUTDOWN
  ;;
hygiene)
  test ! -e "$ROOT"
  if pgrep -af '[w]p-seed-pixel-060-metadata-lab'; then exit 1; fi
  echo 'Owned Linux laboratory and processes physically absent.'
  ;;
cleanup)
  test "$(realpath "$ROOT")" = /home/warzy/.cache/wp-seed-pixel-060-metadata-lab
  test ! -S "$ROOT/mysql.sock"
  test ! -e "$ROOT/server.pid"
  rm -rf -- "$ROOT"
  ;;
*) exit 2 ;;
esac
