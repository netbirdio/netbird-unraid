# NetBird for Unraid

Run [NetBird](https://netbird.io), the open-source WireGuard mesh VPN, as a
native Unraid OS plugin. The Unraid host itself becomes a peer on your
NetBird network. No Docker container, no extra hop.

<img width="1058" height="559" alt="Screenshot 2026-05-16 at 2 05 12 PM" src="https://github.com/user-attachments/assets/048db37d-7384-4fc8-b42f-ce74df959951" />

## Install

In **Plugins → Install Plugin**, paste:

```
https://raw.githubusercontent.com/netbirdio/netbird-unraid/main/plugin/netbird.plg
```

Then open **Settings → Netbird**, enter a setup key, and select **Enable
NetBird: Yes**. Fresh installations remain disabled until that first successful
configuration.

## What you get

- The official upstream `netbird` Linux binary at `/usr/local/sbin/netbird`
- An rc.d service (`/etc/rc.d/rc.netbird`) that survives reboots once enabled
- Dynamix WebGUI pages: **Settings**, **Status**, **Info** + a Dashboard tile
- Persistent identity and config on the USB flash drive, so reboots don't
  forget who this peer is
- Multiple **profiles** (for example a self-hosted tenant and NetBird Cloud)
  that you can switch between from the Settings page

## How it works

NetBird's upstream packaging assumes systemd. Unraid runs Slackware with
plain init, so this plugin:

1. Downloads the upstream tarball from `github.com/netbirdio/netbird/releases`,
   pinned by SHA256.
2. Drops the binary at `/usr/local/sbin/netbird`.
3. Once enabled, runs `netbird service run` from `/etc/rc.d/rc.netbird`
   (foreground daemon, logging to `/var/log/netbird.log`).
4. Symlinks NetBird's config paths onto the flash drive so the peer
   identity survives Unraid's ephemeral rootfs. The files NetBird rewrites
   while it is running, `state.json` and the `resolv.conf` backup, are
   sent to `/var/run/netbird` on the root ramdisk instead:

   | NetBird default      | Symlinked to                          |
   | -------------------- | ------------------------------------- |
   | `/etc/netbird`       | `/boot/config/plugins/netbird/etc`    |
   | `/var/lib/netbird`   | `/boot/config/plugins/netbird/lib`    |

5. Adds an array-start event hook so an enabled daemon comes up whenever the
   array starts. Disabled installations remain stopped.

## Notes

A few NetBird behaviors are worth knowing when editing a profile:

- Changing a profile's **management URL or hostname** re-registers the peer. It
  gets a new identity and IP, and the old peer lingers in your NetBird
  dashboard until you delete it there.
- A **pre-shared key can be set or changed but not cleared** from the UI.
  Removing it requires erasing the profile and setting it up again.
- **Lazy Connections** defaults to **Follow account setting**. Select **Enabled**
  or **Disabled** to override it for this host across all profiles. Applying a
  change restarts the daemon briefly; an unchanged save leaves it running.
  Rosenpass keeps permanent connections regardless of this setting.
  Use the plugin's setting instead of `netbird service reconfigure`, which
  expects an upstream-managed service. The plugin controls `NB_LAZY_CONN`,
  including clearing overrides inherited from `custom-params.sh` when following
  the account setting. See the [NetBird lazy connections guide](https://docs.netbird.io/manage/peers/lazy-connection).

## Building locally

```bash
./scripts/build.sh
```

Produces:

- `dist/unraid-netbird-utils-<version>-noarch-1.txz`
- `dist/netbird.plg` with the version and package SHA256 substituted in

Upload the `.txz` to a GitHub release tagged with the same version, then
commit the updated `plugin/netbird.plg` to `main` so users get the new
version through normal plugin updates.

## License

GPL-3.0. See [LICENSE](LICENSE).

## Credit

The official Unraid
[Tailscale plugin](https://github.com/unraid/unraid-tailscale) by Derek
Kaser, was used as a base for this plugin, same general daemon+CLI+Dynamix shape. Plugin docs from the
[mstrhakr/plugin-docs](https://github.com/mstrhakr/plugin-docs) project.
