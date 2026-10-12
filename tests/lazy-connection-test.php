<?php
// Installs stubs at Unraid paths. Run only in the documented disposable container.
if (getenv('NETBIRD_TEST_CONTAINER') !== '1' || !is_file('/.dockerenv')) {
    fwrite(STDERR, "Run this test in the documented disposable Docker container.\n");
    exit(1);
}

$root = dirname(__DIR__);
$plugin = $root . '/src/usr/local/emhttp/plugins/netbird';
require_once $plugin . '/include/common.php';
foreach ([Netbird\CFG_FILE, Netbird\RC_SCRIPT, '/usr/local/emhttp/plugins/netbird/scripts/apply.sh'] as $path) {
    if (file_exists($path)) {
        throw new RuntimeException("Refusing to replace existing installation: $path");
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

function run(array $command, array $env = []): array
{
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, array_merge(getenv(), $env));
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $out, $err];
}

// Execute the production startup environment block in a child shell, then
// inspect a child's environment to catch missing exports as well as stale ones.
$rc = file_get_contents($root . '/src/usr/local/etc/rc.d/rc.netbird');
$begin = strpos($rc, '    # Lazy connections are a daemon environment override');
$end = strpos($rc, '    # Remove stale sockets', $begin);
if ($begin === false || $end === false) {
    throw new RuntimeException('Cannot locate daemon environment block');
}
$environmentBlock = substr($rc, $begin, $end - $begin);
foreach (['auto' => false, 'on' => 'on', 'off' => 'off', '' => false, 'invalid' => false] as $mode => $expected) {
    [$exit, $out, $err] = run(['sh', '-c', $environmentBlock . "\nphp -r 'echo json_encode(getenv(\"NB_LAZY_CONN\"));'"],
        ['LAZY_CONNECTION' => $mode, 'NB_LAZY_CONN' => 'inherited']);
    check("$mode startup exit: $err", $exit, 0);
    check("$mode exported environment", json_decode($out, true), $expected);
}
[$exit, $out] = run(['sh', '-c', 'unset LAZY_CONNECTION; ' . $environmentBlock . "\nphp -r 'echo json_encode(getenv(\"NB_LAZY_CONN\"));'"], ['NB_LAZY_CONN' => 'on']);
check('legacy configuration follows account', json_decode($out, true), false);

// Exercise the apply lifecycle block with real /proc environments. Only PID
// discovery and service mutations are stubbed; no real NetBird daemon is used.
mkdir(dirname(Netbird\RC_SCRIPT), 0700, true);
file_put_contents(Netbird\RC_SCRIPT, <<<'SH'
#!/bin/sh
echo "$1" >> /tmp/nb-service-calls
exit "${NB_TEST_SERVICE_EXIT:-0}"
SH
);
chmod(Netbird\RC_SCRIPT, 0700);
$apply = file_get_contents($plugin . '/scripts/apply.sh');
$begin = strpos($apply, '# Compare the actual startup environment');
$end = strpos($apply, '# Wait for the daemon socket', $begin);
if ($begin === false || $end === false) {
    throw new RuntimeException('Cannot locate apply lifecycle block');
}
$lifecycle = substr($apply, $begin, $end - $begin);
$lifecycle = str_replace('/usr/bin/pgrep -f "^${NB} service run"', 'printf "%s" "$NB_TEST_PID"', $lifecycle, $replacements);
check('one PID discovery stub', $replacements, 1);
$lifecycle = "log() { :; }\nwrite_result() { printf '%s:%s' \"\$1\" \"\$2\"; }\n" . $lifecycle;
foreach ([null, '', 'on', 'off'] as $running) {
    $env = getenv();
    unset($env['NB_LAZY_CONN']);
    // proc_open omits empty environment values; env preserves an explicitly
    // empty override. Wait for exec so /proc reflects the final environment.
    $command = $running === null ? ['sleep', '120'] : ['env', 'NB_LAZY_CONN=' . $running, 'sleep', '120'];
    $daemon = proc_open($command, [], $pipes, null, $env);
    $pid = proc_get_status($daemon)['pid'];
    try {
        for ($i = 0; $i < 100; $i++) {
            clearstatcache();
            if (basename(readlink("/proc/$pid/exe")) === 'sleep') {
                break;
            }
            usleep(1000);
        }
        check('fixture reached sleep', basename(readlink("/proc/$pid/exe")), 'sleep');
        foreach (['auto', 'on', 'off', 'invalid'] as $mode) {
            file_put_contents('/tmp/nb-service-calls', '');
            [$exit, $out, $err] = run(['bash', '-c', $lifecycle], ['LAZY_CONNECTION' => $mode, 'NB_TEST_PID' => (string) $pid]);
            $wanted = in_array($mode, ['on', 'off'], true) ? $mode : null;
            check("$mode from " . json_encode($running) . " exit: $err", $exit, 0);
            check("$mode from " . json_encode($running) . ' restart only when needed', file_get_contents('/tmp/nb-service-calls'), $wanted === $running ? '' : "restart\n");
        }
        if ($running === 'on') {
            file_put_contents('/tmp/nb-service-calls', '');
            [$exit, $out] = run(['bash', '-c', $lifecycle], ['LAZY_CONNECTION' => 'off', 'NB_TEST_PID' => (string) $pid, 'NB_TEST_SERVICE_EXIT' => '1']);
            check('failed restart exits unsuccessfully', $exit, 1);
            check('failed restart reports apply failure', str_starts_with($out, 'false:'), true);
            run(['bash', '-c', $lifecycle], ['LAZY_CONNECTION' => 'off', 'NB_TEST_PID' => (string) $pid]);
            check('retry after failure restarts again', file_get_contents('/tmp/nb-service-calls'), "restart\nrestart\n");
        }
    } finally {
        proc_terminate($daemon);
        proc_close($daemon);
    }
}
file_put_contents('/tmp/nb-service-calls', '');
[$exit] = run(['bash', '-c', $lifecycle], ['LAZY_CONNECTION' => 'off', 'NB_TEST_PID' => '']);
check('stopped daemon starts', file_get_contents('/tmp/nb-service-calls'), "start\n");
[$exit, $out] = run(['bash', '-c', $lifecycle], ['LAZY_CONNECTION' => 'off', 'NB_TEST_PID' => '', 'NB_TEST_SERVICE_EXIT' => '1']);
check('failed start exits unsuccessfully', $exit, 1);
check('failed start reports apply failure', str_starts_with($out, 'false:'), true);

