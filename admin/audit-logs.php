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
$auth->requireRole('admin');

$siteName = getSetting('site_name', $config['site_name']);

// Filters
$action = get('action', '');
$userId = (int)get('user_id', 0);
$days = (int)get('days', 7);

// Build query
$where = ['1=1'];
$params = [];

if ($action) {
    $where[] = 'a.action = ?';
    $params[] = $action;
}

if ($userId) {
    $where[] = 'a.user_id = ?';
    $params[] = $userId;
}

if ($days) {
    $where[] = 'a.logged_at >= DATE_SUB(NOW(), INTERVAL ? DAY)';
    $params[] = $days;
}

$totalRecords = (int)Database::fetchScalar(
    'SELECT COUNT(*) FROM audit_logs a WHERE ' . implode(' AND ', $where),
    $params
);

$perPage = 50;
$currentPage = max(1, (int)get('page', 1));
$totalPages = ceil($totalRecords / $perPage);
$offset = ($currentPage - 1) * $perPage;

$logs = Database::fetchAll(
    'SELECT a.*, u.name, u.email FROM audit_logs a
     LEFT JOIN users u ON u.id = a.user_id
     WHERE ' . implode(' AND ', $where) . '
     ORDER BY a.logged_at DESC
     LIMIT ? OFFSET ?',
    array_merge($params, [$perPage, $offset])
);

// Export CSV
if (get('export') === '1') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit-logs-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Timestamp', 'User', 'Email', 'Action', 'Resource', 'Resource ID', 'IP Address']);
    foreach ($logs as $log) {
        fputcsv($out, [
            $log['logged_at'],
            $log['name'] ?? 'Unknown',
            $log['email'] ?? '',
            $log['action'],
            $log['resource_type'],
            $log['resource_id'],
            $log['ip_address'],
        ]);
    }
    fclose($out);
    exit;
}

$actions = Database::fetchAll('SELECT DISTINCT action FROM audit_logs ORDER BY action ASC');
$users = Database::fetchAll('SELECT id, name, email FROM users WHERE status = "active" ORDER BY name ASC');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php require ROOT . '/admin/partials/head-meta.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Logs — <?= e($siteName) ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="admin-layout">

<?php require ROOT . '/admin/partials/sidebar.php'; ?>

<div class="admin-main">
    <?php require ROOT . '/admin/partials/topbar.php'; ?>

    <div class="admin-content">
        <div class="page-header">
            <div><h1>Audit Logs</h1><p class="text-muted">Security & compliance tracking</p></div>
            <a href="?export=1<?php foreach (['action', 'user_id', 'days'] as $k): if (get($k)): ?>&<?= $k ?>=<?= urlencode(get($k)) ?><?php endif; endforeach; ?>" class="btn btn-outline">Export CSV</a>
        </div>

        <div class="card" style="margin-bottom: 2rem;">
            <div class="card-body">
                <form method="GET" style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 1rem; align-items: end;">
                    <div class="form-group">
                        <label class="form-label">Action</label>
                        <select name="action" class="form-control">
                            <option value="">All Actions</option>
                            <?php foreach ($actions as $a): ?>
                            <option value="<?= e($a['action']) ?>" <?= $action === $a['action'] ? 'selected' : '' ?>>
                                <?= e(ucwords(str_replace('_', ' ', $a['action']))) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">User</label>
                        <select name="user_id" class="form-control">
                            <option value="">All Users</option>
                            <?php foreach ($users as $u): ?>
                            <option value="<?= $u['id'] ?>" <?= $userId === $u['id'] ? 'selected' : '' ?>>
                                <?= e($u['name']) ?> (<?= e($u['email']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Time Period</label>
                        <select name="days" class="form-control">
                            <option value="1" <?= $days === 1 ? 'selected' : '' ?>>Last 24 hours</option>
                            <option value="7" <?= $days === 7 ? 'selected' : '' ?>>Last 7 days</option>
                            <option value="30" <?= $days === 30 ? 'selected' : '' ?>>Last 30 days</option>
                            <option value="90" <?= $days === 90 ? 'selected' : '' ?>>Last 90 days</option>
                            <option value="0" <?= $days === 0 ? 'selected' : '' ?>>All time</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">Filter</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body" style="padding: 0; overflow-x: auto;">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Resource</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                        <tr><td colspan="5" style="text-align: center; padding: 40px; color: var(--text-muted);">No logs found</td></tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                            <tr>
                                <td style="font-size: 12px;"><?= date('M j, H:i', strtotime($log['logged_at'])) ?></td>
                                <td>
                                    <?php if ($log['name']): ?>
                                    <strong><?= e($log['name']) ?></strong><br>
                                    <small style="color: var(--text-muted);"><?= e($log['email']) ?></small>
                                    <?php else: ?>
                                    <span style="color: var(--text-muted);">Unknown</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge" style="background: var(--primary-light); color: var(--primary);">
                                        <?= e(ucwords(str_replace('_', ' ', $log['action']))) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($log['resource_type']): ?>
                                    <?= e(ucfirst($log['resource_type'])) ?> #<?= $log['resource_id'] ?>
                                    <?php else: ?>
                                    <span style="color: var(--text-muted);">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><code style="font-size: 11px;"><?= e($log['ip_address']) ?></code></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div style="display: flex; justify-content: center; gap: 8px; margin-top: 2rem;">
            <?php if ($currentPage > 1): ?>
            <a href="?page=1<?php foreach (['action', 'user_id', 'days'] as $k): if (get($k)): ?>&<?= $k ?>=<?= urlencode(get($k)) ?><?php endif; endforeach; ?>" class="btn btn-sm btn-outline">First</a>
            <a href="?page=<?= $currentPage - 1 ?><?php foreach (['action', 'user_id', 'days'] as $k): if (get($k)): ?>&<?= $k ?>=<?= urlencode(get($k)) ?><?php endif; endforeach; ?>" class="btn btn-sm btn-outline">Previous</a>
            <?php endif; ?>

            <span style="padding: 8px 16px;">Page <?= $currentPage ?> of <?= $totalPages ?></span>

            <?php if ($currentPage < $totalPages): ?>
            <a href="?page=<?= $currentPage + 1 ?><?php foreach (['action', 'user_id', 'days'] as $k): if (get($k)): ?>&<?= $k ?>=<?= urlencode(get($k)) ?><?php endif; endforeach; ?>" class="btn btn-sm btn-outline">Next</a>
            <a href="?page=<?= $totalPages ?><?php foreach (['action', 'user_id', 'days'] as $k): if (get($k)): ?>&<?= $k ?>=<?= urlencode(get($k)) ?><?php endif; endforeach; ?>" class="btn btn-sm btn-outline">Last</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
