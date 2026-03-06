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

$filters = [
    'pdf_id'     => get('pdf_id') ? (int)get('pdf_id') : null,
    'date_from'  => get('date_from', date('Y-m-d', strtotime('-30 days'))),
    'date_to'    => get('date_to', date('Y-m-d')),
    'visitor_ip' => trim(get('visitor_ip', '')),
];

// ── CSV exports ──
if (get('export') === 'csv') {
    exportCsv(Analytics::getReportData($filters), 'pdf-report-' . date('Ymd') . '.csv');
}
if (get('export') === 'summary_csv') {
    exportCsv(Analytics::getReportSummary($filters), 'pdf-summary-' . date('Ymd') . '.csv');
}

$reportData  = Analytics::getReportData($filters);
$summaryData = Analytics::getReportSummary($filters);
$userStats   = Analytics::getUserStats();
$allDocs     = $pdfManager->getAll(['status' => 'active']);

// Total views / unique in selected range (for PDF report header)
$rangeViews   = array_sum(array_column($summaryData, 'views'));
$rangeUnique  = array_sum(array_column($summaryData, 'unique_visitors'));

// ── Print / PDF mode ──
$printMode = get('print') === '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports — <?= e($siteName) ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js" defer></script>
    <style>
        /* ── User stat cards ── */
        .user-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:1rem; }
        .us-card    { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:1rem 1.25rem; }
        .us-label   { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#718096; }
        .us-value   { font-size:1.8rem; font-weight:700; color:#1a202c; line-height:1.1; margin:.2rem 0; }
        .us-sub     { font-size:.75rem; color:#a0aec0; }
        .role-pill  { display:inline-block; padding:2px 10px; border-radius:9999px; font-size:.75rem; font-weight:600; }
        .pill-admin  { background:#fed7d7; color:#9b2c2c; }
        .pill-editor { background:#fefcbf; color:#7b341e; }
        .pill-viewer { background:#bee3f8; color:#2c5282; }

        /* ── Print styles ── */
        @media print {
            body { background:#fff !important; font-size:11pt; }
            .admin-sidebar, .admin-topbar, .no-print,
            .page-header .btn, form.filters-form { display:none !important; }
            .admin-main  { margin:0 !important; padding:0 !important; }
            .admin-content { padding:0 !important; }
            .card { border:1px solid #ccc !important; box-shadow:none !important; break-inside:avoid; }
            .print-header { display:block !important; }
            table { font-size:9pt; }
            th, td { padding:4px 6px !important; }
            a { color:inherit; text-decoration:none; }
        }
        .print-header { display:none; }

        /* ── Role bar ── */
        .role-bar-row { display:flex; align-items:center; gap:.5rem; margin:.3rem 0; }
        .rb-label { width:60px; font-size:.78rem; color:#4a5568; text-transform:capitalize; }
        .rb-wrap  { flex:1; height:8px; background:#edf2f7; border-radius:4px; overflow:hidden; }
        .rb-fill  { height:100%; border-radius:4px; }
        .rb-count { width:30px; font-size:.78rem; font-weight:600; color:#2d3748; text-align:right; }
    </style>
</head>
<body class="admin-layout">

<?php require ROOT . '/admin/partials/sidebar.php'; ?>

<div class="admin-main">
    <?php require ROOT . '/admin/partials/topbar.php'; ?>

    <div class="admin-content">

        <!-- Print header (hidden on screen, visible when printing) -->
        <div class="print-header" style="margin-bottom:1.5rem;padding-bottom:1rem;border-bottom:2px solid #2d3748">
            <h2 style="margin:0"><?= e($siteName) ?> — Analytics Report</h2>
            <p style="margin:.25rem 0 0;color:#718096;font-size:.85rem">
                Period: <?= e($filters['date_from']) ?> → <?= e($filters['date_to']) ?> &nbsp;·&nbsp;
                Generated: <?= date('Y-m-d H:i') ?>
            </p>
        </div>

        <!-- Page header -->
        <div class="page-header no-print">
            <div><h1>Reports</h1><p class="text-muted">Export and filter analytics data</p></div>
            <div style="display:flex;gap:.5rem">
                <button onclick="window.open('reports.php?<?= http_build_query(array_merge($_GET,['print'=>'1'])) ?>','_blank')" class="btn btn-outline">
                    Print / Save PDF
                </button>
            </div>
        </div>

        <!-- ── Filters ── -->
        <div class="card no-print" style="margin-bottom:1.5rem">
            <div class="card-header"><h3 class="card-title">Filters</h3></div>
            <div class="card-body">
                <form method="GET" class="filters-form" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;align-items:end">
                    <div class="form-group" style="margin:0">
                        <label class="form-label">Document</label>
                        <select name="pdf_id" class="form-control">
                            <option value="">All Documents</option>
                            <?php foreach ($allDocs as $d): ?>
                            <option value="<?= $d['id'] ?>" <?= $filters['pdf_id']===$d['id']?'selected':'' ?>><?= e(mb_substr($d['title'],0,40)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0">
                        <label class="form-label">From Date</label>
                        <input type="date" name="date_from" class="form-control" value="<?= e($filters['date_from']) ?>">
                    </div>
                    <div class="form-group" style="margin:0">
                        <label class="form-label">To Date</label>
                        <input type="date" name="date_to" class="form-control" value="<?= e($filters['date_to']) ?>">
                    </div>
                    <div class="form-group" style="margin:0">
                        <label class="form-label">Visitor IP</label>
                        <input type="text" name="visitor_ip" class="form-control" placeholder="e.g. 1.2.3.4" value="<?= e($filters['visitor_ip']) ?>">
                    </div>
                    <div style="display:flex;gap:.5rem">
                        <button type="submit" class="btn btn-primary">Apply</button>
                        <a href="reports.php" class="btn btn-outline">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- ── User Statistics ── -->
        <div class="card" style="margin-bottom:1.5rem">
            <div class="card-header">
                <h3 class="card-title">User Statistics</h3>
            </div>
            <div class="card-body">
                <div class="user-stats" style="margin-bottom:1.25rem">
                    <div class="us-card">
                        <div class="us-label">Total Users</div>
                        <div class="us-value"><?= $userStats['total'] ?></div>
                        <div class="us-sub">All registered accounts</div>
                    </div>
                    <div class="us-card">
                        <div class="us-label">Active</div>
                        <div class="us-value" style="color:#276749"><?= $userStats['active'] ?></div>
                        <div class="us-sub">Can log in</div>
                    </div>
                    <div class="us-card">
                        <div class="us-label">Inactive</div>
                        <div class="us-value" style="color:#9b2c2c"><?= $userStats['inactive'] ?></div>
                        <div class="us-sub">Suspended / pending</div>
                    </div>
                    <?php
                    $roleMap = [];
                    foreach ($userStats['by_role'] as $r) $roleMap[$r['role']] = (int)$r['count'];
                    foreach (['admin','editor','viewer'] as $role):
                        $cnt = $roleMap[$role] ?? 0;
                    ?>
                    <div class="us-card">
                        <div class="us-label"><?= ucfirst($role) ?>s</div>
                        <div class="us-value"><?= $cnt ?></div>
                        <div class="us-sub"><span class="role-pill pill-<?= $role ?>"><?= $role ?></span></div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Role bars + donut side by side -->
                <div style="display:flex;gap:2rem;flex-wrap:wrap;align-items:center">
                    <div style="flex:1;min-width:180px">
                        <?php
                        $roleColors = ['admin'=>'#e53e3e','editor'=>'#d69e2e','viewer'=>'#3182ce'];
                        $roleTotal  = max(1, array_sum(array_values($roleMap)));
                        foreach (['admin','editor','viewer'] as $role):
                            $cnt = $roleMap[$role] ?? 0;
                            $pct = round($cnt / $roleTotal * 100);
                            $col = $roleColors[$role];
                        ?>
                        <div class="role-bar-row">
                            <div class="rb-label"><?= ucfirst($role) ?></div>
                            <div class="rb-wrap"><div class="rb-fill" style="width:<?= $pct ?>%;background:<?= $col ?>"></div></div>
                            <div class="rb-count"><?= $cnt ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="width:140px;height:140px;flex-shrink:0" class="no-print">
                        <canvas id="usersDonut"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Summary Report ── -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Document Summary</h3>
                <div style="display:flex;gap:.5rem" class="no-print">
                    <a href="?<?= http_build_query(array_merge($_GET,['export'=>'summary_csv'])) ?>" class="btn btn-sm btn-outline">Export CSV</a>
                </div>
            </div>
            <!-- Period totals -->
            <div style="display:flex;gap:2rem;padding:.75rem 1.25rem;background:#f7fafc;border-bottom:1px solid #e2e8f0;font-size:.85rem">
                <div><strong><?= number_format($rangeViews) ?></strong> <span style="color:#718096">total views</span></div>
                <div><strong><?= number_format($rangeUnique) ?></strong> <span style="color:#718096">unique visitors</span></div>
                <div><strong><?= count($summaryData) ?></strong> <span style="color:#718096">documents</span></div>
            </div>
            <div class="card-body" style="padding:0">
                <table class="table">
                    <thead><tr><th>Document</th><th>Slug</th><th>Total Views</th><th>Unique Visitors</th><th>Last Visit</th></tr></thead>
                    <tbody>
                    <?php foreach ($summaryData as $row): ?>
                    <tr>
                        <td><?= e($row['document']) ?></td>
                        <td><code><?= e($row['slug']) ?></code></td>
                        <td><?= number_format($row['views']) ?></td>
                        <td><?= number_format($row['unique_visitors']) ?></td>
                        <td><?= $row['last_visit'] ? formatDate($row['last_visit']) : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($summaryData)): ?><tr><td colspan="5" class="text-center text-muted">No data</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── Detailed Visit Log ── -->
        <div class="card" style="margin-top:1.5rem">
            <div class="card-header">
                <h3 class="card-title">Detailed Visit Log</h3>
                <a href="?<?= http_build_query(array_merge($_GET,['export'=>'csv'])) ?>" class="btn btn-sm btn-outline no-print">Export CSV</a>
            </div>
            <div class="card-body" style="padding:0;overflow-x:auto">
                <table class="table">
                    <thead><tr><th>Document</th><th>Visitor IP</th><th>Referrer</th><th>Time</th><th>User Agent</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($reportData, 0, 200) as $row): ?>
                    <tr>
                        <td><?= e($row['document']) ?></td>
                        <td><?= e($row['visitor_ip']) ?></td>
                        <td><?= e(mb_substr($row['referrer'] ?? '—', 0, 60)) ?></td>
                        <td><?= e($row['visit_time']) ?></td>
                        <td title="<?= e($row['user_agent']) ?>" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e(mb_substr($row['user_agent'] ?? '', 0, 60)) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($reportData)): ?><tr><td colspan="5" class="text-center text-muted">No records</td></tr><?php endif; ?>
                    </tbody>
                </table>
                <?php if (count($reportData) > 200): ?>
                <p class="text-muted" style="padding:.75rem 1rem;font-size:.85rem">
                    Showing 200 of <?= number_format(count($reportData)) ?> records. Export CSV for full data.
                </p>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    /* ── Users donut ── */
    const uCtx = document.getElementById('usersDonut');
    const rolesData = <?= json_encode(array_values($userStats['by_role'])) ?>;
    if (uCtx && rolesData.length) {
        const cols = { admin:'#e53e3e', editor:'#d69e2e', viewer:'#3182ce' };
        new Chart(uCtx, {
            type: 'doughnut',
            data: {
                labels: rolesData.map(r => r.role.charAt(0).toUpperCase() + r.role.slice(1)),
                datasets: [{
                    data: rolesData.map(r => r.count),
                    backgroundColor: rolesData.map(r => cols[r.role] || '#718096'),
                    borderWidth: 2, borderColor: '#fff', hoverOffset: 5,
                }],
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '65%',
                plugins: { legend: { display: false } },
            },
        });
    }

    /* ── Auto-print when ?print=1 ── */
    <?php if ($printMode): ?>
    window.addEventListener('load', () => setTimeout(() => window.print(), 600));
    <?php endif; ?>
});
</script>
</body>
</html>
