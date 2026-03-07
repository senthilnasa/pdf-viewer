<?php
define('ROOT', dirname(__DIR__));
require ROOT . '/includes/Database.php';
require ROOT . '/includes/Auth.php';
require ROOT . '/includes/PDF.php';
require ROOT . '/includes/Analytics.php';
require ROOT . '/includes/helpers.php';

$config = bootstrap();
$auth->requireRole('admin');

$user     = $auth->currentUser();
$siteName = getSetting('site_name', $config['site_name']);
$error    = '';
$success  = '';

if (isPost()) {
    verifyCsrf();
    $postAction = post('_action');

    if ($postAction === 'invite') {
        $email = trim(post('email'));
        $name  = trim(post('name'));
        $role  = post('role', 'viewer');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address.';
        } elseif (!in_array($role, ['admin', 'editor', 'viewer'])) {
            $error = 'Invalid role.';
        } else {
            $existing = Database::fetchOne('SELECT id FROM users WHERE email = ?', [$email]);
            if ($existing) {
                $error = 'A user with this email already exists.';
            } else {
                $token = bin2hex(random_bytes(32));
                Database::insert(
                    'INSERT INTO users (name, email, role, status, invite_token, auth_provider) VALUES (?, ?, ?, ?, ?, ?)',
                    [$name ?: $email, $email, $role, 'invited', $token, 'local']
                );
                $inviteUrl = $config['base_url'] . '/admin/accept-invite.php?token=' . $token;
                $success = "User invited! Share this link with them: <br><code>{$inviteUrl}</code>";
            }
        }
    }

    if ($postAction === 'update_role') {
        $memberId = (int)post('member_id');
        $newRole  = post('role');
        if ($memberId === $user['id']) {
            $error = "You cannot change your own role.";
        } elseif (!in_array($newRole, ['admin', 'editor', 'viewer'])) {
            $error = 'Invalid role.';
        } else {
            Database::query('UPDATE users SET role = ? WHERE id = ?', [$newRole, $memberId]);
            $success = 'Role updated.';
        }
    }

    if ($postAction === 'deactivate') {
        $memberId = (int)post('member_id');
        if ($memberId === $user['id']) {
            $error = "You cannot deactivate your own account.";
        } else {
            Database::query("UPDATE users SET status = 'inactive' WHERE id = ?", [$memberId]);
            $success = 'User deactivated.';
        }
    }

    if ($postAction === 'activate') {
        $memberId = (int)post('member_id');
        Database::query("UPDATE users SET status = 'active' WHERE id = ?", [$memberId]);
        $success = 'User activated.';
    }

    if ($postAction === 'delete') {
        $memberId = (int)post('member_id');
        if ($memberId === $user['id']) {
            $error = 'You cannot delete your own account.';
        } else {
            Database::query('DELETE FROM users WHERE id = ?', [$memberId]);
            $success = 'User deleted.';
        }
    }
}

// CSV export
if (get('export') === 'users_csv') {
    $rows = Database::fetchAll(
        "SELECT name, email, role, status, created_at, last_login FROM users ORDER BY created_at DESC"
    );
    exportCsv($rows, 'users-' . date('Ymd') . '.csv');
}

$members = Database::fetchAll(
    'SELECT *, (SELECT COUNT(*) FROM pdf_documents WHERE created_by = users.id) AS pdf_count FROM users ORDER BY created_at DESC'
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php require ROOT . '/admin/partials/head-meta.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team — <?= e($siteName) ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="admin-layout">

<?php require ROOT . '/admin/partials/sidebar.php'; ?>

<div class="admin-main">
    <?php require ROOT . '/admin/partials/topbar.php'; ?>

    <div class="admin-content">
        <div class="page-header">
            <div><h1>Team</h1><p class="text-muted"><?= number_format(count($members)) ?> members</p></div>
            <div style="display:flex;gap:.5rem">
                <a href="?export=users_csv" class="btn btn-outline">Export CSV</a>
                <button class="btn btn-primary" onclick="document.getElementById('inviteModal').classList.add('open')">+ Invite Member</button>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= $error ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?= $success ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body" style="padding:0">
                <table class="table">
                    <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>PDFs</th><th>Last Login</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($members as $member): ?>
                    <tr>
                        <td>
                            <div style="display:flex;align-items:center;gap:.75rem">
                                <div class="user-avatar" style="width:32px;height:32px;font-size:.8rem"><?= strtoupper(mb_substr($member['name'], 0, 1)) ?></div>
                                <span><?= e($member['name']) ?></span>
                            </div>
                        </td>
                        <td><?= e($member['email']) ?></td>
                        <td>
                            <?php if ($member['id'] !== $user['id']): ?>
                            <form method="POST" style="display:inline-flex;gap:.25rem">
                                <?= csrfField() ?>
                                <input type="hidden" name="_action" value="update_role">
                                <input type="hidden" name="member_id" value="<?= $member['id'] ?>">
                                <select name="role" class="form-control" style="padding:.25rem .5rem;font-size:.83rem" onchange="this.form.submit()">
                                    <option value="admin"  <?= $member['role']==='admin'?'selected':'' ?>>Admin</option>
                                    <option value="editor" <?= $member['role']==='editor'?'selected':'' ?>>Editor</option>
                                    <option value="viewer" <?= $member['role']==='viewer'?'selected':'' ?>>Viewer</option>
                                </select>
                            </form>
                            <?php else: ?>
                                <span class="badge badge-primary"><?= e($member['role']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge badge-<?= $member['status']==='active'?'success':($member['status']==='invited'?'warning':'secondary') ?>"><?= e($member['status']) ?></span></td>
                        <td><?= number_format($member['pdf_count']) ?></td>
                        <td><?= $member['last_login'] ? timeAgo($member['last_login']) : 'Never' ?></td>
                        <td>
                            <?php if ($member['id'] !== $user['id']): ?>
                            <?php if ($member['status'] === 'active'): ?>
                            <form method="POST" style="display:inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="_action" value="deactivate">
                                <input type="hidden" name="member_id" value="<?= $member['id'] ?>">
                                <button type="submit" class="btn btn-xs btn-warning">Deactivate</button>
                            </form>
                            <?php else: ?>
                            <form method="POST" style="display:inline">
                                <?= csrfField() ?>
                                <input type="hidden" name="_action" value="activate">
                                <input type="hidden" name="member_id" value="<?= $member['id'] ?>">
                                <button type="submit" class="btn btn-xs btn-outline">Activate</button>
                            </form>
                            <?php endif; ?>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Delete this user?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="_action" value="delete">
                                <input type="hidden" name="member_id" value="<?= $member['id'] ?>">
                                <button type="submit" class="btn btn-xs btn-danger">Delete</button>
                            </form>
                            <?php else: ?>
                            <span class="text-muted" style="font-size:.8rem">You</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Invite Modal -->
<div class="modal" id="inviteModal">
    <div class="modal-backdrop" onclick="this.parentElement.classList.remove('open')"></div>
    <div class="modal-dialog">
        <div class="modal-header">
            <h3>Invite Team Member</h3>
            <button class="modal-close" onclick="document.getElementById('inviteModal').classList.remove('open')">&times;</button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="_action" value="invite">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Name</label>
                    <input type="text" name="name" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Email *</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Role</label>
                    <select name="role" class="form-control">
                        <option value="viewer">Viewer — view analytics only</option>
                        <option value="editor">Editor — upload and manage PDFs</option>
                        <option value="admin">Admin — full access</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('inviteModal').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn btn-primary">Send Invite</button>
            </div>
        </form>
    </div>
</div>
</body>
</html>
