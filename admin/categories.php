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
$categoryMgr = new Category();
$siteName   = getSetting('site_name', $config['site_name']);
$error      = '';
$success    = '';

if (isPost()) {
    verifyCsrf();
    $isAjax     = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    $postAction = post('_action');

    if ($postAction === 'create') {
        $name     = trim(post('name'));
        $parentId = post('parent_id') !== '' ? (int)post('parent_id') : null;

        $result = $categoryMgr->create($name, $parentId);
        if ($result['success']) {
            AuditLog::log(AuditLog::ACTION_CATEGORY_CREATED, $user['id'], 'category', $result['id'], ['name' => $name, 'parent_id' => $parentId]);
            $success = $parentId ? 'Subcategory created.' : 'Category created.';
        } else {
            $error = $result['error'];
        }
    }

    if ($postAction === 'update') {
        $id       = (int)post('id');
        $name     = trim(post('name'));
        $parentId = post('parent_id') !== '' ? (int)post('parent_id') : null;

        $result = $categoryMgr->update($id, $name, $parentId);
        if ($result['success']) {
            AuditLog::log(AuditLog::ACTION_CATEGORY_UPDATED, $user['id'], 'category', $id, ['name' => $name, 'parent_id' => $parentId]);
            $success = 'Category updated.';
        } else {
            $error = $result['error'];
        }
    }

    if ($postAction === 'delete') {
        $id = (int)post('id');
        $result = $categoryMgr->delete($id);
        if ($result['success']) {
            AuditLog::log(AuditLog::ACTION_CATEGORY_DELETED, $user['id'], 'category', $id);
            $success = 'Category deleted.';
        } else {
            $error = $result['error'];
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => !$error, 'message' => $error ?: $success, 'reload' => !$error]);
        exit;
    }
}

$tree    = $categoryMgr->getTree();
$parents = $categoryMgr->getParents();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php require ROOT . '/admin/partials/head-meta.php'; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Categories — <?= e($siteName) ?></title>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <script src="../assets/js/admin-ajax.js" defer></script>
    <style>
        .cat-tree { list-style: none; }
        .cat-tree > li { margin-bottom: .5rem; }
        .cat-row {
            display: flex; align-items: center; justify-content: space-between;
            gap: .75rem; padding: .65rem .9rem; border-radius: var(--radius);
            background: var(--bg); border: 1px solid var(--border);
        }
        .cat-row.is-parent { background: var(--bg-secondary); font-weight: 600; }
        .cat-name { display: flex; align-items: center; gap: .5rem; }
        .cat-count { font-size: .72rem; color: var(--text-muted); font-weight: 400; }
        .cat-children { list-style: none; margin: .5rem 0 0 1.75rem; padding-left: 1rem; border-left: 2px solid var(--border); }
        .cat-children li { margin-bottom: .5rem; }
        .cat-actions { display: flex; gap: .35rem; flex-shrink: 0; }
        .cat-empty { text-align: center; padding: 3rem 1rem; color: var(--text-muted); }
    </style>
</head>
<body class="admin-layout">

<?php require ROOT . '/admin/partials/sidebar.php'; ?>

