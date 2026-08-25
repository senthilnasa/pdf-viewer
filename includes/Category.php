<?php
/**
 * Category management
 * PDF Viewer Platform
 *
 * Unlimited parent/child hierarchy via self-referencing `parent_id`
 * (adjacency list). The UI currently only exposes two levels (parent +
 * child) but the data model and these helpers place no hard limit on depth.
 */

class Category
{
    // -------------------------------------------------------------------------
    // Reads
    // -------------------------------------------------------------------------

    /** All categories, flat, ordered by hierarchy path for display. */
    public function getAll(): array
    {
        $rows = Database::fetchAll('SELECT * FROM categories ORDER BY parent_id IS NULL DESC, sort_order ASC, name ASC');
        return $rows;
    }

    /** Top-level (parent) categories only. */
    public function getParents(): array
    {
        return Database::fetchAll(
            'SELECT * FROM categories WHERE parent_id IS NULL ORDER BY sort_order ASC, name ASC'
        );
    }

    /** Direct children of a given category. */
    public function getChildren(int $parentId): array
    {
        return Database::fetchAll(
            'SELECT * FROM categories WHERE parent_id = ? ORDER BY sort_order ASC, name ASC',
            [$parentId]
        );
    }

    public function getById(int $id): array|false
    {
        return Database::fetchOne('SELECT * FROM categories WHERE id = ?', [$id]);
    }

    public function getBySlug(string $slug): array|false
    {
        return Database::fetchOne('SELECT * FROM categories WHERE slug = ?', [$slug]);
    }

    /**
     * Nested tree: each parent gets a 'children' array of its descendants.
     * Works for any depth, not just two levels.
     */
    public function getTree(): array
    {
        $all = $this->getAll();
        $byParent = [];
        foreach ($all as $row) {
            $key = $row['parent_id'] ?? 0;
            $byParent[$key][] = $row;
        }

        $build = function (int $parentId) use (&$build, $byParent): array {
            $nodes = $byParent[$parentId] ?? [];
            foreach ($nodes as &$node) {
                $node['children'] = $build((int)$node['id']);
            }
            return $nodes;
        };

        return $build(0);
    }

    /** Flat list annotated with `depth` and `path` (for <select> option labels like "Parent > Child"). */
    public function getFlatWithDepth(): array
    {
        $tree = $this->getTree();
        $result = [];

        $walk = function (array $nodes, int $depth, string $pathPrefix) use (&$walk, &$result) {
            foreach ($nodes as $node) {
                $path = $pathPrefix === '' ? $node['name'] : $pathPrefix . ' › ' . $node['name'];
                $node['depth'] = $depth;
                $node['path']  = $path;
                $children = $node['children'] ?? [];
                unset($node['children']);
                $result[] = $node;
                $walk($children, $depth + 1, $path);
            }
        };

        $walk($tree, 0, '');
        return $result;
    }

    public function countChildren(int $id): int
    {
        return (int)Database::fetchScalar('SELECT COUNT(*) FROM categories WHERE parent_id = ?', [$id]);
    }

    public function countDocuments(int $id): int
    {
        return (int)Database::fetchScalar('SELECT COUNT(*) FROM pdf_documents WHERE category_id = ?', [$id]);
    }

    /** Ancestor chain from root down to (and including) this category. */
    public function getBreadcrumb(int $id): array
    {
        $chain = [];
        $current = $this->getById($id);
        $guard = 0;
        while ($current && $guard++ < 50) {
            array_unshift($chain, $current);
            if (!$current['parent_id']) break;
            $current = $this->getById((int)$current['parent_id']);
        }
        return $chain;
    }

    // -------------------------------------------------------------------------
    // Writes
    // -------------------------------------------------------------------------

