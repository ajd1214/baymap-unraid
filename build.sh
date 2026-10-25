#!/bin/bash
# Build packages/baymap-<version>.txz from src/ and write its SHA256 into baymap.plg.
set -euo pipefail
cd "$(dirname "$0")"
V=$(grep -oP '<!ENTITY version\s+"\K[^"]+' baymap.plg)
SRC=src/usr/local/emhttp/plugins/baymap
for f in "$SRC"/include/*.php; do php -l "$f" >/dev/null; done
for f in "$SRC"/*.page; do sed '1,/^---$/d' "$f" > /tmp/bm-lint.php; php -l /tmp/bm-lint.php >/dev/null || { echo "syntax error in $f"; exit 1; }; done
find src -type d -exec chmod 755 {} +; find src -type f -exec chmod 644 {} +
mkdir -p packages; rm -f packages/baymap-*.txz
tar --owner=0 --group=0 --sort=name --mtime='2026-01-01 00:00Z' -cJf "packages/baymap-$V.txz" -C src .
SHA=$(sha256sum "packages/baymap-$V.txz" | cut -d' ' -f1)
sed -i -E "s#(<!ENTITY pkgSHA\s+\")[^\"]*#\1$SHA#" baymap.plg
echo "built packages/baymap-$V.txz  sha256=$SHA"
