#!/bin/bash
# Apply NetBird settings for a single profile.
#
# Usage: apply.sh <profile-name> [reregister]
#
# Called by action.php's "save" handler after it has written the global
# netbird.cfg (ENABLE_NETBIRD, LOG_LEVEL) and the per-profile credential cfg
# (profiles/<name>.cfg). We reconcile the running daemon WITHOUT a full restart:
# ensure it's up, select the target profile, then run `up` with that profile's
# own credentials. Selecting before `up` is what keeps profiles from crossing.
#
# When <reregister> is "1", the profile is torn down and recreated before `up`.
# NetBird bakes the management URL and hostname into the profile at first
# registration; a plain `up --management-url X`/`--hostname Y` on an
# already-registered profile is ignored ("Already connected"). So changing
# either requires re-registering, which is what action.php requests via this flag.

. /usr/local/emhttp/plugins/netbird/include/log.sh 2>/dev/null || log() { echo "$*" ; }

PROFILE="$1"
REREGISTER="$2"
if [ -z "$PROFILE" ]; then
    log "apply.sh: no profile name given; nothing to apply."
    exit 1
fi

NB=/usr/local/sbin/netbird
GLOBAL_CFG=/boot/config/plugins/netbird/netbird.cfg
PROFILE_CFG="/boot/config/plugins/netbird/profiles/${PROFILE}.cfg"

# Global daemon options.
if [ -f "$GLOBAL_CFG" ]; then
    # shellcheck disable=SC1090
    . "$GLOBAL_CFG"
fi

if [ "$ENABLE_NETBIRD" = "0" ] || [ "$ENABLE_NETBIRD" = "false" ]; then
    log "Settings disabled NetBird; stopping daemon."
    /etc/rc.d/rc.netbird stop
    exit 0
fi

# Per-profile credentials (override any legacy values sourced from the global cfg).
MANAGEMENT_URL="" ; SETUP_KEY="" ; HOSTNAME="" ; PRESHARED_KEY=""
if [ -f "$PROFILE_CFG" ]; then
    # shellcheck disable=SC1090
    . "$PROFILE_CFG"
fi

# Ensure the daemon is running (start if down) — no forced restart on a save.
if ! /usr/bin/pgrep -f "^${NB} service run" >/dev/null 2>&1; then
    log "Daemon not running; starting it."
    /etc/rc.d/rc.netbird start
fi

# Wait for the daemon socket before talking to it.
for _ in $(seq 1 20); do
    [ -S /var/run/netbird.sock ] && break
    sleep 0.5
done

# If the management URL changed, re-register: tear the profile down and
# recreate it so `up` registers fresh against the new management server.
# NetBird won't let you remove the active profile, so we park on a short-lived
# temp profile while removing/recreating the target.
if [ "$REREGISTER" = "1" ]; then
    log "Registration settings changed; re-registering profile '$PROFILE'."
    "$NB" down >/dev/null 2>&1
    TMP="__nbreset_$$"
    "$NB" profile add "$TMP"        >/dev/null 2>&1
    "$NB" profile select "$TMP"     >/dev/null 2>&1
    "$NB" profile remove "$PROFILE" >/dev/null 2>&1
    "$NB" profile add "$PROFILE"    >/dev/null 2>&1
fi

# Select the target profile so the subsequent `up` can't connect another one.
log "Selecting profile '$PROFILE'."
SEL=$("$NB" profile select "$PROFILE" 2>&1)
SRC=$?
if [ "$SRC" -ne 0 ]; then
    log "profile select '$PROFILE' failed (rc=$SRC): $SEL"
fi

# Drop the temp parking profile now that the target is active again.
if [ "$REREGISTER" = "1" ] && [ -n "$TMP" ]; then
    "$NB" profile remove "$TMP" >/dev/null 2>&1
fi

# Bring the profile up with its own credentials.
UP_ARGS="up"
[ -n "$MANAGEMENT_URL" ] && UP_ARGS="$UP_ARGS --management-url $MANAGEMENT_URL"
[ -n "$SETUP_KEY" ]      && UP_ARGS="$UP_ARGS --setup-key $SETUP_KEY"
[ -n "$HOSTNAME" ]       && UP_ARGS="$UP_ARGS --hostname $HOSTNAME"
[ -n "$PRESHARED_KEY" ]  && UP_ARGS="$UP_ARGS --preshared-key $PRESHARED_KEY"

log "Running: netbird up (profile '$PROFILE')"
OUT=$("$NB" $UP_ARGS 2>&1)
RC=$?
echo "$OUT" >> /var/log/netbird-utils.log
if [ "$RC" -ne 0 ]; then
    log "netbird up failed (rc=$RC): $OUT"
else
    log "netbird up succeeded for profile '$PROFILE'."
fi
