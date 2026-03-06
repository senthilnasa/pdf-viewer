<?php
/**
 * Analytics API endpoint
 * Records page views from the viewer client.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));

define('ROOT', dirname(__DIR__));
require ROOT . '/includes/Database.php';
require ROOT . '/includes/Auth.php';
require ROOT . '/includes/PDF.php';
require ROOT . '/includes/Analytics.php';
require ROOT . '/includes/helpers.php';

$config = bootstrap();

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'POST only'], 405);
}

if (!getSetting('analytics_enabled', true)) {
    json_response(['ok' => false, 'reason' => 'analytics disabled']);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    json_response(['error' => 'Invalid JSON'], 400);
}

$action  = $input['action'] ?? '';
$pdfId   = (int)($input['pdf_id'] ?? 0);
$pageNum = (int)($input['page'] ?? 0);

if (!$pdfId) {
    json_response(['error' => 'pdf_id required'], 400);
}

// Verify PDF exists
$exists = Database::fetchScalar('SELECT id FROM pdf_documents WHERE id = ? AND status = ?', [$pdfId, 'active']);
if (!$exists) {
    json_response(['error' => 'Document not found'], 404);
}

switch ($action) {
    case 'page_view':
        if ($pageNum < 1) json_response(['error' => 'Invalid page'], 400);
        Analytics::recordPageView($pdfId, $pageNum);
        json_response(['ok' => true]);
        break;

    default:
        json_response(['error' => 'Unknown action'], 400);
}
