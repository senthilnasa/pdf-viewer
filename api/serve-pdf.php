<?php
/**
 * PDF file serving endpoint
 * Streams PDF files without exposing their real path.
 */
define('ROOT', dirname(__DIR__));
require ROOT . '/includes/Database.php';
require ROOT . '/includes/Auth.php';
require ROOT . '/includes/PDF.php';
require ROOT . '/includes/Analytics.php';
require ROOT . '/includes/helpers.php';

$config     = bootstrap();
$pdfManager = new PDF($config);

$pdfId = (int)get('id', 0);
$token = get('token', '');
$download = get('download', '') === '1';

if (!$pdfId) {
    http_response_code(400);
    exit('Missing ID.');
}

$pdf = Database::fetchOne('SELECT * FROM pdf_documents WHERE id = ? AND status = ?', [$pdfId, 'active']);
if (!$pdf) {
    http_response_code(404);
    exit('Document not found.');
}

// Access control
if ($pdf['visibility'] === 'private') {
    if ($token) {
        // Read-only check — do NOT re-verify the password here (this endpoint
        // is hit repeatedly by range requests while the PDF loads) and do NOT
        // increment view_count here (that happens once, at the viewer page
        // level, in viewer/index.php). Password-protected links instead rely
        // on the session flag set once the visitor cleared the password gate.
        $link = $pdfManager->getShareLinkByToken($token);
        if (!$link || (int)$link['pdf_id'] !== $pdfId) {
            http_response_code(403);
            exit('Access denied.');
        }
        $check = $pdfManager->checkShareLink($link);
        $passwordOk = !$link['password'] || !empty($_SESSION['share_verified'][$token]);
        // The viewer page counts the view *before* PDF.js requests the file,
        // so the last permitted view would otherwise hit "limit_reached" here
        // and never load. Allow it when this session was granted that view.
        $allowedReasons = ['invalid_password'];
        if (!empty($_SESSION['share_granted'][$token])) {
            $allowedReasons[] = 'limit_reached';
        }
        if ((!$check['ok'] && !in_array($check['reason'], $allowedReasons, true)) || !$passwordOk) {
            http_response_code(403);
            exit('Access denied.');
        }
    } elseif (!$auth->isLoggedIn()) {
        http_response_code(403);
        exit('Authentication required.');
    }
}

// Public viewing check
if (!getSetting('enable_public_view', true) && !$auth->isLoggedIn() && !$token) {
    http_response_code(403);
    exit('Login required.');
}

$filePath = $pdf['file_path'];
if (!file_exists($filePath)) {
    http_response_code(404);
    exit('File not found on server.');
}

// Download check
if ($download && (!$pdf['enable_download'] || !getSetting('enable_download', true))) {
    http_response_code(403);
    exit('Download not allowed.');
}

$fileSize = filesize($filePath);
$filename = preg_replace('/[^a-zA-Z0-9_-]/', '-', $pdf['title']) . '.pdf';

// Range request support (for large PDFs / PDF.js)
$start = 0;
$end   = $fileSize - 1;

if (isset($_SERVER['HTTP_RANGE'])) {
    $range = $_SERVER['HTTP_RANGE'];
    if (preg_match('/bytes=(\d*)-(\d*)/i', $range, $m) && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') {
            // Suffix range "bytes=-N" means the LAST N bytes, not bytes 0..N.
            $start = max(0, $fileSize - (int)$m[2]);
            $end   = $fileSize - 1;
        } else {
            $start = (int)$m[1];
            $end   = $m[2] !== '' ? (int)$m[2] : $fileSize - 1;
        }
        // A range end past EOF must be clamped, otherwise Content-Length
        // advertises more bytes than are sent and the client hangs/errors.
        $end = min($end, $fileSize - 1);
    }
    if ($start > $end || $start >= $fileSize) {
        http_response_code(416);
        header("Content-Range: bytes */{$fileSize}");
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes {$start}-{$end}/{$fileSize}");
} else {
    http_response_code(200);
}

$length = $end - $start + 1;

header('Content-Type: application/pdf');
header('Content-Length: ' . $length);
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');

if ($download) {
    header('Content-Disposition: attachment; filename="' . $filename . '"');
} else {
    header('Content-Disposition: inline; filename="' . $filename . '"');
}

// Stream file
$fh = fopen($filePath, 'rb');
fseek($fh, $start);
$remaining = $length;
while ($remaining > 0 && !feof($fh)) {
    $chunk = min(8192, $remaining);
    echo fread($fh, $chunk);
    $remaining -= $chunk;
    if (connection_aborted()) break;
}
fclose($fh);
