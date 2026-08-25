<?php
/**
 * Dev-only router for PHP's built-in server.
 *
 * PHP's built-in server (`php -S`) does not read .htaccess, so pretty URLs
 * like /pdf/{slug} and /admin fall through to index.php at the wrong path,
 * breaking relative asset links. This script re-implements the handful of
 * rewrite rules from .htaccess that matter for local testing.
 *
 * Not used in production — Apache (shared hosting) and the Docker image
 * both use .htaccess / the vhost config directly. This file only matters
 * when running:
 *
 *   php -S localhost:8080 -t . router.php
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Deny direct access to sensitive directories, same as .htaccess.
if (preg_match('#^/(config|includes)/#', $uri) || preg_match('#^/uploads/.*\.php$#', $uri)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

// Serve existing real files (assets, uploaded PDFs, etc.) as-is.
$file = __DIR__ . $uri;
if ($uri !== '/' && file_exists($file) && !is_dir($file)) {
    return false;
}

// /pdf/{slug} -> viewer/index.php?slug={slug}
if (preg_match('#^/pdf/([a-z0-9_-]+)/?$#i', $uri, $m)) {
    $_GET['slug'] = $m[1];
    require __DIR__ . '/viewer/index.php';
    return true;
}

// /sitemap.xml -> index.php?route=sitemap
if ($uri === '/sitemap.xml') {
    $_GET['route'] = 'sitemap';
    require __DIR__ . '/index.php';
    return true;
}

// /admin or /admin/ -> admin/index.php
if (preg_match('#^/admin/?$#', $uri)) {
    require __DIR__ . '/admin/index.php';
    return true;
}

// /logout -> api/auth.php?action=logout
if ($uri === '/logout') {
    $_GET['action'] = 'logout';
    require __DIR__ . '/api/auth.php';
    return true;
}

// Everything else: let the built-in server's default handling take over
// (executes the .php file at that path, or 404s if nothing matches).
return false;
