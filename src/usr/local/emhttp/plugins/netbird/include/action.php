<?php
/**
 * AJAX endpoint for the Status and Settings pages.
 *
 * Actions:
 *   up              — netbird up using the active profile's stored credentials
 *                     (reconnects via stored identity; registration via setup
 *                     key happens on save, interactive SSO is never used)
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
 * True when a profile has never been registered through the plugin. Save keeps
 * a profile's setup key once set (an empty field means "keep existing"), so
 * "no stored setup key" reliably means "never set up". Bringing such a profile
 * up would fall into NetBird's interactive SSO login, which we don't use.
 */
function nb_profile_unconfigured(string $name): bool
{
    return trim(Netbird\readProfileCfg($name)['SETUP_KEY'] ?? '') === '';
}

/**
 * Acquire the apply lock, or emit a "busy" JSON response and return false.
 * Serializes daemon-mutating actions with each other and with apply.sh so
 * concurrent ops don't cancel each other.
 *
 * @return resource|false
 */
function nb_lock_or_busy()
{
    $lock = Netbird\nbTryLock();
    if ($lock === false) {
        echo json_encode([
            'type'    => 'warning',
            'title'   => 'NetBird busy',
            'message' => 'Another NetBird operation is in progress. Try again in a moment.',
        ]);
    }
    return $lock;
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
    // NetBird's built-in SSH server is a host-wide (global) setting. Unraid is a
    // root-operated box, so we also permit root login (the server refuses it
    // otherwise); without this the SSH server is effectively unusable here.
    if ((Netbird\readCfg()['ENABLE_SSH'] ?? '0') === '1') {
        $args[] = '--allow-server-ssh';
        $args[] = '--enable-ssh-root';
    }
    // DNS management is host-wide. Default ("1") lets NetBird run its embedded
    // resolver and rewrite /etc/resolv.conf; turning it off passes
    // --disable-dns so NetBird leaves the host's DNS untouched (see issue #2).
    // Pass an explicit boolean either way so toggling back on actually re-enables
    // it — NetBird remembers the last flag value on the profile otherwise.
    $manageDns = (Netbird\readCfg()['MANAGE_DNS'] ?? '1') === '1';
    $args[] = '--disable-dns=' . ($manageDns ? 'false' : 'true');
    return $args;
}

