#!/usr/bin/env bash

mkdir -p "$HOME/Downloads/ajcore-backups"

tar -czf "$HOME/Downloads/ajcore-backups/ajcore-backup-$(date +%Y%m%d-%H%M%S).tar.gz" \
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
  bin/build-release.sh
