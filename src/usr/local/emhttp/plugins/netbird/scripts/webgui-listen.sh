#!/bin/sh
# Keep the Unraid WebGUI on the LAN when wt0 is added to nginx.
#
# Unraid listens on br/bond/eth/wlan/wg addresses it can see, then adds
# include_interfaces. A reload while the LAN interface has no address saves a
# config that only lists wt0, and that sticks until something reloads nginx
# again. Record the LAN interfaces once they have an address, and retry the
# reload until nginx is bound to the WebGUI port on those addresses and on wt0.
# A LAN interface that is up but has no address yet is waited on.

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

# Value of include_interfaces or exclude_interfaces. Unraid sources the file,
# so a quoted or a bare assignment are both real configs.
nb_cfg_list() {
    [ -f "$NB_NGINX_EXTRA" ] || return 0
    _line=$(grep -m1 "^$1=" "$NB_NGINX_EXTRA" || true)
    _val=${_line#"$1"=}
    _val=${_val#\"}
    _val=${_val%\"}
    printf '%s\n' "$_val"
}

nb_include_interfaces() {
    nb_cfg_list include_interfaces
}

# Unraid drops exclude_interfaces after adding include_interfaces, so nginx
# never binds these and they are not waited on.
nb_is_excluded() {
    case " $(nb_cfg_list exclude_interfaces) " in
        *" $1 "*) return 0 ;;
    esac
    return 1
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

# An interface with no IPv4 may still be waiting on DHCP when its link is up.
# One that is missing or down is treated as stale and not waited on.
nb_iface_waiting() {
    ip -br link show dev "$1" 2>/dev/null \
        | awk '$2 == "UP" || $2 == "UNKNOWN" { found=1 } END { exit !found }'
}

# Where the WebGUI listen set stands for the recorded LAN interfaces and wt0:
#   0  nginx is bound to every one that has an address, including a LAN one
#   1  one has an address nginx is not bound to, so reload
#   2  one is up without an address yet, or no LAN one is bound, so wait
nb_webgui_state() {
    _state=0
    _lan_bound=0
    for _iface in $(nb_recorded_lan_ifaces) wt0; do
        nb_is_excluded "$_iface" && continue
        _ip=$(nb_iface_v4 "$_iface")
        if [ -z "$_ip" ]; then
            if nb_iface_waiting "$_iface" && [ "$_state" -eq 0 ]; then
                _state=2
            fi
            continue
        fi
        if nb_nginx_has_ip "$_ip"; then
            [ "$_iface" = wt0 ] || _lan_bound=1
        else
            _state=1
        fi
    done
    if [ "$_state" -eq 0 ] && [ "$_lan_bound" -eq 0 ]; then
        _state=2
    fi
    return "$_state"
}

# Reload nginx, then keep checking for up to 12 seconds. A LAN interface that
# gets its address during the wait is recorded and nginx is reloaded for it.
# Reload again whenever an address is up that nginx is not bound to.
nb_reload_nginx_keep_lan() {
    nb_ensure_webgui_interfaces
    nb_nginx_reload
    _n=0
    while :; do
        _s=0
        nb_webgui_state || _s=$?
        [ "$_s" -eq 0 ] && return 0
        [ "$_n" -lt 6 ] || break
        _n=$((_n + 1))
        sleep 2
        nb_ensure_webgui_interfaces
        _s=0
        nb_webgui_state || _s=$?
        [ "$_s" -eq 1 ] && nb_nginx_reload
    done
    [ "$_s" -eq 1 ] || return 0
    nb_webgui_note "WARN: nginx is not bound to every WebGUI interface: $(nb_include_interfaces)"
    return 1
}
