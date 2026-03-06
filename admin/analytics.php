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

$allDocs     = $pdfManager->getAll(['status' => 'active']);
$selectedPdf = $pdfId ? $pdfManager->getById($pdfId) : null;

if ($pdfId) {
    $docAnalytics = Analytics::getDocumentAnalytics($pdfId, $days);
    $viewsData    = $docAnalytics['views_per_day'];
    $pageViews    = $docAnalytics['page_views'];
} else {
    $viewsData    = Analytics::getViewsPerDay($days);
    $pageViews    = [];
    $docAnalytics = null;
}

$topDocs     = Analytics::getViewsPerDocument(10);
$summary     = Analytics::getDashboardStats();
$userStats   = Analytics::getUserStats();
$heatmap     = Analytics::getHourlyHeatmap($days);
$hourlyViews = Analytics::getViewsPerHour($days);

// Heatmap max for scaling
$heatmapMax = max(1, ...array_column($heatmap, 'views'));

// Heatmap color helper
function heatCell(int $views, int $max): string {
    if ($views === 0) return '#edf2f7';
    $p = $views / $max;
    if ($p < 0.2) return '#bee3f8';
    if ($p < 0.4) return '#63b3ed';
    if ($p < 0.65) return '#3182ce';
    if ($p < 0.85) return '#2b6cb0';
    return '#1a365d';
}

