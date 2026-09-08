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
