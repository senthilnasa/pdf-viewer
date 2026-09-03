<?php
/**
 * Health Check / Diagnostics — PDF Viewer Platform
 * ================================================
 * Standalone diagnostic page. Deliberately does NOT use bootstrap() or any
 * app class, so it still works when the rest of the app is throwing 500s.
 *
 * USAGE:
 *   1. Set a secret token below (or via the PDFV_HEALTH_TOKEN env var).
 *   2. Visit:  https://your-site/health-check.php?token=YOUR_TOKEN
 *   3. DELETE THIS FILE (or unset the token) when you're done.
 *
 * It reports what's broken — missing tables/columns after an upgrade,
 * DB connection failures, missing config, unwritable directories — plus
 * the tail of the PHP error log if it can find one.
 */

// ---------------------------------------------------------------------------
// Access gate — set this before using, otherwise the page refuses to run.
// ---------------------------------------------------------------------------
$EXPECTED_TOKEN = getenv('PDFV_HEALTH_TOKEN') ?: '';   // <-- put a secret here

$provided = $_GET['token'] ?? '';
if ($EXPECTED_TOKEN === '' || !hash_equals($EXPECTED_TOKEN, (string)$provided)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit(
        "Health check is locked.\n\n" .
        "Open health-check.php and set \$EXPECTED_TOKEN to a secret value\n" .
        "(or set the PDFV_HEALTH_TOKEN environment variable), then visit:\n" .
        "    health-check.php?token=YOUR_SECRET\n"
    );
}

define('ROOT', __DIR__);

// ---------------------------------------------------------------------------
// Expected schema — keep in sync with database.sql
// ---------------------------------------------------------------------------
$REQUIRED_TABLES = [
    'users'               => 'core',
    'pdf_documents'       => 'core',
    'pdf_views'           => 'core',
    'pdf_page_views'      => 'core',
    'share_links'         => 'core',
    'settings'            => 'core',
    'login_attempts'      => 'core',
    'categories'          => 'catalog/categories feature',
    'audit_logs'          => 'audit logging',
    'user_sessions'       => 'session management',
    'email_verifications' => 'email verification',
    'notifications'       => 'notifications',
    'email_queue'         => 'email queue',
    'user_preferences'    => 'user preferences',
    'scheduled_tasks'     => 'background jobs',
];

$REQUIRED_COLUMNS = [
    'pdf_documents' => ['show_in_catalog', 'category_id'],
    'users'         => ['email_verified', 'email_verified_at'],
];

$results = [];
$fatal   = [];

function check(string $label, bool $ok, string $detail = '', string $fix = ''): array
{
    return ['label' => $label, 'ok' => $ok, 'detail' => $detail, 'fix' => $fix];
}

// ---------------------------------------------------------------------------
// 1. PHP environment
// ---------------------------------------------------------------------------
$results['PHP Environment'][] = check(
    'PHP version',
    version_compare(PHP_VERSION, '8.0.0', '>='),
    PHP_VERSION,
    'PHP 8.0 or newer is required.'
);

foreach (['pdo_mysql', 'gd', 'fileinfo', 'curl', 'zip', 'mbstring'] as $ext) {
    $results['PHP Environment'][] = check(
        "Extension: {$ext}",
        extension_loaded($ext),
        extension_loaded($ext) ? 'loaded' : 'MISSING',
        "Install/enable the PHP {$ext} extension."
    );
}

// ---------------------------------------------------------------------------
// 2. Config files
// ---------------------------------------------------------------------------
$dbConfigPath  = ROOT . '/config/database.php';
$appConfigPath = ROOT . '/config/app.php';

$results['Configuration'][] = check(
    'config/database.php',
    is_readable($dbConfigPath),
    is_readable($dbConfigPath) ? 'present' : 'MISSING or unreadable',
    'Copy config/database.php.example and fill in your DB credentials.'
);
$results['Configuration'][] = check(
    'config/app.php',
    is_readable($appConfigPath),
    is_readable($appConfigPath) ? 'present' : 'MISSING or unreadable',
    'Copy config/app.php.example and fill in your settings (or re-run install.php).'
);