<div class="admin-main">
    <?php require ROOT . '/admin/partials/topbar.php'; ?>

    <div class="admin-content">
        <div class="page-header">
            <div><h1>Categories</h1><p class="text-muted">Organize the catalog into parent and child categories</p></div>
            <button class="btn btn-primary" onclick="openCategoryModal()">+ Add Category</button>
        </div>

        <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success"><?= e($success) ?></div><?php endif; ?>

        <div class="card">
            <div class="card-body">
                <?php if (empty($tree)): ?>
                <div class="cat-empty">
                    <p>No categories yet. Create your first parent category to start organizing the catalog.</p>
                </div>
                <?php else: ?>
                <ul class="cat-tree">
                    <?php foreach ($tree as $parent): ?>
                    <li>
                        <div class="cat-row is-parent">
                            <div class="cat-name">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="16" height="16"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                <?= e($parent['name']) ?>
                                <span class="cat-count"><?= count($parent['children']) ?> subcategor<?= count($parent['children']) === 1 ? 'y' : 'ies' ?> · <?= $categoryMgr->countDocuments($parent['id']) ?> docs</span>
                            </div>
                            <div class="cat-actions">
                                <button class="btn btn-xs btn-outline" onclick='openCategoryModal(<?= json_encode(["id" => (int)$parent["id"], "name" => $parent["name"], "parent_id" => null]) ?>)'>Edit</button>
                                <button class="btn btn-xs btn-outline" onclick='openCategoryModal({"parent_id": <?= (int)$parent["id"] ?>})'>+ Subcategory</button>
                                <form method="POST" style="display:inline" data-ajax onsubmit="return confirm('Delete this category?')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="_action" value="delete">
                                    <input type="hidden" name="id" value="<?= $parent['id'] ?>">
                                    <button type="submit" class="btn btn-xs btn-danger">Delete</button>
                                </form>
                            </div>
                        </div>
                        <?php if (!empty($parent['children'])): ?>
                        <ul class="cat-children">
                            <?php foreach ($parent['children'] as $child): ?>
                            <li>
                                <div class="cat-row">
                                    <div class="cat-name">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" width="14" height="14"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                        <?= e($child['name']) ?>
                                        <span class="cat-count"><?= $categoryMgr->countDocuments($child['id']) ?> docs</span>
                                    </div>
                                    <div class="cat-actions">
                                        <button class="btn btn-xs btn-outline" onclick='openCategoryModal(<?= json_encode(["id" => (int)$child["id"], "name" => $child["name"], "parent_id" => (int)$child["parent_id"]]) ?>)'>Edit</button>
                                        <form method="POST" style="display:inline" data-ajax onsubmit="return confirm('Delete this subcategory?')">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="_action" value="delete">
                                            <input type="hidden" name="id" value="<?= $child['id'] ?>">
                                            <button type="submit" class="btn btn-xs btn-danger">Delete</button>
                                        </form>
                                    </div>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Category Modal -->
<div class="modal" id="categoryModal">
    <div class="modal-backdrop" onclick="closeCategoryModal()"></div>
    <div class="modal-dialog">
        <div class="modal-header">
            <h3 id="categoryModalTitle">Add Category</h3>
            <button class="modal-close" onclick="closeCategoryModal()">&times;</button>
        </div>
        <form method="POST" data-ajax id="categoryForm">
            <?= csrfField() ?>
            <input type="hidden" name="_action" id="cat_form_action" value="create">
            <input type="hidden" name="id" id="cat_id" value="">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Category Name</label>
                    <input type="text" name="name" id="cat_name" class="form-control" required maxlength="150" autofocus>
                </div>
                <div class="form-group">
                    <label class="form-label">Parent Category</label>
                    <select name="parent_id" id="cat_parent_id" class="form-control">
                        <option value="">— None (top-level category) —</option>
                        <?php foreach ($parents as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Leave blank to create a top-level parent category.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" onclick="closeCategoryModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
function openCategoryModal(data) {
    data = data || {};
    document.getElementById('categoryModalTitle').textContent = data.id ? 'Edit Category' : (data.parent_id ? 'Add Subcategory' : 'Add Category');
    document.getElementById('cat_form_action').value = data.id ? 'update' : 'create';
    document.getElementById('cat_id').value = data.id || '';
    document.getElementById('cat_name').value = data.name || '';
    document.getElementById('cat_parent_id').value = data.parent_id || '';
    document.getElementById('categoryModal').classList.add('open');
    setTimeout(() => document.getElementById('cat_name').focus(), 50);
}
function closeCategoryModal() {
    document.getElementById('categoryModal').classList.remove('open');
    document.getElementById('categoryForm').reset();
}
</script>

</body>
</html>
