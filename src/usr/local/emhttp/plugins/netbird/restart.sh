#!/bin/bash
# Restart the NetBird daemon. Driven by the "Restart" button on the Status
# page; the array_started event uses ./reconcile.sh instead, and the installer
# calls rc.netbird directly.
#
# Detaches with nohup rather than `at` so the caller doesn't block on the
# service and atd doesn't mail the job's output to root on every restart.

. /usr/local/emhttp/plugins/netbird/include/log.sh 2>/dev/null || log() { echo "$*" ; }

log "Restarting NetBird in 5 seconds"
nohup /bin/sh -c 'sleep 5 ; /etc/rc.d/rc.netbird restart' >/dev/null 2>&1 </dev/null &
disown 2>/dev/null || true
