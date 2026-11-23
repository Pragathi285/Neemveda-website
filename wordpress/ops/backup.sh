#!/bin/bash
# neemveda nightly backup: database + uploaded files, kept 14 days in /var/backups.
# Restore the database:
#   gunzip -c /var/backups/neemveda-db-DATE.sql.gz | docker compose -f /opt/neemveda/wordpress/docker-compose.yml exec -T db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" neemveda'
set -euo pipefail
D=$(date +%F)
OUT=/var/backups
cd /opt/neemveda/wordpress
docker compose exec -T db sh -c 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction --routines neemveda' </dev/null | gzip > "$OUT/neemveda-db-$D.sql.gz.part"
gzip -t "$OUT/neemveda-db-$D.sql.gz.part"
gunzip -c "$OUT/neemveda-db-$D.sql.gz.part" | tail -1 | grep -q 'Dump completed'
mv "$OUT/neemveda-db-$D.sql.gz.part" "$OUT/neemveda-db-$D.sql.gz"
docker run --rm -v neemveda_wp:/wp:ro -v "$OUT":/out alpine tar czf "/out/neemveda-files-$D.tgz" -C /wp wp-content/uploads
find "$OUT" -maxdepth 1 -name 'neemveda-*' -mtime +14 -delete
echo "$(date '+%F %T') ok: $(du -h "$OUT/neemveda-db-$D.sql.gz" | cut -f1) db, $(du -h "$OUT/neemveda-files-$D.tgz" | cut -f1) files"