// ---------------------------------------------------------------------------
// 3. Filesystem
// ---------------------------------------------------------------------------
foreach (['uploads', 'config'] as $dir) {
    $path = ROOT . '/' . $dir;
    $results['Filesystem'][] = check(
        "{$dir}/ writable",
        is_dir($path) && is_writable($path),
        is_dir($path) ? (is_writable($path) ? 'writable' : 'NOT writable') : 'missing',
        "chmod 775 {$path} and make sure the web server user owns it."
    );
}

// ---------------------------------------------------------------------------
// 4. Database connection + schema
// ---------------------------------------------------------------------------
$pdo = null;
if (is_readable($dbConfigPath)) {
    $cfg = require $dbConfigPath;
    try {
        $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['name']};charset={$cfg['charset']}";
        $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $version = $pdo->query('SELECT VERSION()')->fetchColumn();
        $results['Database'][] = check('Connection', true, "connected to {$cfg['name']} (MySQL {$version})");
    } catch (Throwable $e) {
        $results['Database'][] = check(
            'Connection',
            false,
            $e->getMessage(),
            'Check host/port/username/password/database name in config/database.php.'
        );
        $fatal[] = 'Cannot connect to the database — schema checks skipped.';
    }
}

if ($pdo) {
    // Tables
    $existing = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
        $existing[] = $row[0];
    }

    $missingTables = [];
    foreach ($REQUIRED_TABLES as $table => $feature) {
        $ok = in_array($table, $existing, true);
        if (!$ok) $missingTables[] = $table;
        $results['Database Tables'][] = check(
            $table,
            $ok,
            $ok ? 'present' : "MISSING — needed for: {$feature}",
            'Run database-migrations.sql against this database.'
        );
    }

    // Columns
    $missingColumns = [];
    foreach ($REQUIRED_COLUMNS as $table => $columns) {
        if (!in_array($table, $existing, true)) continue;
        $cols = [];
        foreach ($pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll() as $c) {
            $cols[] = $c['Field'];
        }
        foreach ($columns as $col) {
            $ok = in_array($col, $cols, true);
            if (!$ok) $missingColumns[] = "{$table}.{$col}";
            $results['Database Columns'][] = check(
                "{$table}.{$col}",
                $ok,
                $ok ? 'present' : 'MISSING',
                'Run database-migrations.sql against this database.'
            );
        }
    }

    if ($missingTables || $missingColumns) {
        $fatal[] = 'Schema is out of date — the code expects tables/columns that do not exist yet. '
                 . 'This is the usual cause of a site-wide HTTP 500 right after deploying an update.';
    }
}

// ---------------------------------------------------------------------------
// 5. Try to locate a PHP error log
// ---------------------------------------------------------------------------
$logCandidates = array_filter([
    ini_get('error_log') ?: null,
    ROOT . '/error_log',
    ROOT . '/php_errorlog',
    dirname(ROOT) . '/error_log',
    '/var/log/apache2/error.log',
    '/var/log/httpd/error_log',
    '/var/log/php_errors.log',
]);

$foundLog = null;
foreach ($logCandidates as $candidate) {
    if ($candidate && @is_readable($candidate)) { $foundLog = $candidate; break; }
}

$logTail = '';
if ($foundLog) {
    $lines = @file($foundLog, FILE_IGNORE_NEW_LINES) ?: [];
    $logTail = implode("\n", array_slice($lines, -25));
}