// Exercise the real save endpoint. The background worker is stubbed so these
// checks only save config, including while disabled and on older forms.
mkdir(dirname(Netbird\CFG_FILE), 0700, true);
mkdir('/usr/local/emhttp/plugins/netbird/scripts', 0700, true);
file_put_contents('/usr/local/emhttp/plugins/netbird/scripts/apply.sh', "#!/bin/sh\nexit 0\n");
chmod('/usr/local/emhttp/plugins/netbird/scripts/apply.sh', 0700);
$save = '$_SERVER["REQUEST_METHOD"] = "POST"; $_POST = json_decode(getenv("NB_TEST_POST"), true); include ' . var_export($plugin . '/include/action.php', true) . ';';
$basePost = ['action' => 'save', 'name' => 'default', 'ENABLE_NETBIRD' => '0'];
foreach (['auto', 'on', 'off'] as $mode) {
    [$exit, $out, $err] = run(['php', '-r', $save], ['NB_TEST_POST' => json_encode($basePost + ['LAZY_CONNECTION' => $mode])]);
    check("save $mode exit: $err", $exit, 0);
    check("save $mode accepted", json_decode($out, true)['pending'] ?? null, true);
    check("save $mode persisted", Netbird\readCfg()['LAZY_CONNECTION'] ?? null, $mode);
    check("save $mode stays disabled", Netbird\readCfg()['ENABLE_NETBIRD'] ?? null, '0');
}
run(['php', '-r', $save], ['NB_TEST_POST' => json_encode($basePost)]);
check('older form preserves override', Netbird\readCfg()['LAZY_CONNECTION'], 'off');
$before = file_get_contents(Netbird\CFG_FILE);
foreach (['invalid', '$(touch /tmp/injected)', ['on']] as $invalid) {
    [$exit, $out] = run(['php', '-r', $save], ['NB_TEST_POST' => json_encode($basePost + ['LAZY_CONNECTION' => $invalid])]);
    check('invalid override rejected', json_decode($out, true)['type'] ?? null, 'error');
    check('invalid override leaves config untouched', file_get_contents(Netbird\CFG_FILE), $before);
}
check('invalid input never evaluated', file_exists('/tmp/injected'), false);
file_put_contents(Netbird\CFG_FILE, "ENABLE_NETBIRD=\"0\"\n");
run(['php', '-r', $save], ['NB_TEST_POST' => json_encode($basePost)]);
check('older config and form default to account', Netbird\readCfg()['LAZY_CONNECTION'], 'auto');

echo "$checks checks, $failures failures\n";
exit($failures === 0 ? 0 : 1);
