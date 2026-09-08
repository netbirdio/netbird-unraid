<?php
// Run with PHP 8.2+ and the plugin mounted at its installed path.
// No daemon, root access, or host network changes are needed.
$plugin = '/usr/local/emhttp/plugins/netbird';
require_once $plugin . '/include/common.php';
$tmp = sys_get_temp_dir() . '/netbird-guard-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700);
register_shutdown_function(function () use ($tmp) {
    foreach (glob($tmp . '/*') as $file) {
        unlink($file);
    }
    rmdir($tmp);
});

file_put_contents($tmp . '/netbird', <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "$NB_TEST_CALLS"
case "$1" in
    status)
        if [ "${NB_TEST_HANG_STATUS:-0}" = 1 ]; then
            trap '' TERM
            sleep 30
        fi
        if [ "$2" = --json ]; then
            cat "$NB_TEST_STATUS"
            if [ -n "${NB_TEST_SECOND_STATUS:-}" ]; then
                printf '%s\n' "$NB_TEST_SECOND_STATUS" > "$NB_TEST_STATUS"
            fi
        elif grep -q '"quantumResistancePermissive":true' "$NB_TEST_STATUS"; then
            echo 'Quantum resistance: true (permissive)'
        else
            echo 'Quantum resistance: true'
        fi
        ;;
    down) exit 0 ;;
    up) cp "$NB_TEST_AFTER_UP" "$NB_TEST_STATUS" ;;
    *) exit 1 ;;
esac
SH
);
chmod($tmp . '/netbird', 0700);

$failures = 0;
$checks = 0;
function check(string $name, mixed $actual, mixed $expected): void
{
    global $failures, $checks;
    $checks++;
    if ($actual !== $expected) {
        $failures++;
        echo "FAIL $name: expected " . json_encode($expected) . ', got ' . json_encode($actual) . "\n";
    }
}

function peer(array $overrides = []): array
{
    return array_replace([
        'status' => 'Connected',
        'quantumResistance' => true,
        'lastWireguardHandshake' => gmdate('Y-m-d\TH:i:s\Z'),
    ], $overrides);
}

function status(array $peers, bool $permissive = false): string
{
    return json_encode([
        'quantumResistance' => true,
        'quantumResistancePermissive' => $permissive,
        'peers' => ['total' => count($peers), 'details' => $peers],
    ], JSON_THROW_ON_ERROR);
}

