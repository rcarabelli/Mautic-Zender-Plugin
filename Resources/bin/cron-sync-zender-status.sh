#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
export HOME="/home/paellas"
export USER="paellas"
export LOGNAME="paellas"
export LC_ALL=C
BIN="/home/paellas/public_html/marketautomation/plugins/MauticZenderBundle/Resources/bin"
PHP="/opt/cpanel/ea-php82/root/usr/bin/php"
RUNTIME="/home/paellas/.7cats-runtime/mautic-zender-status-cron"
LOG_DIR="/home/paellas/logs/mautic-zender"
mkdir -p "$RUNTIME" "$LOG_DIR"
chmod 0750 "$RUNTIME" "$LOG_DIR"
exec 9>"$RUNTIME/sync-zender-status.lock"
/usr/bin/flock -n 9 || {
  echo "MAUTIC_ZENDER_STATUS_CRON_SKIPPED=lock_busy"
  exit 0
}
exec "$PHP" "$BIN/cron-sync-zender-status.php" "$@"
