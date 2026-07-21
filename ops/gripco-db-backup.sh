#!/bin/sh
set -eu

project_dir=/var/www/gripco-wp
backup_dir=/var/backups/gripco
timestamp=$(date -u +%Y%m%dT%H%M%SZ)
destination=$backup_dir/wordpress-$timestamp.sql.gz
temporary=$destination.partial

umask 077
mkdir -p "$backup_dir"
cd "$project_dir"

cleanup() {
    rm -f "$temporary"
}
trap cleanup EXIT INT TERM

/usr/bin/docker compose exec -T db sh -c \
    'exec mariadb-dump --single-transaction --quick --lock-tables=false -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' \
    | gzip -9 > "$temporary"

gzip -t "$temporary"
mv "$temporary" "$destination"
trap - EXIT INT TERM

# Local recovery copies are retained for 14 days. Replicate this directory
# off-server for disaster recovery.
find "$backup_dir" -maxdepth 1 -type f -name 'wordpress-*.sql.gz' -mtime +14 -delete
logger -t gripco-backup "Created $destination"
