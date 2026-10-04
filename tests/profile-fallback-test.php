<?php
// Run only in the disposable, network-disabled container documented in README.md.
// The integration checks install a fake CLI and configuration at Unraid paths.
if (getenv('NETBIRD_TEST_CONTAINER') !== '1' || !is_file('/.dockerenv')) {
    fwrite(STDERR, "Run this test in the documented disposable Docker container.\n");
    exit(1);
}

$plugin = '/usr/local/emhttp/plugins/netbird';
require_once $plugin . '/include/common.php';
foreach ([Netbird\NETBIRD_BIN, Netbird\CFG_FILE, Netbird\RC_SCRIPT, Netbird\HTTP_SOCK, '/var/run/netbird.sock'] as $path) {
    if (file_exists($path)) {
        fwrite(STDERR, "Refusing to replace existing installation: $path\n");
        exit(1);
    }
}

$checks = 0;
$failures = 0;
function check(string $name, mixed $actual, mixed $expected): void
{
    global $checks, $failures;
    $checks++;
    if ($actual !== $expected) {
        $failures++;
        echo "FAIL $name: expected " . json_encode($expected) . ', got ' . json_encode($actual) . "\n";
    }
}

// The first fixture is the output captured from the released v0.80.0 binary.
$cases = [
    'v0.80 table' => ["NAME       ACTIVE\ndefault    ✓\ncompat080  \n", [
        ['name' => 'default', 'active' => true], ['name' => 'compat080', 'active' => false],
    ]],
    'active profile after inactive rows' => ["NAME       ACTIVE\ndefault\nwork-test  ✓\n", [
        ['name' => 'default', 'active' => false], ['name' => 'work-test', 'active' => true],
    ]],
    'legacy list' => ["Found 2 profiles:\n✗ default\n✓ work-test\n", [
        ['name' => 'default', 'active' => false], ['name' => 'work-test', 'active' => true],
    ]],
    'tabs, CRLF and names with spaces' => ["NAME\tACTIVE\r\nHome Lab\t✓\r\nFoundry backup\t\r\n\r\n", [
        ['name' => 'Home Lab', 'active' => true], ['name' => 'Foundry backup', 'active' => false],
    ]],
    'legacy names with spaces' => ["Found 2 profiles:\n✓ Home Lab\n✗ backup site\n", [
        ['name' => 'Home Lab', 'active' => true], ['name' => 'backup site', 'active' => false],
    ]],
    'no active profile' => ["NAME       ACTIVE\ndefault\n", [['name' => 'default', 'active' => false]]],
    'header only' => ["NAME       ACTIVE\n", []],
    'empty legacy list' => ["Found 0 profiles:\n", []],
    'empty output' => ['', []],
    'unrecognized output' => ["Error: daemon unavailable\n", []],
];

$tmp = sys_get_temp_dir() . '/netbird-profiles-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700);
putenv('NB_TEST_PROFILES=' . $tmp . '/profiles');
putenv('NB_TEST_CALLS=' . $tmp . '/calls');
putenv('NB_TEST_PROFILE_RC=0');
file_put_contents(Netbird\NETBIRD_BIN, <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "$NB_TEST_CALLS"
case "$*" in
    'profile list') cat "$NB_TEST_PROFILES"; exit "$NB_TEST_PROFILE_RC" ;;
    status) echo 'Management: Connected' ;;
    'profile select '*) exit 0 ;;
    *) exit 1 ;;
esac
SH
);
chmod(Netbird\NETBIRD_BIN, 0700);

foreach ($cases as $name => [$output, $expected]) {
    file_put_contents($tmp . '/profiles', $output);
    check($name . ' CLI fallback', Netbird\listProfilesCli(), $expected);
    check($name . ' without JSON gateway', Netbird\listProfiles(), $expected);
    $active = '';
    foreach ($expected as $profile) {
        if ($profile['active']) {
            $active = $profile['name'];
            break;
        }
    }
    check($name . ' active profile', Netbird\activeProfile(), $active);
}

// A failed CLI must not make partial output look like a usable profile list.
file_put_contents($tmp . '/profiles', $cases['v0.80 table'][0]);
putenv('NB_TEST_PROFILE_RC=1');
check('failed CLI ignores partial output', Netbird\listProfilesCli(), []);
check('failed CLI has no active profile', Netbird\activeProfile(), '');
putenv('NB_TEST_PROFILE_RC=0');

mkdir(dirname(Netbird\CFG_FILE), 0700, true);
file_put_contents(Netbird\CFG_FILE, "ENABLE_NETBIRD=\"1\"\nMANAGE_DNS=\"0\"\nENABLE_ROSENPASS=\"0\"\n");
mkdir(dirname(Netbird\RC_SCRIPT), 0700, true);
file_put_contents(Netbird\RC_SCRIPT, "#!/bin/sh\nexit 0\n");
chmod(Netbird\RC_SCRIPT, 0700);
$socket = stream_socket_server('unix:///var/run/netbird.sock', $errno, $error);
if ($socket === false) {
    throw new RuntimeException($error);
}

// Exercise the real apply script with no JSON gateway: a save of the connected
// active profile must stop after the status check, without selecting or upping.
foreach (['v0.80 table' => 'default', 'active profile after inactive rows' => 'work-test', 'legacy list' => 'work-test'] as $name => $active) {
    file_put_contents($tmp . '/profiles', $cases[$name][0]);
    file_put_contents($tmp . '/calls', '');
    $process = proc_open(['timeout', '-k', '1', '10', 'bash', $plugin . '/scripts/apply.sh', $active, 'ensure'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    check($name . ' apply exit (' . trim($out . $err) . ')', proc_close($process), 0);
    $result = json_decode(file_get_contents(Netbird\RESULT_FILE), true, 512, JSON_THROW_ON_ERROR);
    check($name . ' apply success', $result['ok'], true);
    check($name . ' apply no-op', $result['message'], 'no change (already connected)');
    check($name . ' does not reconnect', file_get_contents($tmp . '/calls'), "profile list\nstatus\n");
}
fclose($socket);

echo "$checks checks, $failures failures\n";
exit($failures > 0 ? 1 : 0);
