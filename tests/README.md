Run the Rosenpass regression tests from the repository root:

```sh
docker run --rm --network none \
  -v "$PWD:/work:ro" \
  -v "$PWD/src/usr/local/emhttp/plugins/netbird:/usr/local/emhttp/plugins/netbird:ro" \
  -w /work php:8.2-cli php tests/rosenpass-test.php
```

The tests exercise the installed shell guard and PHP status helpers with a
temporary fake NetBird CLI. They cover unset timestamps in different timezones,
handshake freshness and peer capability, malformed responses, persistent
fallback, and a hung status process. No host networking or real daemon is used.

Run the CLI profile fallback tests in a disposable container:

```sh
docker run --rm --network none -e NETBIRD_TEST_CONTAINER=1 \
  -v "$PWD:/work:ro" \
  -v "$PWD/src/usr/local/emhttp/plugins/netbird:/usr/local/emhttp/plugins/netbird:ro" \
  -w /work php:8.2-cli php tests/profile-fallback-test.php
```

These tests install a fake CLI, service script, and configuration inside the
container. They cover current NAME/ACTIVE tables, legacy marker-first lists,
inactive profiles, whitespace, CLI failures, and the real apply script's no-op
save behavior when the JSON gateway is unavailable. Do not run them on a live
Unraid host.
