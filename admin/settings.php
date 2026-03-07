<?php
define('ROOT', dirname(__DIR__));
require ROOT . '/includes/Database.php';
require ROOT . '/includes/Auth.php';
require ROOT . '/includes/PDF.php';
require ROOT . '/includes/Analytics.php';
require ROOT . '/includes/helpers.php';

$config = bootstrap();
$auth->requireRole('admin');

$siteName = getSetting('site_name', $config['site_name']);
$error    = '';
$success  = '';

if (isPost()) {
    verifyCsrf();

    $action = post('_action', 'save');

    if ($action === 'take_snapshot') {
        demoTakeSnapshot();
        setSetting('demo_activated_at', time(), 'integer');
        setSetting('demo_last_reset_at', time(), 'integer');
        $success = 'Snapshot taken. Demo mode will auto-reset to this state on each cycle.';

    } elseif ($action === 'reset_now') {
        demoResetSettings();
        $success = 'Settings have been reset to the demo snapshot.';

    } elseif ($action === 'regen_token') {
        setSetting('demo_cron_token', bin2hex(random_bytes(24)), 'string');
        $success = 'Cron token regenerated.';

    } else {
        // Normal save
        $demoWasEnabled = getSetting('demo_mode', false);

        $keys = [
            'site_name'            => ['string', post('site_name')],
            'enable_public_view'   => ['boolean', post('enable_public_view', '0')],
            'analytics_enabled'    => ['boolean', post('analytics_enabled', '0')],
            'enable_download'      => ['boolean', post('enable_download', '0')],
            'enable_flipbook'      => ['boolean', post('enable_flipbook', '0')],
            'default_view'         => ['string',  in_array(post('default_view'), ['pdf','flipbook']) ? post('default_view') : 'pdf'],
            'ga_measurement_id'    => ['string',  post('ga_measurement_id')],
            'cloudflare_token'     => ['string',  post('cloudflare_token')],
            'google_oauth_enabled'   => ['boolean', post('google_oauth_enabled', '0')],
            'google_client_id'       => ['string',  post('google_client_id')],
            'google_client_secret'   => ['string',  post('google_client_secret')],
            'google_redirect_uri'    => ['string',  rtrim(trim(post('google_redirect_uri')), '/')],
            'google_allowed_domains' => ['json',    array_values(array_filter(array_map('trim', explode(',', post('google_allowed_domains', '')))))],
            'demo_mode'              => ['boolean', post('demo_mode', '0')],
            'demo_reset_interval'    => ['integer', max(5, (int)post('demo_reset_interval', 30))],
        ];

        foreach ($keys as $key => [$type, $value]) {
            setSetting($key, $value, $type);
        }

        // Also update base_url in config file if changed
        $newBaseUrl = rtrim(trim(post('base_url')), '/');
        if ($newBaseUrl) {
            $appConfig = file_get_contents(ROOT . '/config/app.php');
            $appConfig = preg_replace(
                "/'base_url'\s*=>\s*'[^']*'/",
                "'base_url' => " . var_export($newBaseUrl, true),
                $appConfig
            );
            file_put_contents(ROOT . '/config/app.php', $appConfig);
        }

        // Demo mode just activated: init state
        if (!$demoWasEnabled && post('demo_mode')) {
            setSetting('demo_activated_at', time(), 'integer');
            setSetting('demo_last_reset_at', time(), 'integer');
            if (!getSetting('demo_cron_token', '')) {
                setSetting('demo_cron_token', bin2hex(random_bytes(24)), 'string');
            }
            demoTakeSnapshot();
            $success = 'Settings saved. Demo mode activated and snapshot taken.';
        } else {
            $success = 'Settings saved.';
        }
    }
}

