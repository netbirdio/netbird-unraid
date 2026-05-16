<?php
/**
 * Shared helpers for the NetBird Unraid plugin.
 */

namespace Netbird;

const PLUGIN          = 'netbird';
const NETBIRD_BIN     = '/usr/local/sbin/netbird';
const RC_SCRIPT       = '/etc/rc.d/rc.netbird';
const CFG_FILE        = '/boot/config/plugins/netbird/netbird.cfg';
const DAEMON_ADDR     = 'unix:///var/run/netbird.sock';

/**
 * Run a netbird CLI subcommand and return [exitCode, stdout].
 *
 * @param string[] $args
 * @return array{0:int,1:string}
 */
function nb(array $args): array
{
    $cmd = escapeshellcmd(NETBIRD_BIN) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
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
    [$rc, $out] = nb(['status', '--json']);
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
 * List NetBird profiles by parsing `netbird profile list` text output.
 * Returns an array of ['name' => string, 'active' => bool].
 * Returns [] when the daemon is unreachable.
 *
 * @return array<int, array{name:string, active:bool}>
 */
function listProfiles(): array
{
    [$rc, $out] = nb(['profile', 'list']);
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
