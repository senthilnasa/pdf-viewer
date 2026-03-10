<?php
/**
 * Custom 403 — Access Forbidden
 */
http_response_code(403);

$siteName   = 'PDF Viewer';
$faviconUrl = '';
$logoUrl    = '';
$homeUrl    = '/';
$loginUrl   = '/admin/login.php';
$brandColor = '#4f46e5';
try {
    if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
    // Only bootstrap if helpers not already loaded (avoids double-bootstrap when
    // this file is included from Auth::requireRole mid-request)
    if (!function_exists('getSetting') &&
        file_exists(ROOT . '/includes/helpers.php') &&
        file_exists(ROOT . '/config/app.php')) {
        require_once ROOT . '/includes/Database.php';
        require_once ROOT . '/includes/Auth.php';
        require_once ROOT . '/includes/helpers.php';
        $config = bootstrap();
    }
    if (function_exists('getSetting')) {
        global $config;
        $_c         = $config ?? [];
        $siteName   = getSetting('site_name',   $_c['site_name']  ?? 'PDF Viewer');
        $faviconUrl = getSetting('favicon_url', ($_c['base_url'] ?? '') . '/assets/images/favicon.svg');
        $logoUrl    = getSetting('header_logo', '');
        $homeUrl    = ($_c['base_url'] ?? '') . '/';
        $loginUrl   = ($_c['base_url'] ?? '') . '/admin/login.php';
        $brandColor = getSetting('theme_color', '#4f46e5');
    }
} catch (Throwable $_) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 — Access Forbidden | <?= htmlspecialchars($siteName) ?></title>
    <?php if ($faviconUrl): ?>
    <link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars($faviconUrl) ?>">
    <?php else: ?>
    <link rel="icon" type="image/svg+xml" href="/assets/images/favicon.svg">
    <?php endif; ?>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ── Site Header ── */
        .site-header {
            background: #1e293b;
            border-bottom: 1px solid rgba(255,255,255,.07);
            padding: 0 2rem;
            height: 56px;
            display: flex;
            align-items: center;
            gap: .75rem;
            flex-shrink: 0;
        }
        .site-header a {
            display: flex;
            align-items: center;
            gap: .6rem;
            text-decoration: none;
            color: #f1f5f9;
            font-size: 1rem;
            font-weight: 700;
        }
        .site-header img { height: 28px; width: auto; border-radius: 4px; }
        .site-header .logo-fallback {
            width: 28px; height: 28px;
            background: <?= htmlspecialchars($brandColor) ?>;
            border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
        }

        /* ── Main Content ── */
        .main {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 3rem 2rem;
        }
        .error-card { text-align: center; max-width: 480px; width: 100%; }

        .error-code {
            font-size: clamp(6rem, 20vw, 9rem);
            font-weight: 900;
            line-height: 1;
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 50%, #ef4444 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -.04em;
            margin-bottom: .5rem;
            animation: float 3s ease-in-out infinite;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50%       { transform: translateY(-10px); }
        }
        .error-graphic {
            position: relative;
            display: inline-block;
            margin-bottom: 1.5rem;
        }
        .error-graphic::before, .error-graphic::after {
            content: ''; position: absolute; border-radius: 50%;
            opacity: .1; pointer-events: none;
        }
        .error-graphic::before {
            width: 260px; height: 260px; background: #dc2626;
            top: 50%; left: 50%; transform: translate(-50%, -50%);
        }
        .error-graphic::after {
            width: 340px; height: 340px; background: #ef4444;
            top: 50%; left: 50%; transform: translate(-50%, -50%);
        }
        .error-icon { margin-bottom: .75rem; opacity: .5; }
        .error-title { font-size: 1.5rem; font-weight: 700; color: #f1f5f9; margin-bottom: .6rem; }
        .error-message { font-size: .95rem; color: #94a3b8; line-height: 1.6; margin-bottom: 2rem; }
        .error-actions { display: flex; gap: .75rem; justify-content: center; flex-wrap: wrap; }
        .btn {
            display: inline-flex; align-items: center; gap: .45rem;
            padding: .65rem 1.4rem; border-radius: 8px; font-size: .9rem;
            font-weight: 600; text-decoration: none; border: none; cursor: pointer; transition: all .18s;
        }
        .btn-primary { background: #4f46e5; color: #fff; }
        .btn-primary:hover { background: #4338ca; transform: translateY(-1px); }
        .btn-danger { background: #dc2626; color: #fff; }
        .btn-danger:hover { background: #b91c1c; transform: translateY(-1px); }
        .btn-outline { background: transparent; color: #94a3b8; border: 1.5px solid rgba(255,255,255,.12); }
        .btn-outline:hover { border-color: rgba(255,255,255,.3); color: #e2e8f0; transform: translateY(-1px); }

        /* ── Site Footer ── */
        .site-footer {
            background: #1e293b;
            border-top: 1px solid rgba(255,255,255,.07);
            padding: 1rem 2rem;
            text-align: center;
            font-size: .8rem;
            color: #475569;
            flex-shrink: 0;
        }
        .site-footer a { color: #4f46e5; text-decoration: none; }
        .site-footer a:hover { text-decoration: underline; }
    </style>
</head>
<body>

    <!-- Header -->
    <header class="site-header">
        <a href="<?= htmlspecialchars($homeUrl) ?>">
            <?php if ($logoUrl): ?>
                <img src="<?= htmlspecialchars($logoUrl) ?>" alt="<?= htmlspecialchars($siteName) ?>">
            <?php else: ?>
                <div class="logo-fallback">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                         stroke="#fff" width="16" height="16">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
            <?php endif; ?>
            <?= htmlspecialchars($siteName) ?>
        </a>
    </header>

    <!-- Main -->
    <main class="main">
        <div class="error-card">
            <div class="error-graphic">
                <div class="error-code">403</div>
            </div>

            <div class="error-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                     stroke="#94a3b8" width="40" height="40">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
            </div>

            <h1 class="error-title">Access Forbidden</h1>
            <p class="error-message">
                <?php if (!empty($errorMessage)): ?>
                    <?= htmlspecialchars($errorMessage) ?>
                <?php else: ?>
                    You don't have permission to access this resource.
                    If you believe this is a mistake, please sign in or contact the administrator.
                <?php endif; ?>
            </p>

            <div class="error-actions">
                <a href="<?= htmlspecialchars($loginUrl) ?>" class="btn btn-danger">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor" width="16" height="16">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/>
                    </svg>
                    Sign In
                </a>
                <a href="<?= htmlspecialchars($homeUrl) ?>" class="btn btn-primary">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor" width="16" height="16">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                    </svg>
                    Go Home
                </a>
                <button onclick="history.back()" class="btn btn-outline">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor" width="16" height="16">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                    Go Back
                </button>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="site-footer">
        &copy; <?= date('Y') ?> <a href="<?= htmlspecialchars($homeUrl) ?>"><?= htmlspecialchars($siteName) ?></a>
        &nbsp;&mdash;&nbsp; Error 403 &middot; Access Forbidden
    </footer>

</body>
</html>
