# Slackware post-install hook for unraid-netbird-utils.
# Runs once at upgradepkg time, with the package root as $PWD.

# Make sure the rc.d script is executable.
chmod 0755 usr/local/etc/rc.d/rc.netbird
# Symlink it into /etc/rc.d only when that's a distinct directory. On stock
# Unraid /etc/rc.d is itself a symlink to /usr/local/etc/rc.d, so the payload
# is already reachable as /etc/rc.d/rc.netbird and adding a link would just
# point the file at itself (a symlink loop that breaks the daemon).
if [ ! -e etc/rc.d/rc.netbird ]; then
    ( cd etc/rc.d && ln -sf /usr/local/etc/rc.d/rc.netbird rc.netbird )
fi

# logrotate ownership
chmod 0644 etc/logrotate.d/netbird
chown root:root etc/logrotate.d/netbird

# Event hooks: restart daemon when array starts; stop on shutdown
( cd usr/local/emhttp/plugins/netbird/event
  rm -f array_started stopped started stopping_svcs
  ln -sf ../restart.sh array_started
  ln -sf ../restart.sh started
)

# Make all *.sh executable inside the page tree
find usr/local/emhttp/plugins/netbird -name "*.sh" -exec chmod 0755 {} \;
