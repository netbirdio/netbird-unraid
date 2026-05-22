<?php
try {
$docroot = $docroot ?? $_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
require_once "{$docroot}/plugins/netbird/include/common.php";

$running = Netbird\daemonRunning();
$status  = Netbird\statusJson();

$rawStatus = '';
if (!$status) {
    [$rc, $out] = Netbird\nb(['status']);
    $rawStatus  = $out;
}
?>

<style>
    .nb-card { padding: 10px 14px; border: 1px solid var(--panel-bdr); border-radius: 4px; margin-bottom: 12px; }
    .nb-card h3 { margin-top: 0; margin-bottom: 8px; }
    .nb-grid { display: grid; grid-template-columns: max-content 1fr; gap: 4px 16px; }
    .nb-grid > div:nth-child(odd) { font-weight: 600; color: var(--text-muted, #555); }
    .nb-table { width: 100%; border-collapse: collapse; margin-top: 8px; font-size: 0.95em; }
    .nb-table th, .nb-table td { padding: 6px 8px; text-align: left; border-bottom: 1px solid var(--panel-bdr); vertical-align: top; }
    .nb-table th { background: rgba(0,0,0,0.04); }
    .nb-ok    { color: #2eaa2e; font-weight: 600; }
    .nb-warn  { color: #d39e00; font-weight: 600; }
    .nb-bad   { color: #c8331f; font-weight: 600; }
    .nb-muted { color: var(--text-muted, #888); }
    .nb-actions input { margin-right: 8px; }
    .nb-pill { display: inline-block; padding: 1px 8px; border-radius: 999px; background: rgba(0,0,0,0.06); font-size: 0.85em; margin-right: 4px; }
    code.nb-mono { font-family: var(--mono-font, monospace); }
    .nb-reveal { cursor: pointer; user-select: none; opacity: 0.7; margin-left: 6px; }
    .nb-reveal:hover { opacity: 1; }
    .nb-secret-masked { letter-spacing: 0.05em; }
</style>

<!-- ============================================================== Daemon -->
<div class="nb-card">
    <h3>Daemon</h3>
    <?php if ($running): ?>
        <span class="nb-ok">Running</span>
    <?php else: ?>
        <span class="nb-bad">Stopped</span>
    <?php endif; ?>
    <?php if ($status && !empty($status['profileName'])): ?>
        &middot; profile <code class="nb-mono"><?=htmlspecialchars($status['profileName'])?></code>
    <?php endif; ?>
    <?php if ($status && !empty($status['daemonVersion'])): ?>
        &middot; <span class="nb-muted">v<?=htmlspecialchars($status['daemonVersion'])?></span>
    <?php endif; ?>

    <?php
        // Button enabled-state logic:
        //   daemon stopped       → only Restart usable (Connect/Disconnect disabled).
        //   daemon up, Connected → Connect disabled, Disconnect usable.
        //   daemon up, other     → Connect usable,  Disconnect disabled.
        $daemonState = $status['daemonStatus'] ?? '';
        $isConnected = $running && $daemonState === 'Connected';
        $canConnect    = $running && !$isConnected;
        $canDisconnect = $running &&  $isConnected;
        $disConnect    = $canConnect    ? '' : 'disabled';
        $disDisconnect = $canDisconnect ? '' : 'disabled';
    ?>
    <div class="nb-actions" style="margin-top:10px;">
        <input type="button" value="Connect"    onclick="nbAction('up')"      <?=$disConnect?>>
        <input type="button" value="Disconnect" onclick="nbAction('down')"    <?=$disDisconnect?>>
        <input type="button" value="Restart"    onclick="nbAction('restart')">
        <input type="button" value="Refresh"    onclick="location.reload()">
    </div>
</div>

<?php if ($status && isset($status['daemonStatus'])): ?>

    <!-- ====================================================== Connection -->
    <div class="nb-card">
        <h3>Connection</h3>
        <div class="nb-grid">
            <div>Daemon status</div>     <div><?=htmlspecialchars((string) $status['daemonStatus'])?></div>
            <div>NetBird IPv4</div>      <div><code class="nb-mono"><?=htmlspecialchars($status['netbirdIp'] ?? '-')?></code></div>
            <?php if (!empty($status['netbirdIpv6'])): ?>
                <div>NetBird IPv6</div>  <div><code class="nb-mono"><?=htmlspecialchars($status['netbirdIpv6'])?></code></div>
            <?php endif; ?>
            <div>FQDN</div>              <div><?=htmlspecialchars($status['fqdn'] ?? '-')?></div>
            <div>Public key</div>
            <div>
                <?php $pk = $status['publicKey'] ?? ''; ?>
                <?php if ($pk): ?>
                    <code class="nb-mono nb-secret-masked" id="nb-pubkey" data-value="<?=htmlspecialchars($pk)?>">••••••••••••••••••••••••••••••••</code>
                    <span class="nb-reveal" id="nb-pubkey-toggle" title="Show / hide public key" onclick="nbToggleSecret('nb-pubkey','nb-pubkey-toggle')">
                        <i class="fa fa-eye"></i>
                    </span>
                    <span class="nb-reveal" title="Copy public key" onclick="nbCopy(this.previousElementSibling.previousElementSibling)">
                        <i class="fa fa-clipboard"></i>
                    </span>
                <?php else: ?>
                    <code class="nb-mono">-</code>
                <?php endif; ?>
            </div>
            <div>Management</div>
            <div>
                <span class="<?=!empty($status['management']['connected']) ? 'nb-ok' : 'nb-bad'?>">
                    <?=!empty($status['management']['connected']) ? 'Connected' : 'Disconnected'?>
                </span>
                <?php if (!empty($status['management']['url'])): ?>
                    &middot; <code class="nb-mono"><?=htmlspecialchars($status['management']['url'])?></code>
                <?php endif; ?>
                <?php if (!empty($status['management']['error'])): ?>
                    <div class="nb-bad"><?=htmlspecialchars($status['management']['error'])?></div>
                <?php endif; ?>
            </div>
            <div>Signal</div>
            <div>
                <span class="<?=!empty($status['signal']['connected']) ? 'nb-ok' : 'nb-bad'?>">
                    <?=!empty($status['signal']['connected']) ? 'Connected' : 'Disconnected'?>
                </span>
                <?php if (!empty($status['signal']['url'])): ?>
                    &middot; <code class="nb-mono"><?=htmlspecialchars($status['signal']['url'])?></code>
                <?php endif; ?>
                <?php if (!empty($status['signal']['error'])): ?>
                    <div class="nb-bad"><?=htmlspecialchars($status['signal']['error'])?></div>
                <?php endif; ?>
            </div>
            <div>WireGuard interface</div>
            <div><?=!empty($status['usesKernelInterface']) ? 'Kernel module' : 'Userspace (wireguard-go)'?></div>
            <?php if (!empty($status['quantumResistance'])): ?>
                <div>Rosenpass</div>
                <div>Enabled<?=!empty($status['quantumResistancePermissive']) ? ' (permissive)' : ''?></div>
            <?php endif; ?>
            <?php if (isset($status['lazyConnectionEnabled']) && $status['lazyConnectionEnabled']): ?>
                <div>Lazy connections</div><div>Enabled</div>
            <?php endif; ?>
            <?php if (!empty($status['forwardingRules'])): ?>
                <div>Forwarding rules</div><div><?=(int) $status['forwardingRules']?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ========================================================= Networks -->
    <?php if (!empty($status['networks'])): ?>
        <div class="nb-card">
            <h3>Advertised networks</h3>
            <?php foreach ($status['networks'] as $net): ?>
                <span class="nb-pill"><code class="nb-mono"><?=htmlspecialchars($net)?></code></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- ====================================================== DNS Servers -->
    <?php if (!empty($status['dnsServers'])): ?>
        <div class="nb-card">
            <h3>DNS servers</h3>
            <table class="nb-table">
                <tr><th>Servers</th><th>Domains</th><th>State</th></tr>
                <?php foreach ($status['dnsServers'] as $ns): ?>
                    <tr>
                        <td>
                            <?php foreach (($ns['servers'] ?? []) as $s): ?>
                                <code class="nb-mono"><?=htmlspecialchars($s)?></code><br>
                            <?php endforeach; ?>
                        </td>
                        <td>
                            <?php foreach (($ns['domains'] ?? []) as $d): ?>
                                <span class="nb-pill"><?=htmlspecialchars($d)?></span>
                            <?php endforeach; ?>
                            <?php if (empty($ns['domains'])): ?><span class="nb-muted">(any)</span><?php endif; ?>
                        </td>
                        <td>
                            <span class="<?=!empty($ns['enabled']) ? 'nb-ok' : 'nb-muted'?>">
                                <?=!empty($ns['enabled']) ? 'Enabled' : 'Disabled'?>
                            </span>
                            <?php if (!empty($ns['error'])): ?>
                                <div class="nb-bad"><?=htmlspecialchars($ns['error'])?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>

    <!-- =========================================================== Relays -->
    <?php if (!empty($status['relays']['details'])): ?>
        <div class="nb-card">
            <h3>Relays (<?=(int) ($status['relays']['available'] ?? 0)?>/<?=(int) ($status['relays']['total'] ?? 0)?> available)</h3>
            <table class="nb-table">
                <tr><th>URI</th><th>State</th></tr>
                <?php foreach ($status['relays']['details'] as $r): ?>
                    <tr>
                        <td><code class="nb-mono"><?=htmlspecialchars($r['uri'] ?? '')?></code></td>
                        <td>
                            <span class="<?=!empty($r['available']) ? 'nb-ok' : 'nb-bad'?>">
                                <?=!empty($r['available']) ? 'Available' : 'Unavailable'?>
                            </span>
                            <?php if (!empty($r['error'])): ?>
                                <div class="nb-bad"><?=htmlspecialchars($r['error'])?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>

    <!-- ============================================================ Peers -->
    <?php
    $peers = $status['peers']['details'] ?? [];
    $peerTotal     = (int) ($status['peers']['total']     ?? count($peers));
    $peerConnected = (int) ($status['peers']['connected'] ?? 0);
    ?>
    <div class="nb-card">
        <h3>Peers (<?=$peerConnected?>/<?=$peerTotal?> connected)</h3>
        <?php if (!$peers): ?>
            <p class="nb-muted">No peers reported by the daemon yet.</p>
        <?php else: ?>
            <table class="nb-table">
                <tr>
                    <th>FQDN / hostname</th>
                    <th>NetBird IP</th>
                    <th>Status</th>
                    <th>Connection</th>
                    <th>Latency</th>
                    <th>Last handshake</th>
                    <th>Transfer (RX / TX)</th>
                    <th>Networks</th>
                </tr>
                <?php foreach ($peers as $peer):
                    $conn = $peer['status'] ?? '';
                    $connClass = $conn === 'Connected' ? 'nb-ok' : ($conn === 'Connecting' ? 'nb-warn' : 'nb-bad');
                ?>
                    <tr>
                        <td><?=htmlspecialchars($peer['fqdn'] ?? '-')?></td>
                        <td>
                            <code class="nb-mono"><?=htmlspecialchars($peer['netbirdIp'] ?? '-')?></code>
                            <?php if (!empty($peer['netbirdIpv6'])): ?>
                                <br><code class="nb-mono nb-muted"><?=htmlspecialchars($peer['netbirdIpv6'])?></code>
                            <?php endif; ?>
                        </td>
                        <td><span class="<?=$connClass?>"><?=htmlspecialchars($conn ?: '-')?></span></td>
                        <td>
                            <?=htmlspecialchars($peer['connectionType'] ?? '-')?>
                            <?php
                                $ice = $peer['iceCandidateType'] ?? null;
                                if (is_array($ice) && (!empty($ice['local']) || !empty($ice['remote']))):
                            ?>
                                <div class="nb-muted" style="font-size:0.9em;">
                                    <?=htmlspecialchars(($ice['local'] ?? '?') . ' ⇄ ' . ($ice['remote'] ?? '?'))?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($peer['relayAddress'])): ?>
                                <div class="nb-muted" style="font-size:0.85em;">
                                    via <code class="nb-mono"><?=htmlspecialchars($peer['relayAddress'])?></code>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?=htmlspecialchars(Netbird\formatLatency($peer['latency'] ?? 0))?></td>
                        <td><?=htmlspecialchars(Netbird\relativeTime($peer['lastWireguardHandshake'] ?? null))?></td>
                        <td>
                            <span class="nb-muted">↓</span> <?=htmlspecialchars(Netbird\humanBytes((int) ($peer['transferReceived'] ?? 0)))?>
                            <br>
                            <span class="nb-muted">↑</span> <?=htmlspecialchars(Netbird\humanBytes((int) ($peer['transferSent']    ?? 0)))?>
                        </td>
                        <td>
                            <?php foreach (($peer['networks'] ?? []) as $net): ?>
                                <span class="nb-pill"><code class="nb-mono"><?=htmlspecialchars($net)?></code></span>
                            <?php endforeach; ?>
                            <?php if (empty($peer['networks'])): ?><span class="nb-muted">-</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

<?php else: ?>
    <div class="nb-card">
        <h3>Status output</h3>
        <pre><?=htmlspecialchars($rawStatus ?: '(daemon not responding)')?></pre>
        <?php if (!$running): ?>
            <p>The NetBird daemon isn't running. Click <b>Connect</b> or check the Settings tab.</p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script>
// Pull CSRF token straight from var.ini at render time so we don't depend
// on any global JS variable being set by the page chrome.
var nbCsrfToken = <?= json_encode((@parse_ini_file('/var/local/emhttp/var.ini') ?: [])['csrf_token'] ?? '') ?>;

function nbAction(action) {
    // Resolve the token in this order so we surface clearly what's happening:
    //  1. nbCsrfToken (the literal we render into the page from var.ini)
    //  2. window.csrf_token (if the page chrome sets one)
    //  3. read it out of a hidden input on the page (a few Unraid pages set one)
    var token = (typeof nbCsrfToken !== 'undefined' && nbCsrfToken)
             || (typeof csrf_token !== 'undefined' && csrf_token)
             || ($('input[name=csrf_token]').val() || '')
             || '';
    console.log('[netbird] nbAction', action, 'token len=', token.length, 'src=',
        (typeof nbCsrfToken !== 'undefined' && nbCsrfToken) ? 'nbCsrfToken'
      : (typeof csrf_token !== 'undefined' && csrf_token)   ? 'csrf_token'
      : ($('input[name=csrf_token]').val())                 ? 'dom-input' : 'none');

    $.post('/plugins/netbird/include/action.php', { action: action, csrf_token: token }, function(data) {
        var isError = data && (data.type === 'error' || data.type === 'warning');
        if (data && data.message) {
            swal({ title: data.title || 'NetBird', text: data.message, type: data.type || 'info' });
        }
        // Only refresh on success; on an error/warning leave the message up so the
        // user can read it and dismiss it themselves (nothing changed to refresh).
        if (!isError) {
            setTimeout(function(){ location.reload(); }, 1500);
        }
    }, 'json').fail(function(xhr){
        swal({ title: 'NetBird error', text: xhr.responseText || 'Request failed', type: 'error' });
    });
}

function nbToggleSecret(codeId, toggleId) {
    var code = document.getElementById(codeId);
    var tog  = document.getElementById(toggleId);
    var icon = tog ? tog.querySelector('i') : null;
    if (!code) return;
    var revealed = code.dataset.revealed === '1';
    if (revealed) {
        code.textContent = '••••••••••••••••••••••••••••••••';
        code.classList.add('nb-secret-masked');
        code.dataset.revealed = '0';
        if (icon) { icon.classList.remove('fa-eye-slash'); icon.classList.add('fa-eye'); }
    } else {
        code.textContent = code.dataset.value || '';
        code.classList.remove('nb-secret-masked');
        code.dataset.revealed = '1';
        if (icon) { icon.classList.remove('fa-eye'); icon.classList.add('fa-eye-slash'); }
    }
}

function nbCopy(el) {
    if (!el) return;
    var text = el.dataset.value || el.textContent;
    if (!text) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text);
    } else {
        var ta = document.createElement('textarea');
        ta.value = text; document.body.appendChild(ta);
        ta.select(); document.execCommand('copy');
        document.body.removeChild(ta);
    }
}
</script>
<?php
} catch (\Throwable $e) {
    echo "<div style='background:#fee;padding:12px;border:1px solid #c00;border-radius:4px;'>"
       . "<b>NetBird plugin error</b><pre>" . htmlspecialchars($e->getMessage() . "\n@ " . $e->getFile() . ':' . $e->getLine()) . "</pre></div>";
}
?>
