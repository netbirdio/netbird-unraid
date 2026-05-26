Thanks for the detailed writeup, and thanks @tycoon1807 / @Selmaks for helping dig in.

So here's what's going on: when NetBird comes up it starts its own DNS resolver and rewrites `/etc/resolv.conf` to point at itself, so it can do split DNS (resolve your NetBird domains + any nameservers your network defines, and forward the rest upstream). That's standard NetBird behavior — I just didn't account for it when putting the plugin together, so that's on me for overlooking it.

I think the reason internet names break for you is that NetBird's resolver becomes your only nameserver but has no working upstream to forward normal queries to — so IPs work, names don't. Same root cause as netbirdio/netbird#4250 and #3356.

Two fixes:

1. **Best option:** add a Nameserver group under **DNS** in the NetBird dashboard (what @Selmaks pointed at). That gives the resolver real upstreams and split DNS keeps working.
2. **Or just opt out:** `netbird up --disable-dns` leaves your `/etc/resolv.conf` alone. Trade-off is you lose auto-resolution of NetBird domains.

I'm also adding a **"Manage DNS"** toggle to the Settings page (on by default) that flips `--disable-dns` for you, so you won't have to do it by hand. Will update here when it ships.