switch ($action) {
    case 'up':
        $lock = nb_lock_or_busy();
        if ($lock === false) { break; }
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
        // Refuse to connect a profile that was never registered (no stored key) —
        // `up` would otherwise drop into interactive SSO login, which we don't use.
        if ($active === '' || nb_profile_unconfigured($active)) {
            Netbird\nbUnlock($lock);
            echo json_encode([
                'type'    => 'error',
                'title'   => 'Profile not set up',
                'message' => ($active === '' ? 'No active profile. ' : "Profile '$active' hasn't been registered yet. ")
                           . 'Open the Settings tab, enter a setup key, and save to register it.',
            ]);
            break;
        }
        $creds = Netbird\readProfileCfg($active);
        // Reconnect uses the profile's stored identity; no setup key needed here
        // (a key is only required to register, which happens on save). Bounded so
        // a failing reconnect's retry/backoff can't hang the request.
        [$rc, $out] = Netbird\nb(nb_up_args($creds), 90);
        Netbird\nbUnlock($lock);
        echo json_encode([
            'type'    => $rc === 0 ? 'success' : 'error',
            'title'   => $rc === 0 ? 'Connecting' : 'NetBird up failed',
            'message' => $out ?: ($rc === 0 ? 'NetBird is connecting…' : 'Unknown error'),
        ]);
        break;

    case 'down':
        $lock = nb_lock_or_busy();
        if ($lock === false) { break; }
        [$rc, $out] = Netbird\nb(['down']);
        Netbird\nbUnlock($lock);
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
        $lock = nb_lock_or_busy();
        if ($lock === false) { break; }
        [$rc, $out] = Netbird\nb(['profile', 'add', $name]);
        if ($rc === 0) {
            // Seed an empty credential cfg so the new profile starts blank
            // rather than appearing to inherit another profile's settings.
            Netbird\writeProfileCfg($name, []);
        }
        Netbird\nbUnlock($lock);
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
        $lock = nb_lock_or_busy();
        if ($lock === false) { break; }
        [$rc, $out] = Netbird\nb(['profile', 'remove', $name]);
        if ($rc === 0) {
            Netbird\deleteProfileCfg($name);
        }
        Netbird\nbUnlock($lock);
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
        $lock = nb_lock_or_busy();
        if ($lock === false) { break; }
        // Refuse to switch to a never-registered profile (no stored key); the
        // follow-up `up` would otherwise fall into interactive SSO login.
        if (nb_profile_unconfigured($name)) {
            Netbird\nbUnlock($lock);
            echo json_encode([
                'type'    => 'error',
                'title'   => 'Profile not set up',
                'message' => "Profile '$name' hasn't been registered yet. Open the Settings tab, enter a setup key, and save to register it.",
            ]);
            break;
        }
        [$rcSel, $outSel] = Netbird\nb(['profile', 'select', $name]);
        if ($rcSel !== 0) {
            Netbird\nbUnlock($lock);
            echo json_encode([
                'type'    => 'error',
                'title'   => 'Switch failed',
                'message' => $outSel ?: 'profile select returned an error.',
            ]);
            break;
        }
        // Re-run `up` using THIS profile's own stored credentials, so switching
        // never connects with another profile's settings. Bounded so a failing
        // reconnect can't hang the request.
        [$rcUp, $outUp] = Netbird\nb(nb_up_args(Netbird\readProfileCfg($name)), 90);
        Netbird\nbUnlock($lock);
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
        // NetBird SSH server is a global (host-wide) toggle; changing it requires a
        // reconnect to take effect (see the mode selection below).
        $sshWas = (Netbird\readCfg()['ENABLE_SSH'] ?? '0') === '1';
        $ssh    = (($_POST['ENABLE_SSH'] ?? '0') === '1') ? '1' : '0';
        // DNS management is a global (host-wide) toggle, like SSH; a change
        // requires a reconnect to take effect (see the mode selection below).
        $dnsWas = (Netbird\readCfg()['MANAGE_DNS'] ?? '1') === '1';
        $dns    = (($_POST['MANAGE_DNS'] ?? '1') === '0') ? '0' : '1';
        $globalCfg = ['ENABLE_NETBIRD' => $enable, 'LOG_LEVEL' => $log, 'ENABLE_SSH' => $ssh, 'MANAGE_DNS' => $dns];
        $mode      = 'ensure';

        // Disabling is deliberately independent of profile state. A fresh
        // installation must be stoppable without first supplying registration
        // credentials. When enabling, validate and save the selected profile as
        // usual before queueing the apply.
        if ($enable === '1') {
            // Detect a registration-parameter change BEFORE overwriting the
            // stored cfg. NetBird bakes the management URL and hostname into the
            // profile at registration; changing either is ignored by a plain
            // `up`, so the profile must be re-registered (see apply.sh). The setup
            // key is auth material, not an identity, so it does not trigger
            // re-registration.
            $old   = Netbird\readProfileCfg($name);
            $creds = [];
            foreach (Netbird\PROFILE_KEYS as $k) {
                $creds[$k] = (string) ($_POST[$k] ?? '');
            }
            // Pick how apply.sh should bring the change into effect. NetBird
            // ignores most setting changes on an already-connected profile, so:
            //   reregister — mgmt URL / hostname changed (baked at registration).
            //   reconnect  — pre-shared key changed to a new value (down+up
            //                re-applies it without a full re-register).
            //   ensure     — no credential change (plain select+up; no disconnect).
            // Setup-key-only changes stay 'ensure' (the key is auth material, not
            // an identity — re-registering on it would needlessly spend a key use).
            // NOTE: a PSK cannot be *removed* via the CLI — netbird treats an
            // empty --preshared-key as "leave unchanged" and even remove+add+up
            // retains the stored value. Clearing the field therefore cannot take
            // effect without a full erase/re-create of the profile.
            $mgmtChanged = nb_mgmt_norm($old['MANAGEMENT_URL']) !== nb_mgmt_norm($creds['MANAGEMENT_URL']);
            $hostChanged = strtolower(trim($old['HOSTNAME'])) !== strtolower(trim($creds['HOSTNAME']));
            $pskChanged  = trim($creds['PRESHARED_KEY']) !== '' && trim($old['PRESHARED_KEY']) !== trim($creds['PRESHARED_KEY']);
            $sshChanged  = $sshWas !== ($ssh === '1');
            $dnsChanged  = $dnsWas !== ($dns === '1');
            $mode = ($mgmtChanged || $hostChanged) ? 'reregister' : (($pskChanged || $sshChanged || $dnsChanged) ? 'reconnect' : 'ensure');

            // An empty Setup Key field means "keep the stored key" — the key is
            // only consumed at registration, so a profile never loses it once
            // set. A key is mandatory only when enabling a profile that needs to
            // be initially registered or re-registered.
            if (trim($creds['SETUP_KEY']) === '' && trim($old['SETUP_KEY']) !== '') {
                $creds['SETUP_KEY'] = $old['SETUP_KEY'];
            }
            $everConfigured = trim($old['SETUP_KEY']) !== '';
            $registering    = !$everConfigured || $mode === 'reregister';
            if ($registering && trim($creds['SETUP_KEY']) === '') {
                http_response_code(400);
                echo json_encode([
                    'type'    => 'error',
                    'title'   => 'Setup key required',
                    'message' => 'A setup key is required to register this profile. Generate one in your NetBird dashboard and paste it here.',
                ]);
                break;
            }
        }

        if (!Netbird\writeGlobalCfg($globalCfg)) {
            http_response_code(500);
            echo json_encode(['type' => 'error', 'message' => 'Could not write the NetBird global configuration.']);
            break;
        }
        if ($enable === '1' && !Netbird\writeProfileCfg($name, $creds)) {
            http_response_code(500);
            echo json_encode(['type' => 'error', 'message' => "Could not write profile '$name'."]);
            break;
        }

        // apply.sh (backgrounded; it takes the apply lock itself) stops the
        // daemon immediately when disabled. When enabled it selects the profile,
        // ensures the daemon is running, and runs up in the chosen mode. It
        // records the outcome to RESULT_FILE, which the page polls via
        // 'apply-status'. We return immediately with a "since" stamp so the
        // poller only accepts a result newer than this request.
        exec('/usr/local/emhttp/plugins/netbird/scripts/apply.sh '
            . escapeshellarg($name) . ' ' . escapeshellarg($mode) . ' > /dev/null 2>&1 &');
        echo json_encode([
            'type'    => 'info',
            'title'   => 'Settings saved',
            'message' => $enable === '1' ? "Saved profile '$name'; applying…" : 'Disabling NetBird…',
            'profile' => $name,
            'pending' => true,
            'since'   => time(),
        ]);
        break;

    case 'apply-status':
        // Last result written by apply.sh, for the Settings page to poll after
        // a save. {profile, ok:true|false|null, ts, message} or {} if none.
        echo json_encode(Netbird\readApplyResult() ?? new stdClass());
        break;

    default:
        http_response_code(400);
        echo json_encode(['type' => 'error', 'message' => "Unknown action: $action"]);
}
