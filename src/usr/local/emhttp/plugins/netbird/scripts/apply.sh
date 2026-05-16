#!/bin/bash
# Triggered by the Settings page Apply button (#command in the form post).
# By the time we're called, /update.php has already written the new
# netbird.cfg. Reconcile the running daemon with the new settings.

. /usr/local/emhttp/plugins/netbird/include/log.sh 2>/dev/null || log() { echo "$*" ; }

CFG=/boot/config/plugins/netbird/netbird.cfg
[ -f "$CFG" ] || { log "No netbird.cfg, nothing to apply." ; exit 0 ; }

# shellcheck disable=SC1090
. "$CFG"

if [ "$ENABLE_NETBIRD" = "0" ] || [ "$ENABLE_NETBIRD" = "false" ]; then
    log "Settings disabled NetBird; stopping daemon."
    /etc/rc.d/rc.netbird stop
    exit 0
fi

log "Applying settings; restarting daemon."
/etc/rc.d/rc.netbird restart
sleep 2

# If a setup key is provided, attempt non-interactive login on apply.
if [ -n "$SETUP_KEY" ]; then
    UP_ARGS="up --setup-key $SETUP_KEY"
    [ -n "$MANAGEMENT_URL" ] && UP_ARGS="$UP_ARGS --management-url $MANAGEMENT_URL"
    [ -n "$HOSTNAME" ]       && UP_ARGS="$UP_ARGS --hostname $HOSTNAME"
    [ -n "$PRESHARED_KEY" ]  && UP_ARGS="$UP_ARGS --preshared-key $PRESHARED_KEY"
    log "Running: netbird $UP_ARGS"
    /usr/local/sbin/netbird $UP_ARGS >>/var/log/netbird-utils.log 2>&1 || \
        log "netbird up returned non-zero (this is normal if already connected)."
fi