    /**
     * @return array{success:bool, id?:int, error?:string}
     */
    public function create(string $name, ?int $parentId = null): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['success' => false, 'error' => 'Category name is required.'];
        }
        if (mb_strlen($name) > 150) {
            return ['success' => false, 'error' => 'Category name is too long (max 150 characters).'];
        }

        if ($parentId !== null && !$this->getById($parentId)) {
            return ['success' => false, 'error' => 'Selected parent category does not exist.'];
        }

        if ($this->isDuplicateName($name, $parentId)) {
            return ['success' => false, 'error' => 'A category with this name already exists at this level.'];
        }

        $slug = $this->generateSlug($name);
        $sortOrder = (int)Database::fetchScalar(
            'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM categories WHERE ' . ($parentId ? 'parent_id = ?' : 'parent_id IS NULL'),
            $parentId ? [$parentId] : []
        );

        $id = (int)Database::insert(
            'INSERT INTO categories (parent_id, name, slug, sort_order) VALUES (?, ?, ?, ?)',
            [$parentId, $name, $slug, $sortOrder]
        );

        return ['success' => true, 'id' => $id];
    }

    /**
     * @return array{success:bool, error?:string}
     */
    public function update(int $id, string $name, ?int $parentId = null): array
    {
        $current = $this->getById($id);
        if (!$current) {
            return ['success' => false, 'error' => 'Category not found.'];
        }

        $name = trim($name);
        if ($name === '') {
            return ['success' => false, 'error' => 'Category name is required.'];
        }
        if (mb_strlen($name) > 150) {
            return ['success' => false, 'error' => 'Category name is too long (max 150 characters).'];
        }

        if ($parentId === $id) {
            return ['success' => false, 'error' => 'A category cannot be its own parent.'];
        }
        if ($parentId !== null) {
            if (!$this->getById($parentId)) {
                return ['success' => false, 'error' => 'Selected parent category does not exist.'];
            }
            if ($this->wouldCreateCycle($id, $parentId)) {
                return ['success' => false, 'error' => 'Cannot move a category under one of its own subcategories.'];
            }
        }

        if ($this->isDuplicateName($name, $parentId, $id)) {
            return ['success' => false, 'error' => 'A category with this name already exists at this level.'];
        }

        $slug = ($name !== $current['name']) ? $this->generateSlug($name, $id) : $current['slug'];

        Database::query(
            'UPDATE categories SET name = ?, slug = ?, parent_id = ? WHERE id = ?',
            [$name, $slug, $parentId, $id]
        );

        return ['success' => true];
    }

    /**
     * @return array{success:bool, error?:string}
     */
    public function delete(int $id): array
    {
        if (!$this->getById($id)) {
            return ['success' => false, 'error' => 'Category not found.'];
        }

        $childCount = $this->countChildren($id);
        if ($childCount > 0) {
            return ['success' => false, 'error' => "This category has {$childCount} subcategor" . ($childCount === 1 ? 'y' : 'ies') . '. Delete or move them first.'];
        }

        $docCount = $this->countDocuments($id);
        if ($docCount > 0) {
            return ['success' => false, 'error' => "This category is assigned to {$docCount} document" . ($docCount === 1 ? '' : 's') . '. Reassign them first.'];
        }

        Database::query('DELETE FROM categories WHERE id = ?', [$id]);
        return ['success' => true];
    }

    public function reorder(array $orderedIds): void
    {
        foreach ($orderedIds as $i => $id) {
            Database::query('UPDATE categories SET sort_order = ? WHERE id = ?', [$i, (int)$id]);
        }
    }

    // -------------------------------------------------------------------------
    // Validation helpers
    // -------------------------------------------------------------------------

    public function isDuplicateName(string $name, ?int $parentId, int $excludeId = 0): bool
    {
        $sql = 'SELECT COUNT(*) FROM categories WHERE LOWER(name) = LOWER(?) AND id != ? AND ';
        $sql .= $parentId === null ? 'parent_id IS NULL' : 'parent_id = ?';
        $params = $parentId === null ? [$name, $excludeId] : [$name, $excludeId, $parentId];
        return (int)Database::fetchScalar($sql, $params) > 0;
    }

    /** True if setting $id's parent to $newParentId would create a cycle (moving a node under its own descendant). */
    public function wouldCreateCycle(int $id, ?int $newParentId): bool
    {
        if ($newParentId === null) return false;
        $current = $this->getById($newParentId);
        $guard = 0;
        while ($current && $guard++ < 50) {
            if ((int)$current['id'] === $id) return true;
            if (!$current['parent_id']) break;
            $current = $this->getById((int)$current['parent_id']);
        }
        return false;
    }

    public function generateSlug(string $name, int $excludeId = 0): string
    {
        $slug = mb_strtolower($name);
        $slug = preg_replace('/[^\w\s-]/u', '', $slug);
        $slug = preg_replace('/[\s_-]+/', '-', $slug);
        $slug = trim($slug, '-');
        $slug = substr($slug, 0, 150) ?: 'category';

        $base = $slug;
        $n = 1;
        while ((int)Database::fetchScalar('SELECT COUNT(*) FROM categories WHERE slug = ? AND id != ?', [$slug, $excludeId]) > 0) {
            $slug = $base . '-' . $n++;
        }
        return $slug;
    }
}
