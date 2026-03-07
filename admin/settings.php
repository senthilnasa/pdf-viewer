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

    $keys = [
        'site_name'            => ['string', post('site_name')],
        'enable_public_view'   => ['boolean', post('enable_public_view', '0')],
        'analytics_enabled'    => ['boolean', post('analytics_enabled', '0')],
        'enable_download'      => ['boolean', post('enable_download', '0')],
        'enable_flipbook'      => ['boolean', post('enable_flipbook', '0')],
        'default_view'         => ['string',  in_array(post('default_view'), ['pdf','flipbook']) ? post('default_view') : 'pdf'],
        'ga_measurement_id'    => ['string',  post('ga_measurement_id')],
        'cloudflare_token'     => ['string',  post('cloudflare_token')],
        'google_oauth_enabled'    => ['boolean', post('google_oauth_enabled', '0')],
        'google_client_id'        => ['string',  post('google_client_id')],
        'google_client_secret'    => ['string',  post('google_client_secret')],
        'google_redirect_uri'     => ['string',  rtrim(trim(post('google_redirect_uri')), '/')],
        'google_allowed_domains'  => ['json',    array_values(array_filter(array_map('trim', explode(',', post('google_allowed_domains', '')))))],
        'demo_mode'               => ['boolean', post('demo_mode', '0')],
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

    $success = 'Settings saved.';
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
];
$googleDomains = implode(', ', (array)($settings['google_allowed_domains'] ?? []));
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
                <div class="card-header"><h3 class="card-title">Demo / Test Mode</h3></div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="toggle-label">
                            <input type="checkbox" name="demo_mode" value="1" <?= $settings['demo_mode']?'checked':'' ?>>
                            <span>Enable Demo Mode (shows credentials on login page)</span>
                        </label>
                    </div>
                    <div class="alert alert-info" style="margin-top:.75rem">Demo credentials are set in <code>config/app.php</code>: <strong>demo_email</strong> and <strong>demo_password</strong>.</div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">Save Settings</button>
        </form>
    </div>
</div>
</body>
</html>