$weekdays = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics — <?= e($siteName) ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js" defer></script>
    <style>
        /* ── Cloudflare-style stat cards ── */
        .cf-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:1rem; margin-bottom:1.5rem; }
        .cf-card  { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:1.25rem 1.5rem; }
        .cf-card-label { font-size:.75rem; font-weight:600; text-transform:uppercase; letter-spacing:.06em; color:#718096; margin-bottom:.4rem; }
        .cf-card-value { font-size:2rem; font-weight:700; color:#1a202c; line-height:1; }
        .cf-card-sub   { font-size:.78rem; color:#a0aec0; margin-top:.35rem; }
        .cf-card-badge { display:inline-block; font-size:.7rem; font-weight:700; padding:2px 7px; border-radius:9999px; margin-left:.4rem; vertical-align:middle; }
        .badge-up   { background:#c6f6d5; color:#276749; }
        .badge-down { background:#fed7d7; color:#9b2c2c; }

        /* ── Role donut row ── */
        .role-row { display:flex; align-items:center; gap:.75rem; padding:.5rem 0; border-bottom:1px solid #f0f4f8; }
        .role-row:last-child { border-bottom:none; }
        .role-dot  { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
        .role-name { flex:1; font-size:.85rem; color:#4a5568; text-transform:capitalize; }
        .role-bar-wrap { width:90px; height:6px; background:#edf2f7; border-radius:3px; overflow:hidden; }
        .role-bar  { height:100%; border-radius:3px; }
        .role-count{ font-size:.85rem; font-weight:600; color:#2d3748; width:28px; text-align:right; }

        /* ── Activity heatmap ── */
        .heatmap-wrap   { overflow-x:auto; }
        .heatmap-table  { border-collapse:separate; border-spacing:3px; min-width:600px; }
        .heatmap-table th { font-size:.65rem; color:#a0aec0; font-weight:600; text-align:center; padding:0 2px; }
        .heatmap-table td { width:22px; height:22px; border-radius:3px; cursor:default; }
        .heatmap-table td:hover { opacity:.75; outline:2px solid #4299e1; }
        .hm-row-label   { font-size:.7rem; color:#718096; white-space:nowrap; padding-right:6px; text-align:right; }

        /* ── Views area chart ── */
        .chart-wrap { position:relative; height:260px; }

        /* ── Grid ── */
        .analytics-grid { display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; margin-top:1.5rem; }
        @media(max-width:900px){ .analytics-grid { grid-template-columns:1fr; } }

        /* ── Page heatmap bar colours ── */
        .page-hot  { background: linear-gradient(90deg,#fc8181,#e53e3e); }
        .page-warm { background: linear-gradient(90deg,#f6ad55,#dd6b20); }
        .page-mid  { background: linear-gradient(90deg,#68d391,#38a169); }
        .page-cool { background: linear-gradient(90deg,#63b3ed,#3182ce); }
    </style>
</head>
<body class="admin-layout">

<?php require ROOT . '/admin/partials/sidebar.php'; ?>

<div class="admin-main">
    <?php require ROOT . '/admin/partials/topbar.php'; ?>

    <div class="admin-content">

        <!-- Page header + filters -->
        <div class="page-header">
            <div>
                <h1>Analytics</h1>
                <p class="text-muted"><?= $selectedPdf ? e($selectedPdf['title']) : 'All Documents' ?></p>
            </div>
            <div style="display:flex;gap:.75rem;align-items:center">
                <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap">
                    <select name="pdf_id" class="form-control" onchange="this.form.submit()">
                        <option value="">All Documents</option>
                        <?php foreach ($allDocs as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $pdfId===$d['id']?'selected':'' ?>><?= e($d['title']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="days" class="form-control" onchange="this.form.submit()">
                        <option value="7"  <?= $days===7 ?'selected':'' ?>>Last 7 days</option>
                        <option value="14" <?= $days===14?'selected':'' ?>>Last 14 days</option>
                        <option value="30" <?= $days===30?'selected':'' ?>>Last 30 days</option>
                        <option value="90" <?= $days===90?'selected':'' ?>>Last 90 days</option>
                    </select>
                </form>
            </div>
        </div>

        <!-- ── Cloudflare-style stat cards ── -->
        <?php
        $totalViews   = $docAnalytics ? $docAnalytics['total_views']     : $summary['total_views'];
        $uniqueVis    = $docAnalytics ? $docAnalytics['unique_visitors']  : $summary['unique_visitors'];
        $periodViews  = $docAnalytics ? $docAnalytics['period_views']     : $summary['today_views'];
        $periodLabel  = $docAnalytics ? "Views ({$days}d)" : 'Views Today';
        $totalUsers   = $userStats['total'];
        $activeUsers  = $userStats['active'];
        $totalDocs    = count($allDocs);
        ?>
        <div class="cf-stats">
            <div class="cf-card">
                <div class="cf-card-label">Total Views</div>
                <div class="cf-card-value"><?= number_format($totalViews) ?></div>
                <div class="cf-card-sub">All time page loads</div>
            </div>
            <div class="cf-card">
                <div class="cf-card-label">Unique Visitors</div>
                <div class="cf-card-value"><?= number_format($uniqueVis) ?></div>
                <div class="cf-card-sub">Distinct IPs</div>
            </div>
            <div class="cf-card">
                <div class="cf-card-label"><?= e($periodLabel) ?></div>
                <div class="cf-card-value"><?= number_format($periodViews) ?></div>
                <div class="cf-card-sub">Current period</div>
            </div>
            <div class="cf-card">
                <div class="cf-card-label">Active Users</div>
                <div class="cf-card-value"><?= number_format($activeUsers) ?></div>
                <div class="cf-card-sub"><?= $totalUsers ?> total registered</div>
            </div>
            <?php if (!$pdfId): ?>
            <div class="cf-card">
                <div class="cf-card-label">Published PDFs</div>
                <div class="cf-card-value"><?= number_format($totalDocs) ?></div>
                <div class="cf-card-sub">Active documents</div>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── Views over time (area chart) ── -->
        <div class="card">
            <div class="card-header"><h3 class="card-title">Views Over Time</h3></div>
            <div class="card-body">
                <div class="chart-wrap"><canvas id="viewsChart"></canvas></div>
            </div>
        </div>

        <!-- ── Two-column: User roles + Top docs / Page heatmap ── -->
        <div class="analytics-grid">

            <!-- User role breakdown -->
            <div class="card">
                <div class="card-header"><h3 class="card-title">Users by Role</h3></div>
                <div class="card-body" style="display:flex;gap:1.5rem;align-items:center;flex-wrap:wrap">
                    <div style="width:140px;height:140px;flex-shrink:0">
                        <canvas id="rolesChart"></canvas>
                    </div>
                    <div style="flex:1;min-width:120px">
                        <?php
                        $roleColors = ['admin'=>'#e53e3e','editor'=>'#d69e2e','viewer'=>'#3182ce'];
                        $roleTotal  = max(1, array_sum(array_column($userStats['by_role'], 'count')));
                        foreach ($userStats['by_role'] as $r):
                            $pct = round($r['count'] / $roleTotal * 100);
                            $col = $roleColors[$r['role']] ?? '#718096';
                        ?>
                        <div class="role-row">
                            <div class="role-dot" style="background:<?= $col ?>"></div>
                            <div class="role-name"><?= e(ucfirst($r['role'])) ?></div>
                            <div class="role-bar-wrap"><div class="role-bar" style="width:<?= $pct ?>%;background:<?= $col ?>"></div></div>
                            <div class="role-count"><?= $r['count'] ?></div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($userStats['by_role'])): ?>
                        <p class="text-muted" style="font-size:.85rem">No users yet.</p>
                        <?php endif; ?>
                        <div style="margin-top:.75rem;padding-top:.75rem;border-top:1px solid #edf2f7;font-size:.8rem;color:#718096">
                            <?= $userStats['inactive'] ?> inactive &nbsp;·&nbsp; <?= $userStats['total'] ?> total
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top documents or page views -->
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
                            <td><a href="analytics.php?pdf_id=<?= $d['id'] ?>&days=<?= $days ?>"><?= e(mb_substr($d['title'],0,38)) ?></a></td>
                            <td><?= number_format($d['total_views']) ?></td>
                            <td><?= number_format($d['unique_visitors']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($topDocs)): ?><tr><td colspan="4" class="text-center text-muted">No data</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php elseif (!empty($pageViews)): ?>
            <div class="card">
                <div class="card-header"><h3 class="card-title">Page View Heatmap</h3></div>
                <div class="card-body">
                    <div class="chart-wrap" style="height:240px"><canvas id="pageChart"></canvas></div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── Hourly activity heatmap ── -->
        <div class="card" style="margin-top:1.5rem">
            <div class="card-header">
                <h3 class="card-title">Activity Heatmap</h3>
                <span style="font-size:.8rem;color:#a0aec0">Hour of day vs. day of week · last <?= $days ?> days</span>
            </div>
            <div class="card-body">
                <div class="heatmap-wrap">
                    <table class="heatmap-table">
                        <thead>
                            <tr>
                                <th></th>
                                <?php for ($h = 0; $h < 24; $h++): ?>
                                <th><?= $h % 3 === 0 ? sprintf('%02d:00', $h) : '' ?></th>
                                <?php endfor; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        // Re-index heatmap as [weekday][hour]
                        $hm = [];
                        foreach ($heatmap as $cell) {
                            $hm[$cell['weekday']][$cell['hour']] = $cell['views'];
                        }
                        foreach ($weekdays as $wi => $wname):
                        ?>
                        <tr>
                            <td class="hm-row-label"><?= $wname ?></td>
                            <?php for ($h = 0; $h < 24; $h++):
                                $v = $hm[$wi][$h] ?? 0;
                                $bg = heatCell($v, $heatmapMax);
                                $title = "{$wname} {$h}:00 — {$v} view" . ($v !== 1 ? 's' : '');
                            ?>
                            <td style="background:<?= $bg ?>" title="<?= e($title) ?>"></td>
                            <?php endfor; ?>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <!-- Legend -->
                <div style="display:flex;align-items:center;gap:4px;margin-top:.75rem;font-size:.72rem;color:#718096">
                    <span>Less</span>
                    <?php foreach (['#edf2f7','#bee3f8','#63b3ed','#3182ce','#2b6cb0','#1a365d'] as $c): ?>
                    <div style="width:14px;height:14px;border-radius:2px;background:<?= $c ?>"></div>
                    <?php endforeach; ?>
                    <span>More</span>
                </div>
            </div>
        </div>

        <!-- ── Traffic by hour (bar) ── -->
        <div class="card" style="margin-top:1.5rem">
            <div class="card-header"><h3 class="card-title">Traffic by Hour of Day</h3></div>
            <div class="card-body">
                <div class="chart-wrap" style="height:200px"><canvas id="hourChart"></canvas></div>
            </div>
        </div>

    </div><!-- /admin-content -->
</div><!-- /admin-main -->

<script>
document.addEventListener('DOMContentLoaded', function () {
    const viewsData  = <?= json_encode($viewsData) ?>;
    const hourlyData = <?= json_encode($hourlyViews) ?>;
    const rolesData  = <?= json_encode(array_values($userStats['by_role'])) ?>;

    /* ── Gradient helper ── */
    function makeGradient(ctx, topColor, bottomColor) {
        const g = ctx.createLinearGradient(0, 0, 0, ctx.canvas.clientHeight || 260);
        g.addColorStop(0, topColor);
        g.addColorStop(1, bottomColor);
        return g;
    }

    /* ── Views over time ── */
    const vCtx = document.getElementById('viewsChart');
    if (vCtx) {
        const grad = makeGradient(vCtx.getContext('2d'), 'rgba(79,70,229,.35)', 'rgba(79,70,229,.02)');
        new Chart(vCtx, {
            type: 'line',
            data: {
                labels: viewsData.map(d => d.day),
                datasets: [
                    {
                        label: 'Views',
                        data: viewsData.map(d => d.views),
                        borderColor: '#4f46e5',
                        backgroundColor: grad,
                        borderWidth: 2,
                        fill: true,
                        tension: 0.35,
                        pointRadius: 0,
                        pointHoverRadius: 5,
                    },
                    {
                        label: 'Unique Visitors',
                        data: viewsData.map(d => d.unique_visitors),
                        borderColor: '#10b981',
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        tension: 0.35,
                        pointRadius: 0,
                        pointHoverRadius: 5,
                    },
                ],
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'top' } },
                scales: {
                    x: { ticks: { maxTicksLimit: 14, font: { size: 11 } }, grid: { display: false } },
                    y: { beginAtZero: true, grid: { color: '#f0f4f8' } },
                },
            },
        });
    }

    /* ── Users by role donut ── */
    const rCtx = document.getElementById('rolesChart');
    if (rCtx && rolesData.length) {
        const roleColors = { admin: '#e53e3e', editor: '#d69e2e', viewer: '#3182ce' };
        new Chart(rCtx, {
            type: 'doughnut',
            data: {
                labels: rolesData.map(r => r.role.charAt(0).toUpperCase() + r.role.slice(1)),
                datasets: [{
                    data: rolesData.map(r => r.count),
                    backgroundColor: rolesData.map(r => roleColors[r.role] || '#718096'),
                    borderWidth: 2,
                    borderColor: '#fff',
                    hoverOffset: 6,
                }],
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '68%',
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => ` ${ctx.label}: ${ctx.raw}` } } },
            },
        });
    }

    /* ── Traffic by hour ── */
    const hCtx = document.getElementById('hourChart');
    if (hCtx) {
        const hours = hourlyData.map(h => `${String(h.hour).padStart(2,'0')}:00`);
        new Chart(hCtx, {
            type: 'bar',
            data: {
                labels: hours,
                datasets: [{
                    label: 'Views',
                    data: hourlyData.map(h => h.views),
                    backgroundColor: hourlyData.map(h => {
                        const maxV = Math.max(1, ...hourlyData.map(x => x.views));
                        const i = h.views / maxV;
                        return `rgba(49,130,206,${0.2 + i * 0.8})`;
                    }),
                    borderRadius: 4,
                }],
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { maxTicksLimit: 12, font: { size: 10 } }, grid: { display: false } },
                    y: { beginAtZero: true, grid: { color: '#f0f4f8' } },
                },
            },
        });
    }

    /* ── Page heatmap (per-doc) ── */
    <?php if (!empty($pageViews)): ?>
    const pageData = <?= json_encode($pageViews) ?>;
    const pCtx = document.getElementById('pageChart');
    if (pCtx && pageData.length) {
        const maxV = Math.max(1, ...pageData.map(p => p.views));
        new Chart(pCtx, {
            type: 'bar',
            data: {
                labels: pageData.map(p => 'Pg ' + p.page_number),
                datasets: [{
                    label: 'Views',
                    data: pageData.map(p => p.views),
                    backgroundColor: pageData.map(p => {
                        const i = p.views / maxV;
                        if (i >= .8)  return 'rgba(229,62,62,.85)';
                        if (i >= .55) return 'rgba(214,158,46,.85)';
                        if (i >= .3)  return 'rgba(56,161,105,.85)';
                        return 'rgba(49,130,206,.85)';
                    }),
                    borderRadius: 3,
                }],
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true }, x: { ticks: { font: { size: 10 } } } },
            },
        });
    }
    <?php endif; ?>
});
</script>
</body>
</html>
