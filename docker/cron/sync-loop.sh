#!/bin/sh
# Sidecar cron: esegue il sync del feed ogni FEED_SYNC_INTERVAL secondi
# (default 7200 = 2h) e logga l'esito su logs/cron-sync.log.
set -u

INTERVAL="${FEED_SYNC_INTERVAL:-7200}"
LOG_FILE="/var/www/html/logs/cron-sync.log"

mkdir -p /var/www/html/logs

echo "[cron] avvio sync-loop, intervallo ${INTERVAL}s" >> "$LOG_FILE"

# primo giro: attende che mysql e le migrazioni siano pronti
sleep 15

while true; do
    php /var/www/html/bin/sync-feed.php >> "$LOG_FILE" 2>&1
    echo "[cron] exit=$? — prossimo run tra ${INTERVAL}s" >> "$LOG_FILE"
    sleep "$INTERVAL"
done
