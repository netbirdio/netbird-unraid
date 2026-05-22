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

/**
 * Validate a profile name. Allowed: letters, digits, dot, dash, underscore.
 */
function nb_valid_profile_name(string $name): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_.\-]{1,32}$/', $name);
}

/**
 * Build the argument vector for `netbird up` from a profile's credentials.
 *
 * @param array<string,string> $creds
 * @return string[]
 */
/**
 * Normalize a management URL for comparison. Blank means NetBird's default
 * cloud; trailing slashes and the default :443 port are insignificant.
 */
function nb_mgmt_norm(string $url): string
{
    $url = strtolower(trim($url));
    if ($url === '') {
        $url = 'https://api.netbird.io';
    }
    $url = rtrim($url, '/');
    $url = preg_replace('/:443$/', '', $url);
    return $url;
}

function nb_up_args(array $creds): array
{
    $args = ['up'];
    if (!empty($creds['MANAGEMENT_URL']))  { $args[] = '--management-url'; $args[] = $creds['MANAGEMENT_URL']; }
    if (!empty($creds['SETUP_KEY']))       { $args[] = '--setup-key';      $args[] = $creds['SETUP_KEY']; }
    if (!empty($creds['HOSTNAME']))        { $args[] = '--hostname';       $args[] = $creds['HOSTNAME']; }
    if (!empty($creds['PRESHARED_KEY']))   { $args[] = '--preshared-key';  $args[] = $creds['PRESHARED_KEY']; }
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
        // Bring up the currently-active profile using its own stored credentials.
        $active = Netbird\activeProfile();
        if ($active !== '') {
            Netbird\nb(['profile', 'select', $active]);
        }
        $creds = $active !== '' ? Netbird\readProfileCfg($active) : [];
        [$rc, $out] = Netbird\nb(nb_up_args($creds));
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
        if ($rc === 0) {
            // Seed an empty credential cfg so the new profile starts blank
            // rather than appearing to inherit another profile's settings.
            Netbird\writeProfileCfg($name, []);
        }
        echo json_encode([
            'type'    => $rc === 0 ? 'success' : 'error',
            'title'   => $rc === 0 ? 'Profile added' : 'Add failed',
            'message' => $out ?: "Profile '$name' added.",
            'profile' => $rc === 0 ? $name : null,
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
        if ($rc === 0) {
            Netbird\deleteProfileCfg($name);
        }
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
        // Re-run `up` using THIS profile's own stored credentials, so switching
        // never connects with another profile's settings.
        [$rcUp, $outUp] = Netbird\nb(nb_up_args(Netbird\readProfileCfg($name)));
        echo json_encode([
            'type'    => 'success',
            'title'   => 'Profile switched',
            'message' => "Active profile is now '$name'.\n" . ($outUp ?: ''),
        ]);
        break;

    case 'save':
        // Save the Settings form: global daemon options + the selected
        // profile's credentials, then apply them scoped to that profile.
        $name = trim((string) ($_POST['name'] ?? ''));
        if (!nb_valid_profile_name($name)) {
            http_response_code(400);
            echo json_encode(['type' => 'error', 'message' => 'Invalid profile name.']);
            break;
        }

        $enable = (($_POST['ENABLE_NETBIRD'] ?? '1') === '0') ? '0' : '1';
        $log    = (string) ($_POST['LOG_LEVEL'] ?? 'info');
        if (!in_array($log, ['panic', 'fatal', 'error', 'warn', 'info', 'debug', 'trace'], true)) {
            $log = 'info';
        }
        Netbird\writeGlobalCfg(['ENABLE_NETBIRD' => $enable, 'LOG_LEVEL' => $log]);

        // Detect a registration-parameter change BEFORE overwriting the stored
        // cfg. NetBird bakes the management URL and hostname into the profile at
        // registration; changing either is ignored by a plain `up`, so the
        // profile must be re-registered (see apply.sh). The setup key is auth
        // material, not an identity, so it does not trigger re-registration.
        $old      = Netbird\readProfileCfg($name);
        $creds    = [];
        foreach (Netbird\PROFILE_KEYS as $k) {
            $creds[$k] = (string) ($_POST[$k] ?? '');
        }
        $mgmtChanged = nb_mgmt_norm($old['MANAGEMENT_URL']) !== nb_mgmt_norm($creds['MANAGEMENT_URL']);
        $hostChanged = strtolower(trim($old['HOSTNAME'])) !== strtolower(trim($creds['HOSTNAME']));
        $reregister  = ($mgmtChanged || $hostChanged) ? '1' : '0';

        if (!Netbird\writeProfileCfg($name, $creds)) {
            http_response_code(500);
            echo json_encode(['type' => 'error', 'message' => "Could not write profile '$name'."]);
            break;
        }

        // apply.sh selects the profile, ensures the daemon is running, and runs up
        // (re-registering first when the management URL changed).
        exec('/usr/local/emhttp/plugins/netbird/scripts/apply.sh '
            . escapeshellarg($name) . ' ' . escapeshellarg($reregister) . ' > /dev/null 2>&1 &');
        echo json_encode([
            'type'    => 'success',
            'title'   => 'Settings saved',
            'message' => "Saved profile '$name' and applying…",
            'profile' => $name,
        ]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['type' => 'error', 'message' => "Unknown action: $action"]);
}
