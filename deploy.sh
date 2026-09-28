#!/usr/bin/env bash
set -euo pipefail

DEPLOY_PATH="/home3/zikatecn/public_html"
MANAGEMENT_PATH="$DEPLOY_PATH/systemManagement"
CONSULTATION_PATH="$DEPLOY_PATH/consultationManagement"
PRIVATE_PATH="/home3/zikatecn/zikatec-private"

PHP_BIN="$(command -v php || true)"
if [[ -z "$PHP_BIN" ]]; then
  for candidate in /usr/local/bin/php /usr/bin/php; do
    if [[ -x "$candidate" ]]; then PHP_BIN="$candidate"; break; fi
  done
fi
if [[ -z "$PHP_BIN" ]]; then
  echo "PHP CLI bulunamadı; api.php sözdizimi doğrulanamadı." >&2
  exit 1
fi
"$PHP_BIN" -l ./api.php
"$PHP_BIN" -l ./systemManagement/api.php

/bin/mkdir -p "$DEPLOY_PATH" "$MANAGEMENT_PATH" "$CONSULTATION_PATH" "$PRIVATE_PATH"
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
  ./api.php \
  "$DEPLOY_PATH/"

/bin/rm -rf "$MANAGEMENT_PATH/assets" "$MANAGEMENT_PATH/fonts"
/bin/cp -R ./systemManagement/assets "$MANAGEMENT_PATH/assets"
/bin/cp -R ./systemManagement/fonts "$MANAGEMENT_PATH/fonts"
/bin/cp -f ./systemManagement/index.html "$MANAGEMENT_PATH/index.html"
/bin/cp -f ./systemManagement/.htaccess "$MANAGEMENT_PATH/.htaccess"
/bin/cp -f ./systemManagement/api.php "$MANAGEMENT_PATH/api.php"
/bin/rm -f "$MANAGEMENT_PATH/admin.css" "$MANAGEMENT_PATH/admin.js"

/bin/cp -f ./consultationManagement/index.html "$CONSULTATION_PATH/index.html"
/bin/cp -f ./consultationManagement/.htaccess "$CONSULTATION_PATH/.htaccess"
/bin/cp -f ./consultationManagement/admin.css "$CONSULTATION_PATH/admin.css"
/bin/cp -f ./consultationManagement/admin.js "$CONSULTATION_PATH/admin.js"

# The former root-level panel assets are no longer used. admin.html redirects
# to the preserved consultation panel; /systemManagement is the sales dashboard.
/bin/rm -f "$DEPLOY_PATH/admin.css" "$DEPLOY_PATH/admin.js"

/bin/chmod 644 \
  "$DEPLOY_PATH/index.html" \
  "$DEPLOY_PATH/styles.css" \
  "$DEPLOY_PATH/script.js" \
  "$DEPLOY_PATH/product.html" \
  "$DEPLOY_PATH/product.css" \
  "$DEPLOY_PATH/product.js" \
  "$DEPLOY_PATH/admin.html" \
  "$DEPLOY_PATH/api.php" \
  "$MANAGEMENT_PATH/index.html" \
  "$MANAGEMENT_PATH/.htaccess" \
  "$MANAGEMENT_PATH/api.php" \
  "$MANAGEMENT_PATH/assets/"* \
  "$MANAGEMENT_PATH/fonts/Peyda.ttf" \
  "$CONSULTATION_PATH/index.html" \
  "$CONSULTATION_PATH/.htaccess" \
  "$CONSULTATION_PATH/admin.css" \
  "$CONSULTATION_PATH/admin.js"