// Load current settings
$settings = [
    'site_name'            => getSetting('site_name', $config['site_name']),
    'enable_public_view'   => getSetting('enable_public_view', true),
    'analytics_enabled'    => getSetting('analytics_enabled', true),
    'enable_download'      => getSetting('enable_download', true),
    'enable_flipbook'      => getSetting('enable_flipbook', false),
    'default_view'         => getSetting('default_view', 'pdf'),
    'ga_measurement_id'    => getSetting('ga_measurement_id', ''),
    'cloudflare_token'     => getSetting('cloudflare_token', ''),
    'google_oauth_enabled'   => getSetting('google_oauth_enabled', false),
    'google_client_id'       => getSetting('google_client_id', ''),
    'google_client_secret'   => getSetting('google_client_secret', ''),
    'google_redirect_uri'    => getSetting('google_redirect_uri', $config['base_url'] . '/api/auth.php?action=google_callback'),
    'google_allowed_domains' => getSetting('google_allowed_domains', []),
    'demo_mode'              => getSetting('demo_mode', false),
    'demo_reset_interval'    => (int)getSetting('demo_reset_interval', 30),
    'demo_activated_at'      => (int)getSetting('demo_activated_at', 0),
    'demo_last_reset_at'     => (int)getSetting('demo_last_reset_at', 0),
    'demo_cron_token'        => getSetting('demo_cron_token', ''),
    'demo_has_snapshot'      => (bool)Database::fetchScalar('SELECT 1 FROM settings WHERE `key` = "demo_snapshot"'),
];
$googleDomains = implode(', ', (array)($settings['google_allowed_domains'] ?? []));

// Demo mode computed values
$demoNextReset = 0;
if ($settings['demo_mode'] && $settings['demo_reset_interval'] > 0 && $settings['demo_last_reset_at']) {
    $demoNextReset = $settings['demo_last_reset_at'] + ($settings['demo_reset_interval'] * 60);
}
$demoCronUrl    = $config['base_url'] . '/cron.php?token=' . urlencode($settings['demo_cron_token']);
$demoCronPhpCmd = 'php ' . ROOT . '/cron.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php require ROOT . '/admin/partials/head-meta.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings — <?= e($siteName) ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="admin-layout">

<?php require ROOT . '/admin/partials/sidebar.php'; ?>

