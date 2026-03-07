<?php
/**
 * Demo Mode Auto-Reset Endpoint
 * Called by server cron or external scheduler to restore settings to demo snapshot.
 *
 * Usage:
 *   GET/POST /api/demo-reset.php?token=YOUR_CRON_TOKEN
 *
 * Returns JSON: { "success": true, "reset": true|false, "message": "..." }
 */
define('ROOT', dirname(__DIR__));
define('SKIP_DEMO_CRON', true); // Prevent recursive pseudo-cron trigger

require ROOT . '/includes/Database.php';
require ROOT . '/includes/Auth.php';
require ROOT . '/includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $config = bootstrap();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Bootstrap failed: ' . $e->getMessage()]);
    exit;
}

// Demo mode must be active
if (!getSetting('demo_mode', false)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Demo mode is not active.']);
    exit;
}

// Validate token
$expectedToken = getSetting('demo_cron_token', '');
$providedToken = trim($_GET['token'] ?? $_POST['token'] ?? '');

if (!$expectedToken || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid or missing cron token.']);
    exit;
}

// Check if a reset is due (or force=1 bypasses interval check)
$force     = !empty($_GET['force']) || !empty($_POST['force']);
$interval  = (int)getSetting('demo_reset_interval', 30) * 60;
$lastReset = (int)getSetting('demo_last_reset_at', 0);
$due       = $force || ($interval > 0 && (time() - $lastReset) >= $interval);

if (!$due) {
    $nextReset  = $lastReset + $interval;
    $remaining  = max(0, $nextReset - time());
    echo json_encode([
        'success'    => true,
        'reset'      => false,
        'message'    => 'Not due yet.',
        'next_reset' => date('Y-m-d H:i:s', $nextReset),
        'seconds_remaining' => $remaining,
    ]);
    exit;
}

// Perform reset
demoResetSettings();

echo json_encode([
    'success'    => true,
    'reset'      => true,
    'message'    => 'Settings reset to demo snapshot.',
    'reset_at'   => date('Y-m-d H:i:s'),
]);
