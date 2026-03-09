<?php
/**
 * Admin: Branding, Favicon & Viewer Header/Footer Manager
 */
define('ROOT', dirname(__DIR__));
require ROOT . '/includes/Database.php';
require ROOT . '/includes/Auth.php';
require ROOT . '/includes/PDF.php';
require ROOT . '/includes/Analytics.php';
require ROOT . '/includes/helpers.php';

$config   = bootstrap();
$auth->requireRole('admin');

$siteName   = getSetting('site_name', $config['site_name']);
$error      = '';
$success    = '';
$activeTab  = get('tab', 'header');   // header | footer | branding

$pdfManager = new PDF($config);
$allDocs    = $pdfManager->getAll(['status' => 'active']);
$selectedId = (int)get('pdf_id', 0);

// ── Load existing viewer prefs ───────────────────────────────────────────────
$globalPrefsRaw = Database::fetchScalar("SELECT value FROM settings WHERE `key` = 'viewer_global_prefs'");
$globalPrefs    = json_decode($globalPrefsRaw ?: '{}', true) ?: [];

$docPrefsRaw = $selectedId
    ? Database::fetchScalar("SELECT value FROM settings WHERE `key` = ?", ['viewer_prefs_' . $selectedId])
    : null;
$docPrefs = json_decode($docPrefsRaw ?: '{}', true) ?: [];

$defaultPrefs = [
    'show_header'     => true,
    'show_footer'     => true,
    'header_logo'     => '',
    'header_title'    => '',
    'header_subtitle' => '',
    'header_bg'       => '#1e293b',
    'header_color'    => '#ffffff',
    'footer_text'     => $siteName . ' · Powered by PDF Viewer',
    'footer_bg'       => '#f1f5f9',
    'footer_color'    => '#64748b',
    'show_page_num'   => true,
    'show_file_info'  => true,
    'show_share_btn'  => true,
    'show_download'   => true,
    'theme'           => 'dark',
];
$prefs = array_merge($defaultPrefs, $globalPrefs, $docPrefs);

// ── Current branding settings ────────────────────────────────────────────────
$branding = [
    'favicon_url'   => getSetting('favicon_url',  $config['base_url'] . '/assets/images/favicon.svg'),
    'app_icon_url'  => getSetting('app_icon_url',  $config['base_url'] . '/assets/images/favicon.svg'),
    'theme_color'   => getSetting('theme_color',   '#4f46e5'),
    'site_name'     => getSetting('site_name',     $config['site_name']),
    'tagline'       => getSetting('tagline',       ''),
    'login_bg'      => getSetting('login_bg',      '#0f172a'),
];

