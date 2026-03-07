<?php
/**
 * Share link error page (invalid / expired / limit reached)
 * Variables available: $siteName, $config
 */
$_logoUrl    = getSetting('header_logo', '');
$_brandColor = getSetting('theme_color', '#4f46e5');
$_homeUrl    = htmlspecialchars($config['base_url'] . '/');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Link Unavailable — <?= htmlspecialchars($siteName) ?></title>
    <?php
    try {
        if (file_exists(ROOT . '/admin/partials/head-meta.php')) {
            require ROOT . '/admin/partials/head-meta.php';
        }
    } catch (Throwable $_) {}
    ?>
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
        .site-header {
            background: #1e293b;
            border-bottom: 1px solid rgba(255,255,255,.07);
            padding: 0 2rem;
            height: 56px;
            display: flex;
            align-items: center;
            flex-shrink: 0;
        }
        .site-header a {
            display: flex; align-items: center; gap: .6rem;
            text-decoration: none; color: #f1f5f9;
            font-size: 1rem; font-weight: 700;
        }
        .site-header img { height: 28px; width: auto; border-radius: 4px; }
        .logo-fallback {
            width: 28px; height: 28px;
            background: <?= htmlspecialchars($_brandColor) ?>;
            border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
        }
        .main {
            flex: 1; display: flex; flex-direction: column;
            align-items: center; justify-content: center; padding: 3rem 2rem;
        }
        .error-card { text-align: center; max-width: 480px; width: 100%; }
        .error-graphic {
            position: relative; display: inline-block; margin-bottom: 1.5rem;
        }
        .error-graphic::before, .error-graphic::after {
            content: ''; position: absolute; border-radius: 50%; opacity: .1; pointer-events: none;
        }
        .error-graphic::before { width: 200px; height: 200px; background: #ef4444; top: 50%; left: 50%; transform: translate(-50%,-50%); }
        .error-graphic::after  { width: 280px; height: 280px; background: #dc2626; top: 50%; left: 50%; transform: translate(-50%,-50%); }
        .error-icon-wrap {
            width: 80px; height: 80px;
            background: rgba(239,68,68,.15);
            border: 2px solid rgba(239,68,68,.3);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center; margin: 0 auto;
        }
        .error-code {
            font-size: clamp(5rem, 18vw, 7.5rem); font-weight: 900; line-height: 1;
            background: linear-gradient(135deg, #dc2626 0%, #ef4444 100%);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
            letter-spacing: -.04em; margin-bottom: .4rem;
            animation: float 3s ease-in-out infinite;
        }
        @keyframes float { 0%,100% { transform: translateY(0); } 50% { transform: translateY(-10px); } }
        .error-title { font-size: 1.5rem; font-weight: 700; color: #f1f5f9; margin: 1.25rem 0 .6rem; }
        .error-message { font-size: .95rem; color: #94a3b8; line-height: 1.6; margin-bottom: 2rem; }
        .error-actions { display: flex; gap: .75rem; justify-content: center; flex-wrap: wrap; }
        .btn {
            display: inline-flex; align-items: center; gap: .45rem;
            padding: .65rem 1.4rem; border-radius: 8px; font-size: .9rem;
            font-weight: 600; text-decoration: none; border: none; cursor: pointer; transition: all .18s;
        }
        .btn-primary { background: #4f46e5; color: #fff; }
        .btn-primary:hover { background: #4338ca; transform: translateY(-1px); }
        .btn-outline { background: transparent; color: #94a3b8; border: 1.5px solid rgba(255,255,255,.12); }
        .btn-outline:hover { border-color: rgba(255,255,255,.3); color: #e2e8f0; transform: translateY(-1px); }
        .site-footer {
            background: #1e293b; border-top: 1px solid rgba(255,255,255,.07);
            padding: 1rem 2rem; text-align: center; font-size: .8rem; color: #475569; flex-shrink: 0;
        }
        .site-footer a { color: #4f46e5; text-decoration: none; }
        .site-footer a:hover { text-decoration: underline; }
    </style>
</head>
<body>

    <header class="site-header">
        <a href="<?= $_homeUrl ?>">
            <?php if ($_logoUrl): ?>
                <img src="<?= htmlspecialchars($_logoUrl) ?>" alt="<?= htmlspecialchars($siteName) ?>">
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

    <main class="main">
        <div class="error-card">
            <div class="error-graphic">
                <div class="error-code">403</div>
            </div>

            <h1 class="error-title">Link Unavailable</h1>
            <p class="error-message">
                This share link is invalid, has expired, or has reached its maximum view limit.
                Please request a new link from the document owner.
            </p>

            <div class="error-actions">
                <a href="<?= $_homeUrl ?>" class="btn btn-primary">
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

    <footer class="site-footer">
        &copy; <?= date('Y') ?> <a href="<?= $_homeUrl ?>"><?= htmlspecialchars($siteName) ?></a>
        &nbsp;&mdash;&nbsp; Error 403 &middot; Link Unavailable
    </footer>

</body>
</html>
