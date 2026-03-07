<?php
define('ROOT', dirname(__DIR__));
require ROOT . '/includes/Database.php';
require ROOT . '/includes/Auth.php';
require ROOT . '/includes/PDF.php';
require ROOT . '/includes/Analytics.php';
require ROOT . '/includes/helpers.php';

$config = bootstrap();

// Redirect if already logged in
if ($auth->isLoggedIn()) {
    redirect($config['base_url'] . '/admin/');
}

$error = '';
$success = '';

// Process login
if (isPost()) {
    verifyCsrf();
    $email    = trim(post('email'));
    $password = post('password');
    $ip       = Analytics::getClientIp();

    $result = $auth->login($email, $password, $ip);
    if ($result['success']) {
        redirect($config['base_url'] . '/admin/');
    } else {
        $error = $result['error'];
    }
}

$siteName = getSetting('site_name', $config['site_name']);
$googleEnabled = getSetting('google_oauth_enabled', false);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php require ROOT . '/admin/partials/head-meta.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?= e($siteName) ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        body { display: flex; align-items: center; justify-content: center; min-height: 100vh; background: var(--bg-secondary); }
        .login-card { background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,.1); width: 100%; max-width: 420px; overflow: hidden; }
        .login-header { background: linear-gradient(135deg, var(--primary), var(--primary-dark)); padding: 2rem; color: #fff; text-align: center; }
        .login-header h1 { font-size: 1.5rem; font-weight: 700; margin-bottom: .25rem; }
        .login-header p { opacity: .85; font-size: .9rem; }
        .login-body { padding: 2rem; }
        .divider { display: flex; align-items: center; gap: .75rem; margin: 1.25rem 0; color: var(--text-muted); font-size: .85rem; }
        .divider::before, .divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }
        .btn-google { display: flex; align-items: center; justify-content: center; gap: .6rem; width: 100%; padding: .65rem; border: 1.5px solid var(--border); border-radius: 8px; background: #fff; color: var(--text); font-size: .9rem; font-weight: 500; cursor: pointer; text-decoration: none; transition: all .2s; }
        .btn-google:hover { background: var(--bg-secondary); }
        .forgot-link { display: block; text-align: right; font-size: .82rem; color: var(--primary); text-decoration: none; margin-top: .25rem; }
        .forgot-link:hover { text-decoration: underline; }
        .demo-notice { background: #fef3c7; border: 1px solid #f59e0b; border-radius: 8px; padding: .75rem; font-size: .83rem; color: #92400e; margin-bottom: 1rem; }
    </style>
</head>
<body>
<div class="login-card">
    <div class="login-header">
        <h1><?= e($siteName) ?></h1>
        <p>Sign in to access the admin panel</p>
    </div>
    <div class="login-body">
        <?php if ($config['demo_mode']): ?>
        <div class="demo-notice">
            <strong>Demo Mode:</strong> Email: <code><?= e($config['demo_email']) ?></code> / Password: <code><?= e($config['demo_password']) ?></code>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= e($success) ?></div>
        <?php endif; ?>

        <?php if ($googleEnabled && $config['google_oauth_client_id']): ?>
        <a href="../api/auth.php?action=google_login" class="btn-google">
            <svg width="18" height="18" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
            Continue with Google
        </a>
        <div class="divider">or</div>
        <?php endif; ?>

        <form method="POST">
            <?= csrfField() ?>
            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-control" required autofocus
                       value="<?= $config['demo_mode'] ? e($config['demo_email']) : '' ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" required
                       value="<?= $config['demo_mode'] ? e($config['demo_password']) : '' ?>">
                <a href="forgot-password.php" class="forgot-link">Forgot password?</a>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%">Sign In</button>
        </form>
    </div>
</div>
</body>
</html>
