<?php
/**
 * AJAX endpoint for the Status and Settings pages.
 *
 * Actions:
 *   up              — netbird up (uses cfg credentials if set)
 *   down            — netbird down
 *   restart         — restart the rc.d daemon
 *   profile-list    — return list of profiles as JSON (rarely needed; pages embed)
 *   profile-select  — netbird profile select <name> + re-up
 *   profile-add     — netbird profile add <name>
 *   profile-remove  — netbird profile remove <name>
 */

require_once __DIR__ . '/common.php';

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['type' => 'error', 'message' => 'POST required']);
    exit;
}

// Note: Unraid's /usr/local/emhttp/webGui/include/local_prepend.php is
// auto-prepended to every PHP request and already validates the CSRF token
// against /var/local/emhttp/var.ini, terminating the request on mismatch.
// After validation it calls unset($_POST['csrf_token']), so by the time we
// run, the field is already gone. No redundant check here.

$action = $_POST['action'] ?? '';
$cfg    = Netbird\readCfg();

/**
 * Validate a profile name. Allowed: letters, digits, dot, dash, underscore.
 */
function nb_valid_profile_name(string $name): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_.\-]{1,32}$/', $name);
}

/**
 * Build the argument vector for `netbird up` using cfg values.
 *
 * @return string[]
 */
function nb_up_args(array $cfg): array
{
    $args = ['up'];
    if (!empty($cfg['MANAGEMENT_URL']))  { $args[] = '--management-url'; $args[] = $cfg['MANAGEMENT_URL']; }
    if (!empty($cfg['SETUP_KEY']))       { $args[] = '--setup-key';      $args[] = $cfg['SETUP_KEY']; }
    if (!empty($cfg['HOSTNAME']))        { $args[] = '--hostname';       $args[] = $cfg['HOSTNAME']; }
    if (!empty($cfg['PRESHARED_KEY']))   { $args[] = '--preshared-key';  $args[] = $cfg['PRESHARED_KEY']; }
    return $args;
}

switch ($action) {
    case 'up':
        // Ensure the daemon is up (and its socket exists) before connecting,
        // so a fresh install can connect from the GUI without a manual start.
        if (!Netbird\daemonRunning() || !file_exists('/var/run/netbird.sock')) {
            exec('/etc/rc.d/rc.netbird start > /dev/null 2>&1');
            for ($i = 0; $i < 20; $i++) {
                if (file_exists('/var/run/netbird.sock')) { break; }
                usleep(500000);
            }
        }
        [$rc, $out] = Netbird\nb(nb_up_args($cfg));
        echo json_encode([
            'type'    => $rc === 0 ? 'success' : 'error',
            'title'   => $rc === 0 ? 'Connecting' : 'NetBird up failed',
            'message' => $out ?: ($rc === 0 ? 'NetBird is connecting…' : 'Unknown error'),
        ]);
        break;

    case 'down':
        [$rc, $out] = Netbird\nb(['down']);
        echo json_encode([
            'type'    => $rc === 0 ? 'success' : 'error',
            'title'   => $rc === 0 ? 'Disconnected' : 'NetBird down failed',
            'message' => $out ?: 'NetBird disconnected.',
        ]);
        break;

    case 'restart':
        exec('/usr/local/emhttp/plugins/netbird/restart.sh > /dev/null 2>&1 &');
        echo json_encode([
            'type'    => 'info',
            'title'   => 'NetBird',
            'message' => 'Daemon restart scheduled.',
        ]);
        break;

    case 'profile-list':
        echo json_encode([
            'type'     => 'info',
            'profiles' => Netbird\listProfiles(),
        ]);
        break;

    case 'profile-add':
        $name = trim((string) ($_POST['name'] ?? ''));
        if (!nb_valid_profile_name($name)) {
            http_response_code(400);
            echo json_encode(['type' => 'error', 'message' => 'Invalid profile name.']);
            break;
        }
        [$rc, $out] = Netbird\nb(['profile', 'add', $name]);
        echo json_encode([
            'type'    => $rc === 0 ? 'success' : 'error',
            'title'   => $rc === 0 ? 'Profile added' : 'Add failed',
            'message' => $out ?: "Profile '$name' added.",
        ]);
        break;

    case 'profile-remove':
        $name = trim((string) ($_POST['name'] ?? ''));
        if (!nb_valid_profile_name($name)) {
            http_response_code(400);
            echo json_encode(['type' => 'error', 'message' => 'Invalid profile name.']);
            break;
        }
        [$rc, $out] = Netbird\nb(['profile', 'remove', $name]);
        echo json_encode([
            'type'    => $rc === 0 ? 'success' : 'error',
            'title'   => $rc === 0 ? 'Profile removed' : 'Remove failed',
            'message' => $out ?: "Profile '$name' removed.",
        ]);
        break;

    case 'profile-select':
        $name = trim((string) ($_POST['name'] ?? ''));
        if (!nb_valid_profile_name($name)) {
            http_response_code(400);
            echo json_encode(['type' => 'error', 'message' => 'Invalid profile name.']);
            break;
        }
        [$rcSel, $outSel] = Netbird\nb(['profile', 'select', $name]);
        if ($rcSel !== 0) {
            echo json_encode([
                'type'    => 'error',
                'title'   => 'Switch failed',
                'message' => $outSel ?: 'profile select returned an error.',
            ]);
            break;
        }
        // After switching, re-run `up` with current cfg so the new profile actually connects.
        [$rcUp, $outUp] = Netbird\nb(nb_up_args($cfg));
        echo json_encode([
            'type'    => 'success',
            'title'   => 'Profile switched',
            'message' => "Active profile is now '$name'.\n" . ($outUp ?: ''),
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['type' => 'error', 'message' => "Unknown action: $action"]);
}
