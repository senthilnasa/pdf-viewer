<?php
/**
 * Admin: Viewer Header & Footer Style Manager
 * Configure branding, header/footer appearance for the PDF viewer.
 */
define('ROOT', dirname(__DIR__));
require ROOT . '/includes/Database.php';
require ROOT . '/includes/Auth.php';
require ROOT . '/includes/PDF.php';
require ROOT . '/includes/Analytics.php';
require ROOT . '/includes/helpers.php';

$config = bootstrap();
$auth->requireRole('admin');

$siteName = getSetting('site_name', $config['site_name']);
$error    = '';
$success  = '';

$pdfManager = new PDF($config);
$allDocs    = $pdfManager->getAll(['status' => 'active']);
$selectedId = (int)get('pdf_id', 0);

// Load existing prefs
$globalPrefsRaw = Database::fetchScalar("SELECT value FROM settings WHERE `key` = 'viewer_global_prefs'");
$globalPrefs    = json_decode($globalPrefsRaw ?: '{}', true) ?: [];

$docPrefsRaw = $selectedId
    ? Database::fetchScalar("SELECT value FROM settings WHERE `key` = ?", ['viewer_prefs_' . $selectedId])
    : null;
$docPrefs = json_decode($docPrefsRaw ?: '{}', true) ?: [];

$prefs = array_merge([
    'show_header'     => true,
    'show_footer'     => true,
    'header_logo'     => '',
    'header_title'    => '',
    'header_subtitle' => '',
    'header_bg'       => '#1e293b',
    'header_color'    => '#ffffff',
    'footer_text'     => $siteName,
    'footer_bg'       => '#f1f5f9',
    'footer_color'    => '#64748b',
    'show_page_num'   => true,
    'show_file_info'  => true,
    'show_share_btn'  => true,
    'show_download'   => true,
    'theme'           => 'dark',
], $globalPrefs, $docPrefs);

if (isPost()) {
    verifyCsrf();

    $newPrefs = [
        'show_header'     => (bool)post('show_header', '0'),
        'show_footer'     => (bool)post('show_footer', '0'),
        'header_logo'     => trim(post('header_logo', '')),
        'header_title'    => trim(post('header_title', '')),
        'header_subtitle' => trim(post('header_subtitle', '')),
        'header_bg'       => trim(post('header_bg', '#1e293b')),
        'header_color'    => trim(post('header_color', '#ffffff')),
        'footer_text'     => trim(post('footer_text', $siteName)),
        'footer_bg'       => trim(post('footer_bg', '#f1f5f9')),
        'footer_color'    => trim(post('footer_color', '#64748b')),
        'show_page_num'   => (bool)post('show_page_num', '0'),
        'show_file_info'  => (bool)post('show_file_info', '0'),
        'show_share_btn'  => (bool)post('show_share_btn', '0'),
        'show_download'   => (bool)post('show_download', '0'),
        'theme'           => in_array(post('theme'), ['dark','light','auto']) ? post('theme') : 'dark',
    ];

    // Handle logo upload
    if (!empty($_FILES['logo_file']['name'])) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($_FILES['logo_file']['tmp_name']);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/svg+xml', 'image/webp'])) {
            $error = 'Logo must be an image (PNG, JPG, GIF, SVG, WebP).';
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
            // Per-document prefs
            Database::query(
                "INSERT INTO settings (`key`, value, type) VALUES (?, ?, 'json') ON DUPLICATE KEY UPDATE value = ?",
                ['viewer_prefs_' . $selectedId, $json, $json]
            );
            $success = 'Viewer style saved for this document!';
        } else {
            // Global prefs
            Database::query(
                "INSERT INTO settings (`key`, value, type) VALUES ('viewer_global_prefs', ?, 'json') ON DUPLICATE KEY UPDATE value = ?",
                [$json, $json]
            );
            $success = 'Global viewer style saved!';
        }

        $prefs = $newPrefs;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Viewer Style — <?= e($siteName) ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <style>
        .preview-header {
            display:flex; align-items:center; justify-content:space-between;
            padding:.75rem 1.25rem; border-radius:8px 8px 0 0;
            margin-bottom:0; transition: all .3s;
        }
        .preview-footer {
            display:flex; align-items:center; justify-content:space-between;
            padding:.5rem 1.25rem; border-radius:0 0 8px 8px;
            font-size:.82rem; transition: all .3s;
        }
        .preview-body {
            height:80px; background:repeating-linear-gradient(
                45deg, #e5e7eb, #e5e7eb 10px, #f9fafb 10px, #f9fafb 20px
            );
            display:flex; align-items:center; justify-content:center; color:#94a3b8; font-size:.85rem;
        }
        .preview-logo { width:32px; height:32px; border-radius:4px; object-fit:contain; }
        .preview-logo-placeholder { width:32px; height:32px; border-radius:4px; background:rgba(255,255,255,.2); display:flex; align-items:center; justify-content:center; }
        .color-row { display:flex; gap:1rem; align-items:center; }
        .color-picker { width:40px; height:36px; padding:2px; border:1.5px solid var(--border); border-radius:6px; cursor:pointer; }
        .section-divider { border:none; border-top:1px solid var(--border); margin:1.5rem 0; }
    </style>
