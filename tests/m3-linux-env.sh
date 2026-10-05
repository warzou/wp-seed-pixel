#!/usr/bin/env bash
set -euo pipefail
# Extract packages into an owned tree; do not install or change host services.
ROOT="$HOME/.cache/wp-seed-pixel-m3-environment"
mkdir -p "$ROOT/apt/lists/partial" "$ROOT/apt/cache/archives/partial" "$ROOT/packages" "$ROOT/root"
OPT=(-o "Dir::State::lists=$ROOT/apt/lists" -o "Dir::Cache=$ROOT/apt/cache" -o APT::Get::List-Cleanup=0)
apt-get "${OPT[@]}" update
cd "$ROOT/packages"
apt-get "${OPT[@]}" download php8.5-gd php8.5-mysql php8.5-imagick mariadb-server-core mariadb-client-core libgd3 libjpeg-turbo8 libpng16-16t64 libwebp7 libavif16 libxpm4 libaom3 libdav1d7 libheif1 libmagickwand-7.q16-10 libmagickcore-7.q16-10 liblqr-1-0 libraw23t64 liblcms2-2 libaio1t64 liburing2 libpcre2-8-0 libssl3t64 libstdc++6
apt-get "${OPT[@]}" download mariadb-server mariadb-common libnuma1 libgomp1 libfftw3-double3 libltdl7 libimagequant0 libtiff6 libsharpyuv0 libjbig0 libdeflate0 liblerc4 libncurses6
for p in ./*.deb; do dpkg-deb -x "$p" "$ROOT/root"; done
echo 'Extracted isolated M3 dependencies.'
