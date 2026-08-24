<?php
define('ROOT', dirname(__DIR__));
require ROOT . '/includes/Database.php';
require ROOT . '/includes/Auth.php';
require ROOT . '/includes/PDF.php';
require ROOT . '/includes/Analytics.php';
require ROOT . '/includes/EmailProvider.php';
require ROOT . '/includes/EmailTemplate.php';
require ROOT . '/includes/AuditLog.php';
require ROOT . '/includes/Notification.php';
require ROOT . '/includes/helpers.php';

$config = bootstrap();
$auth->requireLogin();

$user = $auth->currentUser();
$siteName = getSetting('site_name', $config['site_name']);
$error = '';
$success = '';

if (isPost()) {
    verifyCsrf();
    $action = post('_action', '');

    if ($action === 'update_profile') {
        $name = trim(post('name'));
        if (strlen($name) < 2) {
            $error = 'Name must be at least 2 characters.';
        } else {
            Database::query('UPDATE users SET name = ? WHERE id = ?', [$name, $user['id']]);
            $success = 'Profile updated successfully.';
            $user = $auth->currentUser();
            AuditLog::log(AuditLog::ACTION_PROFILE_UPDATED, $user['id'], 'user', $user['id'], ['name' => $name]);
        }
    } elseif ($action === 'change_password') {
        $current = post('current_password');
        $newPass = post('new_password');
        $confirm = post('confirm_password');

        if (!password_verify($current, $user['password'])) {
            $error = 'Current password is incorrect.';
        } elseif (strlen($newPass) < 8) {
            $error = 'New password must be at least 8 characters.';
        } elseif ($newPass !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $hash = password_hash($newPass, PASSWORD_BCRYPT);
            Database::query('UPDATE users SET password = ? WHERE id = ?', [$hash, $user['id']]);
            $success = 'Password changed successfully.';
            AuditLog::log(AuditLog::ACTION_PASSWORD_CHANGED, $user['id'], 'user', $user['id']);
        }
    } elseif ($action === 'change_email') {
        $newEmail = trim(post('new_email'));
        $password = post('password');

        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address.';
        } elseif (!password_verify($password, $user['password'])) {
            $error = 'Password is incorrect.';
        } elseif ($newEmail === $user['email']) {
            $error = 'New email must be different.';
        } else {
            $existing = Database::fetchOne('SELECT id FROM users WHERE email = ? AND id != ?', [$newEmail, $user['id']]);
            if ($existing) {
                $error = 'This email is already in use.';
            } else {
                Database::query('UPDATE users SET email = ? WHERE id = ?', [$newEmail, $user['id']]);
                $success = 'Email updated successfully.';
                $user = $auth->currentUser();
                AuditLog::log(AuditLog::ACTION_EMAIL_CHANGED, $user['id'], 'user', $user['id'], ['old_email' => $user['email'], 'new_email' => $newEmail]);
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php require ROOT . '/admin/partials/head-meta.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile — <?= e($siteName) ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="admin-layout">

<?php require ROOT . '/admin/partials/sidebar.php'; ?>

<div class="admin-main">
    <?php require ROOT . '/admin/partials/topbar.php'; ?>

    <div class="admin-content">
        <div class="page-header">
            <div><h1>My Profile</h1><p class="text-muted"><?= e($user['email']) ?></p></div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= e($success) ?></div>
        <?php endif; ?>

        <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 2rem;">
            <!-- Profile Card -->
            <div class="card">
                <div class="card-header">
                    <h2>Account Information</h2>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <?= csrfField() ?>
                        <input type="hidden" name="_action" value="update_profile">
                        <div class="form-group">
                            <label class="form-label">Name</label>
                            <input type="text" name="name" class="form-control" value="<?= e($user['name']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" value="<?= e($user['email']) ?>" disabled>
                            <small class="text-muted">To change email, use the email change form below</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Role</label>
                            <input type="text" class="form-control" value="<?= e(ucfirst($user['role'])) ?>" disabled>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Member Since</label>
                            <input type="text" class="form-control" value="<?= date('M j, Y', strtotime($user['created_at'])) ?>" disabled>
                        </div>
                        <button type="submit" class="btn btn-primary">Update Profile</button>
                    </form>
                </div>
            </div>

            <!-- Security Card -->
            <div class="card">
                <div class="card-header">
                    <h2>Security</h2>
                </div>
                <div class="card-body">
                    <div style="margin-bottom: 2rem;">
                        <h3 style="font-size: 1rem; margin-bottom: 1rem;">Change Password</h3>
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="_action" value="change_password">
                            <div class="form-group">
                                <label class="form-label">Current Password</label>
                                <input type="password" name="current_password" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">New Password</label>
                                <input type="password" name="new_password" class="form-control" minlength="8" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Confirm Password</label>
                                <input type="password" name="confirm_password" class="form-control" minlength="8" required>
                            </div>
                            <button type="submit" class="btn btn-primary" style="width: 100%;">Change Password</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Change Email Card -->
        <div class="card" style="margin-top: 2rem;">
            <div class="card-header">
                <h2>Change Email Address</h2>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="_action" value="change_email">
                    <div class="grid" style="grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div class="form-group">
                            <label class="form-label">New Email Address</label>
                            <input type="email" name="new_email" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Confirm Password</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">Update Email</button>
                </form>
            </div>
        </div>
    </div>
</div>

</body>
</html>
