<?php

namespace Netbird;

/**
 * Parse a handshake timestamp, rejecting unset times regardless of timezone.
 */
function handshakeTime(mixed $value): ?int
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
        return null;
    }
    // Go emits nanoseconds; second precision is enough for the liveness check.
    $value = preg_replace('/\.\d+/', '', $value);
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
    $errors = \DateTimeImmutable::getLastErrors();
    if ($date === false || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
        return null;
    }
    $time = $date->getTimestamp();
    return $time > 0 ? $time : null;
}

/**
 * Whether a connected Rosenpass peer has a recent WireGuard handshake.
 *
 * @param array<string,mixed> $peer
 */
function hasRosenpassHandshake(array $peer, ?int $now = null): bool
{
    if (($peer['status'] ?? '') !== 'Connected' || ($peer['quantumResistance'] ?? false) !== true) {
        return false;
    }
    $handshake = handshakeTime($peer['lastWireguardHandshake'] ?? null);
    $now = $now ?? time();
    // Match NetBird's WireGuard watcher: three minutes plus 30 seconds grace.
    return $handshake !== null && $handshake <= $now && $handshake >= $now - 210;
}

/**
 * Count peers and healthy handshakes without treating malformed status as empty.
 *
 * @return array{int,int}
 */
function handshakeStats(\stdClass $status): array
{
    $peers = $status->peers ?? null;
    if (!$peers instanceof \stdClass || !is_int($peers->total ?? null) || !property_exists($peers, 'details')) {
        throw new \UnexpectedValueException('Missing peer status');
    }
    // A nil Go slice is encoded as null when there are no peers.
    $details = $peers->details ?? [];
    if (!is_array($details) || count($details) !== $peers->total) {
        throw new \UnexpectedValueException('Incomplete peer status');
    }
    $healthy = 0;
    $now = time();
    foreach ($details as $peer) {
        if (!$peer instanceof \stdClass || !is_string($peer->status ?? null)) {
            throw new \UnexpectedValueException('Invalid peer status');
        }
        if (hasRosenpassHandshake((array) $peer, $now)) {
            $healthy++;
        }
    }
    return [count($details), $healthy];
}

// The shell guard supplies CLI JSON on stdin. Including this file in the UI
// only defines the shared timestamp and liveness helpers.
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $status = json_decode(file_get_contents('php://stdin'), false, 512, JSON_THROW_ON_ERROR);
        if (!$status instanceof \stdClass) {
            throw new \UnexpectedValueException('Invalid status');
        }
        if (($argv[1] ?? '') === 'permissive') {
            exit(($status->quantumResistance ?? false) === true && ($status->quantumResistancePermissive ?? false) === true ? 0 : 1);
        }
        [$peers, $healthy] = handshakeStats($status);
        echo "$peers $healthy\n";
    } catch (\Throwable $error) {
        fwrite(STDERR, "Unreadable NetBird status\n");
        exit(2);
    }
}
