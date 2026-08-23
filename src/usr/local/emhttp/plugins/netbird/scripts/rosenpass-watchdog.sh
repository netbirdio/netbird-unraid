#!/bin/sh
# Verify a strict-Rosenpass connection really carries traffic, and fall back to
# permissive if it doesn't. Fired by the paths that connect without going
# through apply.sh: the Connect action, profile switching, and daemon start at
# boot or array start.
#
# Usage: rosenpass-watchdog.sh [initial-delay-seconds] [window-seconds]
# Boot needs a longer window than a warm reconnect, since peers must reach
# management and re-establish before any handshake can land.

DELAY="${1:-0}"
RP_CONFIRM_WINDOW="${2:-30}"
export RP_CONFIRM_WINDOW

NB=/usr/local/sbin/netbird
DEFAULT_CFG=/usr/local/emhttp/plugins/netbird/default.cfg
GLOBAL_CFG=/boot/config/plugins/netbird/netbird.cfg
LOCK_FILE=/var/run/netbird-apply.lock

. /usr/local/emhttp/plugins/netbird/include/log.sh 2>/dev/null || log() { echo "$*" ; }
[ -r /usr/local/emhttp/plugins/netbird/include/rosenpass.sh ] || exit 0
. /usr/local/emhttp/plugins/netbird/include/rosenpass.sh

[ "$DELAY" -gt 0 ] 2>/dev/null && sleep "$DELAY"

[ -f "$DEFAULT_CFG" ] && . "$DEFAULT_CFG"
[ -f "$GLOBAL_CFG" ]  && . "$GLOBAL_CFG"

# Nothing to guard unless the daemon is meant to be up and strict is armed.
[ "${ENABLE_NETBIRD:-0}" = "1" ]   || exit 0
[ "${ENABLE_ROSENPASS:-0}" = "1" ] || exit 0

# Serialize against apply.sh: if an apply holds the lock it runs this same check
# itself, so there is nothing for us to do.
exec 9>"$LOCK_FILE"
flock -n 9 || exit 0

rosenpass_guard
log "rosenpass watchdog: $RP_GUARD_MSG"
