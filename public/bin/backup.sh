#!/usr/bin/env bash
# Database backup (compressed pg_dump) with automatic clean-up of old copies.
#   bin/backup.sh                       -> /var/backups/pos/pos-YYYYmmdd-HHMMSS.dump
# Cron (every night at 02:00):   0 2 * * *  /var/www/pos/bin/backup.sh >> /var/log/pos-backup.log 2>&1
# Settings come from the environment (DATABASE_URL, or DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASSWORD).
# To load them from a file:  ENV_FILE=/etc/pos.env bin/backup.sh
# Docker: docker compose exec -T db pg_dump -U "$DB_USER" -Fc "$DB_NAME" > pos-$(date +%F).dump
set -euo pipefail
umask 077
if [ -n "${ENV_FILE:-}" ]; then set -a; . "$ENV_FILE"; set +a; fi

BACKUP_DIR="${BACKUP_DIR:-/var/backups/pos}"
KEEP_DAYS="${KEEP_DAYS:-14}"
mkdir -p "$BACKUP_DIR"
OUT="$BACKUP_DIR/pos-$(date +%Y%m%d-%H%M%S).dump"

if [ -n "${DATABASE_URL:-}" ]; then
  pg_dump -Fc "$DATABASE_URL" > "$OUT"
else
  PGPASSWORD="${DB_PASSWORD:-}" pg_dump -h "${DB_HOST:-localhost}" -p "${DB_PORT:-5432}" -U "${DB_USER:-postgres}" -Fc "${DB_NAME:-pos_db}" > "$OUT"
fi
[ -s "$OUT" ] || { echo "Backup is empty: $OUT" >&2; rm -f "$OUT"; exit 1; }
find "$BACKUP_DIR" -name 'pos-*.dump' -mtime +"$KEEP_DAYS" -delete
echo "Backup written: $OUT ($(du -h "$OUT" | cut -f1))"
# Restore into an empty database:  pg_restore --clean --if-exists -d pos_db FILE.dump