// ── POST handling ────────────────────────────────────────────────────────────
if (isPost()) {
    verifyCsrf();
    $isAjax     = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    $postAction = post('_tab', 'header');

    /* ---------- Header / Footer tab ---------- */
    if (in_array($postAction, ['header', 'footer'])) {
        $newPrefs = [
            'show_header'     => (bool)post('show_header', '0'),
            'show_footer'     => (bool)post('show_footer', '0'),
            'header_logo'     => trim(post('header_logo', '')),
            'header_title'    => trim(post('header_title', '')),
            'header_subtitle' => trim(post('header_subtitle', '')),
            'header_bg'       => trim(post('header_bg',    '#1e293b')),
            'header_color'    => trim(post('header_color', '#ffffff')),
            'footer_text'     => trim(post('footer_text',  $siteName)),
            'footer_bg'       => trim(post('footer_bg',    '#f1f5f9')),
            'footer_color'    => trim(post('footer_color', '#64748b')),
            'show_page_num'   => (bool)post('show_page_num',  '0'),
            'show_file_info'  => (bool)post('show_file_info', '0'),
            'show_share_btn'  => (bool)post('show_share_btn', '0'),
            'show_download'   => (bool)post('show_download',  '0'),
            'theme'           => in_array(post('theme'), ['dark','light','auto']) ? post('theme') : 'dark',
        ];

        // Logo upload
        if (!empty($_FILES['logo_file']['name'])) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($_FILES['logo_file']['tmp_name']);
            if (!in_array($mime, ['image/png','image/jpeg','image/gif','image/svg+xml','image/webp'])) {
                $error = 'Logo must be PNG, JPG, GIF, SVG or WebP.';
            } else {
                $ext  = pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION);
                $dest = ROOT . '/uploads/logo_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $dest)) {
                    $newPrefs['header_logo'] = $config['base_url'] . '/uploads/' . basename($dest);
                }
            }
        }

        if (!$error) {
            $json = json_encode($newPrefs);
            if ($selectedId) {
                Database::query(
                    "INSERT INTO settings (`key`, value, type) VALUES (?, ?, 'json') ON DUPLICATE KEY UPDATE value = ?",
                    ['viewer_prefs_' . $selectedId, $json, $json]
                );
                $success = 'Viewer style saved for this document.';
            } else {
                Database::query(
                    "INSERT INTO settings (`key`, value, type) VALUES ('viewer_global_prefs', ?, 'json') ON DUPLICATE KEY UPDATE value = ?",
                    [$json, $json]
                );
                $success = 'Global viewer style saved.';
            }
            $prefs = $newPrefs;
        }
        $activeTab = $postAction;
    }

    /* ---------- Branding tab ---------- */
    if ($postAction === 'branding') {
        $newThemeColor = trim(post('theme_color', '#4f46e5'));
        $newTagline    = trim(post('tagline',     ''));
        $newLoginBg    = trim(post('login_bg',    '#0f172a'));
        $newSiteName   = trim(post('site_name',   $siteName));

        setSetting('theme_color', $newThemeColor, 'string');
        setSetting('tagline',     $newTagline,     'string');
        setSetting('login_bg',    $newLoginBg,     'string');
        if ($newSiteName) setSetting('site_name', $newSiteName, 'string');

        // Favicon upload
        foreach ([
            'favicon_file' => ['favicon_url',  'favicon_'],
            'icon_file'    => ['app_icon_url',  'app_icon_'],
        ] as $field => [$settingKey, $prefix]) {
            if (!empty($_FILES[$field]['name'])) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($_FILES[$field]['tmp_name']);
                if (!in_array($mime, ['image/png','image/jpeg','image/gif','image/svg+xml','image/webp','image/x-icon','image/vnd.microsoft.icon'])) {
                    $error = 'Icon must be an image file.';
                } else {
                    $ext  = pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION) ?: 'png';
                    $dest = ROOT . '/uploads/' . $prefix . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES[$field]['tmp_name'], $dest)) {
                        setSetting($settingKey, $config['base_url'] . '/uploads/' . basename($dest), 'string');
                    }
                }
            }
        }

        if (!$error) {
            $success = 'Branding settings saved.';
            $branding = [
                'favicon_url'  => getSetting('favicon_url',  $config['base_url'] . '/assets/images/favicon.svg'),
                'app_icon_url' => getSetting('app_icon_url', $config['base_url'] . '/assets/images/favicon.svg'),
                'theme_color'  => getSetting('theme_color',  '#4f46e5'),
                'site_name'    => getSetting('site_name',    $config['site_name']),
                'tagline'      => getSetting('tagline',      ''),
                'login_bg'     => getSetting('login_bg',     '#0f172a'),
            ];
        }
        $activeTab = 'branding';
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => !$error, 'message' => $error ?: $success, 'reload' => !$error]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php require ROOT . '/admin/partials/head-meta.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Branding &amp; Viewer Style — <?= e($siteName) ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <script src="../assets/js/admin-ajax.js" defer></script>
    <style>
        /* ── Tabs ── */
        .tab-nav {
            display: flex; gap: 0; border-bottom: 2px solid var(--border);
            margin-bottom: 1.5rem;
        }
        .tab-btn {
            padding: .65rem 1.25rem; background: none; border: none;
            font-size: .88rem; font-weight: 600; color: var(--text-muted);
            cursor: pointer; border-bottom: 2px solid transparent;
            margin-bottom: -2px; display: flex; align-items: center; gap: .45rem;
            transition: color .15s;
        }
        .tab-btn:hover  { color: var(--text); }
        .tab-btn.active { color: var(--primary); border-bottom-color: var(--primary); }
        .tab-panel { display: none; }
        .tab-panel.active { display: block; }

        /* ── Live preview ── */
        .preview-wrap {
            border: 1px solid var(--border); border-radius: 10px;
            overflow: hidden; margin-bottom: 1.5rem;
            box-shadow: 0 2px 12px rgba(0,0,0,.06);
        }
        .preview-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: .75rem 1.25rem; transition: background .2s, color .2s;
        }
        .preview-footer {
            display: flex; align-items: center; justify-content: space-between;
            padding: .5rem 1.25rem; font-size: .82rem;
            transition: background .2s, color .2s;
        }
        .preview-canvas {
            height: 72px;
            background: repeating-linear-gradient(45deg,#e2e8f0,#e2e8f0 10px,#f8fafc 10px,#f8fafc 20px);
            display: flex; align-items: center; justify-content: center;
            color: #94a3b8; font-size: .82rem; letter-spacing: .03em;
        }
        .preview-logo { height: 34px; border-radius: 4px; object-fit: contain; }
        .preview-logo-ph {
            width: 34px; height: 34px; border-radius: 6px;
            background: rgba(255,255,255,.2); display: flex;
            align-items: center; justify-content: center;
        }
        .preview-actions { display: flex; gap: .4rem; font-size: .78rem; opacity: .85; }
        .preview-pill {
            background: rgba(255,255,255,.18); padding: .2rem .55rem;
            border-radius: 4px; white-space: nowrap;
        }

        /* ── Branding card ── */
        .icon-preview-row { display: flex; gap: 1.5rem; flex-wrap: wrap; align-items: flex-start; margin-bottom: 1.25rem; }
        .icon-preview-box {
            border: 1px solid var(--border); border-radius: 10px; padding: 1rem;
            text-align: center; min-width: 130px; background: var(--surface);
        }
        .icon-preview-box img, .icon-preview-box .icon-ph {
            width: 64px; height: 64px; border-radius: 12px; object-fit: contain;
            display: block; margin: 0 auto .5rem; border: 1px solid var(--border);
        }
        .icon-preview-box .icon-ph {
            background: #4f46e5; display: flex; align-items: center;
            justify-content: center;
        }
        .icon-label { font-size: .75rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }

        /* ── Color inputs ── */
        .color-row { display: flex; gap: .75rem; align-items: center; }
        .color-picker { width: 40px; height: 36px; padding: 2px; border: 1.5px solid var(--border); border-radius: 6px; cursor: pointer; }

        /* ── Grid ── */
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        @media (max-width: 640px) { .grid-2 { grid-template-columns: 1fr; } }

        /* ── Theme selector ── */
        .theme-cards { display: flex; gap: .75rem; flex-wrap: wrap; }
        .theme-card {
            border: 2px solid var(--border); border-radius: 8px; padding: .6rem .9rem;
            cursor: pointer; display: flex; align-items: center; gap: .5rem;
            font-size: .85rem; font-weight: 500; transition: border-color .15s;
        }
        .theme-card:has(input:checked) { border-color: var(--primary); background: rgba(79,70,229,.06); }
        .theme-card input { position: absolute; opacity: 0; pointer-events: none; }
        .theme-swatch { width: 22px; height: 22px; border-radius: 50%; border: 1.5px solid rgba(0,0,0,.1); }

        .section-divider { border: none; border-top: 1px solid var(--border); margin: 1.25rem 0; }
    </style>
</head>
<body class="admin-layout">

<?php require ROOT . '/admin/partials/sidebar.php'; ?>

<div class="admin-main">
    <?php require ROOT . '/admin/partials/topbar.php'; ?>

    <div class="admin-content">

        <div class="page-header">
            <div>
                <h1>Branding &amp; Viewer Style</h1>
                <p class="text-muted">Favicon, app icons, and viewer header/footer customisation</p>
            </div>
            <div style="display:flex;gap:.5rem;align-items:center">
                <form method="GET" style="display:flex;gap:.5rem;align-items:center">
                    <input type="hidden" name="tab" value="<?= e($activeTab) ?>">
                    <select name="pdf_id" class="form-control" onchange="this.form.submit()">
                        <option value="">Global (all documents)</option>
                        <?php foreach ($allDocs as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $selectedId===$d['id']?'selected':'' ?>><?= e(mb_substr($d['title'],0,38)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php if ($selectedId && ($doc = $pdfManager->getById($selectedId))): ?>
                <a href="<?= $config['base_url'] ?>/pdf/<?= e($doc['slug']) ?>" target="_blank" class="btn btn-outline">Preview PDF</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($error):   ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= $success ?></div><?php endif; ?>

        <?php if ($selectedId): ?>
        <div class="alert alert-info" style="margin-bottom:1rem">
            Editing per-document style for: <strong><?= e($pdfManager->getById($selectedId)['title'] ?? '') ?></strong>. Global settings are used as defaults.
        </div>
        <?php endif; ?>

        <!-- ── Tab navigation ── -->
        <div class="tab-nav">
            <button class="tab-btn <?= $activeTab==='header'   ? 'active' : '' ?>" onclick="switchTab('header')">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16"/></svg>
                Header
            </button>
            <button class="tab-btn <?= $activeTab==='footer'   ? 'active' : '' ?>" onclick="switchTab('footer')">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 14h16M4 18h16"/></svg>
                Footer
            </button>
            <button class="tab-btn <?= $activeTab==='branding' ? 'active' : '' ?>" onclick="switchTab('branding')">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>
                Branding &amp; Icons
            </button>
        </div>

        <!-- ═══════════════════════════════════════════════════════════ -->
        <!-- TAB: HEADER                                                -->
        <!-- ═══════════════════════════════════════════════════════════ -->
        <div class="tab-panel <?= $activeTab==='header' ? 'active' : '' ?>" id="panel-header">

            <!-- Live preview -->
            <div class="card" style="margin-bottom:1.5rem">
                <div class="card-header"><h3 class="card-title">Live Preview</h3><span class="text-muted" style="font-size:.8rem">Updates as you type</span></div>
                <div class="card-body" style="padding:0">
                    <div class="preview-wrap" style="margin:1rem;margin-bottom:.5rem">
                        <div class="preview-header" id="pvHeader"
                             style="background:<?= e($prefs['header_bg']) ?>;color:<?= e($prefs['header_color']) ?>">
                            <div style="display:flex;align-items:center;gap:.75rem">
                                <?php if ($prefs['header_logo']): ?>
                                <img src="<?= e($prefs['header_logo']) ?>" class="preview-logo" id="pvLogo">
                                <?php else: ?>
                                <div class="preview-logo-ph" id="pvLogo">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="18" height="18" stroke-opacity=".7"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                                </div>
                                <?php endif; ?>
                                <div>
                                    <div style="font-weight:700;font-size:.92rem" id="pvTitle"><?= e($prefs['header_title'] ?: $siteName) ?></div>
                                    <div style="font-size:.73rem;opacity:.72" id="pvSubtitle"><?= e($prefs['header_subtitle']) ?></div>
                                </div>
                            </div>
                            <div class="preview-actions">
                                <span id="pvFileInfo" class="preview-pill" style="<?= $prefs['show_file_info']?'':'display:none' ?>">12 pages · 2.4 MB</span>
                                <span id="pvShare" class="preview-pill" style="<?= $prefs['show_share_btn']?'':'display:none' ?>">Share</span>
                                <span id="pvDownload" class="preview-pill" style="<?= $prefs['show_download']?'':'display:none' ?>">Download</span>
                            </div>
                        </div>
                        <div class="preview-canvas">PDF Canvas Area</div>
                    </div>
                </div>
            </div>

            <form method="POST" enctype="multipart/form-data" style="max-width:760px" data-ajax>
                <?= csrfField() ?>
                <input type="hidden" name="_tab" value="header">
                <?php if ($selectedId): ?><input type="hidden" name="pdf_id" value="<?= $selectedId ?>"><?php endif; ?>

                <div class="card" style="margin-bottom:1.5rem">
                    <div class="card-header"><h3 class="card-title">Header Settings</h3></div>
                    <div class="card-body">
                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="show_header" value="1" <?= $prefs['show_header']?'checked':'' ?>
                                       onchange="el('pvHeader').style.display=this.checked?'':'none'">
                                <span>Show Header</span>
                            </label>
                        </div>
                        <hr class="section-divider">

                        <div class="grid-2">
                            <div class="form-group">
                                <label class="form-label">Header Title</label>
                                <input type="text" name="header_title" class="form-control"
                                       value="<?= e($prefs['header_title']) ?>"
                                       placeholder="<?= e($siteName) ?>"
                                       oninput="el('pvTitle').textContent=this.value||'<?= e(addslashes($siteName)) ?>'">
                                <small class="text-muted">Leave empty to use document title</small>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Subtitle / Tagline</label>
                                <input type="text" name="header_subtitle" class="form-control"
                                       value="<?= e($prefs['header_subtitle']) ?>"
                                       placeholder="e.g. Annual Report 2025"
                                       oninput="el('pvSubtitle').textContent=this.value">
                            </div>
                        </div>

                        <div class="grid-2">
                            <div class="form-group">
                                <label class="form-label">Background Color</label>
                                <div class="color-row">
                                    <input type="color" name="header_bg" class="color-picker"
                                           value="<?= e($prefs['header_bg']) ?>"
                                           oninput="syncColor(this,'pvHeader','background','hdrBgTxt')">
                                    <input type="text" id="hdrBgTxt" class="form-control" style="width:110px"
                                           value="<?= e($prefs['header_bg']) ?>"
                                           oninput="syncTextColor(this,'pvHeader','background','header_bg')">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Text Color</label>
                                <div class="color-row">
                                    <input type="color" name="header_color" class="color-picker"
                                           value="<?= e($prefs['header_color']) ?>"
                                           oninput="syncColor(this,'pvHeader','color','hdrColTxt')">
                                    <input type="text" id="hdrColTxt" class="form-control" style="width:110px"
                                           value="<?= e($prefs['header_color']) ?>"
                                           oninput="syncTextColor(this,'pvHeader','color','header_color')">
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Logo / Brand Image</label>
                            <?php if ($prefs['header_logo']): ?>
                            <div style="margin-bottom:.75rem;display:flex;align-items:center;gap:.75rem">
                                <img src="<?= e($prefs['header_logo']) ?>"
                                     style="height:44px;border-radius:6px;border:1px solid var(--border)">
                                <span class="text-muted" style="font-size:.8rem">Current logo</span>
                            </div>
                            <input type="hidden" name="header_logo" value="<?= e($prefs['header_logo']) ?>">
                            <?php endif; ?>
                            <input type="file" name="logo_file" class="form-control" accept="image/*"
                                   onchange="previewUpload(this,'pvLogo','img')">
                            <small class="text-muted">PNG, JPG, SVG or WebP — recommended height 36–50 px</small>
                        </div>

                        <hr class="section-divider">
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.5rem">
                            <label class="toggle-label">
                                <input type="checkbox" name="show_file_info" value="1" <?= $prefs['show_file_info']?'checked':'' ?>
                                       onchange="el('pvFileInfo').style.display=this.checked?'':'none'">
                                <span>Show file info (pages, size)</span>
                            </label>
                            <label class="toggle-label">
                                <input type="checkbox" name="show_share_btn" value="1" <?= $prefs['show_share_btn']?'checked':'' ?>
                                       onchange="el('pvShare').style.display=this.checked?'':'none'">
                                <span>Show Share button</span>
                            </label>
                            <label class="toggle-label">
                                <input type="checkbox" name="show_download" value="1" <?= $prefs['show_download']?'checked':'' ?>
                                       onchange="el('pvDownload').style.display=this.checked?'':'none'">
                                <span>Show Download button</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Canvas Theme card (lives on header tab for convenience) -->
                <div class="card" style="margin-bottom:1.5rem">
                    <div class="card-header"><h3 class="card-title">Canvas Background Theme</h3></div>
                    <div class="card-body">
                        <div class="theme-cards">
                            <?php foreach (['dark'=>['Dark','#0f172a'],'light'=>['Light','#f8fafc'],'auto'=>['Auto (system)','linear-gradient(90deg,#0f172a 50%,#f8fafc 50%)']] as $val => [$label, $bg]): ?>
                            <label class="theme-card">
                                <input type="radio" name="theme" value="<?= $val ?>" <?= $prefs['theme']===$val?'checked':'' ?>>
                                <span class="theme-swatch" style="background:<?= $bg ?>"></span>
                                <?= $label ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Pass footer values through so they are not lost on header save -->
                <input type="hidden" name="show_footer"  value="<?= $prefs['show_footer']  ? '1' : '0' ?>">
                <input type="hidden" name="footer_text"  value="<?= e($prefs['footer_text']) ?>">
                <input type="hidden" name="footer_bg"    value="<?= e($prefs['footer_bg']) ?>">
                <input type="hidden" name="footer_color" value="<?= e($prefs['footer_color']) ?>">
                <input type="hidden" name="show_page_num" value="<?= $prefs['show_page_num'] ? '1' : '0' ?>">

                <div style="display:flex;gap:.75rem">
                    <button type="submit" class="btn btn-primary">Save Header Settings</button>
                    <?php if ($selectedId): ?>
                    <a href="viewer-style.php?tab=header" class="btn btn-outline">Reset to Global</a>
                    <?php endif; ?>
                </div>
            </form>
        </div><!-- /panel-header -->


        <!-- ═══════════════════════════════════════════════════════════ -->
        <!-- TAB: FOOTER                                                -->
        <!-- ═══════════════════════════════════════════════════════════ -->
        <div class="tab-panel <?= $activeTab==='footer' ? 'active' : '' ?>" id="panel-footer">

            <!-- Live preview -->
            <div class="card" style="margin-bottom:1.5rem">
                <div class="card-header"><h3 class="card-title">Live Preview</h3><span class="text-muted" style="font-size:.8rem">Updates as you type</span></div>
                <div class="card-body" style="padding:0">
                    <div class="preview-wrap" style="margin:1rem;margin-bottom:.5rem">
                        <div class="preview-canvas">PDF Canvas Area</div>
                        <div class="preview-footer" id="pvFooter"
                             style="background:<?= e($prefs['footer_bg']) ?>;color:<?= e($prefs['footer_color']) ?>">
                            <span id="pvFooterText"><?= e($prefs['footer_text']) ?></span>
                            <span id="pvPageNum" style="<?= $prefs['show_page_num']?'':'display:none' ?>">Page 1 of 12</span>
                            <span><?= e($siteName) ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <form method="POST" enctype="multipart/form-data" style="max-width:760px" data-ajax>
                <?= csrfField() ?>
                <input type="hidden" name="_tab" value="footer">
                <?php if ($selectedId): ?><input type="hidden" name="pdf_id" value="<?= $selectedId ?>"><?php endif; ?>

                <div class="card" style="margin-bottom:1.5rem">
                    <div class="card-header"><h3 class="card-title">Footer Settings</h3></div>
                    <div class="card-body">
                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="show_footer" value="1" <?= $prefs['show_footer']?'checked':'' ?>
                                       onchange="el('pvFooter').style.display=this.checked?'':'none'">
                                <span>Show Footer</span>
                            </label>
                        </div>
                        <hr class="section-divider">
                        <div class="form-group">
                            <label class="form-label">Left-side Text</label>
                            <input type="text" name="footer_text" class="form-control"
                                   value="<?= e($prefs['footer_text']) ?>"
                                   oninput="el('pvFooterText').textContent=this.value">
                            <small class="text-muted">e.g. your company name or copyright notice</small>
                        </div>
                        <div class="grid-2">
                            <div class="form-group">
                                <label class="form-label">Background Color</label>
                                <div class="color-row">
                                    <input type="color" name="footer_bg" class="color-picker"
                                           value="<?= e($prefs['footer_bg']) ?>"
                                           oninput="syncColor(this,'pvFooter','background','ftrBgTxt')">
                                    <input type="text" id="ftrBgTxt" class="form-control" style="width:110px"
                                           value="<?= e($prefs['footer_bg']) ?>"
                                           oninput="syncTextColor(this,'pvFooter','background','footer_bg')">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Text Color</label>
                                <div class="color-row">
                                    <input type="color" name="footer_color" class="color-picker"
                                           value="<?= e($prefs['footer_color']) ?>"
                                           oninput="syncColor(this,'pvFooter','color','ftrColTxt')">
                                    <input type="text" id="ftrColTxt" class="form-control" style="width:110px"
                                           value="<?= e($prefs['footer_color']) ?>"
                                           oninput="syncTextColor(this,'pvFooter','color','footer_color')">
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="show_page_num" value="1" <?= $prefs['show_page_num']?'checked':'' ?>
                                       onchange="el('pvPageNum').style.display=this.checked?'':'none'">
                                <span>Show page number (centre)</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Pass header values through so they are not lost on footer save -->
                <input type="hidden" name="show_header"     value="<?= $prefs['show_header']     ? '1' : '0' ?>">
                <input type="hidden" name="header_title"    value="<?= e($prefs['header_title']) ?>">
                <input type="hidden" name="header_subtitle" value="<?= e($prefs['header_subtitle']) ?>">
                <input type="hidden" name="header_bg"       value="<?= e($prefs['header_bg']) ?>">
                <input type="hidden" name="header_color"    value="<?= e($prefs['header_color']) ?>">
                <input type="hidden" name="header_logo"     value="<?= e($prefs['header_logo']) ?>">
                <input type="hidden" name="show_file_info"  value="<?= $prefs['show_file_info']  ? '1' : '0' ?>">
                <input type="hidden" name="show_share_btn"  value="<?= $prefs['show_share_btn']  ? '1' : '0' ?>">
                <input type="hidden" name="show_download"   value="<?= $prefs['show_download']   ? '1' : '0' ?>">
                <input type="hidden" name="theme"           value="<?= e($prefs['theme']) ?>">

                <div style="display:flex;gap:.75rem">
                    <button type="submit" class="btn btn-primary">Save Footer Settings</button>
                    <?php if ($selectedId): ?>
                    <a href="viewer-style.php?tab=footer" class="btn btn-outline">Reset to Global</a>
                    <?php endif; ?>
                </div>
            </form>
        </div><!-- /panel-footer -->


        <!-- ═══════════════════════════════════════════════════════════ -->
        <!-- TAB: BRANDING & ICONS                                     -->
        <!-- ═══════════════════════════════════════════════════════════ -->
        <div class="tab-panel <?= $activeTab==='branding' ? 'active' : '' ?>" id="panel-branding">
            <form method="POST" enctype="multipart/form-data" style="max-width:760px" data-ajax>
                <?= csrfField() ?>
                <input type="hidden" name="_tab" value="branding">

                <!-- Icon previews -->
                <div class="card" style="margin-bottom:1.5rem">
                    <div class="card-header">
                        <h3 class="card-title">App Icons &amp; Favicon</h3>
                    </div>
                    <div class="card-body">
                        <div class="icon-preview-row">
                            <!-- Favicon preview -->
                            <div class="icon-preview-box">
                                <?php if ($branding['favicon_url']): ?>
                                <img src="<?= e($branding['favicon_url']) ?>" id="pvFavicon" alt="Favicon">
                                <?php else: ?>
                                <div class="icon-ph" id="pvFavicon">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="white" viewBox="0 0 24 24" width="32" height="32"><path d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                                </div>
                                <?php endif; ?>
                                <div class="icon-label" style="margin-top:.4rem">Favicon</div>
                                <div class="text-muted" style="font-size:.72rem;margin-top:.2rem">Browser tab icon</div>
                            </div>
                            <!-- App icon preview -->
                            <div class="icon-preview-box">
                                <?php if ($branding['app_icon_url']): ?>
                                <img src="<?= e($branding['app_icon_url']) ?>" id="pvAppIcon" alt="App Icon">
                                <?php else: ?>
                                <div class="icon-ph" id="pvAppIcon">
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="white" viewBox="0 0 24 24" width="32" height="32"><path d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                                </div>
                                <?php endif; ?>
                                <div class="icon-label" style="margin-top:.4rem">App Icon</div>
                                <div class="text-muted" style="font-size:.72rem;margin-top:.2rem">Home screen / PWA</div>
                            </div>
                            <!-- Browser bar preview -->
                            <div class="icon-preview-box" style="flex:1;min-width:200px;text-align:left">
                                <div class="icon-label" style="margin-bottom:.6rem">Browser Tab Preview</div>
                                <div style="display:flex;align-items:center;gap:.5rem;background:#f1f5f9;border-radius:8px 8px 0 0;padding:.45rem .75rem;border:1px solid #e2e8f0;border-bottom:none">
                                    <img src="<?= e($branding['favicon_url'] ?: $config['base_url'].'/assets/images/favicon.svg') ?>"
                                         id="pvTabIcon" style="width:16px;height:16px;border-radius:2px">
                                    <span id="pvTabTitle" style="font-size:.78rem;color:#374151;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:160px"><?= e($branding['site_name']) ?></span>
                                </div>
                                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:0 0 4px 4px;padding:.3rem .75rem">
                                    <div style="height:8px;background:#e2e8f0;border-radius:4px;width:70%"></div>
                                </div>
                                <div class="text-muted" style="font-size:.72rem;margin-top:.6rem">Appears in browser tabs and bookmarks</div>
                            </div>
                        </div>

                        <div class="grid-2">
                            <div class="form-group">
                                <label class="form-label">Favicon</label>
                                <input type="file" name="favicon_file" class="form-control" accept="image/*,.ico"
                                       onchange="previewUpload(this,'pvFavicon','img'); previewUpload(this,'pvTabIcon','img')">
                                <small class="text-muted">SVG or 32×32 PNG recommended. ICO, PNG, JPG accepted.</small>
                            </div>
                            <div class="form-group">
                                <label class="form-label">App Icon (PWA / Apple Touch)</label>
                                <input type="file" name="icon_file" class="form-control" accept="image/*"
                                       onchange="previewUpload(this,'pvAppIcon','img')">
                                <small class="text-muted">PNG 180×180 or 192×192 recommended.</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Site identity -->
                <div class="card" style="margin-bottom:1.5rem">
                    <div class="card-header"><h3 class="card-title">Site Identity</h3></div>
                    <div class="card-body">
                        <div class="grid-2">
                            <div class="form-group">
                                <label class="form-label">Application Name</label>
                                <input type="text" name="site_name" class="form-control"
                                       value="<?= e($branding['site_name']) ?>"
                                       oninput="el('pvTabTitle').textContent=this.value">
                                <small class="text-muted">Shown in browser tabs and the admin sidebar</small>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Tagline</label>
                                <input type="text" name="tagline" class="form-control"
                                       value="<?= e($branding['tagline']) ?>"
                                       placeholder="e.g. Document sharing made easy">
                            </div>
                        </div>
                        <div class="grid-2">
                            <div class="form-group">
                                <label class="form-label">Brand / Theme Color</label>
                                <div class="color-row">
                                    <input type="color" name="theme_color" class="color-picker"
                                           value="<?= e($branding['theme_color']) ?>"
                                           oninput="brandColTxt.value=this.value">
                                    <input type="text" id="brandColTxt" class="form-control" style="width:110px"
                                           value="<?= e($branding['theme_color']) ?>"
                                           oninput="this.previousElementSibling.value=this.value">
                                </div>
                                <small class="text-muted">Used for buttons, active states &amp; PWA theme-color</small>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Login Page Background</label>
                                <div class="color-row">
                                    <input type="color" name="login_bg" class="color-picker"
                                           value="<?= e($branding['login_bg']) ?>"
                                           oninput="loginBgTxt.value=this.value">
                                    <input type="text" id="loginBgTxt" class="form-control" style="width:110px"
                                           value="<?= e($branding['login_bg']) ?>"
                                           oninput="this.previousElementSibling.value=this.value">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div style="display:flex;gap:.75rem">
                    <button type="submit" class="btn btn-primary">Save Branding</button>
                </div>
            </form>
        </div><!-- /panel-branding -->

    </div><!-- /admin-content -->
</div><!-- /admin-main -->

<script>
/* ── helpers ── */
function el(id) { return document.getElementById(id); }

function syncColor(picker, previewId, prop, txtId) {
    el(previewId).style[prop] = picker.value;
    el(txtId).value = picker.value;
}
function syncTextColor(txt, previewId, prop, inputName) {
    if (/^#[0-9a-fA-F]{3,8}$|^rgb/.test(txt.value)) {
        el(previewId).style[prop] = txt.value;
        // keep linked color picker in sync
        const pickers = document.querySelectorAll(`input[name="${inputName}"]`);
        pickers.forEach(p => { if (p.type === 'color') p.value = txt.value; });
    }
}
function previewUpload(input, targetId, type) {
    const file = input.files[0];
    if (!file) return;
    const url = URL.createObjectURL(file);
    const t = el(targetId);
    if (type === 'img') {
        if (t.tagName === 'IMG') {
            t.src = url;
        } else {
            // replace placeholder div with img
            const img = document.createElement('img');
            img.src = url;
            img.id  = targetId;
            img.style.cssText = t.style.cssText;
            img.className = t.className;
            t.replaceWith(img);
        }
    }
}

/* ── tab switching ── */
function switchTab(name) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelector(`.tab-btn[onclick="switchTab('${name}')"]`).classList.add('active');
    el('panel-' + name).classList.add('active');
    // update URL without reload
    const u = new URL(location.href);
    u.searchParams.set('tab', name);
    history.replaceState(null, '', u);
}
</script>
</body>
</html>