<div class="admin-main">
    <?php require ROOT . '/admin/partials/topbar.php'; ?>

    <div class="admin-content">
        <div class="page-header">
            <div><h1>Settings</h1></div>
        </div>

        <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

        <form method="POST" style="max-width:700px">
            <?= csrfField() ?>

            <!-- Site Settings -->
            <div class="card" style="margin-bottom:1.5rem">
                <div class="card-header"><h3 class="card-title">Site Settings</h3></div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Site Name</label>
                        <input type="text" name="site_name" class="form-control" value="<?= e($settings['site_name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Base URL (no trailing slash)</label>
                        <input type="url" name="base_url" class="form-control" value="<?= e($config['base_url']) ?>">
                        <small class="text-muted">Changing this updates config/app.php directly.</small>
                    </div>
                </div>
            </div>

            <!-- Viewer Settings -->
            <div class="card" style="margin-bottom:1.5rem">
                <div class="card-header"><h3 class="card-title">Viewer Settings</h3></div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="toggle-label">
                            <input type="checkbox" name="enable_public_view" value="1" <?= $settings['enable_public_view']?'checked':'' ?>>
                            <span>Allow Public Viewing (no login required)</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="toggle-label">
                            <input type="checkbox" name="enable_download" value="1" <?= $settings['enable_download']?'checked':'' ?>>
                            <span>Enable PDF Download Button</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="toggle-label">
                            <input type="checkbox" name="enable_flipbook" value="1" <?= $settings['enable_flipbook']?'checked':'' ?>>
                            <span>Enable Flipbook Mode</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Default View Mode</label>
                        <div style="display:flex;gap:1rem;margin-top:.35rem">
                            <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
                                <input type="radio" name="default_view" value="pdf"
                                       <?= $settings['default_view']==='pdf'?'checked':'' ?>>
                                <span>
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="15" height="15" style="vertical-align:middle"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                    PDF Viewer (default)
                                </span>
                            </label>
                            <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
                                <input type="radio" name="default_view" value="flipbook"
                                       <?= $settings['default_view']==='flipbook'?'checked':'' ?>>
                                <span>
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="15" height="15" style="vertical-align:middle"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                                    Flipbook (auto-open on load)
                                </span>
                            </label>
                        </div>
                        <small class="text-muted" style="margin-top:.35rem;display:block">Requires Flipbook Mode to be enabled for the Flipbook option to take effect.</small>
                    </div>
                </div>
            </div>

            <!-- Analytics -->
            <div class="card" style="margin-bottom:1.5rem">
                <div class="card-header"><h3 class="card-title">Analytics</h3></div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="toggle-label">
                            <input type="checkbox" name="analytics_enabled" value="1" <?= $settings['analytics_enabled']?'checked':'' ?>>
                            <span>Enable Built-in Analytics</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Google Analytics 4 Measurement ID</label>
                        <input type="text" name="ga_measurement_id" class="form-control" placeholder="G-XXXXXXXXXX" value="<?= e($settings['ga_measurement_id']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Cloudflare Analytics Token</label>
                        <input type="text" name="cloudflare_token" class="form-control" placeholder="Optional" value="<?= e($settings['cloudflare_token']) ?>">
                    </div>
                </div>
            </div>

            <!-- Google OAuth -->
            <div class="card" style="margin-bottom:1.5rem">
                <div class="card-header"><h3 class="card-title">Google OAuth2 Login</h3></div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="toggle-label">
                            <input type="checkbox" name="google_oauth_enabled" value="1" <?= $settings['google_oauth_enabled']?'checked':'' ?>>
                            <span>Enable Google Sign-In</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Google Client ID</label>
                        <input type="text" name="google_client_id" class="form-control" value="<?= e($settings['google_client_id']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Google Client Secret</label>
                        <input type="password" name="google_client_secret" class="form-control" value="<?= e($settings['google_client_secret']) ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label">OAuth2 Redirect URI</label>
                        <input type="url" name="google_redirect_uri" class="form-control" value="<?= e($settings['google_redirect_uri']) ?>">
                        <small class="text-muted">Copy this URL into your Google Cloud Console → Authorized redirect URIs.</small>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Allowed Email Domains (comma-separated, empty = all)</label>
                        <input type="text" name="google_allowed_domains" class="form-control" placeholder="krea.edu, example.com" value="<?= e($googleDomains) ?>">
                    </div>
                </div>
            </div>

            <!-- Demo Mode -->
            <div class="card" style="margin-bottom:1.5rem">
                <div class="card-header">
                    <h3 class="card-title">Demo / Test Mode</h3>
                    <?php if ($settings['demo_mode']): ?>
                    <span style="background:#f59e0b20;color:#f59e0b;border:1px solid #f59e0b40;border-radius:6px;padding:.2rem .65rem;font-size:.75rem;font-weight:700;letter-spacing:.04em">ACTIVE</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">

                    <?php if ($settings['demo_mode']): ?>
                    <div class="alert" style="background:#f59e0b15;border:1px solid #f59e0b40;color:#fbbf24;margin-bottom:1.25rem;border-radius:8px;padding:.75rem 1rem;font-size:.88rem">
                        <strong>Demo mode is active.</strong> Settings will auto-reset to the snapshot every
                        <?= $settings['demo_reset_interval'] ?> minute<?= $settings['demo_reset_interval'] != 1 ? 's' : '' ?>.
                        Visitors can freely change settings — they will be restored automatically.
                    </div>
                    <?php endif; ?>

                    <div class="form-group">
                        <label class="toggle-label">
                            <input type="checkbox" name="demo_mode" value="1" <?= $settings['demo_mode'] ? 'checked' : '' ?> id="demo_mode_toggle">
                            <span>Enable Demo Mode</span>
                        </label>
                        <small class="text-muted" style="display:block;margin-top:.3rem">
                            Shows demo credentials on login page. Settings auto-reset to snapshot on schedule.
                            Demo credentials are set in <code>config/app.php</code> (<strong>demo_email</strong> / <strong>demo_password</strong>).
                        </small>
                    </div>

                    <div id="demo-options" style="<?= $settings['demo_mode'] ? '' : 'display:none' ?>">

                        <!-- Reset interval -->
                        <div class="form-group">
                            <label class="form-label">Auto-Reset Interval</label>
                            <select name="demo_reset_interval" class="form-control" style="max-width:220px">
                                <?php foreach ([5=>'5 minutes',10=>'10 minutes',15=>'15 minutes',30=>'30 minutes',60=>'1 hour',120=>'2 hours',360=>'6 hours',720=>'12 hours',1440=>'24 hours'] as $val => $label): ?>
                                <option value="<?= $val ?>" <?= $settings['demo_reset_interval'] == $val ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">How often settings reset back to the snapshot baseline.</small>
                        </div>

                        <!-- Status panel -->
                        <div style="background:#0f172a;border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:1rem 1.25rem;margin:1rem 0;display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.75rem">
                            <div>
                                <div style="font-size:.7rem;color:#64748b;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.2rem">Activated</div>
                                <div style="font-size:.88rem;color:#e2e8f0;font-weight:600">
                                    <?= $settings['demo_activated_at'] ? date('M j, Y H:i', $settings['demo_activated_at']) : '—' ?>
                                </div>
                            </div>
                            <div>
                                <div style="font-size:.7rem;color:#64748b;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.2rem">Last Reset</div>
                                <div style="font-size:.88rem;color:#e2e8f0;font-weight:600">
                                    <?= $settings['demo_last_reset_at'] ? date('M j, Y H:i', $settings['demo_last_reset_at']) : '—' ?>
                                </div>
                            </div>
                            <div>
                                <div style="font-size:.7rem;color:#64748b;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.2rem">Next Reset</div>
                                <div style="font-size:.88rem;font-weight:600" id="demo-next-reset"
                                     data-ts="<?= $demoNextReset ?>">
                                    <?= $demoNextReset > time() ? date('M j, Y H:i', $demoNextReset) : ($settings['demo_mode'] ? 'Imminent' : '—') ?>
                                </div>
                            </div>
                            <div>
                                <div style="font-size:.7rem;color:#64748b;text-transform:uppercase;letter-spacing:.06em;margin-bottom:.2rem">Snapshot</div>
                                <div style="font-size:.88rem;font-weight:600;color:<?= $settings['demo_has_snapshot'] ? '#4ade80' : '#f87171' ?>">
                                    <?= $settings['demo_has_snapshot'] ? 'Saved' : 'None' ?>
                                </div>
                            </div>
                        </div>

                        <!-- Snapshot actions -->
                        <div style="display:flex;gap:.65rem;flex-wrap:wrap;margin-bottom:1.25rem">
                            <button type="submit" name="_action" value="take_snapshot" class="btn btn-secondary" style="font-size:.85rem">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="15" height="15"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                Take Snapshot Now
                            </button>
                            <?php if ($settings['demo_has_snapshot']): ?>
                            <button type="submit" name="_action" value="reset_now"
                                    class="btn" style="background:#dc2626;color:#fff;font-size:.85rem"
                                    onclick="return confirm('Reset all settings to demo snapshot now?')">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="15" height="15"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                Reset Now
                            </button>
                            <?php endif; ?>
                        </div>

                        <!-- Cron setup -->
                        <div style="background:#0f172a;border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:1rem 1.25rem">
                            <div style="font-size:.85rem;font-weight:600;color:#94a3b8;margin-bottom:.75rem;display:flex;align-items:center;gap:.5rem">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="15" height="15"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                Server Cron Setup <span style="font-weight:400;color:#475569">(optional — pseudo-cron runs on every page load)</span>
                            </div>

                            <div style="margin-bottom:.75rem">
                                <div style="font-size:.75rem;color:#64748b;margin-bottom:.3rem">Cron URL (call this with wget/curl)</div>
                                <div style="display:flex;gap:.5rem;align-items:center">
                                    <code style="flex:1;background:#1e293b;border:1px solid rgba(255,255,255,.08);border-radius:6px;padding:.45rem .7rem;font-size:.78rem;color:#a5b4fc;word-break:break-all" id="cron-url"><?= e($demoCronUrl) ?></code>
                                    <button type="button" onclick="copyToClipboard('cron-url', this)" class="btn btn-secondary" style="font-size:.78rem;white-space:nowrap;padding:.4rem .75rem">Copy</button>
                                </div>
                            </div>

                            <div style="margin-bottom:.75rem">
                                <div style="font-size:.75rem;color:#64748b;margin-bottom:.3rem">crontab — HTTP (every 60 min)</div>
                                <div style="display:flex;gap:.5rem;align-items:center">
                                    <code style="flex:1;background:#1e293b;border:1px solid rgba(255,255,255,.08);border-radius:6px;padding:.45rem .7rem;font-size:.78rem;color:#a5b4fc;word-break:break-all" id="cron-cmd-http">0 * * * * wget -qO- "<?= e($demoCronUrl) ?>" > /dev/null 2>&1</code>
                                    <button type="button" onclick="copyToClipboard('cron-cmd-http', this)" class="btn btn-secondary" style="font-size:.78rem;white-space:nowrap;padding:.4rem .75rem">Copy</button>
                                </div>
                            </div>
                            <div style="margin-bottom:.75rem">
                                <div style="font-size:.75rem;color:#64748b;margin-bottom:.3rem">crontab — PHP CLI (every 60 min)</div>
                                <div style="display:flex;gap:.5rem;align-items:center">
                                    <code style="flex:1;background:#1e293b;border:1px solid rgba(255,255,255,.08);border-radius:6px;padding:.45rem .7rem;font-size:.78rem;color:#a5b4fc;word-break:break-all" id="cron-cmd-cli">0 * * * * php <?= e(ROOT) ?>/cron.php >> /var/log/pdfviewer-cron.log 2>&1</code>
                                    <button type="button" onclick="copyToClipboard('cron-cmd-cli', this)" class="btn btn-secondary" style="font-size:.78rem;white-space:nowrap;padding:.4rem .75rem">Copy</button>
                                </div>
                            </div>

                            <div style="display:flex;align-items:center;gap:.65rem">
                                <div style="flex:1">
                                    <div style="font-size:.75rem;color:#64748b;margin-bottom:.3rem">Secret Token</div>
                                    <code style="font-size:.78rem;color:#94a3b8"><?= $settings['demo_cron_token'] ? substr($settings['demo_cron_token'], 0, 8) . '••••••••••••••••••••••••••' : 'Not generated yet' ?></code>
                                </div>
                                <button type="submit" name="_action" value="regen_token"
                                        class="btn btn-secondary" style="font-size:.78rem;padding:.4rem .75rem"
                                        onclick="return confirm('Regenerate cron token? The old URL will stop working.')">
                                    Regenerate
                                </button>
                            </div>
                        </div>

                    </div><!-- /demo-options -->
                </div>
            </div>

            <button type="submit" name="_action" value="save" class="btn btn-primary">Save Settings</button>
        </form>
    </div>
