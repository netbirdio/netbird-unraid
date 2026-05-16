#!/bin/bash
# Restart the NetBird daemon. Used both by the install step and by the
# "Restart" button on the Status page. Detaches via `at` so the install
# UI doesn't block waiting for the service.

. /usr/local/emhttp/plugins/netbird/include/log.sh 2>/dev/null || log() { echo "$*" ; }

log "Restarting NetBird in 5 seconds"
echo "sleep 5 ; /etc/rc.d/rc.netbird restart" | at now 2>/dev/null
