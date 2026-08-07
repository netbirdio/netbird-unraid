<?php
/**
 * Shared helpers for the NetBird Unraid plugin.
 */

namespace Netbird;

const PLUGIN          = 'netbird';
const NETBIRD_BIN     = '/usr/local/sbin/netbird';
const RC_SCRIPT       = '/etc/rc.d/rc.netbird';
const CFG_FILE        = '/boot/config/plugins/netbird/netbird.cfg';
const PROFILE_DIR     = '/boot/config/plugins/netbird/profiles';
const DAEMON_ADDR     = 'unix:///var/run/netbird.sock';

// Advisory lock serializing daemon-mutating ops (apply.sh + the connect/profile
// actions) so concurrent runs can't cancel each other. Shared with apply.sh via
// flock(2), which PHP flock() and flock(1) both use on Linux.
const LOCK_FILE       = '/var/run/netbird-apply.lock';

// Where apply.sh records the outcome of the last apply, for UI feedback.
const RESULT_FILE     = '/var/run/netbird-apply-result.json';

// Credential keys stored per profile (the rest of netbird.cfg is daemon-global).
const PROFILE_KEYS    = ['MANAGEMENT_URL', 'SETUP_KEY', 'HOSTNAME', 'PRESHARED_KEY'];

/**
 * Run a netbird CLI subcommand and return [exitCode, stdout].
 * When $timeoutSec > 0 the command is wrapped in timeout(1) so a hung call
 * (e.g. `up` retrying login) can't block the web request indefinitely; a
 * timeout surfaces as exit code 124.
 *
 * @param string[] $args
 * @return array{0:int,1:string}
 */
function nb(array $args, int $timeoutSec = 0): array
{
    $cmd = escapeshellcmd(NETBIRD_BIN) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
    if ($timeoutSec > 0) {
        $cmd = 'timeout ' . $timeoutSec . ' ' . $cmd;
    }
    $out = [];
    $rc  = 0;
    exec($cmd, $out, $rc);
    return [$rc, implode("\n", $out)];
}

// ---------------------------------------------------------------------------
// Daemon HTTP/JSON gateway (NetBird >= 0.75, daemon started with
// --enable-json-socket by rc.netbird). Exposes the daemon gRPC API as
// POST /daemon.DaemonService/<Method> with protobuf-JSON bodies over a
// root-only unix socket — structured data without exec()'ing the CLI.
// ---------------------------------------------------------------------------

const HTTP_SOCK = '/var/run/netbird-http.sock';

/**
 * True when the daemon's JSON gateway socket is present and usable.
 */
function apiAvailable(): bool
{
    return file_exists(HTTP_SOCK) && function_exists('curl_init');
}

/**
 * POST to the daemon's JSON gateway.
 *
 * Returns:
 *   ok        — call succeeded (HTTP 200, valid JSON body)
 *   data      — decoded response on success, null otherwise
 *   error     — human-readable error text on failure
 *   reachable — false only when the gateway itself couldn't be reached
 *               (socket missing / connect failure): callers should fall back
 *               to the CLI. True for application-level errors, which must be
 *               surfaced, not silently retried through the CLI.
 *
 * @param array<string,mixed> $body
 * @return array{ok:bool, data:?array, error:string, reachable:bool}
 */
function apiCall(string $method, array $body = [], int $timeoutSec = 10): array
{
    if (!apiAvailable()) {
        return ['ok' => false, 'data' => null, 'error' => 'JSON gateway socket not available', 'reachable' => false];
    }
    $ch = curl_init('http://localhost/daemon.DaemonService/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_UNIX_SOCKET_PATH => HTTP_SOCK,
        CURLOPT_POST             => true,
        CURLOPT_POSTFIELDS       => json_encode((object) $body),
        CURLOPT_HTTPHEADER       => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER   => true,
        CURLOPT_TIMEOUT          => $timeoutSec,
    ]);
    $raw   = curl_exec($ch);
    $errno = curl_errno($ch);
    $http  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false) {
        // A timeout means the daemon accepted the request but didn't answer in
        // time — it may still be executing the call, so report it reachable to
        // keep nb_api_or_cli() from re-issuing the operation through the CLI.
        // Other curl failures (connect refused, etc.) mean the gateway isn't
        // usable and the CLI fallback is safe.
        return [
            'ok'        => false,
            'data'      => null,
            'error'     => "gateway request failed (curl errno $errno)",
            'reachable' => $errno === CURLE_OPERATION_TIMEDOUT,
        ];
    }
    $decoded = json_decode((string) $raw, true);
    if ($http !== 200) {
        // grpc-gateway maps gRPC errors to {code, message} plus an HTTP status.
        $msg = is_array($decoded) && !empty($decoded['message']) ? (string) $decoded['message'] : "HTTP $http";
        return ['ok' => false, 'data' => null, 'error' => $msg, 'reachable' => true];
    }
    if (!is_array($decoded)) {
        return ['ok' => false, 'data' => null, 'error' => 'gateway returned invalid JSON', 'reachable' => true];
    }
    return ['ok' => true, 'data' => $decoded, 'error' => '', 'reachable' => true];
}

