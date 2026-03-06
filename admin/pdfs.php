<?php
define('ROOT', dirname(__DIR__));
require ROOT . '/includes/Database.php';
require ROOT . '/includes/Auth.php';
require ROOT . '/includes/PDF.php';
require ROOT . '/includes/Analytics.php';
require ROOT . '/includes/helpers.php';

$config = bootstrap();
$auth->requireRole('editor');

$user       = $auth->currentUser();
$pdfManager = new PDF($config);
$action     = get('action', 'list');
// $id may arrive via GET (edit/share links) or POST (delete/update hidden field)
$id         = (int)(get('id', 0) ?: post('id', 0));
$siteName   = getSetting('site_name', $config['site_name']);

$error   = '';
$success = '';

// ------------------------------------------------------------------
// Handle POST actions
// ------------------------------------------------------------------
if (isPost()) {
    verifyCsrf();
    $postAction = post('_action');

    // Upload new PDF
    if ($postAction === 'upload') {
        $title       = trim(post('title'));
        $description = trim(post('description'));
        $visibility  = post('visibility', 'public');
        $metaTitle   = trim(post('meta_title'));
        $metaDesc    = trim(post('meta_desc'));
        $customSlug  = trim(post('slug'));
        $enableDownload = (int)post('enable_download', 1);

        if (!$title) {
            $error = 'Title is required.';
        } elseif (empty($_FILES['pdf_file']['name'])) {
            $error = 'Please select a PDF file.';
        } else {
            $upload = $pdfManager->handleUpload($_FILES['pdf_file']);
            if (!$upload['success']) {
                $error = $upload['error'];
            } else {
                $slug = $customSlug ?: $pdfManager->generateSlug($title);
                $newId = $pdfManager->create([
                    'title'          => $title,
                    'description'    => $description,
                    'slug'           => $slug,
                    'file_path'      => $upload['file_path'],
                    'file_size'      => $upload['file_size'],
                    'visibility'     => in_array($visibility, ['public','private']) ? $visibility : 'public',
                    'meta_title'     => $metaTitle ?: $title,
                    'meta_desc'      => $metaDesc,
                    'enable_download'=> $enableDownload,
                    'created_by'     => $user['id'],
                ]);
                if ($newId) {
                    $success = 'PDF uploaded successfully!';
                    $action  = 'list';
                } else {
                    $error = 'Slug already in use. Please choose a different slug.';
                    @unlink($upload['file_path']);
                }
            }
        }
    }

    // Update PDF metadata
    if ($postAction === 'update' && $id) {
        $data = [
            'title'          => trim(post('title')),
            'description'    => trim(post('description')),
            'slug'           => trim(post('slug')),
            'visibility'     => post('visibility', 'public'),
            'status'         => post('status', 'active'),
            'meta_title'     => trim(post('meta_title')),
            'meta_desc'      => trim(post('meta_desc')),
            'enable_download'=> (int)post('enable_download', 1),
        ];

        // Handle file replacement
        if (!empty($_FILES['pdf_file']['name'])) {
            $upload = $pdfManager->handleUpload($_FILES['pdf_file']);
            if (!$upload['success']) {
                $error = $upload['error'];
            } else {
                $pdfManager->replaceFile($id, $upload['file_path'], $upload['file_size']);
            }
        }

        if (!$error) {
            if ($pdfManager->update($id, $data)) {
                $success = 'PDF updated successfully!';
                $action  = 'list';
            } else {
                $error = 'Update failed. Slug may already be in use.';
            }
        }
    }

    // Delete PDF
    if ($postAction === 'delete' && $id) {
        $auth->requireRole('admin');
        $pdfManager->delete($id);
        $success = 'PDF deleted.';
        $action  = 'list';
    }

    // Create share link
    if ($postAction === 'create_share' && $id) {
        $opts = [
            'expires_days' => (int)post('expires_days', 0) ?: null,
            'max_views'    => (int)post('max_views', 0) ?: null,
            'password'     => post('link_password') ?: null,
        ];
        $token = $pdfManager->createShareLink($id, $user['id'], array_filter($opts, fn($v) => $v !== null));
        $success = 'Share link created! Token: ' . $token;
    }

    // Delete share link
    if ($postAction === 'delete_share') {
        $linkId = (int)post('link_id');
        $pdfManager->deleteShareLink($linkId);
        $success = 'Share link removed.';
    }
}

