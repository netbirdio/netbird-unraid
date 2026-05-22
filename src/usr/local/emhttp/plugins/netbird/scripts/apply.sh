#!/bin/bash
# Apply NetBird settings for a single profile.
#
# Usage: apply.sh <profile-name> [mode]
#   mode = reregister | reconnect | ensure   (default: ensure)
#
# Called by action.php's "save" handler after it has written the global
# netbird.cfg (ENABLE_NETBIRD, LOG_LEVEL) and the per-profile credential cfg
# (profiles/<name>.cfg). We reconcile the running daemon WITHOUT a full restart:
# ensure it's up, select the target profile, then run `up` with that profile's
# own credentials. Selecting before `up` is what keeps profiles from crossing.
#
# Modes (NetBird ignores most setting changes on an already-connected profile):
#   reregister — management URL or hostname changed. These are baked into the
#                profile at registration, so tear it down and recreate it before
#                `up`. NetBird won't remove the active profile, so we park on a
#                short-lived temp profile while removing/recreating the target.
#   reconnect  — pre-shared key changed. `down` first so the following `up`
#                re-applies it (a plain `up` on a live profile is a no-op).
#   ensure     — no credential change. Just select + up (no disconnect).
#
# Concurrency: we hold an flock for the whole run so two rapid applies (or an
# apply racing a Connect/Switch from action.php) serialize instead of cancelling
# each other. Result of the run is written to RESULT_FILE for the UI to poll.

. /usr/local/emhttp/plugins/netbird/include/log.sh 2>/dev/null || log() { echo "$*" ; }

PROFILE="$1"
MODE="${2:-ensure}"
if [ -z "$PROFILE" ]; then
    log "apply.sh: no profile name given; nothing to apply."
    exit 1
fi

NB=/usr/local/sbin/netbird
GLOBAL_CFG=/boot/config/plugins/netbird/netbird.cfg
PROFILE_CFG="/boot/config/plugins/netbird/profiles/${PROFILE}.cfg"
LOCK_FILE=/var/run/netbird-apply.lock
RESULT_FILE=/var/run/netbird-apply-result.json

# Record the outcome for the UI poller. $1 = true|false|null, $2 = short message.
# All values are controlled (profile name is validated upstream; messages are
# literals) so the hand-built JSON is safe.
write_result() {
    printf '{"profile":"%s","ok":%s,"ts":%s,"message":"%s"}\n' \
        "$PROFILE" "$1" "$(date +%s)" "$2" > "$RESULT_FILE" 2>/dev/null
}

# Serialize with other applies / connect actions.
exec 9>"$LOCK_FILE"
if ! flock -w 90 9 ; then
    log "apply.sh: lock busy after 90s; aborting apply for '$PROFILE'."
    write_result false "another operation was in progress"
    exit 1
fi

write_result null "applying"

# Global daemon options.
if [ -f "$GLOBAL_CFG" ]; then
    # shellcheck disable=SC1090
    . "$GLOBAL_CFG"
fi

if [ "$ENABLE_NETBIRD" = "0" ] || [ "$ENABLE_NETBIRD" = "false" ]; then
    log "Settings disabled NetBird; stopping daemon."
    /etc/rc.d/rc.netbird stop
    write_result true "NetBird disabled; daemon stopped"
    exit 0
fi

# Per-profile credentials (override any legacy values sourced from the global cfg).
# A setup key is only needed to register (enforced by action.php's save on initial
# setup / re-register); reconnects reuse the stored identity, so no key guard here.
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

# Ensure mode with nothing to change: if this profile is already the active one
# and the daemon is connected, there's nothing to apply — skip the select+up so
# a no-op save doesn't needlessly bounce the connection.
if [ "$MODE" = "ensure" ]; then
    ACTIVE=$("$NB" profile list 2>/dev/null | awk '/^✓/{print $2}')
    if [ "$ACTIVE" = "$PROFILE" ] && "$NB" status 2>/dev/null | grep -q "Management: Connected"; then
        log "No credential change and '$PROFILE' already active+connected; nothing to apply."
        write_result true "no change (already connected)"
        exit 0
    fi
fi

TMP=""
case "$MODE" in
    reregister)
        # Management URL / hostname changed: recreate the profile so `up`
        # registers fresh. Park on a temp profile so we can remove the target.
        log "Registration settings changed; re-registering profile '$PROFILE'."
        "$NB" down >/dev/null 2>&1
        TMP="__nbreset_$$"
        "$NB" profile add "$TMP"        >/dev/null 2>&1
        "$NB" profile select "$TMP"     >/dev/null 2>&1
        "$NB" profile remove "$PROFILE" >/dev/null 2>&1
        "$NB" profile add "$PROFILE"    >/dev/null 2>&1
        ;;
    reconnect)
        # Pre-shared key changed: disconnect so the next `up` re-applies it.
        log "Credentials changed; reconnecting profile '$PROFILE'."
        "$NB" down >/dev/null 2>&1
        sleep 1
        ;;
    *)
        : # ensure: nothing extra
        ;;
esac

# Select the target profile so the subsequent `up` can't connect another one.
log "Selecting profile '$PROFILE'."
SEL=$("$NB" profile select "$PROFILE" 2>&1)
SRC=$?
if [ "$SRC" -ne 0 ]; then
    log "profile select '$PROFILE' failed (rc=$SRC): $SEL"
fi

# Drop the temp parking profile now that the target is active again.
if [ -n "$TMP" ]; then
    "$NB" profile remove "$TMP" >/dev/null 2>&1
fi

# Bring the profile up with its own credentials (bounded so a failing login's
# retry/backoff can't hang forever).
UP_ARGS="up"
[ -n "$MANAGEMENT_URL" ] && UP_ARGS="$UP_ARGS --management-url $MANAGEMENT_URL"
[ -n "$SETUP_KEY" ]      && UP_ARGS="$UP_ARGS --setup-key $SETUP_KEY"
[ -n "$HOSTNAME" ]       && UP_ARGS="$UP_ARGS --hostname $HOSTNAME"
[ -n "$PRESHARED_KEY" ]  && UP_ARGS="$UP_ARGS --preshared-key $PRESHARED_KEY"

log "Running: netbird up (profile '$PROFILE', mode '$MODE')"
OUT=$(timeout 90 "$NB" $UP_ARGS 2>&1)
RC=$?
echo "$OUT" >> /var/log/netbird-utils.log
if [ "$RC" -eq 0 ]; then
    log "netbird up succeeded for profile '$PROFILE'."
    write_result true "connected"
elif [ "$RC" -eq 124 ]; then
    log "netbird up timed out for profile '$PROFILE' after 90s."
    write_result false "timed out connecting (check management URL / setup key)"
else
    log "netbird up failed (rc=$RC) for profile '$PROFILE': $OUT"
    write_result false "up failed (rc=$RC)"
fi