</head>
<body class="admin-layout">

<?php require ROOT . '/admin/partials/sidebar.php'; ?>

<div class="admin-main">
    <?php require ROOT . '/admin/partials/topbar.php'; ?>

    <div class="admin-content">
        <div class="page-header">
            <div>
                <h1>Viewer Header &amp; Footer Manager</h1>
                <p class="text-muted">Customize the look and feel of the PDF viewer</p>
            </div>
            <div style="display:flex;gap:.75rem">
                <form method="GET" style="display:flex;gap:.5rem;align-items:center">
                    <select name="pdf_id" class="form-control" onchange="this.form.submit()">
                        <option value="">Global (all documents)</option>
                        <?php foreach ($allDocs as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $selectedId===$d['id']?'selected':'' ?>><?= e(mb_substr($d['title'],0,40)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php if ($selectedId): ?>
                <a href="<?= $config['base_url'] ?>/pdf/<?= e($pdfManager->getById($selectedId)['slug'] ?? '') ?>" target="_blank" class="btn btn-outline">Preview</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

        <?php if ($selectedId): ?>
        <div class="alert alert-info">Editing per-document style for: <strong><?= e($pdfManager->getById($selectedId)['title'] ?? '') ?></strong>. Global settings are used as defaults.</div>
        <?php else: ?>
        <div class="alert alert-info">Editing <strong>global</strong> viewer style. This applies to all documents unless overridden per document.</div>
        <?php endif; ?>

        <!-- Live Preview -->
        <div class="card" style="margin-bottom:1.5rem">
            <div class="card-header"><h3 class="card-title">Live Preview</h3></div>
            <div class="card-body" style="padding:0;overflow:hidden;border-radius:0 0 8px 8px">
                <div class="preview-header" id="previewHeader" style="background:<?= e($prefs['header_bg']) ?>;color:<?= e($prefs['header_color']) ?>">
                    <div style="display:flex;align-items:center;gap:.75rem">
                        <?php if ($prefs['header_logo']): ?>
                        <img src="<?= e($prefs['header_logo']) ?>" class="preview-logo" id="previewLogo">
                        <?php else: ?>
                        <div class="preview-logo-placeholder" id="previewLogo">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="18" height="18" stroke-opacity=".7"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                        </div>
                        <?php endif; ?>
                        <div>
                            <div style="font-weight:700;font-size:.95rem" id="previewTitle"><?= e($prefs['header_title'] ?: $siteName) ?></div>
                            <?php if ($prefs['header_subtitle']): ?>
                            <div style="font-size:.75rem;opacity:.75" id="previewSubtitle"><?= e($prefs['header_subtitle']) ?></div>
                            <?php else: ?>
                            <div style="font-size:.75rem;opacity:.75" id="previewSubtitle"></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:.5rem;font-size:.8rem;opacity:.85">
                        <span>12 pages &bull; 2.4 MB</span>
                        <span style="background:rgba(255,255,255,.15);padding:.25rem .6rem;border-radius:4px">Share</span>
                        <span style="background:rgba(255,255,255,.15);padding:.25rem .6rem;border-radius:4px">Download</span>
                    </div>
                </div>
                <div class="preview-body">PDF Canvas Area</div>
                <div class="preview-footer" id="previewFooter" style="background:<?= e($prefs['footer_bg']) ?>;color:<?= e($prefs['footer_color']) ?>">
                    <span id="previewFooterText"><?= e($prefs['footer_text']) ?></span>
                    <span>Page 1 of 12</span>
                    <span><?= e($siteName) ?></span>
                </div>
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data" style="max-width:760px">
            <?= csrfField() ?>
            <?php if ($selectedId): ?><input type="hidden" name="pdf_id" value="<?= $selectedId ?>"><?php endif; ?>

            <!-- Header Settings -->
            <div class="card" style="margin-bottom:1.5rem">
                <div class="card-header"><h3 class="card-title">Header</h3></div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="toggle-label">
                            <input type="checkbox" name="show_header" value="1" <?= $prefs['show_header']?'checked':'' ?> onchange="togglePreviewSection('previewHeader', this.checked)">
                            <span>Show Header</span>
                        </label>
                    </div>
                    <hr class="section-divider">
                    <div class="grid-2">
                        <div class="form-group">
                            <label class="form-label">Header Title</label>
                            <input type="text" name="header_title" class="form-control" value="<?= e($prefs['header_title']) ?>"
                                   oninput="document.getElementById('previewTitle').textContent = this.value || '<?= e($siteName) ?>'">
                            <small class="text-muted">Defaults to document title if empty</small>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Subtitle / Tagline</label>
                            <input type="text" name="header_subtitle" class="form-control" value="<?= e($prefs['header_subtitle']) ?>"
                                   oninput="document.getElementById('previewSubtitle').textContent = this.value">
                        </div>
                    </div>
                    <div class="grid-2">
                        <div class="form-group">
                            <label class="form-label">Background Color</label>
                            <div class="color-row">
                                <input type="color" name="header_bg" class="color-picker" value="<?= e($prefs['header_bg']) ?>"
                                       oninput="document.getElementById('previewHeader').style.background=this.value">
                                <input type="text" class="form-control" value="<?= e($prefs['header_bg']) ?>" oninput="this.previousElementSibling.value=this.value;document.getElementById('previewHeader').style.background=this.value" style="width:120px">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Text Color</label>
                            <div class="color-row">
                                <input type="color" name="header_color" class="color-picker" value="<?= e($prefs['header_color']) ?>"
                                       oninput="document.getElementById('previewHeader').style.color=this.value">
                                <input type="text" class="form-control" value="<?= e($prefs['header_color']) ?>" oninput="this.previousElementSibling.value=this.value;document.getElementById('previewHeader').style.color=this.value" style="width:120px">
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Logo</label>
                        <?php if ($prefs['header_logo']): ?>
                        <div style="margin-bottom:.75rem">
                            <img src="<?= e($prefs['header_logo']) ?>" style="height:48px;border-radius:6px;border:1px solid var(--border)">
                            <input type="hidden" name="header_logo" value="<?= e($prefs['header_logo']) ?>">
                        </div>
                        <?php endif; ?>
                        <input type="file" name="logo_file" class="form-control" accept="image/*">
                        <small class="text-muted">PNG, JPG, SVG or WebP. Recommended height: 40–60px.</small>
                    </div>
                    <div class="grid-2">
                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="show_file_info" value="1" <?= $prefs['show_file_info']?'checked':'' ?>>
                                <span>Show file info (page count, size)</span>
                            </label>
                        </div>
                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="show_share_btn" value="1" <?= $prefs['show_share_btn']?'checked':'' ?>>
                                <span>Show Share button</span>
                            </label>
                        </div>
                        <div class="form-group">
                            <label class="toggle-label">
                                <input type="checkbox" name="show_download" value="1" <?= $prefs['show_download']?'checked':'' ?>>
                                <span>Show Download button</span>
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer Settings -->
            <div class="card" style="margin-bottom:1.5rem">
                <div class="card-header"><h3 class="card-title">Footer</h3></div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="toggle-label">
                            <input type="checkbox" name="show_footer" value="1" <?= $prefs['show_footer']?'checked':'' ?> onchange="togglePreviewSection('previewFooter', this.checked)">
                            <span>Show Footer</span>
                        </label>
                    </div>
                    <hr class="section-divider">
                    <div class="form-group">
                        <label class="form-label">Footer Text (left side)</label>
                        <input type="text" name="footer_text" class="form-control" value="<?= e($prefs['footer_text']) ?>"
                               oninput="document.getElementById('previewFooterText').textContent = this.value">
                    </div>
                    <div class="grid-2">
                        <div class="form-group">
                            <label class="form-label">Background Color</label>
                            <div class="color-row">
                                <input type="color" name="footer_bg" class="color-picker" value="<?= e($prefs['footer_bg']) ?>"
                                       oninput="document.getElementById('previewFooter').style.background=this.value">
                                <input type="text" class="form-control" value="<?= e($prefs['footer_bg']) ?>" oninput="this.previousElementSibling.value=this.value;document.getElementById('previewFooter').style.background=this.value" style="width:120px">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Text Color</label>
                            <div class="color-row">
                                <input type="color" name="footer_color" class="color-picker" value="<?= e($prefs['footer_color']) ?>"
                                       oninput="document.getElementById('previewFooter').style.color=this.value">
                                <input type="text" class="form-control" value="<?= e($prefs['footer_color']) ?>" oninput="this.previousElementSibling.value=this.value;document.getElementById('previewFooter').style.color=this.value" style="width:120px">
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="toggle-label">
                            <input type="checkbox" name="show_page_num" value="1" <?= $prefs['show_page_num']?'checked':'' ?>>
                            <span>Show page number in footer</span>
                        </label>
                    </div>
                </div>
            </div>

            <!-- Viewer Theme -->
            <div class="card" style="margin-bottom:1.5rem">
                <div class="card-header"><h3 class="card-title">Canvas Theme</h3></div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Background Theme</label>
                        <div style="display:flex;gap:1rem">
                            <?php foreach (['dark' => 'Dark', 'light' => 'Light', 'auto' => 'Auto (system)'] as $val => $label): ?>
                            <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer">
                                <input type="radio" name="theme" value="<?= $val ?>" <?= $prefs['theme']===$val?'checked':'' ?>>
                                <?= $label ?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div style="display:flex;gap:.75rem">
                <button type="submit" class="btn btn-primary">Save Style</button>
                <?php if ($selectedId): ?>
                <a href="viewer-style.php" class="btn btn-outline">Reset to Global</a>
                <?php endif; ?>
                <?php if ($selectedId && $pdfManager->getById($selectedId)): ?>
                <a href="<?= $config['base_url'] ?>/pdf/<?= e($pdfManager->getById($selectedId)['slug']) ?>" target="_blank" class="btn btn-outline">Preview PDF</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<script>
function togglePreviewSection(id, show) {
    document.getElementById(id).style.display = show ? '' : 'none';
}
</script>
</body>
</html>