function runGuard(string $status, string $function = 'rosenpass_commit_confirm', array $extraEnv = []): array
{
    global $tmp, $plugin;
    file_put_contents($tmp . '/status.json', $status);
    file_put_contents($tmp . '/after-up.json', $extraEnv['NB_TEST_UP_STATUS'] ?? status([peer()], true));
    file_put_contents($tmp . '/calls', '');
    file_put_contents($tmp . '/netbird.cfg', "ENABLE_NETBIRD=\"1\"\nENABLE_ROSENPASS=\"1\"\n");
    $env = array_merge(getenv(), [
        'NB' => $tmp . '/netbird',
        'GLOBAL_CFG' => $tmp . '/netbird.cfg',
        'ENABLE_ROSENPASS' => '1',
        'RP_CONFIRM_WINDOW' => '0',
        'NB_TEST_STATUS' => $tmp . '/status.json',
        'NB_TEST_AFTER_UP' => $tmp . '/after-up.json',
        'NB_TEST_CALLS' => $tmp . '/calls',
    ], $extraEnv);
    $command = 'log() { :; }; . "$1"; ' . $function;
    $start = microtime(true);
    $process = proc_open(['timeout', '-k', '1', '8', 'sh', '-c', $command, 'guard-test', $plugin . '/include/rosenpass.sh'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = proc_close($process);
    return [$rc, file_get_contents($tmp . '/netbird.cfg'), file_get_contents($tmp . '/calls'), microtime(true) - $start, $out, $err];
}

$cases = [
    'UTC unset handshake' => [status([peer(['lastWireguardHandshake' => '0001-01-01T00:00:00Z'])]), 1],
    'captured Los Angeles unset handshake' => [status([peer(['lastWireguardHandshake' => '0000-12-31T16:07:02-07:52'])]), 1],
    'positive offset unset handshake' => [status([peer(['lastWireguardHandshake' => '0001-01-01T09:18:59+09:18'])]), 1],
    'local Unix epoch' => [status([peer(['lastWireguardHandshake' => '1969-12-31T16:00:00-08:00'])]), 1],
    'empty handshake' => [status([peer(['lastWireguardHandshake' => ''])]), 1],
    'invalid handshake' => [status([peer(['lastWireguardHandshake' => 'not a date'])]), 1],
    'invalid calendar date' => [status([peer(['lastWireguardHandshake' => '2026-02-30T00:00:00Z'])]), 1],
    'fresh handshake' => [status([peer()]), 0],
    'formatted JSON' => [json_encode(json_decode(status([peer()])), JSON_PRETTY_PRINT), 0],
    'fresh fractional handshake' => [status([peer(['lastWireguardHandshake' => gmdate('Y-m-d\TH:i:s') . '.123456789Z'])]), 0],
    'fresh non-UTC handshake' => [status([peer(['lastWireguardHandshake' => gmdate('Y-m-d\TH:i:s', time() - 7 * 3600) . '-07:00'])]), 0],
    'stale handshake' => [status([peer(['lastWireguardHandshake' => gmdate('Y-m-d\TH:i:s\Z', time() - 300)])]), 1],
    'future handshake' => [status([peer(['lastWireguardHandshake' => gmdate('Y-m-d\TH:i:s\Z', time() + 60)])]), 1],
    'disconnected peer with old stats' => [status([peer(['status' => 'Disconnected'])]), 1],
    'non-Rosenpass peer with old handshake' => [status([peer(['quantumResistance' => false])]), 1],
    'no peers' => [status([]), 0],
    'null list when no peers' => ['{"peers":{"total":0,"details":null}}', 0],
    'malformed JSON' => ['{"peers": broken}', 2],
    'truncated JSON' => ['{"peers":', 2],
    'missing details' => ['{"peers":{"total":1}}', 2],
    'missing peer entries' => ['{"peers":{"total":1,"details":[]}}', 2],
    'peer entry is not an object' => ['{"peers":{"total":1,"details":[null]}}', 2],
    'missing status' => ['{"peers":{"total":1,"details":[{}]}}', 2],
];
foreach ($cases as $name => [$fixture, $expected]) {
    check($name, runGuard($fixture)[0], $expected);
}

[$rc, $cfg, $calls] = runGuard($cases['captured Los Angeles unset handshake'][0], 'rosenpass_guard');
check('unset timestamp triggers fallback', $rc, 1);
check('fallback is saved', str_contains($cfg, 'ENABLE_ROSENPASS="permissive"'), true);
check('fallback disconnects before changing mode', strpos($calls, "down\n") < strpos($calls, "up --enable-rosenpass=true --rosenpass-permissive=true\n") && str_contains($calls, "down\n"), true);

[$rc, $cfg] = runGuard(status([peer()]), 'rosenpass_guard');
check('healthy peer keeps strict', $rc, 0);
check('healthy peer keeps configuration', str_contains($cfg, 'ENABLE_ROSENPASS="1"'), true);

check('successful up without live permissive mode is a failure',
    runGuard($cases['UTC unset handshake'][0], 'rosenpass_guard', ['NB_TEST_UP_STATUS' => status([peer()], false)])[0], 2);

[$rc, , , $elapsed] = runGuard(status([]), 'rosenpass_commit_confirm', ['NB_TEST_HANG_STATUS' => '1', 'RP_CONFIRM_WINDOW' => '1']);
check('hung status is unreadable', $rc, 2);
check('confirmation deadline bounds a status process ignoring TERM', $elapsed < 4, true);
check('an empty response does not hide later status errors', runGuard(status([]), 'rosenpass_commit_confirm', [
    'RP_CONFIRM_WINDOW' => '1', 'NB_TEST_SECOND_STATUS' => '{"peers": broken}',
])[0], 2);

check('UI normalizes local zero timestamp', Netbird\pbTimestamp('0000-12-31T16:07:02-07:52'), null);
check('UI hides local zero timestamp', Netbird\relativeTime('0000-12-31T16:07:02-07:52'), '-');
check('UI normalizes local epoch', Netbird\pbTimestamp('1969-12-31T16:00:00-08:00'), null);

$mapped = Netbird\mapGatewayStatus(['fullStatus' => ['peers' => [[
    'connStatus' => 'Connected', 'rosenpassEnabled' => true,
    'lastWireguardHandshake' => gmdate('Y-m-d\TH:i:s\Z'),
]]]], 'default');
check('gateway preserves peer Rosenpass support for UI health', Netbird\hasRosenpassHandshake($mapped['peers']['details'][0]), true);
check('UI flags an unset handshake even on a Rosenpass peer', Netbird\hasRosenpassHandshake(peer([
    'lastWireguardHandshake' => '0000-12-31T16:07:02-07:52',
])), false);

echo "$checks checks, $failures failures\n";
exit($failures > 0 ? 1 : 0);
