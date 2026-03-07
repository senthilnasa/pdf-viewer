<?php
/**
 * Share link password entry form
 * Variables available: $siteName, $token, $slug, $sharePassError, $config
 */
$_formAction = htmlspecialchars($config['base_url'] . '/pdf/' . $slug . '?token=' . urlencode($token));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Required — <?= htmlspecialchars($siteName) ?></title>
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
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        .card {
            background: #1e293b;
            border: 1px solid rgba(255,255,255,.08);
            border-radius: 16px;
            padding: 2.5rem 2rem;
            width: 100%;
            max-width: 400px;
            text-align: center;
            box-shadow: 0 24px 64px rgba(0,0,0,.4);
        }
        .lock-icon {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
        }
        h1 {
            font-size: 1.35rem;
            font-weight: 700;
            color: #f1f5f9;
            margin-bottom: .5rem;
        }
        p.subtitle {
            font-size: .9rem;
            color: #94a3b8;
            margin-bottom: 1.75rem;
            line-height: 1.5;
        }
        .alert-error {
            background: rgba(239,68,68,.15);
            border: 1px solid rgba(239,68,68,.35);
            color: #fca5a5;
            border-radius: 8px;
            padding: .65rem .9rem;
            font-size: .85rem;
            margin-bottom: 1.25rem;
            text-align: left;
        }
        .input-wrap {
            position: relative;
            margin-bottom: 1.25rem;
        }
        .input-wrap input {
            width: 100%;
            padding: .75rem 2.75rem .75rem 1rem;
            background: #0f172a;
            border: 1.5px solid rgba(255,255,255,.12);
            border-radius: 8px;
            color: #f1f5f9;
            font-size: .95rem;
            outline: none;
            transition: border-color .18s;
        }
        .input-wrap input:focus { border-color: #4f46e5; }
        .toggle-vis {
            position: absolute;
            right: .75rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #64748b;
            padding: 0;
            line-height: 1;
        }
        .toggle-vis:hover { color: #94a3b8; }
        .btn-submit {
            width: 100%;
            padding: .75rem;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: .95rem;
            font-weight: 600;
            cursor: pointer;
            transition: opacity .18s, transform .18s;
        }
        .btn-submit:hover { opacity: .9; transform: translateY(-1px); }
        .site-badge {
            margin-top: 2rem;
            font-size: .78rem;
            color: #475569;
        }
        .site-badge a { color: #4f46e5; text-decoration: none; }
        .site-badge a:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="card">
    <div class="lock-icon">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
             stroke="#ffffff" width="26" height="26">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                  d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
        </svg>
    </div>

    <h1>Password Required</h1>
    <p class="subtitle">This document is password-protected.<br>Enter the password to continue.</p>

    <?php if (!empty($sharePassError)): ?>
        <div class="alert-error"><?= htmlspecialchars($sharePassError) ?></div>
    <?php endif; ?>

    <form method="POST" action="<?= $_formAction ?>">
        <div class="input-wrap">
            <input type="password" name="share_pass" id="share_pass"
                   placeholder="Enter password…" required autofocus
                   autocomplete="current-password">
            <button type="button" class="toggle-vis" onclick="
                var i=document.getElementById('share_pass');
                i.type=i.type==='password'?'text':'password';
            ">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" width="18" height="18">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                </svg>
            </button>
        </div>
        <button type="submit" class="btn-submit">Unlock Document</button>
    </form>

    <p class="site-badge">
        <a href="<?= htmlspecialchars($config['base_url']) ?>/"><?= htmlspecialchars($siteName) ?></a>
    </p>
</div>
</body>
</html>
