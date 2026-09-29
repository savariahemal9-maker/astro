#!/usr/bin/env bash
# Builds engine/bin/astro_calc and fetches Swiss Ephemeris data files (1800-2399 CE).
# Requires: git, gcc, make. Run on the server (VPS) once: bash engine/build.sh
set -euo pipefail
cd "$(dirname "$0")"
SWE_TAG="${SWE_TAG:-master}"
[ -d swisseph ] || git clone --depth 1 --branch "$SWE_TAG" https://github.com/aloistr/swisseph.git swisseph
( cd swisseph && make libswe.a >/dev/null )
mkdir -p bin ephe
gcc -O2 -Wall -o bin/astro_calc astro_calc.c -Iswisseph swisseph/libswe.a -lm -ldl
for f in sepl_18.se1 semo_18.se1 seas_18.se1 sepl_12.se1 semo_12.se1 seas_12.se1 sepl_24.se1 semo_24.se1; do
  [ -f "swisseph/ephe/$f" ] && cp "swisseph/ephe/$f" ephe/ || echo "warn: $f not in repo"
done
./bin/astro_calc info --ephe=./ephe --require-swieph
echo "OK: engine built"
