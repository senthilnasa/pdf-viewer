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
    'pdf_id'    => get('pdf_id') ? (int)get('pdf_id') : null,
    'date_from' => get('date_from', date('Y-m-d', strtotime('-30 days'))),
    'date_to'   => get('date_to', date('Y-m-d')),
    'visitor_ip'=> trim(get('visitor_ip', '')),
];

// Export CSV/Excel
if (get('export') === 'csv') {
    $rows = Analytics::getReportData($filters);
    exportCsv($rows, 'pdf-report-' . date('Ymd') . '.csv');
}

if (get('export') === 'summary_csv') {
    $rows = Analytics::getReportSummary($filters);
    exportCsv($rows, 'pdf-summary-' . date('Ymd') . '.csv');
}

$reportData    = Analytics::getReportData(array_merge($filters, []));
$summaryData   = Analytics::getReportSummary($filters);
$allDocs       = $pdfManager->getAll(['status' => 'active']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports — <?= e($siteName) ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="admin-layout">

<?php require ROOT . '/admin/partials/sidebar.php'; ?>

<div class="admin-main">
    <?php require ROOT . '/admin/partials/topbar.php'; ?>

    <div class="admin-content">
        <div class="page-header">
            <div><h1>Reports</h1><p class="text-muted">Export and filter analytics data</p></div>
        </div>

        <!-- Filters -->
        <div class="card" style="margin-bottom:1.5rem">
            <div class="card-header"><h3 class="card-title">Filters</h3></div>
            <div class="card-body">
                <form method="GET" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;align-items:end">
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
                    <div style="display:flex;gap:.5rem;align-items:center">
                        <button type="submit" class="btn btn-primary">Apply</button>
                        <a href="reports.php" class="btn btn-outline">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Summary Report -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Summary Report</h3>
                <div style="display:flex;gap:.5rem">
                    <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'summary_csv'])) ?>" class="btn btn-sm btn-outline">Export CSV</a>
                </div>
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

        <!-- Detailed Log -->
        <div class="card" style="margin-top:1.5rem">
            <div class="card-header">
                <h3 class="card-title">Detailed Visit Log</h3>
                <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'csv'])) ?>" class="btn btn-sm btn-outline">Export CSV</a>
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
                <p class="text-muted" style="padding:.75rem 1rem;font-size:.85rem">Showing 200 of <?= number_format(count($reportData)) ?> records. Export CSV for full data.</p>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>
</body>
</html>
