#!/usr/bin/env bash

BACKUP_DIR="/Users/sandip/Projects/ajcore/releases/ajcore-backups"

if [ -n "${1:-}" ]; then
  BACKUP_FILE="$1"
else
  BACKUP_FILE="$(ls -t "$BACKUP_DIR"/ajcore-backup-*.tar.gz 2>/dev/null | head -n 1)"
fi

if [ -z "$BACKUP_FILE" ]; then
  echo "No AJ Core backup found in: $BACKUP_DIR"
  exit 1
fi

if [ ! -f "$BACKUP_FILE" ]; then
  echo "Backup file not found: $BACKUP_FILE"
  exit 1
fi

echo "Restoring from: $BACKUP_FILE"

tar -xzf "$BACKUP_FILE"

echo "Restore complete."
