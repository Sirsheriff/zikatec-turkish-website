#!/usr/bin/env bash
set -euo pipefail

DEPLOY_PATH="/home3/zikatecn/public_html"
PRIVATE_PATH="/home3/zikatecn/zikatec-private"

/bin/mkdir -p "$DEPLOY_PATH" "$PRIVATE_PATH"
/bin/chmod 700 "$PRIVATE_PATH"

if [[ ! -f "$PRIVATE_PATH/config.php" ]]; then
  /bin/cp ./private-config.example.php "$PRIVATE_PATH/config.php"
  /bin/chmod 600 "$PRIVATE_PATH/config.php"
fi

/bin/cp -R ./assets "$DEPLOY_PATH/"
/bin/cp -f \
  ./index.html \
  ./styles.css \
  ./script.js \
  ./product.html \
  ./product.css \
  ./product.js \
  ./admin.html \
  ./admin.css \
  ./admin.js \
  ./api.php \
  "$DEPLOY_PATH/"

/bin/chmod 644 \
  "$DEPLOY_PATH/index.html" \
  "$DEPLOY_PATH/styles.css" \
  "$DEPLOY_PATH/script.js" \
  "$DEPLOY_PATH/product.html" \
  "$DEPLOY_PATH/product.css" \
  "$DEPLOY_PATH/product.js" \
  "$DEPLOY_PATH/admin.html" \
  "$DEPLOY_PATH/admin.css" \
  "$DEPLOY_PATH/admin.js" \
  "$DEPLOY_PATH/api.php"
