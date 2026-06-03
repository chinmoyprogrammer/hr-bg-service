#!/bin/sh
set -e

# Configurable via environment; sensible defaults provided

QUEUES="${HR_BG_WORKER_QUEUES:-processTempData_queue,processManualData_queue,insertWeekendHolidays_queue,processTempSalary_queue,processRealSalary_queue,recalculateAttendance_queue,employeeDeactivation_queue}"
SLEEP="${HR_BG_WORKER_SLEEP:-1}"
TRIES="${HR_BG_WORKER_TRIES:-100}"
TIMEOUT="${HR_BG_WORKER_TIMEOUT:-60}"
MAX_JOBS="${HR_BG_WORKER_MAX_JOBS:-100}"
STOP_WHEN_EMPTY="${HR_BG_WORKER_STOP_WHEN_EMPTY:-0}"
TRIGGER_ENABLED="${HR_BG_TRIGGER_ENABLED:-1}"
TRIGGER_QUEUES="${HR_BG_TRIGGER_QUEUES:-processTempData_trigger_queue,employeeDeactivation_trigger_queue}"

cd /var/www/html

if [ "$TRIGGER_ENABLED" = "1" ] || [ "$TRIGGER_ENABLED" = "true" ]; then
  (
    while true; do
      echo "[trigger] starting: queues=$TRIGGER_QUEUES"
      set +e
      php -d opcache.enable_cli=0 artisan rabbitmq:consume-triggers --queues="$TRIGGER_QUEUES" --no-interaction -vvv
      EXIT_CODE=$?
      set -e
      echo "[trigger] exited code=$EXIT_CODE; restarting in 2s..."
      sleep 2
    done
  ) &
fi

while true; do
  echo "[worker] starting: queues=$QUEUES sleep=$SLEEP tries=$TRIES timeout=$TIMEOUT"
  STOP_FLAG=""
  if [ "$STOP_WHEN_EMPTY" = "1" ] || [ "$STOP_WHEN_EMPTY" = "true" ]; then
    STOP_FLAG="--stop-when-empty"
  fi

  set +e
  php -d opcache.enable_cli=0 artisan queue:work rabbitmq \
    --queue="$QUEUES" \
    --sleep="$SLEEP" \
    --tries="$TRIES" \
    --timeout="$TIMEOUT" \
    --max-jobs="$MAX_JOBS" \
    $STOP_FLAG \
    --no-interaction -vvv
  EXIT_CODE=$?
  set -e

  echo "[worker] exited code=$EXIT_CODE; restarting in 2s..."
  sleep 2
done
