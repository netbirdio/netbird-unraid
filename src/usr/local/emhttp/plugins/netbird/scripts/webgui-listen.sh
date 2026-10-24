#!/bin/sh
# Keep the Unraid WebGUI on the LAN when wt0 is added to nginx.
#
# Unraid listens on br/bond/eth/wlan/wg addresses it can see, then adds
# include_interfaces. A reload while the LAN interface has no address saves a
# config that only lists wt0, and that sticks until something reloads nginx
# again. Record the LAN interfaces once they have an address, and retry the
# reload until those addresses are still bound.

PATH=/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin
export PATH

: "${NB_NGINX_EXTRA:=/boot/config/network-extra.cfg}"

nb_webgui_note() {
    # The shell prints its own error when the log file cannot be opened, so
    # discard that from a subshell rather than letting it into the installer.
    (
        echo "$(date '+%Y-%m-%d %H:%M:%S') [netbird] $*" >> /var/log/netbird-utils.log
    ) 2>/dev/null || true
}

# Interface names Unraid's own nginx scan would bind, that have an address now.
nb_lan_ifaces() {
    ip -br addr show scope global primary -deprecated 2>/dev/null \
        | awk '$1 ~ /^(br|bond|eth|wlan|wg)[0-9]+(\.[0-9]+)?$/ && $3 != "" {
            gsub(/@.*/, "", $1)
            print $1
          }' \
        | sort -u
}

nb_lan_v4() {
    ip -4 -br addr show scope global primary -deprecated 2>/dev/null \
        | awk '$1 ~ /^(br|bond|eth|wlan|wg)[0-9]+(\.[0-9]+)?$/ && $3 != "" {
            gsub(/@.*/, "", $1)
            split($3, a, "/")
            print a[1]
          }' \
        | sort -u
}

# include_interfaces gains wt0 and any LAN interface that is up. Other lines
# in the file, including exclude_interfaces, are left as they are.
nb_ensure_webgui_interfaces() {
    _existing=""
    if [ -f "$NB_NGINX_EXTRA" ]; then
        _existing=$(sed -n 's/^include_interfaces="\([^"]*\)".*/\1/p' "$NB_NGINX_EXTRA" | head -n 1)
    fi
    _merged=$(printf '%s\n' $_existing $(nb_lan_ifaces) wt0 \
        | awk 'NF && !seen[$0]++ { printf "%s%s", (n++ ? " " : ""), $0 }')
    [ -n "$_merged" ] || _merged=wt0

    if [ -f "$NB_NGINX_EXTRA" ] && grep -q '^include_interfaces=' "$NB_NGINX_EXTRA"; then
        [ "$_existing" = "$_merged" ] && return 0
        sed -i "s|^include_interfaces=\"[^\"]*\"|include_interfaces=\"${_merged}\"|" "$NB_NGINX_EXTRA"
    elif [ -f "$NB_NGINX_EXTRA" ]; then
        printf 'include_interfaces="%s"\n' "$_merged" >> "$NB_NGINX_EXTRA"
    else
        printf 'include_interfaces="%s"\nexclude_interfaces=""\n' "$_merged" > "$NB_NGINX_EXTRA"
    fi
    nb_webgui_note "WebGUI listen interfaces: $_merged"
}

# Reload nginx, then reload again if a LAN address that was up is no longer bound.
nb_reload_nginx_keep_lan() {
    nb_ensure_webgui_interfaces
    _lans=$(nb_lan_v4 | tr '\n' ' ')
    /etc/rc.d/rc.nginx reload >/dev/null 2>&1 || true
    [ -n "$_lans" ] || return 0
    _n=0
    while [ "$_n" -lt 6 ]; do
        _missing=0
        for _ip in $_lans; do
            ss -tln 2>/dev/null | grep -q "${_ip}:" || _missing=1
        done
        [ "$_missing" -eq 0 ] && return 0
        _n=$((_n + 1))
        sleep 2
        /etc/rc.d/rc.nginx reload >/dev/null 2>&1 || true
    done
    nb_webgui_note "WARN: WebGUI reload lost LAN listen address(es): $_lans"
    return 1
}
