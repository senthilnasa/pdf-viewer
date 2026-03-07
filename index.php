<?php
/**
 * Main entry point / router
 * PDF Viewer Platform
 */

define('ROOT', __DIR__);

require ROOT . '/includes/Database.php';
require ROOT . '/includes/Auth.php';
require ROOT . '/includes/PDF.php';
require ROOT . '/includes/Analytics.php';
require ROOT . '/includes/helpers.php';

$config = bootstrap();
$route  = get('route', '');

// ------------------------------------------------------------------
// Sitemap
// ------------------------------------------------------------------
if ($route === 'sitemap') {
    if (!getSetting('sitemap_enabled', true)) {
        http_response_code(404);
        exit('Sitemap disabled.');
    }

    $docs = Database::fetchAll(
        "SELECT slug, updated_at FROM pdf_documents WHERE status = 'active' AND visibility = 'public' ORDER BY updated_at DESC"
    );

    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    echo '  <url><loc>' . e($config['base_url']) . '/</loc><changefreq>weekly</changefreq></url>' . "\n";

    foreach ($docs as $doc) {
        $loc = e($config['base_url']) . '/pdf/' . e($doc['slug']);
        $lastmod = date('Y-m-d', strtotime($doc['updated_at']));
        echo "  <url><loc>{$loc}</loc><lastmod>{$lastmod}</lastmod><changefreq>monthly</changefreq></url>\n";
    }
    echo '</urlset>';
    exit;
}

// ------------------------------------------------------------------
// Home page — public document listing
// ------------------------------------------------------------------
$pdfManager = new PDF($config);
$docs = $pdfManager->getAll(['status' => 'active', 'visibility' => 'public']);
$siteName = getSetting('site_name', $config['site_name']);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php require ROOT . '/admin/partials/head-meta.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($siteName) ?></title>
    <meta name="description" content="Browse and view documents online.">
    <link rel="stylesheet" href="assets/css/public.css">
    <?php if ($config['ga_measurement_id']): ?>
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?= e($config['ga_measurement_id']) ?>"></script>
    <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());gtag('config','<?= e($config['ga_measurement_id']) ?>');</script>
    <?php endif; ?>
</head>
<body>
<header class="site-header">
    <div class="container">
        <a href="<?= e($config['base_url']) ?>/" class="logo"><?= e($siteName) ?></a>
        <nav>
            <?php if ($auth->isLoggedIn()): ?>
                <a href="admin/">Admin Panel</a>
                <a href="api/auth.php?action=logout">Logout</a>
            <?php else: ?>
                <a href="admin/login.php">Login</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main class="container">
    <div class="page-header">
        <h1>Document Library</h1>
        <p>Browse our collection of online documents.</p>
    </div>

    <?php if (empty($docs)): ?>
    <div class="empty-state">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="48" height="48">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
        </svg>
        <p>No documents available yet.</p>
    </div>
    <?php else: ?>
    <div class="doc-grid">
        <?php foreach ($docs as $doc): ?>
        <a href="pdf/<?= e($doc['slug']) ?>" class="doc-card">
            <div class="doc-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                </svg>
            </div>
            <div class="doc-info">
                <h3><?= e($doc['title']) ?></h3>
                <?php if ($doc['description']): ?>
                    <p><?= e(mb_substr($doc['description'], 0, 100)) ?>...</p>
                <?php endif; ?>
                <span class="doc-views"><?= number_format($doc['total_views'] ?? 0) ?> views</span>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>

<footer class="site-footer">
    <div class="container">
        <p>&copy; <?= date('Y') ?> <?= e($siteName) ?>. Powered by <a href="https://github.com/senthilnasa/pdf-viewer" target="_blank" rel="noopener">PDF Viewer</a>.</p>
    </div>
</footer>
</body>
</html>
