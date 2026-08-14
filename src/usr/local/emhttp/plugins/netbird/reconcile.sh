#!/bin/bash
# Bring the NetBird daemon up if it isn't already. Wired to the array_started
# event by ../install/doinst.sh.
#
# This deliberately does NOT restart: rc.netbird's start is idempotent and
# honours ENABLE_NETBIRD, so a healthy daemon is left alone and a deliberately
# disabled one stays stopped. Tearing down a working daemon on every array
# start was what produced the boot-time restart storm.
#
# Detached with nohup rather than `at` so the event dispatcher isn't blocked
# waiting on the socket, and so atd doesn't mail the job's output to root on
# every boot. rc.netbird still records the cycle in /var/log/netbird-utils.log.

. /usr/local/emhttp/plugins/netbird/include/log.sh 2>/dev/null || log() { echo "$*" ; }

log "array_started: ensuring NetBird is up"
nohup /bin/sh -c 'sleep 5 ; /etc/rc.d/rc.netbird start' >/dev/null 2>&1 </dev/null &
disown 2>/dev/null || true
