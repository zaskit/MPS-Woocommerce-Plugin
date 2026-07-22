#!/usr/bin/env bash
# Publishes the current MPS-Woocommerce-Plugin repo as the merchant-facing
# download served at https://mpsgateway.com/plugin/mps-gateway.zip AND the
# staging copy at https://staging.mpsgateway.com/plugin/mps-gateway.zip, so
# both environments always hand out the same, current plugin.
#
# The Getting Started page (getting-started.blade.php) reads the version
# straight out of this zip via a View Composer, so the download link AND the
# "Latest version" label stay in sync automatically — just run this after a
# version bump/tag.
#
# Usage:  bash publish-download.sh            -> STAGING only (safe default)
#         bash publish-download.sh live       -> live only
#         bash publish-download.sh both       -> both environments
#
# Staging-only is the default on purpose: publishing to live puts the build in front of every
# merchant who downloads the plugin, so promoting there must be a deliberate act.
set -euo pipefail

SRC=/root/MPS-Woocommerce-Plugin
DL_LIVE=/var/www/vhosts/mpsgateway.com/httpdocs/public/plugin
DL_STAGING=/var/www/vhosts/mpsgateway.com/staging/public/plugin
STG=$(mktemp -d)

TARGET="${1:-staging}"
case "$TARGET" in
  staging) TARGETS=("$DL_STAGING") ;;
  live)    TARGETS=("$DL_LIVE") ;;
  both)    TARGETS=("$DL_LIVE" "$DL_STAGING") ;;
  *) echo "Usage: $0 [staging|live|both]  (default: staging)"; exit 1 ;;
esac
echo "Publishing to: $TARGET" 

VER=$(grep -i 'Version:' "$SRC/mps-gateway.php" | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+')
[ -n "$VER" ] || { echo "Could not read plugin version"; exit 1; }
echo "Building mps-gateway.zip from repo @ v$VER"

mkdir -p "$STG/mps-gateway"
rsync -a --exclude='.git' --exclude='.github' --exclude='node_modules' \
      --exclude='*.md' --exclude='.gitignore' --exclude='publish-download.sh' \
      "$SRC"/ "$STG/mps-gateway"/

# Publish to both environments: back up whatever each is currently serving
# (under its own version), then drop in the freshly built zip.
for DL in "${TARGETS[@]}"; do
  [ -d "$DL" ] || { echo "SKIP (no such dir): $DL"; continue; }

  if [ -f "$DL/mps-gateway.zip" ]; then
    OLDVER=$(unzip -p "$DL/mps-gateway.zip" mps-gateway/mps-gateway.php 2>/dev/null \
             | grep -i 'Version:' | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || echo unknown)
    [ -f "$DL/mps-gateway-v${OLDVER}.bak.zip" ] || cp -a "$DL/mps-gateway.zip" "$DL/mps-gateway-v${OLDVER}.bak.zip"
  fi

  ( cd "$STG" && rm -f "$DL/mps-gateway.zip" && zip -rq "$DL/mps-gateway.zip" mps-gateway )
  chown mpsadmin:psacln "$DL/mps-gateway.zip"
  chmod 644 "$DL/mps-gateway.zip"
  echo "Published v$VER -> $DL/mps-gateway.zip ($(unzip -p "$DL/mps-gateway.zip" mps-gateway/mps-gateway.php | grep -i 'Version:' | head -1 | tr -s ' '))"
done

rm -rf "$STG"

echo
case "$TARGET" in
  staging) echo "Staging: https://staging.mpsgateway.com/plugin/mps-gateway.zip" ;;
  live)    echo "Live:    https://mpsgateway.com/plugin/mps-gateway.zip" ;;
  both)    echo "Live:    https://mpsgateway.com/plugin/mps-gateway.zip"
           echo "Staging: https://staging.mpsgateway.com/plugin/mps-gateway.zip" ;;
esac
