#!/bin/sh
set -e

# Configurable via environment; sensible defaults provided
QUEUES="${HR_BG_WORKER_QUEUES:-processTempData_queue,insertWeekendHolidays_queue}"
SLEEP="${HR_BG_WORKER_SLEEP:-1}"
TRIES="${HR_BG_WORKER_TRIES:-20}"
TIMEOUT="${HR_BG_WORKER_TIMEOUT:-60}"
MAX_JOBS="${HR_BG_WORKER_MAX_JOBS:-100}"

cd /var/www/html

while true; do
  echo "[worker] starting: queues=$QUEUES sleep=$SLEEP tries=$TRIES timeout=$TIMEOUT"
  # Run the Laravel worker; don't crash container on exit, auto-restart
  php -d opcache.enable_cli=0 artisan queue:work rabbitmq \
    --queue="$QUEUES" \
    --sleep="$SLEEP" \
    --tries="$TRIES" \
    --timeout="$TIMEOUT" \
    --max-jobs="$MAX_JOBS" \
    --stop-when-empty \
    --no-interaction -vvv || true

  echo "[worker] exited; restarting in 2s..."
  sleep 2
done
