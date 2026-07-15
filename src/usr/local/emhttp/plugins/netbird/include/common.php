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

/**
 * Read JSON status. Returns null if daemon unreachable or output isn't JSON.
 *
 * @return array<string,mixed>|null
 */
function statusJson(): ?array
{
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
 * List NetBird profiles by parsing `netbird profile list` text output.
 * Returns an array of ['name' => string, 'active' => bool].
 * Returns [] when the daemon is unreachable.
 *
 * @return array<int, array{name:string, active:bool}>
 */
function listProfiles(): array
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
