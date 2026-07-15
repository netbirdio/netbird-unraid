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
DEFAULT_CFG=/usr/local/emhttp/plugins/netbird/default.cfg
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

# Once a first-time connection creates wt0, reload nginx so the Unraid WebGUI
# binds to the NetBird address added to network-extra.cfg by the installer. The
# watcher closes the apply lock descriptor so it cannot delay another operation.
reload_nginx_when_wt0_ready() {
    (
        exec 9>&-
        for _ in $(seq 1 15); do
            if ip -4 addr show wt0 >/dev/null 2>&1; then
                /etc/rc.d/rc.nginx reload >/dev/null 2>&1
                exit 0
            fi
            sleep 2
        done
    ) >/dev/null 2>&1 </dev/null &
}

# Serialize with other applies / connect actions.
exec 9>"$LOCK_FILE"
if ! flock -w 90 9 ; then
    log "apply.sh: lock busy after 90s; aborting apply for '$PROFILE'."
    write_result false "another operation was in progress"
    exit 1
fi

write_result null "applying"

# Global daemon options. Packaged defaults make a missing persistent cfg
# disabled, matching rc.netbird and the Settings page.
if [ -f "$DEFAULT_CFG" ]; then
    # shellcheck disable=SC1090
    . "$DEFAULT_CFG"
fi
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
# NetBird's built-in SSH server (host-wide global toggle from netbird.cfg).
# Unraid is root-operated, so also permit root login (refused otherwise).
[ "$ENABLE_SSH" = "1" ]  && UP_ARGS="$UP_ARGS --allow-server-ssh --enable-ssh-root"

# Guard against a stale NetBird-managed /etc/resolv.conf surviving an ungraceful
# shutdown (reboot, SIGKILL, array stop). If it points only at NetBird's own
# embedded resolver (100.64.0.0/10) while the daemon is down, the host can't
# resolve anything -- including the management domain -- so `up` fails with a
# connect timeout (issue #2, ungraceful-exit case). If the current resolv.conf
# has no usable upstream, recover one before connecting: prefer NetBird's saved
# original, else fall back to public resolvers. Runs regardless of MANAGE_DNS,
# since with --disable-dns NetBird won't fix a stale file on its own.
nb_cgnat='^100\.(6[4-9]|[7-9][0-9]|1[0-1][0-9]|12[0-7])\.'
nb_has_usable_upstream() { # $1=file; 0 if it lists a nameserver outside 100.64/10
    sed -nE 's/^[[:space:]]*nameserver[[:space:]]+([^[:space:]#]+).*/\1/p' "$1" 2>/dev/null \
        | grep -qvE "$nb_cgnat"
}
if [ -r /etc/resolv.conf ] && ! nb_has_usable_upstream /etc/resolv.conf; then
    if [ -r /etc/resolv.conf.original.netbird ] && nb_has_usable_upstream /etc/resolv.conf.original.netbird; then
        cp -a /etc/resolv.conf.original.netbird /etc/resolv.conf \
            && log "Recovered /etc/resolv.conf from .original.netbird (no usable upstream; issue #2)."
    else
        cp -a /etc/resolv.conf "/etc/resolv.conf.preNB.$(date +%s)" 2>/dev/null
        printf '# Written by netbird-unraid recovery (issue #2)\nnameserver 1.1.1.1\nnameserver 8.8.8.8\n' > /etc/resolv.conf \
            && log "WARN: no usable upstream in resolv.conf or saved original; wrote fallback (1.1.1.1/8.8.8.8) (issue #2)."
    fi
fi

# DNS management (host-wide global toggle from netbird.cfg). Default manages DNS
# (NetBird's embedded resolver rewrites /etc/resolv.conf); MANAGE_DNS=0 passes
# --disable-dns so NetBird leaves host DNS alone (issue #2). Explicit boolean so
# toggling back on re-enables it rather than keeping the profile's last value.
if [ "$MANAGE_DNS" = "0" ] || [ "$MANAGE_DNS" = "false" ]; then
    UP_ARGS="$UP_ARGS --disable-dns=true"
else
    UP_ARGS="$UP_ARGS --disable-dns=false"
    # Unraid's rc.inet1 writes resolv.conf with inline comments on each line,
    # e.g. "nameserver 1.1.1.1  # eth0:v4". NetBird can't parse a nameserver off
    # a commented line, so it captures zero upstreams and its resolver refuses
    # ordinary lookups (issue #2). Strip the trailing comments off nameserver
    # lines just before `up` so NetBird records the real upstreams and split DNS
    # works out of the box. The comments are informational only. Skipped when DNS
    # management is off (NetBird leaves resolv.conf alone in that case).
    if grep -qE '^[[:space:]]*nameserver[[:space:]]+[^[:space:]#]+[[:space:]]*#' /etc/resolv.conf 2>/dev/null; then
        if cp -a /etc/resolv.conf "/etc/resolv.conf.preNB.$(date +%s)" 2>/dev/null \
           && sed -i -E 's/^([[:space:]]*nameserver[[:space:]]+[^[:space:]#]+).*/\1/' /etc/resolv.conf 2>/dev/null; then
            log "Stripped inline comments from /etc/resolv.conf nameserver lines (issue #2)."
        else
            log "WARN: could not sanitize /etc/resolv.conf; DNS forwarding may not work (issue #2)."
        fi
    fi
fi

log "Running: netbird up (profile '$PROFILE', mode '$MODE')"
OUT=$(timeout 90 "$NB" $UP_ARGS 2>&1)
RC=$?
echo "$OUT" >> /var/log/netbird-utils.log
if [ "$RC" -eq 0 ]; then
    log "netbird up succeeded for profile '$PROFILE'."
    reload_nginx_when_wt0_ready
    write_result true "connected"
elif [ "$RC" -eq 124 ]; then
    log "netbird up timed out for profile '$PROFILE' after 90s."
    write_result false "timed out connecting (check management URL / setup key)"
else
    log "netbird up failed (rc=$RC) for profile '$PROFILE': $OUT"
    write_result false "up failed (rc=$RC)"
fi
