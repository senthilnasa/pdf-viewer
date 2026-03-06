<?php
define('ROOT', dirname(__DIR__));
require ROOT . '/includes/Database.php';
require ROOT . '/includes/Auth.php';
require ROOT . '/includes/PDF.php';
require ROOT . '/includes/Analytics.php';
require ROOT . '/includes/helpers.php';

$config = bootstrap();
$auth->requireRole('viewer');

$pdfManager = new PDF($config);
$siteName   = getSetting('site_name', $config['site_name']);

$pdfId = (int)get('pdf_id', 0);
$days  = (int)get('days', 30);
$days  = in_array($days, [7, 14, 30, 90]) ? $days : 30;

$allDocs = $pdfManager->getAll(['status' => 'active']);
$selectedPdf = $pdfId ? $pdfManager->getById($pdfId) : null;

if ($pdfId) {
    $docAnalytics = Analytics::getDocumentAnalytics($pdfId, $days);
    $viewsData    = $docAnalytics['views_per_day'];
    $pageViews    = $docAnalytics['page_views'];
} else {
    $viewsData  = Analytics::getViewsPerDay($days);
    $pageViews  = [];
    $docAnalytics = null;
}

$topDocs  = Analytics::getViewsPerDocument(10);
$summary  = Analytics::getDashboardStats();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics — <?= e($siteName) ?></title>
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
                <h1>Analytics</h1>
                <p class="text-muted"><?= $selectedPdf ? e($selectedPdf['title']) : 'All Documents' ?></p>
            </div>
            <div style="display:flex;gap:.75rem;align-items:center">
                <form method="GET" style="display:flex;gap:.5rem">
                    <select name="pdf_id" class="form-control" onchange="this.form.submit()">
                        <option value="">All Documents</option>
                        <?php foreach ($allDocs as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $pdfId===$d['id']?'selected':'' ?>><?= e($d['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="days" class="form-control" onchange="this.form.submit()">
                        <option value="7" <?= $days===7?'selected':'' ?>>Last 7 days</option>
                        <option value="14" <?= $days===14?'selected':'' ?>>Last 14 days</option>
                        <option value="30" <?= $days===30?'selected':'' ?>>Last 30 days</option>
                        <option value="90" <?= $days===90?'selected':'' ?>>Last 90 days</option>
                    </select>
                </form>
            </div>
        </div>

        <!-- Summary Cards -->
        <?php
        $eyeSvg  = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="22" height="22"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>';
        $userSvg = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="22" height="22"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>';
        $trendSvg= '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="22" height="22"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>';
        ?>
        <div class="stats-grid">
            <?php if ($docAnalytics): ?>
            <div class="stat-card">
                <div class="stat-icon blue"><?= $eyeSvg ?></div>
                <div class="stat-data"><div class="stat-value"><?= number_format($docAnalytics['total_views']) ?></div><div class="stat-label">Total Views</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><?= $userSvg ?></div>
                <div class="stat-data"><div class="stat-value"><?= number_format($docAnalytics['unique_visitors']) ?></div><div class="stat-label">Unique Visitors</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon purple"><?= $trendSvg ?></div>
                <div class="stat-data"><div class="stat-value"><?= number_format($docAnalytics['period_views']) ?></div><div class="stat-label">Views (<?= $days ?> days)</div></div>
            </div>
            <?php else: ?>
            <div class="stat-card">
                <div class="stat-icon blue"><?= $eyeSvg ?></div>
                <div class="stat-data"><div class="stat-value"><?= number_format($summary['total_views']) ?></div><div class="stat-label">Total Views</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><?= $userSvg ?></div>
                <div class="stat-data"><div class="stat-value"><?= number_format($summary['unique_visitors']) ?></div><div class="stat-label">Unique Visitors</div></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange"><?= $trendSvg ?></div>
                <div class="stat-data"><div class="stat-value"><?= number_format($summary['today_views']) ?></div><div class="stat-label">Views Today</div></div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Views chart -->
        <div class="card" style="margin-top:0">
            <div class="card-header"><h3 class="card-title">Views Over Time</h3></div>
            <div class="card-body">
                <canvas id="viewsChart" height="280"></canvas>
            </div>
        </div>

        <div class="grid-2" style="margin-top:1.5rem">
            <!-- Top documents table -->
            <?php if (!$pdfId): ?>
            <div class="card">
                <div class="card-header"><h3 class="card-title">Top Documents</h3></div>
                <div class="card-body" style="padding:0">
                    <table class="table">
                        <thead><tr><th>#</th><th>Document</th><th>Views</th><th>Unique</th></tr></thead>
                        <tbody>
                        <?php foreach ($topDocs as $i => $d): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><a href="analytics.php?pdf_id=<?= $d['id'] ?>"><?= e(mb_substr($d['title'], 0, 40)) ?></a></td>
                            <td><?= number_format($d['total_views']) ?></td>
                            <td><?= number_format($d['unique_visitors']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($topDocs)): ?><tr><td colspan="4" class="text-center text-muted">No data</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- Page heatmap -->
            <?php if ($pdfId && !empty($pageViews)): ?>
            <div class="card">
                <div class="card-header"><h3 class="card-title">Page View Heatmap</h3></div>
                <div class="card-body">
                    <canvas id="pageChart" height="300"></canvas>
                </div>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const viewsData = <?= json_encode($viewsData) ?>;
    const ctx = document.getElementById('viewsChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: viewsData.map(d => d.day),
                datasets: [
                    {
                        label: 'Views',
                        data: viewsData.map(d => d.views),
                        backgroundColor: 'rgba(79,70,229,.7)',
                        borderRadius: 4,
                    },
                    {
                        label: 'Unique Visitors',
                        data: viewsData.map(d => d.unique_visitors),
                        backgroundColor: 'rgba(16,185,129,.5)',
                        borderRadius: 4,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'top' } },
                scales: {
                    x: { ticks: { maxTicksLimit: 14, font: { size: 11 } } },
                    y: { beginAtZero: true },
                },
            },
        });
    }

    <?php if (!empty($pageViews)): ?>
    const pageData = <?= json_encode($pageViews) ?>;
    const pctx = document.getElementById('pageChart');
    if (pctx && pageData.length) {
        const maxV = Math.max(...pageData.map(p => p.views));
        new Chart(pctx, {
            type: 'bar',
            data: {
                labels: pageData.map(p => 'Page ' + p.page_number),
                datasets: [{
                    label: 'Views',
                    data: pageData.map(p => p.views),
                    backgroundColor: pageData.map(p => {
                        const intensity = p.views / maxV;
                        return `rgba(239, ${Math.round(68 + (1-intensity)*120)}, 68, ${0.3 + intensity*0.7})`;
                    }),
                    borderRadius: 4,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true } },
            },
        });
    }
    <?php endif; ?>
});
</script>
</body>
</html>
