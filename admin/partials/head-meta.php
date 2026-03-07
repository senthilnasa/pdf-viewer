<?php
/**
 * Shared <head> meta tags: favicon, PWA icons, theme colour.
 * Include inside every page's <head> after <meta charset>.
 */
$_faviconUrl  = getSetting('favicon_url', $config['base_url'] . '/assets/images/favicon.svg');
$_iconUrl     = getSetting('app_icon_url', $config['base_url'] . '/assets/images/favicon.svg');
$_appName     = getSetting('site_name', $config['site_name'] ?? 'PDF Viewer');
$_themeColor  = getSetting('theme_color', '#4f46e5');
?>
<link rel="icon" type="image/svg+xml" href="<?= e($_faviconUrl) ?>">
<link rel="shortcut icon" href="<?= e($_faviconUrl) ?>">
<link rel="apple-touch-icon" href="<?= e($_iconUrl) ?>">
<meta name="application-name" content="<?= e($_appName) ?>">
<meta name="theme-color" content="<?= e($_themeColor) ?>">
<meta name="apple-mobile-web-app-title" content="<?= e($_appName) ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
