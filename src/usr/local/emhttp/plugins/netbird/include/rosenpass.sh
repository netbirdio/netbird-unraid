#!/bin/sh
# Shared strict-Rosenpass safety net.
#
# Sourced by apply.sh and by scripts/rosenpass-watchdog.sh, so every path that
# can bring a profile up while strict is armed gets the same verification
# instead of only the Settings save path. Callers need $NB, $GLOBAL_CFG,
# $ENABLE_ROSENPASS and a log() function.

# CLI timestamps use the host timezone, even for Go's zero time. Parse JSON
# and dates instead of matching the UTC sentinel's literal date prefix.
nb_handshake_stats() {
    _hs_out=$(timeout -k 1 "${1:-3}" "$NB" status --json 2>/dev/null) || return 1
    printf '%s\n' "$_hs_out" | php /usr/local/emhttp/plugins/netbird/include/handshake.php 2>/dev/null
}

# Write permissive, then read it back: a failed sed or read-only /boot must not
# be reported as a durable revert.
nb_persist_rosenpass_permissive() {
    if grep -q '^ENABLE_ROSENPASS=' "$GLOBAL_CFG" 2>/dev/null; then
        sed -i 's/^ENABLE_ROSENPASS=.*/ENABLE_ROSENPASS="permissive"/' "$GLOBAL_CFG" || return 1
    else
        # Without a trailing newline the key would glue onto the last line.
        if [ -s "$GLOBAL_CFG" ] && [ -n "$(tail -c 1 "$GLOBAL_CFG")" ]; then
            printf '\n' >> "$GLOBAL_CFG" || return 1
        fi
        printf 'ENABLE_ROSENPASS="permissive"\n' >> "$GLOBAL_CFG" || return 1
    fi
    grep -q '^ENABLE_ROSENPASS="permissive"$' "$GLOBAL_CFG" 2>/dev/null
}

nb_live_rosenpass_permissive() {
    _hs_out=$(timeout -k 1 3 "$NB" status --json 2>/dev/null) || return 1
    printf '%s\n' "$_hs_out" | php /usr/local/emhttp/plugins/netbird/include/handshake.php permissive 2>/dev/null
}

# Commit-confirm for strict Rosenpass, like a router's "reload in 5": strict
# refuses peers that don't run it and the setting survives a reboot, so prove the
# data plane works before leaving it armed. 0 = keep strict (a handshake landed,
# or no peers for the whole window), 1 = peers but no handshake, 2 = status
# unreadable. Non-zero reverts; "unknown" must not read as "fine". Zero peers is
# only believed if it holds all window, since the list is empty just after `up`.
rosenpass_commit_confirm() {
    _rp_deadline=$(( $(date +%s) + ${RP_CONFIRM_WINDOW:-30} ))
    _rp_saw_status=0
    _rp_saw_peers=0
    _rp_unreadable=0
    while :; do
        _rp_remaining=$(( _rp_deadline - $(date +%s) ))
        _rp_probe=$_rp_remaining
        [ "$_rp_probe" -gt 0 ] || _rp_probe=1
        [ "$_rp_probe" -le 3 ] || _rp_probe=3
        if _rp_census=$(nb_handshake_stats "$_rp_probe"); then
            _rp_saw_status=1
            set -- $_rp_census
            [ "${1:-0}" -gt 0 ] && _rp_saw_peers=1
            [ "${2:-0}" -gt 0 ] && return 0
        else
            _rp_unreadable=1
        fi
        _rp_remaining=$(( _rp_deadline - $(date +%s) ))
        [ "$_rp_remaining" -gt 0 ] || break
        [ "$_rp_remaining" -le 5 ] || _rp_remaining=5
        sleep "$_rp_remaining"
    done
    [ "$_rp_saw_status" -eq 0 ] && return 2
    [ "$_rp_unreadable" -eq 1 ] && return 2
    [ "$_rp_saw_peers" -eq 1 ] && return 1
    return 0
}

# The whole safety net in one call. $1 = the `up` argument string to reconnect
# with (defaults to just the Rosenpass flags, which is all a revert needs since
# NetBird keeps the profile's other values). Sets RP_GUARD_MSG for the caller's
# result reporting. Returns 0 nothing-to-do-or-verified, 1 reverted, 2 revert
# needed but incomplete.
rosenpass_guard() {
    RP_GUARD_MSG="connected"
    [ "${ENABLE_ROSENPASS:-0}" = "1" ] || return 0

    rosenpass_commit_confirm
    _rp_rc=$?
    [ "$_rp_rc" -eq 0 ] && return 0
    if [ "$_rp_rc" -eq 1 ]; then
        _rp_why="no Rosenpass peer has a recent handshake"
    else
        _rp_why="peer status was unreadable"
    fi
    log "Strict Rosenpass: $_rp_why within ${RP_CONFIRM_WINDOW:-30}s; reverting to permissive."

    # Persist the safer value first so a reboot can't re-arm the lockout.
    if ! nb_persist_rosenpass_permissive; then
        log "ERROR: could not write ENABLE_ROSENPASS=permissive to $GLOBAL_CFG; strict stays armed for the next boot."
        RP_GUARD_MSG="strict Rosenpass unverified ($_rp_why) and the permissive fallback could not be saved"
        return 2
    fi

    _rp_args="${1:-up --enable-rosenpass=true --rosenpass-permissive=true}"
    _rp_args=$(printf '%s' "$_rp_args" | sed 's/--rosenpass-permissive=false/--rosenpass-permissive=true/')
    # `down` first: `up` on a live profile is a no-op (see 'reconnect').
    if ! timeout -k 1 30 "$NB" down >/dev/null 2>&1; then
        log "WARN: netbird down failed before the permissive retry; the following up may be a no-op."
    fi
    _rp_out=$(timeout -k 1 90 "$NB" $_rp_args 2>&1)
    _rp_up=$?
    if [ "$_rp_up" -ne 0 ]; then
        log "Permissive Rosenpass reconnect failed (rc=$_rp_up): $_rp_out"
        RP_GUARD_MSG="strict Rosenpass unverified ($_rp_why); permissive retry failed (rc=$_rp_up)"
        return 2
    fi
    if ! nb_live_rosenpass_permissive; then
        log "Permissive reconnect returned success but the daemon is not in permissive mode."
        RP_GUARD_MSG="strict Rosenpass unverified ($_rp_why); permissive is not in effect"
        return 2
    fi
    log "Reconnected with permissive Rosenpass."
    RP_GUARD_MSG="strict Rosenpass unverified ($_rp_why); reverted to permissive"
    return 1
}
