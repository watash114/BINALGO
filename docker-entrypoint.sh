#!/bin/bash
set -e

# Run database migration on startup if DATABASE_URL is set
if [ -n "$DATABASE_URL" ] && [ -f /var/www/html/database/tourism.sql ]; then
    echo "Importing database schema..."
    PGPASSWORD="$DB_PASS" psql -h "$DB_HOST" -U "$DB_USER" -d "$DB_NAME" -f /var/www/html/database/tourism.sql 2>&1 || echo "DB import skipped (may already exist)"
fi

exec apache2-foreground
