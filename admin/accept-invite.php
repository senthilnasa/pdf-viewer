<?php
define('ROOT', dirname(__DIR__));
require ROOT . '/includes/Database.php';
require ROOT . '/includes/Auth.php';
require ROOT . '/includes/PDF.php';
require ROOT . '/includes/Analytics.php';
require ROOT . '/includes/helpers.php';

$config   = bootstrap();
$siteName = getSetting('site_name', $config['site_name']);
$token    = trim(get('token', ''));
$error    = '';
$success  = '';

$user = null;
if ($token) {
    $user = Database::fetchOne("SELECT * FROM users WHERE invite_token = ? AND status = 'invited'", [$token]);
}

if (!$user && !$error) {
    $error = 'Invalid or expired invitation link.';
}

if (isPost() && $user) {
    verifyCsrf();
    $password = post('password');
    $confirm  = post('confirm');
    $name     = trim(post('name')) ?: $user['name'];

    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        Database::query(
            "UPDATE users SET name = ?, password = ?, status = 'active', invite_token = NULL WHERE id = ?",
            [$name, $hash, $user['id']]
        );
        $success = 'Account activated! <a href="login.php">Login now</a>';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accept Invitation — <?= e($siteName) ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        body { display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--bg-secondary); }
        .card { max-width:420px;width:100%; }
        .login-header { background:linear-gradient(135deg,var(--primary),var(--primary-dark));padding:2rem;color:#fff;text-align:center;border-radius:12px 12px 0 0; }
        .login-body { padding:2rem; }
    </style>
</head>
<body>
<div class="card">
    <div class="login-header"><h1>Accept Invitation</h1><p style="opacity:.85;font-size:.9rem"><?= e($siteName) ?></p></div>
    <div class="login-body">
        <?php if ($error): ?><div class="alert alert-error"><?= $error ?></div>
        <?php elseif ($success): ?><div class="alert alert-success"><?= $success ?></div>
        <?php else: ?>
        <p style="margin-bottom:1.25rem">You've been invited as <strong><?= e($user['role']) ?></strong>. Set your password to activate your account.</p>
        <form method="POST">
            <?= csrfField() ?>
            <div class="form-group">
                <label class="form-label">Your Name</label>
                <input type="text" name="name" class="form-control" value="<?= e($user['name']) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Email</label>
                <input type="email" class="form-control" value="<?= e($user['email']) ?>" disabled>
            </div>
            <div class="form-group">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" minlength="8" required>
            </div>
            <div class="form-group">
                <label class="form-label">Confirm Password</label>
                <input type="password" name="confirm" class="form-control" minlength="8" required>
            </div>
            <button type="submit" class="btn btn-primary" style="width:100%">Activate Account</button>
        </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
