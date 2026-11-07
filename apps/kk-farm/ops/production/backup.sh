#!/bin/sh
# Nightly database and photo backup on the server, kept 14 days. Run by cron (installed by deploy.sh).
set -e
umask 077
DIR=/srv/kkfarm/backups
STAMP=$(date +%Y%m%d-%H%M)
mkdir -p "$DIR"
cd /srv/kkfarm
docker compose exec -T db pg_dump -U kkfarm -d kkfarm -Fc > "$DIR/db-$STAMP.dump"
docker run --rm -v kkfarm_storage:/data:ro -v "$DIR":/backup alpine tar czf "/backup/photos-$STAMP.tar.gz" -C /data .
find "$DIR" -type f -mtime +14 -delete