/**
 * OS username the daemon scopes profile operations to. The web UI runs as
 * root on Unraid, same as the CLI, so profiles line up between the two.
 */
function apiUsername(): string
{
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $pw = @posix_getpwuid(posix_geteuid());
        if (is_array($pw) && !empty($pw['name'])) {
            return $pw['name'];
        }
    }
    return 'root';
}

/**
 * First present key wins. Shields the gateway mapping from protojson json_name
 * ambiguity on all-caps proto fields (IP/URL/URI keep their casing today, but
 * a regenerated gateway could camel-case them).
 *
 * @param array<string,mixed> $a
 * @param string[] $keys
 */
function pbGet(array $a, array $keys, mixed $default = null): mixed
{
    foreach ($keys as $k) {
        if (array_key_exists($k, $a)) {
            return $a[$k];
        }
    }
    return $default;
}

/**
 * protobuf-JSON Duration ("0.0125s") → nanoseconds, the unit the CLI's
 * status --json uses and formatLatency() expects.
 */
function pbDurationNs(mixed $dur): int
{
    if (is_string($dur) && preg_match('/^([0-9]*\.?[0-9]+)s$/', $dur, $m)) {
        return (int) round(((float) $m[1]) * 1_000_000_000);
    }
    return 0;
}

/**
 * protobuf-JSON Timestamp → value relativeTime() treats as unknown when the
 * daemon reported a zero time (protojson encodes it as the Unix epoch).
 */
function pbTimestamp(?string $ts): ?string
{
    if (!$ts || str_starts_with($ts, '1970-01-01') || str_starts_with($ts, '0001-01-01')) {
        return null;
    }
    return $ts;
}

/**
 * Reshape a gateway StatusResponse into the `netbird status --json` schema the
 * views were written against, so the CLI remains a drop-in fallback source.
 *
 * @param array<string,mixed> $resp
 * @return array<string,mixed>
 */