// ---------------------------------------------------------------------------
// Render
// ---------------------------------------------------------------------------
$totalFail = 0;
foreach ($results as $checks) {
    foreach ($checks as $c) if (!$c['ok']) $totalFail++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Health Check — PDF Viewer</title>
<style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#0f172a;color:#e2e8f0;padding:2rem 1rem;line-height:1.55}
    .wrap{max-width:900px;margin:0 auto}
    h1{font-size:1.5rem;margin-bottom:.35rem}
    .sub{color:#94a3b8;font-size:.9rem;margin-bottom:1.5rem}
    .banner{border-radius:10px;padding:1rem 1.25rem;margin-bottom:1.5rem;font-size:.9rem}
    .banner.bad{background:rgba(239,68,68,.14);border:1px solid rgba(239,68,68,.4);color:#fca5a5}
    .banner.good{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.35);color:#86efac}
    .banner strong{display:block;margin-bottom:.35rem;font-size:.95rem}
    h2{font-size:.8rem;text-transform:uppercase;letter-spacing:.06em;color:#64748b;margin:1.5rem 0 .6rem}
    table{width:100%;border-collapse:collapse;background:#1e293b;border-radius:10px;overflow:hidden}
    td{padding:.6rem .9rem;border-bottom:1px solid rgba(255,255,255,.06);font-size:.87rem;vertical-align:top}
    tr:last-child td{border-bottom:none}
    td.status{width:34px;text-align:center;font-weight:700}
    .ok{color:#4ade80}.fail{color:#f87171}
    td.name{width:230px;font-weight:600}
    td.detail{color:#94a3b8;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.8rem;word-break:break-word}
    .fix{display:block;margin-top:.3rem;color:#fbbf24;font-family:inherit;font-size:.8rem}
    pre{background:#020617;border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:1rem;overflow-x:auto;font-size:.75rem;color:#cbd5e1;white-space:pre-wrap;word-break:break-word}
    code{background:rgba(255,255,255,.08);padding:.12rem .4rem;border-radius:4px;font-size:.85em}
    .warn{margin-top:2rem;padding:1rem 1.25rem;background:rgba(251,191,36,.1);border:1px solid rgba(251,191,36,.3);border-radius:10px;color:#fde68a;font-size:.85rem}
</style>
</head>
<body>
<div class="wrap">
    <h1>Health Check</h1>
    <p class="sub">PDF Viewer Platform · <?= date('Y-m-d H:i:s') ?></p>

    <?php if ($fatal): ?>
        <div class="banner bad">
            <strong><?= $totalFail ?> check<?= $totalFail === 1 ? '' : 's' ?> failed</strong>
            <?php foreach ($fatal as $f): ?><div><?= htmlspecialchars($f) ?></div><?php endforeach; ?>
        </div>
    <?php elseif ($totalFail === 0): ?>
        <div class="banner good"><strong>All checks passed</strong>No configuration or schema problems detected.</div>
    <?php else: ?>
        <div class="banner bad"><strong><?= $totalFail ?> check<?= $totalFail === 1 ? '' : 's' ?> failed</strong>See details below.</div>
    <?php endif; ?>

    <?php foreach ($results as $section => $checks): ?>
        <h2><?= htmlspecialchars($section) ?></h2>
        <table>
            <?php foreach ($checks as $c): ?>
            <tr>
                <td class="status <?= $c['ok'] ? 'ok' : 'fail' ?>"><?= $c['ok'] ? '✓' : '✕' ?></td>
                <td class="name"><?= htmlspecialchars($c['label']) ?></td>
                <td class="detail">
                    <?= htmlspecialchars($c['detail']) ?>
                    <?php if (!$c['ok'] && $c['fix']): ?>
                        <span class="fix">→ <?= htmlspecialchars($c['fix']) ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
    <?php endforeach; ?>

    <h2>PHP Error Log</h2>
    <?php if ($foundLog && $logTail !== ''): ?>
        <p class="sub" style="margin-bottom:.6rem">Last 25 lines of <code><?= htmlspecialchars($foundLog) ?></code></p>
        <pre><?= htmlspecialchars($logTail) ?></pre>
    <?php else: ?>
        <pre>No readable PHP error log found in the usual locations.

On cPanel/shared hosting, look for an "error_log" file in the site root
or in public_html, or open Metrics → Errors in cPanel.

On a VPS, try:
  tail -50 /var/log/apache2/error.log
  tail -50 /var/log/httpd/error_log
  tail -50 /var/log/nginx/error.log</pre>
    <?php endif; ?>

    <div class="warn">
        <strong>Delete this file when you're done.</strong> It exposes schema and
        environment details. Remove <code>health-check.php</code> from the server,
        or unset the token, once the issue is resolved.
    </div>
</div>
</body>
</html>
