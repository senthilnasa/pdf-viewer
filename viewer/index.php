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
    $errorMessage = $slug
        ? 'The document "' . htmlspecialchars($slug) . '" could not be found. It may have been removed or the link is incorrect.'
        : 'No document was specified in the URL.';
    http_response_code(404);
    include ROOT . '/errors/404.php';
    exit;
}

// Share link token flow
$shareLink = null;
if ($token) {
    // Check if this token exists and requires a password before full validation
    $rawLink = Database::fetchOne(
        'SELECT sl.password, p.slug FROM share_links sl JOIN pdf_documents p ON p.id = sl.pdf_id WHERE sl.token = ?',
        [$token]
    );

    $sharePass      = trim(get('share_pass', post('share_pass', '')));
    $sharePassError = '';

    if ($rawLink && $rawLink['password']) {
        // Password-protected link: show form if password not submitted yet
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !isset($_GET['share_pass'])) {
            // Show password entry form
            $siteName = getSetting('site_name', $config['site_name'] ?? 'PDF Viewer');
            include ROOT . '/viewer/partials/share-password-form.php';
            exit;
        }

        // Validate with password
        $shareLink = $pdfManager->validateShareLink($token, $sharePass);

        if (!$shareLink) {
            // Wrong password or expired/limit reached — re-show form with error
            $siteName       = getSetting('site_name', $config['site_name'] ?? 'PDF Viewer');
            $sharePassError = 'Incorrect password. Please try again.';
            include ROOT . '/viewer/partials/share-password-form.php';
            exit;
        }
    } else {
        // No password required — normal validation
        $shareLink = $pdfManager->validateShareLink($token);
    }

    if (!$shareLink || $shareLink['slug'] !== $slug) {
        // Invalid / expired / limit reached — styled error page
        $siteName = getSetting('site_name', $config['site_name'] ?? 'PDF Viewer');
        http_response_code(403);
        include ROOT . '/viewer/partials/share-error.php';
        exit;
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
    'footer_text'     => getSetting('site_name', $config['site_name']) . ' · Powered by PDF Viewer',
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
    <?php require ROOT . '/admin/partials/head-meta.php'; ?>
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
    <style>
    /* ================================================================
       FLIPBOOK OVERLAY — dearFlip / pdf-flip style
    ================================================================ */
    .fb-overlay {
        position: fixed; inset: 0; z-index: 9000;
        background: #0d1117;
        display: flex; flex-direction: column;
        align-items: center;
        overflow: hidden;
    }
    .fb-overlay.fb-hidden { display: none; }

    /* Top bar */
    .fb-top {
        width: 100%; display: flex; align-items: center;
        justify-content: space-between;
        padding: .6rem 1.25rem;
        background: rgba(255,255,255,.04);
        border-bottom: 1px solid rgba(255,255,255,.08);
        flex-shrink: 0;
    }
    .fb-top-title { color: #e2e8f0; font-size: .9rem; font-weight: 600; }
    .fb-top-actions { display: flex; gap: .5rem; }
    .fb-icon-btn {
        background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.12);
        color: #cbd5e0; border-radius: 6px; padding: .35rem .65rem;
        cursor: pointer; font-size: .82rem; display: flex; align-items: center; gap: .35rem;
        transition: background .15s, color .15s;
    }
    .fb-icon-btn:hover { background: rgba(255,255,255,.18); color: #fff; }
    .fb-icon-btn.fb-active { background: #4f46e5; border-color: #4f46e5; color: #fff; }

    /* Loading */
    .fb-loading {
        position: absolute; inset: 0; display: flex; flex-direction: column;
        align-items: center; justify-content: center; gap: 1.25rem; z-index: 10;
        background: #0d1117;
    }
    .fb-loading-icon { width: 48px; height: 48px; }
    .fb-loading-icon circle { stroke: #4f46e5; animation: fb-spin 1s linear infinite; transform-origin: center; }
    @keyframes fb-spin { to { stroke-dashoffset: -200; } }
    .fb-loading-text { color: #a0aec0; font-size: .9rem; }
    .fb-progress-wrap { width: 220px; height: 4px; background: rgba(255,255,255,.1); border-radius: 2px; overflow: hidden; }
    .fb-progress-bar  { height: 100%; background: linear-gradient(90deg,#4f46e5,#7c3aed); border-radius: 2px; transition: width .25s; }

    /* Stage (where the book lives) */
    .fb-stage {
        flex: 1; display: flex; align-items: center; justify-content: center;
        width: 100%; position: relative; overflow: hidden;
        perspective: 2000px;
    }

    /* Navigation arrows */
    .fb-arrow {
        position: absolute; top: 50%; transform: translateY(-50%);
        z-index: 20; background: rgba(255,255,255,.08);
        border: 1px solid rgba(255,255,255,.15); color: #fff;
        width: 44px; height: 44px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        cursor: pointer; transition: background .2s; font-size: 1.1rem;
    }
    .fb-arrow:hover { background: rgba(79,70,229,.7); border-color: #4f46e5; }
    .fb-arrow-left  { left: 1rem; }
    .fb-arrow-right { right: 1rem; }

    /* Bottom controls */
    .fb-controls {
        display: flex; align-items: center; gap: .75rem; flex-shrink: 0;
        padding: .65rem 1.5rem;
        background: rgba(255,255,255,.04);
        border-top: 1px solid rgba(255,255,255,.08);
        width: 100%;
        justify-content: center;
    }
    .fb-page-pill {
        background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.12);
        color: #e2e8f0; font-size: .85rem; border-radius: 20px;
        padding: .3rem 1rem; min-width: 100px; text-align: center;
    }
    .fb-zoom-label { color: #718096; font-size: .8rem; }

    /* Thumbnail strip */
    .fb-thumbs-bar {
        background: rgba(0,0,0,.35); border-top: 1px solid rgba(255,255,255,.06);
        padding: .5rem .75rem; width: 100%; flex-shrink: 0; overflow: hidden;
    }
    .fb-thumbs {
        display: flex; gap: 6px; overflow-x: auto; padding-bottom: 4px;
        scrollbar-width: thin; scrollbar-color: rgba(255,255,255,.15) transparent;
    }
    .fb-thumbs::-webkit-scrollbar { height: 4px; }
    .fb-thumbs::-webkit-scrollbar-thumb { background: rgba(255,255,255,.15); border-radius: 2px; }
    .fb-thumb-item {
        flex-shrink: 0; width: 52px; cursor: pointer;
        opacity: .45; border-radius: 3px; overflow: hidden;
        border: 2px solid transparent; transition: opacity .2s, transform .15s;
    }
    .fb-thumb-item:hover { opacity: .75; transform: scale(1.06); }
    .fb-thumb-item.fb-thumb-active { opacity: 1; border-color: #4f46e5; }
    .fb-thumb-item canvas { display: block; width: 100%; height: auto; }

    /* Page elements passed to StPageFlip */
    .fb-page {
        background: #fff; overflow: hidden;
        display: flex; align-items: center; justify-content: center;
    }
    .fb-page canvas { display: block; width: 100%; height: 100%; object-fit: contain; }
    /* Hard cover pages */
    .fb-page.fb-cover {
        background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    }
    .fb-page.fb-cover canvas { opacity: .92; }

    /* Book container wrapper (zoom) */
    #fbBookWrap {
        transform-origin: center center;
        transition: transform .25s;
    }

    /* StPageFlip shadow tweak */
    .stf__parent { perspective: 2000px !important; }

    /* Toolbar toggle button */
    .tool-btn-flipbook {
        width: auto; padding: 0 .75rem; gap: .4rem;
        font-size: .8rem; font-weight: 600; white-space: nowrap;
    }
    @media (max-width: 640px) {
        .tool-btn-flipbook { width: 30px; padding: 0; gap: 0; font-size: 0; }
    }

    /* Flipbook overlay responsive */
    @media (max-width: 640px) {
        .fb-top        { padding: .45rem .75rem; }
        .fb-top-title  { font-size: .82rem; max-width: 40%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .fb-icon-btn   { padding: .28rem .5rem; font-size: .75rem; gap: .25rem; }
        .fb-controls   { padding: .5rem .75rem; gap: .5rem; flex-wrap: wrap; justify-content: center; }
        /* Hide side arrows on mobile — use bottom prev/next controls instead */
        .fb-arrow      { display: none; }
        .fb-page-pill  { min-width: 80px; font-size: .78rem; padding: .25rem .75rem; }
        /* Keep only PDF View + Close in top bar on mobile */
        #fbZoomOut, #fbZoomIn, .fb-zoom-label,
        #fbSoundBtn, #fbLayoutBtn { display: none; }
    }
    </style>
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
        <button class="tool-btn tool-btn-flipbook" id="flipbookToggle" title="Flipbook mode" onclick="openFlipbook()">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="18" height="18"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.746 0 3.332.477 4.5 1.253v13C19.832 18.477 18.246 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
            Flipbook
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
        <?php
        $footerText = $prefs['footer_text'];
        // Render "Powered by PDF Viewer" as a clickable link
        $footerHtml = e($footerText);
        $footerHtml = preg_replace(
            '/Powered by PDF Viewer/i',
            'Powered by <a href="https://github.com/senthilnasa/pdf-viewer" target="_blank" rel="noopener" style="color:inherit;opacity:.75;text-decoration:underline;text-underline-offset:2px">PDF Viewer</a>',
            $footerHtml
        );
        echo $footerHtml;
        ?>
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

<!-- ================================================================
     FLIPBOOK OVERLAY
================================================================ -->
<div class="fb-overlay fb-hidden" id="fbOverlay">

    <!-- Top bar -->
    <div class="fb-top">
        <span class="fb-top-title"><?= e($prefs['header_title']) ?></span>
        <div class="fb-top-actions">
            <!-- Zoom out -->
            <button class="fb-icon-btn" id="fbZoomOut" title="Zoom out">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="15" height="15"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM13 10H7"/></svg>
            </button>
            <span class="fb-zoom-label" id="fbZoomLabel">100%</span>
            <!-- Zoom in -->
            <button class="fb-icon-btn" id="fbZoomIn" title="Zoom in">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="15" height="15"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0zM10 7v3m0 0v3m0-3h3m-3 0H7"/></svg>
            </button>
            <!-- Sound toggle -->
            <button class="fb-icon-btn fb-active" id="fbSoundBtn" title="Toggle sound">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="15" height="15"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.536 8.464a5 5 0 010 7.072M12 6v12m-3.536-9.536a5 5 0 000 7.072M7 10l5-5 5 5M7 14l5 5 5-5"/></svg>
                Sound
            </button>
            <!-- Single / double page -->
            <button class="fb-icon-btn" id="fbLayoutBtn" title="Toggle single/double page">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="15" height="15"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                <span class="fb-layout-label">Double</span>
            </button>
            <!-- Switch to PDF Viewer -->
            <button class="fb-icon-btn" onclick="closeFlipbook()" title="Switch to standard PDF viewer">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="15" height="15"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                PDF View
            </button>
            <!-- Close -->
            <button class="fb-icon-btn" onclick="closeFlipbook()" title="Close flipbook">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="15" height="15"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
    </div>

    <!-- Loading overlay -->
    <div class="fb-loading" id="fbLoading">
        <svg class="fb-loading-icon" viewBox="0 0 48 48">
            <circle cx="24" cy="24" r="20" fill="none" stroke-width="4"
                stroke-dasharray="100 30" stroke-linecap="round"/>
        </svg>
        <div class="fb-loading-text" id="fbLoadingText">Preparing flipbook…</div>
        <div class="fb-progress-wrap"><div class="fb-progress-bar" id="fbProgressBar" style="width:0%"></div></div>
    </div>

    <!-- Stage -->
    <div class="fb-stage" id="fbStage">
        <!-- Left arrow -->
        <div class="fb-arrow fb-arrow-left" id="fbPrevBtn" onclick="fbFlipPrev()" title="Previous page">&#10094;</div>
        <!-- Book wrapper (zoom target) -->
        <div id="fbBookWrap">
            <div id="fbBook"></div>
        </div>
        <!-- Right arrow -->
        <div class="fb-arrow fb-arrow-right" id="fbNextBtn" onclick="fbFlipNext()" title="Next page">&#10095;</div>
    </div>

    <!-- Controls bar -->
    <div class="fb-controls" id="fbControls" style="display:none">
        <button class="fb-icon-btn" onclick="fbFlipPrev()">&#10094; Prev</button>
        <div class="fb-page-pill" id="fbPagePill">— / —</div>
        <button class="fb-icon-btn" onclick="fbFlipNext()">Next &#10095;</button>
    </div>

    <!-- Thumbnail strip -->
    <div class="fb-thumbs-bar" id="fbThumbsBar" style="display:none">
        <div class="fb-thumbs" id="fbThumbs"></div>
    </div>
</div>

<!-- PDF.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js" crossorigin="anonymous"></script>
<script>
// Config passed from PHP
const VIEWER_CONFIG = {
    pdfUrl:         <?= json_encode($pdfUrl) ?>,
    pdfId:          <?= (int)$pdf['id'] ?>,
    analyticsUrl:   <?= json_encode($config['base_url'] . '/api/analytics.php') ?>,
    enableAnalytics:<?= json_encode(getSetting('analytics_enabled', true)) ?>,
    showThumbs:     true,
    defaultView:    <?= json_encode(getSetting('default_view', 'pdf')) ?>,
};
</script>
<script src="../assets/js/viewer.js"></script>

<!-- StPageFlip — MIT licence -->
<script src="https://cdn.jsdelivr.net/npm/page-flip@2.0.7/dist/js/page-flip.browser.js"></script>
<script>
/* ================================================================
   FLIPBOOK ENGINE
================================================================ */
(function () {
    'use strict';

    /* ---------- state ---------- */
    const fb = {
        ready:         false,
        instance:      null,
        totalPages:    0,
        zoom:          1.0,
        soundOn:       true,   // on by default
        doubleMode:    true,   // double-page spread
        canvases:      [],     // full-res canvases for each page
        singlePageIdx: 0,      // current page in single-page mode
        singleFlipTo:  null,   // function set during single-mode init
        dispW:         0,
        dispH:         0,
    };

    /* ---------- Audio (page-turn swoosh) ---------- */
    let audioCtx = null;
    function playFlipSound() {
        if (!fb.soundOn) return;
        try {
            if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            const sr  = audioCtx.sampleRate;
            const dur = 0.22;
            const buf = audioCtx.createBuffer(1, Math.ceil(sr * dur), sr);
            const d   = buf.getChannelData(0);
            for (let i = 0; i < d.length; i++) {
                const t   = i / sr;
                const env = Math.exp(-t * 18) * Math.sin(Math.PI * t / dur); // bell envelope
                const noise = (Math.random() * 2 - 1);
                // low-frequency "whoosh" tone + noise
                d[i] = (noise * 0.55 + Math.sin(2 * Math.PI * 180 * t) * 0.08) * env * 0.35;
            }
            // band-pass: keep papery 400-3000 Hz range
            const filter = audioCtx.createBiquadFilter();
            filter.type = 'bandpass';
            filter.frequency.value = 1200;
            filter.Q.value = 0.7;
            const gain = audioCtx.createGain();
            gain.gain.setValueAtTime(1.4, audioCtx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + dur);
            const src = audioCtx.createBufferSource();
            src.buffer = buf;
            src.connect(filter);
            filter.connect(gain);
            gain.connect(audioCtx.destination);
            src.start();
        } catch (_) {}
    }

    /* ---------- open / close ---------- */
    window.openFlipbook = async function () {
        document.getElementById('fbOverlay').classList.remove('fb-hidden');
        document.body.style.overflow = 'hidden';
        // Wait one frame so the overlay is painted and stage dimensions are available
        await new Promise(r => requestAnimationFrame(r));
        if (!fb.ready) await initFlipbook();
    };

    window.closeFlipbook = function () {
        document.getElementById('fbOverlay').classList.add('fb-hidden');
        document.body.style.overflow = '';
    };

    /* ---------- flip controls ---------- */
    window.fbFlipPrev = function () {
        if (fb.doubleMode) {
            if (fb.instance) { fb.instance.flipPrev('bottom'); playFlipSound(); }
        } else {
            if (fb.singleFlipTo && fb.singlePageIdx > 0) {
                fb.singleFlipTo(fb.singlePageIdx - 1, 'prev');
            }
        }
    };
    window.fbFlipNext = function () {
        if (fb.doubleMode) {
            if (fb.instance) { fb.instance.flipNext('bottom'); playFlipSound(); }
        } else {
            if (fb.singleFlipTo && fb.singlePageIdx < fb.totalPages - 1) {
                fb.singleFlipTo(fb.singlePageIdx + 1, 'next');
            }
        }
    };

    /* ---------- zoom ---------- */
    function applyZoom() {
        document.getElementById('fbBookWrap').style.transform = `scale(${fb.zoom})`;
        document.getElementById('fbZoomLabel').textContent = Math.round(fb.zoom * 100) + '%';
    }
    document.getElementById('fbZoomIn').addEventListener('click', () => {
        fb.zoom = Math.min(2.5, +(fb.zoom + 0.15).toFixed(2)); applyZoom();
    });
    document.getElementById('fbZoomOut').addEventListener('click', () => {
        fb.zoom = Math.max(0.4, +(fb.zoom - 0.15).toFixed(2)); applyZoom();
    });
    document.addEventListener('wheel', function (e) {
        if (document.getElementById('fbOverlay').classList.contains('fb-hidden')) return;
        if (e.ctrlKey) {
            e.preventDefault();
            fb.zoom = Math.max(0.4, Math.min(2.5, +(fb.zoom - e.deltaY * 0.003).toFixed(2)));
            applyZoom();
        }
    }, { passive: false });

    /* ---------- sound toggle ---------- */
    document.getElementById('fbSoundBtn').addEventListener('click', function () {
        fb.soundOn = !fb.soundOn;
        this.classList.toggle('fb-active', fb.soundOn);
    });

    /* ---------- keyboard navigation ---------- */
    document.addEventListener('keydown', function (e) {
        if (document.getElementById('fbOverlay').classList.contains('fb-hidden')) return;
        if (e.key === 'ArrowLeft'  || e.key === 'ArrowUp')   fbFlipPrev();
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') fbFlipNext();
        if (e.key === 'Escape') closeFlipbook();
    });

    /* ---------- layout toggle (single / double) ---------- */
    document.getElementById('fbLayoutBtn').addEventListener('click', async function () {
        fb.doubleMode   = !fb.doubleMode;
        fb.singleFlipTo = null;
        const lbl = this.querySelector('.fb-layout-label');
        if (lbl) lbl.textContent = fb.doubleMode ? 'Double' : 'Single';
        // Reinitialize with new layout
        fb.ready = false;
        document.getElementById('fbBook').innerHTML = '';
        document.getElementById('fbControls').style.display = 'none';
        document.getElementById('fbThumbsBar').style.display = 'none';
        document.getElementById('fbLoading').style.display = 'flex';
        document.getElementById('fbProgressBar').style.width = '0%';
        await initFlipbook();
    });

    /* ---------- update UI on page change ---------- */
    function updateUI(pageIndex) {
        const total = fb.totalPages;
        const current = pageIndex + 1;
        document.getElementById('fbPagePill').textContent = `${current} / ${total}`;
        // Update thumbnails
        document.querySelectorAll('.fb-thumb-item').forEach((el, i) => {
            el.classList.toggle('fb-thumb-active', i === pageIndex);
        });
        const active = document.querySelector('.fb-thumb-item.fb-thumb-active');
        if (active) active.scrollIntoView({ inline: 'center', behavior: 'smooth' });
    }

    /* ---------- INIT ---------- */
    async function initFlipbook() {
        const loadingEl  = document.getElementById('fbLoading');
        const progressEl = document.getElementById('fbProgressBar');
        const textEl     = document.getElementById('fbLoadingText');
        const bookEl     = document.getElementById('fbBook');
        const stageEl    = document.getElementById('fbStage');

        loadingEl.style.display = 'flex';

        /* --- ensure PDF.js worker --- */
        if (!pdfjsLib.GlobalWorkerOptions.workerSrc) {
            pdfjsLib.GlobalWorkerOptions.workerSrc =
                'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
        }

        /* --- load PDF --- */
        textEl.textContent = 'Loading document…';
        const pdfDoc = await pdfjsLib.getDocument(VIEWER_CONFIG.pdfUrl).promise;
        fb.totalPages = pdfDoc.numPages;

        /* --- measure first page for aspect ratio --- */
        const pg1      = await pdfDoc.getPage(1);
        const vp1      = pg1.getViewport({ scale: 1 });
        const pageW    = vp1.width;
        const pageH    = vp1.height;

        /* --- compute display size to fill stage --- */
        const isMobile = window.innerWidth <= 640;
        if (isMobile) fb.doubleMode = false;          // force single-page on mobile
        const arrowPad = isMobile ? 10 : 120;         // side arrows hidden on mobile
        const stageW   = Math.max(100, stageEl.clientWidth  - arrowPad);
        const stageH   = Math.max(100, stageEl.clientHeight - 20);
        const spread   = fb.doubleMode ? 2 : 1;
        // Fill the stage fully — no arbitrary width cap, no upscale prevention
        // (PDF points are 72dpi so a 595pt page at scale=1 is only ~595px — always needs upscaling)
        const scaleW    = stageW  / (pageW * spread);
        const scaleH    = stageH  / pageH;
        const dispScale = Math.min(scaleW, scaleH);   // fit to stage, no upper cap
        const dispW     = Math.round(pageW  * dispScale);
        const dispH     = Math.round(pageH  * dispScale);
        // Render at device pixel ratio for crisp text on HiDPI screens
        const dpr         = Math.min(window.devicePixelRatio || 1, 3);
        const renderScale = dispScale * dpr;

        /* --- render all pages --- */
        bookEl.innerHTML = '';
        fb.canvases = [];
        const thumbsEl = document.getElementById('fbThumbs');
        thumbsEl.innerHTML = '';

        for (let i = 1; i <= fb.totalPages; i++) {
            textEl.textContent = `Rendering page ${i} of ${fb.totalPages}…`;
            progressEl.style.width = (i / fb.totalPages * 100) + '%';

            const page  = await pdfDoc.getPage(i);
            const vp    = page.getViewport({ scale: renderScale });
            const cv    = document.createElement('canvas');
            cv.width    = vp.width;
            cv.height   = vp.height;
            await page.render({ canvasContext: cv.getContext('2d'), viewport: vp }).promise;
            fb.canvases.push(cv);

            /* page div for StPageFlip */
            const div = document.createElement('div');
            div.className = 'fb-page' + (i === 1 || i === fb.totalPages ? ' fb-cover' : '');
            div.style.cssText = `width:${dispW}px;height:${dispH}px;`;
            const cvClone = document.createElement('canvas');
            cvClone.width  = dispW; cvClone.height = dispH;
            cvClone.getContext('2d').drawImage(cv, 0, 0, dispW, dispH);
            div.appendChild(cvClone);
            bookEl.appendChild(div);

            /* thumbnail */
            const tW = 52, tH = Math.round(pageH / pageW * tW);
            const tCv = document.createElement('canvas');
            tCv.width = tW; tCv.height = tH;
            tCv.getContext('2d').drawImage(cv, 0, 0, tW, tH);
            const tDiv = document.createElement('div');
            tDiv.className = 'fb-thumb-item' + (i === 1 ? ' fb-thumb-active' : '');
            tDiv.title = `Page ${i}`;
            tDiv.appendChild(tCv);
            tDiv.addEventListener('click', () => {
                if (fb.doubleMode) {
                    fb.instance && fb.instance.flip(i - 1);
                } else if (fb.singleFlipTo) {
                    fb.singleFlipTo(i - 1, i - 1 > fb.singlePageIdx ? 'next' : 'prev');
                }
            });
            thumbsEl.appendChild(tDiv);
        }

        /* --- destroy previous instance --- */
        if (fb.instance) {
            try { fb.instance.destroy(); } catch (_) {}
            fb.instance = null;
        }
        fb.zoom = 1.0; applyZoom();
        fb.dispW = dispW; fb.dispH = dispH;

        if (fb.doubleMode) {
            /* ── Double-page mode: StPageFlip ── */
            const pf = new St.PageFlip(bookEl, {
                width:               dispW,
                height:              dispH,
                size:                'fixed',
                minWidth:            150,
                maxWidth:            dispW,
                minHeight:           200,
                maxHeight:           dispH,
                drawShadow:          true,
                flippingTime:        700,
                usePortrait:         false,
                startZIndex:         0,
                autoSize:            false,
                maxShadowOpacity:    0.5,
                showCover:           true,
                mobileScrollSupport: true,
                clickEventForward:   true,
                useMouseEvents:      true,
                swipeDistance:       30,
                showPageCorners:     true,
                disableFlipByClick:  false,
            });
            pf.loadFromHTML(bookEl.querySelectorAll('.fb-page'));
            pf.on('flip', (e) => { updateUI(e.data); playFlipSound(); });
            fb.instance = pf;

        } else {
            /* ── Single-page mode: custom CSS-3D flipper ── */
            bookEl.innerHTML = '';
            fb.singlePageIdx = 0;

            const singleEl = document.createElement('div');
            singleEl.id = 'fbSinglePage';
            singleEl.style.cssText = [
                `width:${dispW}px`, `height:${dispH}px`,
                'overflow:hidden', 'position:relative',
                'box-shadow:0 20px 60px rgba(0,0,0,.7)',
                'transform-style:preserve-3d',
                'border-radius:2px',
            ].join(';');
            bookEl.appendChild(singleEl);

            function renderSinglePage(idx, dir) {
                fb.singlePageIdx = idx;
                const cv    = fb.canvases[idx];
                const cvEl  = document.createElement('canvas');
                cvEl.width  = dispW; cvEl.height = dispH;
                cvEl.style.cssText = 'display:block;width:100%;height:100%;';
                cvEl.getContext('2d').drawImage(cv, 0, 0, dispW, dispH);

                if (dir) {
                    /* fly in from edge */
                    const fromX = dir === 'next' ? '60px' : '-60px';
                    singleEl.style.cssText += `;transition:none;opacity:0;transform:perspective(1400px) translateX(${fromX}) rotateY(${dir === 'next' ? 18 : -18}deg)`;
                    singleEl.innerHTML = '';
                    singleEl.appendChild(cvEl);
                    requestAnimationFrame(() => requestAnimationFrame(() => {
                        singleEl.style.transition = 'opacity .32s ease, transform .38s cubic-bezier(.22,1,.36,1)';
                        singleEl.style.opacity    = '1';
                        singleEl.style.transform  = 'perspective(1400px) translateX(0) rotateY(0deg)';
                    }));
                } else {
                    singleEl.style.transition = 'none';
                    singleEl.style.opacity    = '1';
                    singleEl.style.transform  = 'none';
                    singleEl.innerHTML        = '';
                    singleEl.appendChild(cvEl);
                }
                updateUI(idx);
                playFlipSound();
            }

            fb.singleFlipTo = renderSinglePage;
            renderSinglePage(0, null);
        }

        fb.ready = true;

        /* --- show UI --- */
        loadingEl.style.display  = 'none';
        document.getElementById('fbControls').style.display  = 'flex';
        document.getElementById('fbThumbsBar').style.display = 'block';
        updateUI(0);
    }
}());
</script>
</body>
</html>
