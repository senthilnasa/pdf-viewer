<?php
define('ROOT', dirname(__DIR__));
require ROOT . '/includes/Database.php';
require ROOT . '/includes/Auth.php';
require ROOT . '/includes/PDF.php';
require ROOT . '/includes/Analytics.php';
require ROOT . '/includes/helpers.php';

$config     = bootstrap();
$pdfManager = new PDF($config);

$slug  = get('slug', '');
$token = get('token', '');

// ------------------------------------------------------------------
// Resolve document
// ------------------------------------------------------------------
$pdf = $pdfManager->getBySlug($slug);

if (!$pdf) {
    http_response_code(404);
    die('Document not found.');
}

// Share link token flow
$shareLink = null;
if ($token) {
    $shareLink = $pdfManager->validateShareLink($token);
    if (!$shareLink || $shareLink['slug'] !== $slug) {
        http_response_code(403);
        die('This share link is invalid, expired, or has reached its view limit.');
    }
}

// Access control
if ($pdf['visibility'] === 'private' && !$shareLink) {
    if (!$auth->isLoggedIn()) {
        redirect($config['base_url'] . '/admin/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
    }
}

// Public viewing setting
if (!getSetting('enable_public_view', true) && !$auth->isLoggedIn() && !$shareLink) {
    redirect($config['base_url'] . '/admin/login.php');
}

// Track visit (analytics)
if (getSetting('analytics_enabled', true)) {
    Analytics::recordVisit($pdf['id']);
}

// Load viewer preferences (header/footer config)
// Global prefs applied first, per-document prefs override them
$globalPrefsRaw = Database::fetchScalar("SELECT value FROM settings WHERE `key` = 'viewer_global_prefs'");
$globalPrefs    = json_decode($globalPrefsRaw ?: '{}', true) ?: [];

$docPrefsRaw = Database::fetchScalar("SELECT value FROM settings WHERE `key` = ?", ['viewer_prefs_' . $pdf['id']]);
$docPrefs    = json_decode($docPrefsRaw ?: '{}', true) ?: [];

$prefs = array_merge([
    'show_header'     => true,
    'show_footer'     => true,
    'header_logo'     => '',
    'header_title'    => $pdf['title'],
    'header_subtitle' => '',
    'header_bg'       => '#1e293b',
    'header_color'    => '#ffffff',
    'footer_text'     => getSetting('site_name', $config['site_name']),
    'footer_bg'       => '#f1f5f9',
    'footer_color'    => '#64748b',
    'show_page_num'   => true,
    'show_file_info'  => true,
    'show_share_btn'  => true,
    'show_download'   => (bool)$pdf['enable_download'] && (bool)getSetting('enable_download', true),
    'theme'           => 'dark',
], $globalPrefs, $docPrefs);

$siteName  = getSetting('site_name', $config['site_name']);
$metaTitle = ($pdf['meta_title'] ?: $pdf['title']) . e($config['meta_title_suffix'] ?? ' | PDF Viewer');
$metaDesc  = $pdf['meta_desc'] ?: $pdf['description'] ?? '';
$gaId      = getSetting('ga_measurement_id', '');
$cfToken   = getSetting('cloudflare_token', '');

// Serve PDF through API (never expose direct path)
$pdfUrl = $config['base_url'] . '/api/serve-pdf.php?id=' . $pdf['id']
    . ($token ? '&token=' . urlencode($token) : '');

$shareUrl = $config['base_url'] . '/pdf/' . $pdf['slug'];
$fileSizeHuman = $pdfManager->humanFileSize($pdf['file_size']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($metaTitle) ?></title>
    <meta name="description" content="<?= e($metaDesc) ?>">
    <!-- OpenGraph -->
    <meta property="og:title" content="<?= e($pdf['meta_title'] ?: $pdf['title']) ?>">
    <meta property="og:description" content="<?= e($metaDesc) ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= e($shareUrl) ?>">
    <meta name="twitter:card" content="summary">
    <?php if ($gaId): ?>
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?= e($gaId) ?>"></script>
    <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','<?= e($gaId) ?>');</script>
    <?php endif; ?>
    <?php if ($cfToken): ?>
    <script defer src='https://static.cloudflareinsights.com/beacon.min.js' data-cf-beacon='{"token":"<?= e($cfToken) ?>"}'></script>
    <?php endif; ?>
    <link rel="stylesheet" href="../assets/css/viewer.css">
</head>
<body class="viewer-theme-<?= e($prefs['theme']) ?>" id="viewerBody">

<!-- ================================================================
     VIEWER HEADER
================================================================ -->
<?php if ($prefs['show_header']): ?>
<header class="viewer-header" style="background:<?= e($prefs['header_bg']) ?>;color:<?= e($prefs['header_color']) ?>">
    <div class="viewer-header-left">
        <?php if ($prefs['header_logo']): ?>
        <img src="<?= e($prefs['header_logo']) ?>" alt="Logo" class="header-logo">
        <?php else: ?>
        <div class="header-logo-placeholder">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="24" height="24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
            </svg>
        </div>
        <?php endif; ?>
        <div class="header-titles">
            <h1 class="header-title"><?= e($prefs['header_title']) ?></h1>
            <?php if ($prefs['header_subtitle']): ?>
            <p class="header-subtitle"><?= e($prefs['header_subtitle']) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="viewer-header-right">
        <?php if ($prefs['show_file_info']): ?>
        <div class="file-info">
            <span class="file-info-item">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="14" height="14"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                <span id="pdfPageCount">— pages</span>
            </span>
            <span class="file-info-item">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="14" height="14"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/></svg>
                <?= e($fileSizeHuman) ?>
            </span>
        </div>
        <?php endif; ?>

        <div class="header-actions">
            <?php if ($prefs['show_share_btn']): ?>
            <button class="viewer-btn" id="shareBtn" title="Share" onclick="toggleSharePanel()">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="18" height="18">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/>
                </svg>
                Share
            </button>
            <?php endif; ?>
            <?php if ($prefs['show_download']): ?>
            <a class="viewer-btn" href="<?= e($pdfUrl) ?>&download=1" download="<?= e($pdf['title']) ?>.pdf" title="Download">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="18" height="18">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Download
            </a>
            <?php endif; ?>
            <?php if ($auth->isLoggedIn()): ?>
            <a class="viewer-btn" href="../admin/pdfs.php?action=edit&id=<?= $pdf['id'] ?>" title="Edit">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="18" height="18">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                </svg>
                Edit
            </a>
            <?php endif; ?>
        </div>
    </div>
</header>
<?php endif; ?>

<!-- Share Panel -->
<div class="share-panel" id="sharePanel">
    <div class="share-panel-inner">
        <h4>Share this document</h4>
        <div class="share-url-row">
            <input type="text" id="shareUrl" value="<?= e($shareUrl) ?>" readonly onclick="this.select()">
            <button onclick="copyShareUrl()" class="viewer-btn-sm">Copy</button>
        </div>
        <div class="share-social">
            <a href="https://twitter.com/intent/tweet?url=<?= urlencode($shareUrl) ?>&text=<?= urlencode($pdf['title']) ?>" target="_blank" class="share-social-btn twitter">Twitter / X</a>
            <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?= urlencode($shareUrl) ?>" target="_blank" class="share-social-btn linkedin">LinkedIn</a>
            <a href="mailto:?subject=<?= urlencode($pdf['title']) ?>&body=<?= urlencode($shareUrl) ?>" class="share-social-btn email">Email</a>
        </div>
    </div>
</div>

<!-- ================================================================
     VIEWER TOOLBAR
================================================================ -->
<div class="viewer-toolbar" id="viewerToolbar">
    <div class="toolbar-group">
        <button class="tool-btn" id="prevPage" title="Previous page (Left arrow)">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </button>
        <div class="page-input-group">
            <input type="number" id="pageNum" min="1" value="1" aria-label="Current page">
            <span class="page-sep">/</span>
            <span id="pageCount">—</span>
        </div>
        <button class="tool-btn" id="nextPage" title="Next page (Right arrow)">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        </button>
    </div>

    <div class="toolbar-group">
        <button class="tool-btn" id="zoomOut" title="Zoom out (-)">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM13 10H7"/></svg>
        </button>
        <select id="zoomSelect" class="zoom-select">
            <option value="auto">Auto</option>
            <option value="page-fit">Fit Page</option>
            <option value="page-width" selected>Fit Width</option>
            <option value="0.5">50%</option>
            <option value="0.75">75%</option>
            <option value="1">100%</option>
            <option value="1.25">125%</option>
            <option value="1.5">150%</option>
            <option value="2">200%</option>
        </select>
        <button class="tool-btn" id="zoomIn" title="Zoom in (+)">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7"/></svg>
        </button>
    </div>

    <div class="toolbar-group">
        <button class="tool-btn" id="searchToggle" title="Search (Ctrl+F)">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        </button>
        <button class="tool-btn" id="rotateBtn" title="Rotate">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
        </button>
        <button class="tool-btn" id="fullscreenBtn" title="Fullscreen (F)">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/></svg>
        </button>
        <button class="tool-btn tool-btn-active" id="thumbnailToggle" title="Thumbnails">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
        </button>
    </div>

    <div class="toolbar-loading" id="toolbarLoading">
        <div class="spinner"></div>
        <span id="loadingText">Loading…</span>
    </div>
</div>

<!-- Search Bar -->
<div class="search-bar" id="searchBar" style="display:none">
    <input type="text" id="searchInput" placeholder="Search in document…" autocomplete="off">
    <button id="searchPrev" class="viewer-btn-sm">&larr;</button>
    <button id="searchNext" class="viewer-btn-sm">&rarr;</button>
    <span id="searchMatches" class="text-muted" style="font-size:.8rem;margin:0 .5rem"></span>
    <button id="searchClose" class="viewer-btn-sm">✕</button>
</div>

<!-- ================================================================
     MAIN VIEWER LAYOUT
================================================================ -->
<div class="viewer-layout" id="viewerLayout">
    <!-- Thumbnail sidebar -->
    <aside class="viewer-thumbnails" id="thumbnailSidebar">
        <div id="thumbnailList"></div>
    </aside>

    <!-- Canvas area -->
    <main class="viewer-canvas-area" id="canvasArea">
        <div class="canvas-scroll" id="canvasScroll">
            <div id="pdfContainer"></div>
        </div>
    </main>
</div>

<!-- ================================================================
     VIEWER FOOTER
================================================================ -->
<?php if ($prefs['show_footer']): ?>
<footer class="viewer-footer" style="background:<?= e($prefs['footer_bg']) ?>;color:<?= e($prefs['footer_color']) ?>">
    <div class="viewer-footer-left">
        <?= e($prefs['footer_text']) ?>
    </div>
    <div class="viewer-footer-center">
        <?php if ($prefs['show_page_num']): ?>
        <span id="footerPage">Page 1 of —</span>
        <?php endif; ?>
    </div>
    <div class="viewer-footer-right">
        <a href="<?= e($config['base_url']) ?>/" style="color:inherit;text-decoration:none;opacity:.6;font-size:.8rem"><?= e($siteName) ?></a>
    </div>
</footer>
<?php endif; ?>

<!-- PDF.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js" crossorigin="anonymous"></script>
<script>
// Config passed from PHP
const VIEWER_CONFIG = {
    pdfUrl:      <?= json_encode($pdfUrl) ?>,
    pdfId:       <?= (int)$pdf['id'] ?>,
    analyticsUrl:<?= json_encode($config['base_url'] . '/api/analytics.php') ?>,
    enableAnalytics: <?= json_encode(getSetting('analytics_enabled', true)) ?>,
    showThumbs:  true,
};
</script>
<script src="../assets/js/viewer.js"></script>
</body>
</html>