function mapGatewayStatus(array $resp, string $profileName): array
{
    $fs    = is_array($resp['fullStatus']      ?? null) ? $resp['fullStatus']      : [];
    $local = is_array($fs['localPeerState']    ?? null) ? $fs['localPeerState']    : [];
    $mgmt  = is_array($fs['managementState']   ?? null) ? $fs['managementState']   : [];
    $sig   = is_array($fs['signalState']       ?? null) ? $fs['signalState']       : [];

    $peers     = [];
    $connected = 0;
    foreach (($fs['peers'] ?? []) as $p) {
        if (!is_array($p)) {
            continue;
        }
        $state = (string) ($p['connStatus'] ?? '');
        if ($state === 'Connected') {
            $connected++;
        }
        $peers[] = [
            'fqdn'             => $p['fqdn'] ?? '',
            'netbirdIp'        => pbGet($p, ['IP', 'ip'], ''),
            'netbirdIpv6'      => $p['ipv6'] ?? '',
            'status'           => $state,
            'connectionType'   => !empty($p['relayed']) ? 'Relayed' : 'P2P',
            'iceCandidateType' => [
                'local'  => $p['localIceCandidateType']  ?? '',
                'remote' => $p['remoteIceCandidateType'] ?? '',
            ],
            'relayAddress'     => $p['relayAddress'] ?? '',
            'latency'          => pbDurationNs($p['latency'] ?? null),
            'lastWireguardHandshake' => pbTimestamp($p['lastWireguardHandshake'] ?? null),
            // int64 arrives as a JSON string per the protobuf JSON mapping.
            'transferReceived' => (int) ($p['bytesRx'] ?? 0),
            'transferSent'     => (int) ($p['bytesTx'] ?? 0),
            'networks'         => $p['networks'] ?? [],
        ];
    }

    $relayDetails = [];
    $relayAvail   = 0;
    foreach (($fs['relays'] ?? []) as $r) {
        if (!is_array($r)) {
            continue;
        }
        if (!empty($r['available'])) {
            $relayAvail++;
        }
        $relayDetails[] = [
            'uri'       => pbGet($r, ['URI', 'uri'], ''),
            'available' => !empty($r['available']),
            'error'     => $r['error'] ?? '',
        ];
    }

    $dnsServers = [];
    foreach (($fs['dnsServers'] ?? $fs['dns_servers'] ?? []) as $g) {
        if (!is_array($g)) {
            continue;
        }
        $dnsServers[] = [
            'servers' => $g['servers'] ?? [],
            'domains' => $g['domains'] ?? [],
            'enabled' => !empty($g['enabled']),
            'error'   => $g['error'] ?? '',
        ];
    }

    return [
        'daemonStatus'  => $resp['status'] ?? '',
        'daemonVersion' => $resp['daemonVersion'] ?? '',
        'profileName'   => $profileName,
        'netbirdIp'     => pbGet($local, ['IP', 'ip'], ''),
        'netbirdIpv6'   => $local['ipv6'] ?? '',
        'publicKey'     => $local['pubKey'] ?? '',
        'fqdn'          => $local['fqdn'] ?? '',
        'usesKernelInterface'         => !empty($local['kernelInterface']),
        'quantumResistance'           => !empty($local['rosenpassEnabled']),
        'quantumResistancePermissive' => !empty($local['rosenpassPermissive']),
        'networks'              => $local['networks'] ?? [],
        'lazyConnectionEnabled' => !empty($fs['lazyConnectionEnabled']),
        'forwardingRules'       => (int) pbGet($fs, ['NumberOfForwardingRules', 'numberOfForwardingRules'], 0),
        'management' => [
            'url'       => pbGet($mgmt, ['URL', 'url'], ''),
            'connected' => !empty($mgmt['connected']),
            'error'     => $mgmt['error'] ?? '',
        ],
        'signal' => [
            'url'       => pbGet($sig, ['URL', 'url'], ''),
            'connected' => !empty($sig['connected']),
            'error'     => $sig['error'] ?? '',
        ],
        'relays' => [
            'total'     => count($relayDetails),
            'available' => $relayAvail,
            'details'   => $relayDetails,
        ],
        'dnsServers' => $dnsServers,
        'peers' => [
            'total'     => count($peers),
            'connected' => $connected,
            'details'   => $peers,
        ],
    ];
}

/**
 * Version of the installed netbird binary (`netbird version`, purely local —
 * no daemon round-trip), or '' if unknown. Cached for the request.
 */
function cliVersion(): string
{
    static $ver = null;
    if ($ver === null) {
        [$rc, $out] = nb(['version'], 3);
        $ver = $rc === 0 ? trim(explode("\n", $out)[0]) : '';
    }
    return $ver;
}

/**
 * Read JSON status. Returns null if daemon unreachable or output isn't JSON.
 * Prefers the JSON gateway; falls back to CLI `status --json` (covers daemons
 * started without --enable-json-socket, e.g. across a plugin upgrade that
 * hasn't restarted the daemon yet).
 *
 * @return array<string,mixed>|null
 */
