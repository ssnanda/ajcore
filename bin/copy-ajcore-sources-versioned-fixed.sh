#!/usr/bin/env bash

VERSION="$(sed -n 's/^[[:space:]]*\*[[:space:]]*[Vv]ersion:[[:space:]]*//p' ajcore.php | head -n 1)"

if [ -z "$VERSION" ]; then
  echo "Could not find Version in ajcore.php"
  exit 1
fi

DEST_DIR="$HOME/Downloads/ajcore-sources/$VERSION"

mkdir -p "$DEST_DIR"

cp -v \
  ajcore.php \
  admin/class-ajforms-admin.php \
  admin/class-ajforms-forms-list-table.php \
  admin/class-ajforms-leads-list-table.php \
  admin/ajforms-builder.js \
  admin/partials/ajforms-admin-builder.php \
  admin/partials/ajforms-admin-forms.php \
  admin/partials/ajforms-admin-leads.php \
  admin/partials/ajforms-admin-lead-details.php \
  admin/partials/ajforms-builder.css \
  includes/class-ajforms.php \
  includes/class-ajforms-activator.php \
  config/synced-settings.json \
  bin/build-release.sh \
  "$DEST_DIR/"

echo "Copied AJ Core source files to: $DEST_DIR"
