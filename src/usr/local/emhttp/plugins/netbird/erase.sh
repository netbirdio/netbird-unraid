#!/bin/bash
# Wipe NetBird's local identity and any cached profile data. Triggered by
# the "Erase configuration" button on the Settings tab.

. /usr/local/emhttp/plugins/netbird/include/log.sh 2>/dev/null || log() { echo "$*" ; }

log "Stopping NetBird"
/etc/rc.d/rc.netbird stop

log "Erasing NetBird configuration and state"
rm -rf /boot/config/plugins/netbird/etc/*
rm -rf /boot/config/plugins/netbird/lib/*
rm -rf /boot/config/plugins/netbird/profiles
rm -f  /boot/config/plugins/netbird/netbird.cfg

# No restart here: deleting netbird.cfg reverts the install to the packaged
# default (disabled), so rc.netbird's start would refuse anyway. An erased
# installation deliberately stays down until it is reconfigured.
log "NetBird stopped; configuration erased. Enable it again from Settings."
