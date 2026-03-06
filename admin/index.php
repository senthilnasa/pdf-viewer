<?php
define('ROOT', dirname(__DIR__));
require ROOT . '/includes/Database.php';
require ROOT . '/includes/Auth.php';
require ROOT . '/includes/PDF.php';
require ROOT . '/includes/Analytics.php';
require ROOT . '/includes/helpers.php';

$config = bootstrap();
$auth->requireLogin($config['base_url'] . '/admin/login.php');

$user       = $auth->currentUser();
$pdfManager = new PDF($config);
$pdfStats   = $pdfManager->getStats();
$analytics  = Analytics::getDashboardStats();
$viewsData  = Analytics::getViewsPerDay(30);
$topDocs    = Analytics::getViewsPerDocument(5);
$siteName   = getSetting('site_name', $config['site_name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — <?= e($siteName) ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js" defer></script>
</head>
<body class="admin-layout">

<?php require ROOT . '/admin/partials/sidebar.php'; ?>

<div class="admin-main">
    <?php require ROOT . '/admin/partials/topbar.php'; ?>

    <div class="admin-content">
        <div class="page-header">
            <div>
                <h1>Dashboard</h1>
                <p class="text-muted">Welcome back, <?= e($user['name']) ?></p>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="24" height="24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg></div>
                <div class="stat-data">
                    <div class="stat-value"><?= number_format($pdfStats['total']) ?></div>
                    <div class="stat-label">Total PDFs</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="24" height="24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg></div>
                <div class="stat-data">
                    <div class="stat-value"><?= number_format($analytics['total_views']) ?></div>
                    <div class="stat-label">Total Views</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="24" height="24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg></div>
                <div class="stat-data">
                    <div class="stat-value"><?= number_format($analytics['unique_visitors']) ?></div>
                    <div class="stat-label">Unique Visitors</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="24" height="24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg></div>
                <div class="stat-data">
                    <div class="stat-value"><?= number_format($analytics['today_views']) ?></div>
                    <div class="stat-label">Views Today</div>
                </div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="grid-2">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Views — Last 30 Days</h3>
                </div>
                <div class="card-body">
                    <canvas id="viewsChart" height="260"></canvas>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Top Documents</h3>
                    <a href="analytics.php" class="btn btn-sm btn-outline">View All</a>
                </div>
                <div class="card-body" style="padding:0">
                    <table class="table">
                        <thead><tr><th>Document</th><th>Views</th><th>Unique</th></tr></thead>
                        <tbody>
                        <?php foreach ($topDocs as $doc): ?>
                        <tr>
                            <td><a href="../pdf/<?= e($doc['slug']) ?>" target="_blank"><?= e(mb_substr($doc['title'], 0, 35)) ?></a></td>
                            <td><?= number_format($doc['total_views']) ?></td>
                            <td><?= number_format($doc['unique_visitors']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($topDocs)): ?><tr><td colspan="3" class="text-center text-muted">No data yet</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Recent PDFs -->
        <div class="card" style="margin-top:1.5rem">
            <div class="card-header">
                <h3 class="card-title">Recent Documents</h3>
                <a href="pdfs.php" class="btn btn-sm btn-primary">Manage PDFs</a>
            </div>
            <div class="card-body" style="padding:0">
                <?php $recentDocs = array_slice($pdfManager->getAll(['status' => 'active']), 0, 5); ?>
                <table class="table">
                    <thead><tr><th>Title</th><th>Visibility</th><th>Views</th><th>Created</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentDocs as $doc): ?>
                    <tr>
                        <td><a href="../pdf/<?= e($doc['slug']) ?>" target="_blank"><?= e($doc['title']) ?></a></td>
                        <td><span class="badge badge-<?= $doc['visibility'] === 'public' ? 'success' : 'warning' ?>"><?= e($doc['visibility']) ?></span></td>
                        <td><?= number_format($doc['total_views']) ?></td>
                        <td><?= formatDate($doc['created_at']) ?></td>
                        <td>
                            <a href="pdfs.php?action=edit&id=<?= $doc['id'] ?>" class="btn btn-xs btn-outline">Edit</a>
                            <a href="analytics.php?pdf_id=<?= $doc['id'] ?>" class="btn btn-xs btn-outline">Analytics</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($recentDocs)): ?><tr><td colspan="5" class="text-center text-muted">No documents yet. <a href="pdfs.php?action=upload">Upload one!</a></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($analytics['top_document']): ?>
        <div class="card" style="margin-top:1.5rem">
            <div class="card-body">
                <p class="text-muted" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.5rem">Most Viewed Document</p>
                <h3><?= e($analytics['top_document']['title']) ?></h3>
                <p style="margin-top:.25rem"><?= number_format($analytics['top_document']['views']) ?> total views &mdash;
                    <a href="../pdf/<?= e($analytics['top_document']['slug']) ?>" target="_blank">View</a> &bull;
                    <a href="analytics.php?pdf_id=<?php
                        $topId = Database::fetchScalar('SELECT id FROM pdf_documents WHERE slug = ?', [$analytics['top_document']['slug']]);
                        echo (int)$topId;
                    ?>">Analytics</a>
                </p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const viewsData = <?= json_encode($viewsData) ?>;
    const ctx = document.getElementById('viewsChart');
    if (!ctx) return;

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: viewsData.map(d => d.day),
            datasets: [
                {
                    label: 'Views',
                    data: viewsData.map(d => d.views),
                    borderColor: '#4f46e5',
                    backgroundColor: 'rgba(79,70,229,.1)',
                    tension: .4,
                    fill: true,
                    pointRadius: 3,
                },
                {
                    label: 'Unique',
                    data: viewsData.map(d => d.unique_visitors),
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16,185,129,.05)',
                    tension: .4,
                    fill: true,
                    pointRadius: 3,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'top' } },
            scales: {
                x: { ticks: { maxTicksLimit: 10, font: { size: 11 } } },
                y: { beginAtZero: true, ticks: { stepSize: 1 } },
            },
        },
    });
});
</script>
</body>
</html>
