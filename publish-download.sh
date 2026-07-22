#!/usr/bin/env bash
# Publishes the current MPS-Woocommerce-Plugin repo as the merchant-facing
# download served at https://mpsgateway.com/plugin/mps-gateway.zip
#
# The Getting Started page (getting-started.blade.php) reads the version
# straight out of this zip via a View Composer, so the download link AND the
# "Latest version" label stay in sync automatically — just run this after a
# version bump/tag.
#
# Usage:  bash /root/MPS-Woocommerce-Plugin/publish-download.sh
set -euo pipefail

SRC=/root/MPS-Woocommerce-Plugin
DL=/var/www/vhosts/mpsgateway.com/httpdocs/public/plugin
STG=$(mktemp -d)

VER=$(grep -i 'Version:' "$SRC/mps-gateway.php" | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+')
[ -n "$VER" ] || { echo "Could not read plugin version"; exit 1; }
echo "Building mps-gateway.zip from repo @ v$VER"

mkdir -p "$STG/mps-gateway"
rsync -a --exclude='.git' --exclude='.github' --exclude='node_modules' \
      --exclude='*.md' --exclude='.gitignore' --exclude='publish-download.sh' \
      "$SRC"/ "$STG/mps-gateway"/

# Back up the currently-served zip under its own version, if present
if [ -f "$DL/mps-gateway.zip" ]; then
  OLDVER=$(unzip -p "$DL/mps-gateway.zip" mps-gateway/mps-gateway.php 2>/dev/null \
           | grep -i 'Version:' | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || echo unknown)
  cp -a "$DL/mps-gateway.zip" "$DL/mps-gateway-v${OLDVER}.bak.zip" || true
fi

( cd "$STG" && rm -f "$DL/mps-gateway.zip" && zip -rq "$DL/mps-gateway.zip" mps-gateway )
chown mpsadmin:psacln "$DL/mps-gateway.zip"
chmod 644 "$DL/mps-gateway.zip"
rm -rf "$STG"

echo "Published v$VER -> $DL/mps-gateway.zip"
unzip -p "$DL/mps-gateway.zip" mps-gateway/mps-gateway.php | grep -i 'Version:' | head -1
echo "Live: https://mpsgateway.com/plugin/mps-gateway.zip"
