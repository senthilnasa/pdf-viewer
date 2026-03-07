<?php
/**
 * Scheduled Task Runner — PDF Viewer Platform
 * =============================================
 * Call via server cron (CLI) or HTTP with token.
 *
 * CLI (every 60 min via crontab):
 *   0 * * * * php /var/www/html/cron.php >> /var/log/pdfviewer-cron.log 2>&1
 *
 * HTTP (call from external scheduler):
 *   GET /cron.php?token=YOUR_CRON_TOKEN
 *
 * Returns:  JSON on HTTP, plain text on CLI
 */
define('ROOT', __DIR__);
define('SKIP_DEMO_CRON', true); // Prevent recursive pseudo-cron trigger in bootstrap

require ROOT . '/includes/Database.php';
require ROOT . '/includes/Auth.php';
require ROOT . '/includes/helpers.php';

$isCli = PHP_SAPI === 'cli';

// ── Bootstrap ────────────────────────────────────────────────────────────────
try {
    $config = bootstrap();
} catch (Throwable $e) {
    _cronExit(false, 'Bootstrap failed: ' . $e->getMessage(), $isCli);
}

// ── Auth: HTTP requires valid cron token ─────────────────────────────────────
if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    $expected = getSetting('demo_cron_token', '');
    $provided = trim($_GET['token'] ?? $_POST['token'] ?? '');
    if (!$expected || !hash_equals($expected, $provided)) {
        http_response_code(401);
        _cronExit(false, 'Invalid or missing cron token.', $isCli);
    }
}

// ── Default interval: 60 minutes ─────────────────────────────────────────────
$interval = (int)getSetting('demo_reset_interval', 60); // minutes
if ($interval < 1) $interval = 60;

// ── Run tasks ─────────────────────────────────────────────────────────────────
$tasks   = [];
$ran     = false;
$now     = time();

// Task 1: Demo mode auto-reset
if (getSetting('demo_mode', false)) {
    $lastReset = (int)getSetting('demo_last_reset_at', 0);
    $due       = ($now - $lastReset) >= ($interval * 60);

    if ($due || !empty($_GET['force']) || !empty($_POST['force'])) {
        demoResetSettings();
        $ran = true;
        $tasks[] = [
            'task'    => 'demo_reset',
            'status'  => 'ok',
            'message' => 'Settings reset to demo snapshot.',
        ];
    } else {
        $remaining = ($lastReset + ($interval * 60)) - $now;
        $tasks[] = [
            'task'    => 'demo_reset',
            'status'  => 'skipped',
            'message' => 'Not due yet. Next run in ' . gmdate('H:i:s', $remaining) . '.',
        ];
    }
} else {
    $tasks[] = [
        'task'    => 'demo_reset',
        'status'  => 'skipped',
        'message' => 'Demo mode is disabled.',
    ];
}

// Record cron last run time (always, regardless of tasks)
$ts = (string)$now;
Database::query(
    'INSERT INTO settings (`key`, `value`, `type`) VALUES ("cron_last_run", ?, "integer")
     ON DUPLICATE KEY UPDATE `value` = ?, `type` = "integer"',
    [$ts, $ts]
);

_cronExit(true, $ran ? 'Tasks completed.' : 'No tasks due.', $isCli, $tasks, $now);

// ── Output helper ─────────────────────────────────────────────────────────────
function _cronExit(bool $success, string $message, bool $cli, array $tasks = [], int $ts = 0): never
{
    if ($cli) {
        $line = '[' . date('Y-m-d H:i:s', $ts ?: time()) . '] ' . ($success ? 'OK' : 'ERR') . ' — ' . $message;
        foreach ($tasks as $t) {
            $line .= "\n  · [{$t['status']}] {$t['task']}: {$t['message']}";
        }
        echo $line . PHP_EOL;
    } else {
        echo json_encode([
            'success'  => $success,
            'message'  => $message,
            'ran_at'   => $ts ? date('Y-m-d H:i:s', $ts) : null,
            'tasks'    => $tasks,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
    exit;
}