// ------------------------------------------------------------------
// Load data for views
// ------------------------------------------------------------------
$pdf = null;
if (in_array($action, ['edit', 'share']) && $id) {
    $pdf = $pdfManager->getById($id);
    if (!$pdf) { $error = 'Document not found.'; $action = 'list'; }
}

$docs = ($action === 'list') ? $pdfManager->getAll(['search' => get('search', '')]) : [];
$shareLinks = ($action === 'share' && $pdf) ? $pdfManager->getShareLinks($id) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PDF Manager — <?= e($siteName) ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="admin-layout">

<?php require ROOT . '/admin/partials/sidebar.php'; ?>

<div class="admin-main">
    <?php require ROOT . '/admin/partials/topbar.php'; ?>

    <div class="admin-content">

    <?php if ($error): ?>
        <div class="alert alert-error"><?= e($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <!-- ======================== LIST ======================== -->
    <?php if ($action === 'list'): ?>
    <div class="page-header">
        <div><h1>PDF Manager</h1><p class="text-muted"><?= number_format(count($docs)) ?> documents</p></div>
        <a href="?action=upload" class="btn btn-primary">+ Upload PDF</a>
    </div>

    <div class="card">
        <div class="card-header">
            <form method="GET" style="display:flex;gap:.75rem;align-items:center">
                <input type="hidden" name="action" value="list">
                <input type="text" name="search" class="form-control" placeholder="Search title or slug..." value="<?= e(get('search')) ?>" style="max-width:280px">
                <button type="submit" class="btn btn-outline">Search</button>
            </form>
        </div>
        <div class="card-body" style="padding:0">
            <table class="table">
                <thead><tr><th>Title / Slug</th><th>Visibility</th><th>Status</th><th>Views</th><th>Size</th><th>Created</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($docs as $doc): ?>
                <tr>
                    <td>
                        <div><strong><?= e($doc['title']) ?></strong></div>
                        <div class="text-muted" style="font-size:.8rem">/pdf/<?= e($doc['slug']) ?></div>
                    </td>
                    <td><span class="badge badge-<?= $doc['visibility'] === 'public' ? 'success' : 'warning' ?>"><?= e($doc['visibility']) ?></span></td>
                    <td><span class="badge badge-<?= $doc['status'] === 'active' ? 'primary' : 'secondary' ?>"><?= e($doc['status']) ?></span></td>
                    <td><?= number_format($doc['total_views']) ?></td>
                    <td><?= $pdfManager->humanFileSize($doc['file_size']) ?></td>
                    <td><?= formatDate($doc['created_at']) ?></td>
                    <td style="white-space:nowrap">
                        <a href="../pdf/<?= e($doc['slug']) ?>" target="_blank" class="btn btn-xs btn-outline">View</a>
                        <a href="?action=edit&id=<?= $doc['id'] ?>" class="btn btn-xs btn-outline">Edit</a>
                        <a href="?action=share&id=<?= $doc['id'] ?>" class="btn btn-xs btn-outline">Share</a>
                        <a href="analytics.php?pdf_id=<?= $doc['id'] ?>" class="btn btn-xs btn-outline">Stats</a>
                        <?php if ($user['role'] === 'admin'): ?>
                        <form method="POST" style="display:inline" onsubmit="return confirm('Delete this PDF permanently?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="_action" value="delete">
                            <input type="hidden" name="id" value="<?= $doc['id'] ?>">
                            <button type="submit" class="btn btn-xs btn-danger">Delete</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($docs)): ?><tr><td colspan="7" class="text-center text-muted">No PDFs found.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ======================== UPLOAD ======================== -->
    <?php elseif ($action === 'upload'): ?>
    <div class="page-header">
        <div><h1>Upload PDF</h1></div>
        <a href="pdfs.php" class="btn btn-outline">&larr; Back</a>
    </div>
    <div class="card" style="max-width:700px">
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="_action" value="upload">

                <div class="form-group">
                    <label class="form-label">PDF File *</label>
                    <input type="file" name="pdf_file" class="form-control" accept=".pdf" required>
                    <small class="text-muted">Max: <?= $pdfManager->humanFileSize($config['max_upload_size']) ?></small>
                </div>
                <div class="form-group">
                    <label class="form-label">Title *</label>
                    <input type="text" name="title" class="form-control" required id="pdfTitle">
                </div>
                <div class="form-group">
                    <label class="form-label">Slug (URL-friendly name)</label>
                    <div style="display:flex;align-items:center;gap:.5rem">
                        <span class="text-muted">/pdf/</span>
                        <input type="text" name="slug" class="form-control" id="pdfSlug" placeholder="auto-generated">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3"></textarea>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Visibility</label>
                        <select name="visibility" class="form-control">
                            <option value="public">Public</option>
                            <option value="private">Private (login required)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Allow Download</label>
                        <select name="enable_download" class="form-control">
                            <option value="1">Yes</option>
                            <option value="0">No</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Meta Title (SEO)</label>
                    <input type="text" name="meta_title" class="form-control">
                </div>
                <div class="form-group">
                    <label class="form-label">Meta Description (SEO)</label>
                    <textarea name="meta_desc" class="form-control" rows="2"></textarea>
                </div>
                <div style="display:flex;gap:.75rem">
                    <button type="submit" class="btn btn-primary">Upload PDF</button>
                    <a href="pdfs.php" class="btn btn-outline">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <!-- ======================== EDIT ======================== -->
    <?php elseif ($action === 'edit' && $pdf): ?>
    <div class="page-header">
        <div><h1>Edit PDF</h1><p class="text-muted"><?= e($pdf['title']) ?></p></div>
        <a href="pdfs.php" class="btn btn-outline">&larr; Back</a>
    </div>
    <div class="card" style="max-width:700px">
        <div class="card-body">
            <form method="POST" action="?action=edit&id=<?= $id ?>" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="_action" value="update">
                <input type="hidden" name="id" value="<?= $id ?>">

                <div class="form-group">
                    <label class="form-label">Replace PDF File (optional)</label>
                    <input type="file" name="pdf_file" class="form-control" accept=".pdf">
                    <small class="text-muted">Current: <?= e(basename($pdf['file_path'])) ?> (<?= $pdfManager->humanFileSize($pdf['file_size']) ?>). Leave empty to keep existing file.</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Title *</label>
                    <input type="text" name="title" class="form-control" value="<?= e($pdf['title']) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Slug</label>
                    <div style="display:flex;align-items:center;gap:.5rem">
                        <span class="text-muted">/pdf/</span>
                        <input type="text" name="slug" class="form-control" value="<?= e($pdf['slug']) ?>" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3"><?= e($pdf['description']) ?></textarea>
                </div>
                <div class="grid-2">
                    <div class="form-group">
                        <label class="form-label">Visibility</label>
                        <select name="visibility" class="form-control">
                            <option value="public" <?= $pdf['visibility']==='public'?'selected':'' ?>>Public</option>
                            <option value="private" <?= $pdf['visibility']==='private'?'selected':'' ?>>Private</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-control">
                            <option value="active" <?= $pdf['status']==='active'?'selected':'' ?>>Active</option>
                            <option value="inactive" <?= $pdf['status']==='inactive'?'selected':'' ?>>Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Allow Download</label>
                    <select name="enable_download" class="form-control">
                        <option value="1" <?= $pdf['enable_download']?'selected':'' ?>>Yes</option>
                        <option value="0" <?= !$pdf['enable_download']?'selected':'' ?>>No</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Meta Title (SEO)</label>
                    <input type="text" name="meta_title" class="form-control" value="<?= e($pdf['meta_title']) ?>">
                </div>
                <div class="form-group">
                    <label class="form-label">Meta Description (SEO)</label>
                    <textarea name="meta_desc" class="form-control" rows="2"><?= e($pdf['meta_desc']) ?></textarea>
                </div>
                <div style="display:flex;gap:.75rem">
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                    <a href="pdfs.php" class="btn btn-outline">Cancel</a>
                    <a href="?action=share&id=<?= $id ?>" class="btn btn-outline">Manage Share Links</a>
                </div>
            </form>
        </div>
    </div>

    <!-- ======================== SHARE LINKS ======================== -->
    <?php elseif ($action === 'share' && $pdf): ?>
    <div class="page-header">
        <div><h1>Share Links</h1><p class="text-muted"><?= e($pdf['title']) ?></p></div>
        <a href="pdfs.php" class="btn btn-outline">&larr; Back</a>
    </div>

    <div class="grid-2" style="align-items:start">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Create Share Link</h3></div>
            <div class="card-body">
                <form method="POST" action="?action=share&id=<?= $id ?>">
                    <?= csrfField() ?>
                    <input type="hidden" name="_action" value="create_share">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <div class="form-group">
                        <label class="form-label">Expires in (days, 0 = never)</label>
                        <input type="number" name="expires_days" class="form-control" min="0" value="30">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Max Views (0 = unlimited)</label>
                        <input type="number" name="max_views" class="form-control" min="0" value="0">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Password (optional)</label>
                        <input type="password" name="link_password" class="form-control">
                    </div>
                    <button type="submit" class="btn btn-primary">Generate Link</button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">Existing Links</h3></div>
            <div class="card-body" style="padding:0">
                <table class="table">
                    <thead><tr><th>Token</th><th>Views</th><th>Max</th><th>Expires</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($shareLinks as $link):
                        $url = $config['base_url'] . '/pdf/' . e($pdf['slug']) . '?token=' . $link['token'];
                    ?>
                    <tr>
                        <td><input type="text" class="form-control" style="font-size:.75rem" value="<?= $url ?>" readonly onclick="this.select()"></td>
                        <td><?= $link['view_count'] ?></td>
                        <td><?= $link['max_views'] ?? '∞' ?></td>
                        <td><?= $link['expires_at'] ? formatDate($link['expires_at']) : 'Never' ?></td>
                        <td>
                            <form method="POST" action="?action=share&id=<?= $id ?>" onsubmit="return confirm('Delete link?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="_action" value="delete_share">
                                <input type="hidden" name="link_id" value="<?= $link['id'] ?>">
                                <button type="submit" class="btn btn-xs btn-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($shareLinks)): ?><tr><td colspan="5" class="text-center text-muted">No share links yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    </div><!-- end admin-content -->
</div>

<script>
// Auto-generate slug from title on upload form
const titleInput = document.getElementById('pdfTitle');
const slugInput  = document.getElementById('pdfSlug');
if (titleInput && slugInput) {
    titleInput.addEventListener('input', function () {
        if (!slugInput.dataset.manual) {
            slugInput.value = this.value
                .toLowerCase()
                .replace(/[^a-z0-9\s-]/g, '')
                .replace(/\s+/g, '-')
                .replace(/-+/g, '-')
                .replace(/^-|-$/g, '')
                .substring(0, 100);
        }
    });
    slugInput.addEventListener('input', () => slugInput.dataset.manual = '1');
}
</script>
</body>
</html>
