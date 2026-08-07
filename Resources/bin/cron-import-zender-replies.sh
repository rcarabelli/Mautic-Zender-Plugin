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
RUNTIME="/home/paellas/.7cats-runtime/mautic-zender-cron"

mkdir -p "$RUNTIME"
chmod 0750 "$RUNTIME"

exec 8>"$RUNTIME/import-zender-replies.lock"

/usr/bin/flock -n 8 || {
    echo "RECEIVED_IMPORT_CRON_SKIPPED=lock_busy"
    exit 0
}

exec "$PHP" "$BIN/cron-import-zender-replies.php" "$@"
