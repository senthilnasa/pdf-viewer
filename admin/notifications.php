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

if (isPost()) {
    verifyCsrf();
    $action = post('_action', '');

    if ($action === 'mark_read') {
        $notifId = (int)post('notification_id');
        Notification::markAsRead($notifId, $user['id']);
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    } elseif ($action === 'mark_all_read') {
        Notification::markAllAsRead($user['id']);
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    } elseif ($action === 'delete') {
        $notifId = (int)post('notification_id');
        Notification::delete($notifId, $user['id']);
        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    }
}

$notifications = Notification::getAll($user['id'], 100);
$unreadCount = Notification::getUnreadCount($user['id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php require ROOT . '/admin/partials/head-meta.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications — <?= e($siteName) ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <script src="../assets/js/admin-ajax.js" defer></script>
</head>
<body class="admin-layout">

<?php require ROOT . '/admin/partials/sidebar.php'; ?>

<div class="admin-main">
    <?php require ROOT . '/admin/partials/topbar.php'; ?>

    <div class="admin-content">
        <div class="page-header">
            <div>
                <h1>Notifications</h1>
                <p class="text-muted"><?= $unreadCount ?> unread</p>
            </div>
            <?php if ($unreadCount > 0): ?>
            <button class="btn btn-outline" onclick="markAllRead()">Mark All as Read</button>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-body" style="padding: 0;">
                <?php if (empty($notifications)): ?>
                <div style="padding: 40px; text-align: center; color: var(--text-muted);">
                    <p>No notifications yet.</p>
                </div>
                <?php else: ?>
                <div style="max-height: 600px; overflow-y: auto;">
                    <?php foreach ($notifications as $notif): ?>
                    <div class="notification-item" data-id="<?= $notif['id'] ?>" style="padding: 16px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: flex-start; <?= $notif['read_at'] ? '' : 'background: var(--bg-secondary);' ?>">
                        <div style="flex: 1;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span class="badge" style="background: var(--primary-light); color: var(--primary); font-size: 11px; text-transform: uppercase;"><?= e($notif['type']) ?></span>
                                <strong><?= e($notif['title']) ?></strong>
                            </div>
                            <p style="margin: 8px 0; color: var(--text-muted); font-size: 14px;"><?= e($notif['message']) ?></p>
                            <small style="color: #999;"><?= timeAgo($notif['created_at']) ?></small>
                            <?php if ($notif['action_url']): ?>
                            <div style="margin-top: 8px;">
                                <a href="<?= e($notif['action_url']) ?>" class="btn btn-sm btn-outline">View</a>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div style="display: flex; gap: 8px;">
                            <?php if (!$notif['read_at']): ?>
                            <button class="btn btn-sm btn-ghost" onclick="markRead(<?= $notif['id'] ?>)" title="Mark as read">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="16" height="16">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                            </button>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-ghost" onclick="deleteNotif(<?= $notif['id'] ?>)" title="Delete">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="16" height="16">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function markRead(id) {
    fetch(document.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `_action=mark_read&notification_id=${id}&csrf_token=<?= $auth->generateCsrfToken() ?>`
    }).then(() => {
        document.querySelector(`[data-id="${id}"]`).style.background = '';
    });
}

function markAllRead() {
    fetch(document.location.href, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `_action=mark_all_read&csrf_token=<?= $auth->generateCsrfToken() ?>`
    }).then(() => location.reload());
}

function deleteNotif(id) {
    if (confirm('Delete this notification?')) {
        fetch(document.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `_action=delete&notification_id=${id}&csrf_token=<?= $auth->generateCsrfToken() ?>`
        }).then(() => {
            document.querySelector(`[data-id="${id}"]`).remove();
        });
    }
}
</script>

</body>
</html>
