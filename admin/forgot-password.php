<?php
define('ROOT', dirname(__DIR__));
require ROOT . '/includes/Database.php';
require ROOT . '/includes/Auth.php';
require ROOT . '/includes/PDF.php';
require ROOT . '/includes/Analytics.php';
require ROOT . '/includes/helpers.php';

$config   = bootstrap();
$siteName = getSetting('site_name', $config['site_name']);
$step     = get('step', 'request');
$token    = get('token', '');
$error    = '';
$success  = '';

if (isPost()) {
    verifyCsrf();
    if ($step === 'request') {
        $email = trim(post('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email.';
        } else {
            $resetToken = $auth->generateResetToken($email);
            // In production: send email. For now, display the link (demo-friendly).
            $resetUrl = $config['base_url'] . '/admin/forgot-password.php?step=reset&token=' . ($resetToken ?? '');
            if ($resetToken) {
                $success = 'Password reset link generated. In production this would be emailed. <br>
                    <a href="' . e($resetUrl) . '">Click here to reset your password</a>';
            } else {
                // Don't reveal if email exists
                $success = 'If that email exists, a reset link has been sent.';
            }
        }
    } elseif ($step === 'reset') {
        $token       = post('token') ?: $token;
        $newPassword = post('new_password');
        $confirm     = post('confirm_password');

        if (strlen($newPassword) < 8) {
            $error = 'Password must be at least 8 characters.';
        } elseif ($newPassword !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $user = $auth->validateResetToken($token);
            if (!$user) {
                $error = 'Invalid or expired reset link.';
            } else {
                $auth->resetPassword($user['id'], $newPassword);
                $success = 'Password updated! <a href="login.php">Login now</a>';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password — <?= e($siteName) ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        body { display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--bg-secondary); }
        .login-card { background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.1);width:100%;max-width:400px;overflow:hidden; }
        .login-header { background:linear-gradient(135deg,var(--primary),var(--primary-dark));padding:2rem;color:#fff;text-align:center; }
        .login-header h1 { font-size:1.4rem;font-weight:700; }
        .login-body { padding:2rem; }
    </style>
</head>
<body>
<div class="login-card">
    <div class="login-header"><h1>Reset Password</h1></div>
    <div class="login-body">
        <?php if ($error): ?><div class="alert alert-error"><?= $error ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div>
        <?php elseif ($step === 'request'): ?>
        <form method="POST">
            <?= csrfField() ?>
            <div class="form-group">
                <label class="form-label">Email Address</label>
                <input type="email" name="email" class="form-control" required autofocus>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%">Send Reset Link</button>
        </form>
        <?php elseif ($step === 'reset'): ?>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <div class="form-group">
                <label class="form-label">New Password</label>
                <input type="password" name="new_password" class="form-control" minlength="8" required>
            </div>
            <div class="form-group">
                <label class="form-label">Confirm Password</label>
                <input type="password" name="confirm_password" class="form-control" minlength="8" required>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%">Update Password</button>
        </form>
        <?php endif; ?>
        <p style="text-align:center;margin-top:1rem"><a href="login.php" style="color:var(--primary);font-size:.85rem">Back to login</a></p>
    </div>
</div>
</body>
</html>