</div>

<script>
// Toggle demo options visibility
document.getElementById('demo_mode_toggle').addEventListener('change', function() {
    document.getElementById('demo-options').style.display = this.checked ? '' : 'none';
});

// Countdown timer for next reset
(function() {
    var el = document.getElementById('demo-next-reset');
    if (!el) return;
    var ts = parseInt(el.dataset.ts, 10);
    if (!ts) return;

    function update() {
        var remaining = ts - Math.floor(Date.now() / 1000);
        if (remaining <= 0) {
            el.textContent = 'Imminent';
            el.style.color = '#f87171';
            return;
        }
        var h = Math.floor(remaining / 3600);
        var m = Math.floor((remaining % 3600) / 60);
        var s = remaining % 60;
        var parts = [];
        if (h) parts.push(h + 'h');
        if (m || h) parts.push(m + 'm');
        parts.push(s + 's');
        el.textContent = parts.join(' ');
        el.style.color = remaining < 60 ? '#f87171' : remaining < 300 ? '#f59e0b' : '#4ade80';
    }
    update();
    setInterval(update, 1000);
})();

// Copy to clipboard
function copyToClipboard(id, btn) {
    var text = document.getElementById(id).textContent;
    navigator.clipboard.writeText(text).then(function() {
        var orig = btn.textContent;
        btn.textContent = 'Copied!';
        btn.style.color = '#4ade80';
        setTimeout(function() { btn.textContent = orig; btn.style.color = ''; }, 2000);
    });
}
</script>
</body>
</html>
