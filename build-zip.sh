#!/usr/bin/env sh
# Builds dist/synra-pay-magento2.zip with app/code/Synra/Pay/ at the zip root
# and forward-slash entries (PowerShell's Compress-Archive writes backslashes,
# which break the module on Linux hosts).
set -e
cd "$(dirname "$0")"
mkdir -p dist
rm -rf dist/stage dist/synra-pay-magento2.zip
mkdir -p dist/stage/app/code/Synra
cp -R synra-pay dist/stage/app/code/Synra/Pay
cd dist/stage
if command -v zip >/dev/null 2>&1; then
  zip -qr ../synra-pay-magento2.zip app -x '*.DS_Store'
else
  /c/Windows/System32/tar.exe -a -cf ../synra-pay-magento2.zip app
fi
cd ..
rm -rf stage
ls -la synra-pay-magento2.zip