function statusJson(): ?array
{
    $res = apiCall('Status', ['getFullPeerStatus' => true], 3);
    if ($res['ok'] && isset($res['data']['status'])) {
        $profile = '';
        $ap = apiCall('GetActiveProfile', [], 3);
        if ($ap['ok']) {
            $profile = (string) ($ap['data']['profileName'] ?? '');
        }
        $mapped = mapGatewayStatus($res['data'], $profile);
        // `status --json` reports the invoking binary's version as cliVersion;
        // the gateway response has no equivalent, so ask the binary directly.
        // The dashboard compares it against daemonVersion for its
        // "restart pending" hint after a plugin upgrade.
        $mapped['cliVersion'] = cliVersion();
        return $mapped;
    }

    [$rc, $out] = nb(['status', '--json'], 3);
    if ($rc !== 0 || $out === '') {
        return null;
    }
    $decoded = json_decode($out, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * True when the rc.d-managed daemon is running.
 */
function daemonRunning(): bool
{
    exec('/usr/bin/pgrep -f "^' . NETBIRD_BIN . ' service run" 2>/dev/null', $out, $rc);
    return $rc === 0;
}

/**
 * Acquire the advisory apply lock, waiting up to $waitSec for it.
 * Returns the held file handle (pass to nbUnlock) or false if it couldn't be
 * acquired in time. Best-effort: if the lock file can't be opened at all we
 * return a sentinel handle so callers still proceed (locking is advisory).
 *
 * @return resource|false
 */
function nbTryLock(int $waitSec = 8)
{
    $fh = @fopen(LOCK_FILE, 'c');
    if ($fh === false) {
        return false;
    }
    $deadline = microtime(true) + $waitSec;
    do {
        if (flock($fh, LOCK_EX | LOCK_NB)) {
            return $fh;
        }
        usleep(200000); // 0.2s
    } while (microtime(true) < $deadline);

    fclose($fh);
    return false;
}

/**
 * Release a lock handle obtained from nbTryLock().
 *
 * @param resource $fh
 */
function nbUnlock($fh): void
{
    if (is_resource($fh)) {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/**
 * Read the last apply result written by apply.sh, or null if none/unreadable.
 *
 * @return array<string,mixed>|null
 */
function readApplyResult(): ?array
{
    if (!is_readable(RESULT_FILE)) {
        return null;
    }
    $decoded = json_decode((string) file_get_contents(RESULT_FILE), true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * List NetBird profiles as ['name' => string, 'active' => bool] entries.
 * Prefers the JSON gateway's ListProfiles; falls back to parsing the CLI's
 * text output. Returns [] when the daemon is unreachable.
 *
 * @return array<int, array{name:string, active:bool}>
 */
function listProfiles(): array
{
    $res = apiCall('ListProfiles', ['username' => apiUsername()], 3);
    if ($res['ok']) {
        // protojson omits empty repeated fields, so a daemon with zero
        // profiles answers {} — still a successful response, not a reason
        // to fall back to the CLI.
        $list = $res['data']['profiles'] ?? [];
        $profiles = [];
        foreach ((is_array($list) ? $list : []) as $p) {
            if (is_array($p) && isset($p['name'])) {
                $profiles[] = [
                    'name'   => (string) $p['name'],
                    'active' => !empty(pbGet($p, ['isActive', 'is_active'])),
                ];
            }
        }
        return $profiles;
    }
    return listProfilesCli();
}

/**
 * CLI fallback for listProfiles(): parse `netbird profile list` text output.
 *
 * @return array<int, array{name:string, active:bool}>
 */
function listProfilesCli(): array
{
    [$rc, $out] = nb(['profile', 'list'], 3);
    if ($rc !== 0) {
        return [];
    }
    $profiles = [];
    foreach (explode("\n", $out) as $line) {
        $line = trim($line);
        // Skip the header line "Found N profiles:" and blanks.
        if ($line === '' || stripos($line, 'Found') === 0) {
            continue;
        }
        // Lines are "✓ name" (active) or "✗ name" (passive).
        if (preg_match('/^(✓|✗)\s+(.+)$/u', $line, $m)) {
            $profiles[] = [
                'name'   => $m[2],
                'active' => $m[1] === '✓',
            ];
        }
    }
    return $profiles;
}

/**
 * List profiles with credentials saved by the plugin without contacting the
 * daemon. This keeps the Settings page usable while NetBird is disabled.
 *
 * @return array<int, array{name:string, active:bool}>
 */
function savedProfiles(): array
{
    $profiles = [];
    foreach (glob(PROFILE_DIR . '/*.cfg') ?: [] as $path) {
        $name = pathinfo($path, PATHINFO_FILENAME);
        if (validProfileName($name)) {
            $profiles[] = ['name' => $name, 'active' => false];
        }
    }
    usort($profiles, static function (array $a, array $b): int {
        return strcasecmp($a['name'], $b['name']);
    });
    return $profiles;
}

/**
 * Convenience: name of the currently-active profile, or '' if none.
 */
function activeProfile(): string
{
    foreach (listProfiles() as $p) {
        if ($p['active']) {
            return $p['name'];
        }
    }
    return '';
}

function readCfg(): array
{
    if (!function_exists('parse_plugin_cfg')) {
        // outside emhttp (e.g., AJAX endpoint) — minimal fallback parser
        if (!is_readable(CFG_FILE)) {
            return [];
        }
        return parse_ini_file(CFG_FILE) ?: [];
    }
    return parse_plugin_cfg(PLUGIN) ?: [];
}

/**
 * Merge the given key/value pairs into the global netbird.cfg, preserving any
 * other keys already present. Values are written quoted; embedded quotes are
 * stripped.
 *
 * @param array<string,string> $updates
 */
function writeGlobalCfg(array $updates): bool
{
    $existing = is_readable(CFG_FILE) ? (parse_ini_file(CFG_FILE) ?: []) : [];
    $merged   = array_merge($existing, $updates);

    $dir = dirname(CFG_FILE);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return false;
    }
    $lines = '';
    foreach ($merged as $k => $v) {
        $v = str_replace('"', '', (string) $v);
        $lines .= $k . '="' . $v . "\"\n";
    }
    return file_put_contents(CFG_FILE, $lines) !== false;
}

/**
 * Reject anything that isn't a valid profile name (mirrors action.php).
 * Used to keep profile names safe as filename components.
 */
function validProfileName(string $name): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_.\-]{1,32}$/', $name);
}

/**
 * Absolute path to a profile's credential cfg file.
 */
function profileCfgPath(string $name): string
{
    return PROFILE_DIR . '/' . $name . '.cfg';
}

/**
 * Read the per-profile credentials (MANAGEMENT_URL, SETUP_KEY, HOSTNAME,
 * PRESHARED_KEY) for a given profile. Profiles with no cfg yet start blank.
 *
 * @return array<string,string>
 */
function readProfileCfg(string $name): array
{
    $creds = array_fill_keys(PROFILE_KEYS, '');

    $path = profileCfgPath($name);
    if (is_readable($path)) {
        $vals = parse_ini_file($path) ?: [];
        foreach (PROFILE_KEYS as $k) {
            $creds[$k] = (string) ($vals[$k] ?? '');
        }
    }
    return $creds;
}

/**
 * Persist a profile's credentials to profiles/<name>.cfg.
 * Returns false if the name is invalid or the file can't be written.
 *
 * @param array<string,string> $creds
 */
function writeProfileCfg(string $name, array $creds): bool
{
    if (!validProfileName($name)) {
        return false;
    }
    if (!is_dir(PROFILE_DIR) && !@mkdir(PROFILE_DIR, 0755, true) && !is_dir(PROFILE_DIR)) {
        return false;
    }
    $lines = '';
    foreach (PROFILE_KEYS as $k) {
        $v = str_replace('"', '', (string) ($creds[$k] ?? ''));
        $lines .= $k . '="' . $v . "\"\n";
    }
    return file_put_contents(profileCfgPath($name), $lines) !== false;
}

/**
 * Delete a profile's credential cfg (best effort).
 */
function deleteProfileCfg(string $name): void
{
    if (validProfileName($name)) {
        @unlink(profileCfgPath($name));
    }
}

/**
 * Format a byte count as a short human string (e.g., "12.4 MB").
 */
function humanBytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $units = ['KB', 'MB', 'GB', 'TB'];
    $n     = $bytes / 1024;
    $i     = 0;
    while ($n >= 1024 && $i < count($units) - 1) {
        $n /= 1024;
        $i++;
    }
    return sprintf('%.1f %s', $n, $units[$i]);
}

/**
 * Format an ISO8601-ish timestamp as a relative "Xm ago" string.
 * Returns '-' for zero/unknown values.
 */
function relativeTime(?string $iso): string
{
    if (!$iso || str_starts_with($iso, '0001-01-01')) {
        return '-';
    }
    $ts = strtotime($iso);
    if ($ts === false) {
        return '-';
    }
    $delta = time() - $ts;
    if ($delta < 0) {
        return 'just now';
    }
    if ($delta < 60)    { return $delta . 's ago'; }
    if ($delta < 3600)  { return floor($delta / 60) . 'm ago'; }
    if ($delta < 86400) { return floor($delta / 3600) . 'h ago'; }
    return floor($delta / 86400) . 'd ago';
}

/**
 * NetBird returns latency as a nanosecond integer (Go time.Duration).
 * Convert to milliseconds for display.
 */
function formatLatency($ns): string
{
    if (!is_numeric($ns) || $ns <= 0) {
        return '-';
    }
    $ms = ((float) $ns) / 1_000_000.0;
    if ($ms < 1.0) {
        return sprintf('%.2f ms', $ms);
    }
    return sprintf('%d ms', (int) round($ms));
}
