# Tiny logger sourced by rc.netbird and other scripts.
log() {
    local _msg
    _msg="$(date '+%Y-%m-%d %H:%M:%S') [netbird] $*"
    echo "$_msg" >>/var/log/netbird-utils.log
    echo "$_msg"
}
