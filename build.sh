#!/bin/bash
#
# Package source/ into archive/rayfish-unraid-<version>.txz and point
# plugin/rayfish.plg at it.
#
#   ./build.sh             version = today's date (YYYY.MM.DD)
#   ./build.sh 2026.10.19a explicit version
#
# Commit plugin/rayfish.plg and the new archive together: the .plg pins the
# archive by SHA-256.
set -euo pipefail
cd "$(dirname "$0")"

VERSION="${1:-$(date +%Y.%m.%d)}"
PKG="archive/rayfish-unraid-${VERSION}.txz"
PLG="plugin/rayfish.plg"

mkdir -p archive
chmod 0755 source/usr/local/emhttp/plugins/rayfish/scripts/*
tar --owner=0 --group=0 --sort=name --mtime="@0" -C source -cJf "$PKG" usr
SHA=$(sha256sum "$PKG" | cut -d' ' -f1)

sed -i -E \
  -e "s|(<!ENTITY version +\")[^\"]*\"|\1${VERSION}\"|" \
  -e "s|(<!ENTITY packageSHA256 +\")[^\"]*\"|\1${SHA}\"|" \
  "$PLG"

echo "built $PKG"
echo "sha256 $SHA"
