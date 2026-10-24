#!/bin/sh
# Keep the Unraid WebGUI on the LAN when wt0 is added to nginx.
#
# Unraid listens on br/bond/eth/wlan/wg addresses it can see, then adds
# include_interfaces. A reload while the LAN interface has no address saves a
# config that only lists wt0, and that sticks until something reloads nginx
# again. Record the LAN interfaces once they have an address, and retry the
# reload until nginx is bound to the WebGUI port on those addresses. A
# recorded LAN interface that has no address yet is retried too.

PATH=/usr/local/sbin:/usr/sbin:/sbin:/usr/local/bin:/usr/bin:/bin
export PATH

: "${NB_NGINX_EXTRA:=/boot/config/network-extra.cfg}"
: "${NB_IDENT_CFG:=/boot/config/ident.cfg}"

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

# Value of include_interfaces. Unraid sources the file, so a quoted or a
# bare assignment are both real configs.
nb_include_interfaces() {
    [ -f "$NB_NGINX_EXTRA" ] || return 0
    _line=$(grep -m1 '^include_interfaces=' "$NB_NGINX_EXTRA" || true)
    _val=${_line#include_interfaces=}
    _val=${_val#\"}
    _val=${_val%\"}
    printf '%s\n' "$_val"
}

# include_interfaces gains wt0 and any LAN interface that is up. Other lines
# in the file, including exclude_interfaces, are left as they are.
nb_ensure_webgui_interfaces() {
    _existing=""
    if [ -f "$NB_NGINX_EXTRA" ]; then
        _existing=$(nb_include_interfaces)
    fi
    # shellcheck disable=SC2086
    _merged=$(printf '%s\n' $_existing $(nb_lan_ifaces) wt0 \
        | awk 'NF && !seen[$0]++ { printf "%s%s", (n++ ? " " : ""), $0 }')
    [ -n "$_merged" ] || _merged=wt0

    if [ -f "$NB_NGINX_EXTRA" ] && grep -q '^include_interfaces=' "$NB_NGINX_EXTRA"; then
        [ "$_existing" = "$_merged" ] && return 0
        sed -i "s|^include_interfaces=.*|include_interfaces=\"${_merged}\"|" "$NB_NGINX_EXTRA"
    elif [ -f "$NB_NGINX_EXTRA" ]; then
        printf 'include_interfaces="%s"\n' "$_merged" >> "$NB_NGINX_EXTRA"
    else
        printf 'include_interfaces="%s"\nexclude_interfaces=""\n' "$_merged" > "$NB_NGINX_EXTRA"
    fi
    nb_webgui_note "WebGUI listen interfaces: $_merged"
}

# LAN names already stored in include_interfaces. wt0 is not in this set.
nb_recorded_lan_ifaces() {
    _vals=$(nb_include_interfaces)
    [ -n "$_vals" ] || return 0
    # shellcheck disable=SC2086
    printf '%s\n' $_vals \
        | awk '$1 ~ /^(br|bond|eth|wlan|wg)[0-9]+(\.[0-9]+)?$/ { print $1 }'
}

# First global IPv4 on one interface, or nothing when it has no address.
nb_iface_v4() {
    ip -4 -br addr show scope global primary -deprecated dev "$1" 2>/dev/null \
        | awk '$3 != "" { split($3, a, "/"); print a[1]; exit }'
}

# Port the WebGUI is actually served on. SSL modes serve PORTSSL.
nb_webgui_port() {
    _ssl=""
    _port=""
    _portssl=""
    if [ -f "$NB_IDENT_CFG" ]; then
        _ssl=$(sed -n 's/^USE_SSL="\([^"]*\)".*/\1/p' "$NB_IDENT_CFG" | head -n 1)
        _port=$(sed -n 's/^PORT="\([^"]*\)".*/\1/p' "$NB_IDENT_CFG" | head -n 1)
        _portssl=$(sed -n 's/^PORTSSL="\([^"]*\)".*/\1/p' "$NB_IDENT_CFG" | head -n 1)
    fi
    [ -n "$_port" ] || _port=80
    [ -n "$_portssl" ] || _portssl=443
    case $_ssl in
        yes|auto) printf '%s\n' "$_portssl" ;;
        *) printf '%s\n' "$_port" ;;
    esac
}

# True when nginx owns the WebGUI socket on this address.
nb_nginx_has_ip() {
    _port=$(nb_webgui_port)
    ss -tlnp 2>/dev/null | awk -v addr="$1:$_port" '$4 == addr && /nginx/ { found=1 } END { exit !found }'
}

nb_nginx_reload() {
    if [ -n "${NB_NGINX_RELOAD_CMD:-}" ]; then
        # shellcheck disable=SC2086
        ${NB_NGINX_RELOAD_CMD} >/dev/null 2>&1 || true
        return 0
    fi
    /etc/rc.d/rc.nginx reload >/dev/null 2>&1 || true
}

# At least one recorded LAN interface has an address, and nginx has each of them.
nb_recorded_lan_bound() {
    _up=0
    _missing=0
    for _iface in $(nb_recorded_lan_ifaces); do
        _ip=$(nb_iface_v4 "$_iface")
        [ -n "$_ip" ] || continue
        _up=1
        nb_nginx_has_ip "$_ip" || _missing=1
    done
    [ "$_up" -eq 1 ] && [ "$_missing" -eq 0 ]
}

# Reload nginx, then reload again until a recorded LAN address is bound by
# nginx. An empty address list is not success when a LAN interface was
# already recorded: it may get its address during this wait.
nb_reload_nginx_keep_lan() {
    nb_ensure_webgui_interfaces
    _want=$(nb_recorded_lan_ifaces | tr '\n' ' ')
    nb_nginx_reload
    [ -n "$_want" ] || return 0
    _n=0
    while [ "$_n" -lt 6 ]; do
        nb_recorded_lan_bound && return 0
        _n=$((_n + 1))
        sleep 2
        nb_ensure_webgui_interfaces
        nb_nginx_reload
    done
    nb_recorded_lan_bound && return 0
    nb_webgui_note "WARN: WebGUI reload lost LAN listen address(es): $_want"
    return 1
}
