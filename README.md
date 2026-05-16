# NetBird for Unraid

Run [NetBird](https://netbird.io) — the open-source WireGuard mesh VPN — as a
native Unraid OS plugin. The Unraid host itself becomes a peer on your
NetBird network. No Docker container, no extra hop.

<img width="1058" height="559" alt="Screenshot 2026-05-16 at 2 05 12 PM" src="https://github.com/user-attachments/assets/048db37d-7384-4fc8-b42f-ce74df959951" />


> ⚠️ Preview release. Validated on Unraid 7.0+. Not yet listed in Community Applications
>

## Install

In **Plugins → Install Plugin**, paste:

```
https://raw.githubusercontent.com/netbirdio/netbird-unraid/main/plugin/netbird.plg
```

Then open **Settings → Netbird** to configure.

## What you get

- The official upstream `netbird` Linux binary at `/usr/local/sbin/netbird`
- An rc.d service (`/etc/rc.d/rc.netbird`) that survives reboots
- Dynamix WebGUI pages: **Settings**, **Status**, **Info** + a Dashboard tile
- Persistent identity and config on the USB flash drive, so reboots don't
  forget who this peer is

## How it works

NetBird's upstream packaging assumes systemd. Unraid runs Slackware with
plain init, so this plugin:

1. Downloads the upstream tarball from `github.com/netbirdio/netbird/releases`,
   pinned by SHA256.
2. Drops the binary at `/usr/local/sbin/netbird`.
3. Runs `netbird service run` from `/etc/rc.d/rc.netbird` (foreground daemon,
   logging to `/var/log/netbird.log`).
4. Symlinks NetBird's two state paths onto the flash drive so the identity
   survives Unraid's ephemeral rootfs:

   | NetBird default      | Symlinked to                          |
   | -------------------- | ------------------------------------- |
   | `/etc/netbird`       | `/boot/config/plugins/netbird/etc`    |
   | `/var/lib/netbird`   | `/boot/config/plugins/netbird/lib`    |

5. Adds an array-start event hook so the daemon comes up whenever the array
   starts.

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

## Credit

The structure here is inspired by the official Unraid
[Tailscale plugin](https://github.com/unraid/unraid-tailscale) by Derek
Kaser, same general daemon+CLI+Dynamix shape. Plugin docs from the
[mstrhakr/plugin-docs](https://github.com/mstrhakr/plugin-docs) project.
