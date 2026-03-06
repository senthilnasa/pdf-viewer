<?php
/**
 * PDF document management
 * PDF Viewer Platform
 */

class PDF
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    public function getAll(array $filters = []): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[]  = 'p.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['visibility'])) {
            $where[]  = 'p.visibility = ?';
            $params[] = $filters['visibility'];
        }
        if (!empty($filters['search'])) {
            $where[]  = '(p.title LIKE ? OR p.slug LIKE ?)';
            $params[] = '%' . $filters['search'] . '%';
            $params[] = '%' . $filters['search'] . '%';
        }

        $sql = 'SELECT p.*, u.name AS author_name,
                       (SELECT COUNT(*) FROM pdf_views WHERE pdf_id = p.id) AS total_views
                FROM pdf_documents p
                LEFT JOIN users u ON u.id = p.created_by
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY p.created_at DESC';

        return Database::fetchAll($sql, $params);
    }

    public function getById(int $id): array|false
    {
        return Database::fetchOne('SELECT * FROM pdf_documents WHERE id = ?', [$id]);
    }

    public function getBySlug(string $slug): array|false
    {
        return Database::fetchOne(
            'SELECT * FROM pdf_documents WHERE slug = ? AND status = ?',
            [$slug, 'active']
        );
    }

    public function create(array $data): int|false
    {
        if (!$this->isUniqueSlug($data['slug'])) return false;

        return (int)Database::insert(
            'INSERT INTO pdf_documents (title, description, slug, file_path, file_size, page_count, visibility, status, meta_title, meta_desc, enable_download, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['title'],
                $data['description'] ?? null,
                $data['slug'],
                $data['file_path'],
                $data['file_size'] ?? 0,
                $data['page_count'] ?? 0,
                $data['visibility'] ?? 'public',
                $data['status'] ?? 'active',
                $data['meta_title'] ?? null,
                $data['meta_desc'] ?? null,
                $data['enable_download'] ?? 1,
                $data['created_by'],
            ]
        );
    }

    public function update(int $id, array $data): bool
    {
        $current = $this->getById($id);
        if (!$current) return false;

        // Check slug uniqueness if changed
        if (isset($data['slug']) && $data['slug'] !== $current['slug']) {
            if (!$this->isUniqueSlug($data['slug'])) return false;
        }

        Database::query(
            'UPDATE pdf_documents SET
                title = ?, description = ?, slug = ?, visibility = ?, status = ?,
                meta_title = ?, meta_desc = ?, enable_download = ?
             WHERE id = ?',
            [
                $data['title']          ?? $current['title'],
                $data['description']    ?? $current['description'],
                $data['slug']           ?? $current['slug'],
                $data['visibility']     ?? $current['visibility'],
                $data['status']         ?? $current['status'],
                $data['meta_title']     ?? $current['meta_title'],
                $data['meta_desc']      ?? $current['meta_desc'],
                $data['enable_download']?? $current['enable_download'],
                $id,
            ]
        );
        return true;
    }

    public function replaceFile(int $id, string $newFilePath, int $fileSize): bool
    {
        $current = $this->getById($id);
        if (!$current) return false;

        // Delete old file
        if (file_exists($current['file_path'])) {
            @unlink($current['file_path']);
        }

        Database::query(
            'UPDATE pdf_documents SET file_path = ?, file_size = ?, updated_at = NOW() WHERE id = ?',
            [$newFilePath, $fileSize, $id]
        );
        return true;
    }

    public function delete(int $id): bool
    {
        $pdf = $this->getById($id);
        if (!$pdf) return false;

        if (file_exists($pdf['file_path'])) {
            @unlink($pdf['file_path']);
        }

        Database::query('DELETE FROM pdf_documents WHERE id = ?', [$id]);
        return true;
    }

    // -------------------------------------------------------------------------
    // File upload
    // -------------------------------------------------------------------------

    public function handleUpload(array $file): array
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'Upload failed with error code: ' . $file['error']];
        }

        if ($file['size'] > $this->config['max_upload_size']) {
            return ['success' => false, 'error' => 'File exceeds maximum size of ' . $this->humanFileSize($this->config['max_upload_size'])];
        }

        // Validate MIME type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        if (!in_array($mime, $this->config['allowed_types'], true)) {
            return ['success' => false, 'error' => 'Only PDF files are allowed.'];
        }

        $uploadDir = rtrim($this->config['upload_directory'], '/\\') . '/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filename  = date('Ymd_His_') . bin2hex(random_bytes(4)) . '.pdf';
        $destPath  = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            return ['success' => false, 'error' => 'Failed to save uploaded file.'];
        }

        return [
            'success'   => true,
            'file_path' => $destPath,
            'file_size' => $file['size'],
            'filename'  => $filename,
        ];
    }

    // -------------------------------------------------------------------------
    // Slug helpers
    // -------------------------------------------------------------------------

    public function generateSlug(string $title): string
    {
        $slug = mb_strtolower($title);
        $slug = preg_replace('/[^\w\s-]/u', '', $slug);
        $slug = preg_replace('/[\s_-]+/', '-', $slug);
        $slug = trim($slug, '-');
        $slug = substr($slug, 0, 100);

        $base = $slug;
        $n    = 1;
        while (!$this->isUniqueSlug($slug)) {
            $slug = $base . '-' . $n++;
        }
        return $slug;
    }

    public function isUniqueSlug(string $slug, int $excludeId = 0): bool
    {
        $count = (int)Database::fetchScalar(
            'SELECT COUNT(*) FROM pdf_documents WHERE slug = ? AND id != ?',
            [$slug, $excludeId]
        );
        return $count === 0;
    }

    // -------------------------------------------------------------------------
    // Share links
    // -------------------------------------------------------------------------

    public function createShareLink(int $pdfId, int $userId, array $options = []): string
    {
        $token = bin2hex(random_bytes(32));
        $passwordHash = isset($options['password']) ? password_hash($options['password'], PASSWORD_BCRYPT) : null;
        $expiresAt = isset($options['expires_days'])
            ? date('Y-m-d H:i:s', strtotime('+' . (int)$options['expires_days'] . ' days'))
            : null;

        Database::insert(
            'INSERT INTO share_links (pdf_id, token, password, max_views, expires_at, created_by) VALUES (?, ?, ?, ?, ?, ?)',
            [$pdfId, $token, $passwordHash, $options['max_views'] ?? null, $expiresAt, $userId]
        );

        return $token;
    }

    public function validateShareLink(string $token, string $password = ''): array|false
    {
        $link = Database::fetchOne(
            'SELECT sl.*, p.slug, p.title FROM share_links sl
             JOIN pdf_documents p ON p.id = sl.pdf_id
             WHERE sl.token = ?',
            [$token]
        );

        if (!$link) return false;
        if ($link['expires_at'] && strtotime($link['expires_at']) < time()) return false;
        if ($link['max_views'] && $link['view_count'] >= $link['max_views']) return false;
        if ($link['password'] && !password_verify($password, $link['password'])) return false;

        Database::query('UPDATE share_links SET view_count = view_count + 1 WHERE id = ?', [$link['id']]);

        return $link;
    }

    public function getShareLinks(int $pdfId): array
    {
        return Database::fetchAll(
            'SELECT sl.*, u.name AS creator FROM share_links sl JOIN users u ON u.id = sl.created_by WHERE sl.pdf_id = ? ORDER BY sl.created_at DESC',
            [$pdfId]
        );
    }

    public function deleteShareLink(int $linkId): void
    {
        Database::query('DELETE FROM share_links WHERE id = ?', [$linkId]);
    }

    // -------------------------------------------------------------------------
    // Stats helper
    // -------------------------------------------------------------------------

    public function getStats(): array
    {
        return [
            'total'    => (int)Database::fetchScalar('SELECT COUNT(*) FROM pdf_documents'),
            'active'   => (int)Database::fetchScalar('SELECT COUNT(*) FROM pdf_documents WHERE status = ?', ['active']),
            'public'   => (int)Database::fetchScalar('SELECT COUNT(*) FROM pdf_documents WHERE visibility = ?', ['public']),
            'private'  => (int)Database::fetchScalar('SELECT COUNT(*) FROM pdf_documents WHERE visibility = ?', ['private']),
        ];
    }

    // -------------------------------------------------------------------------
    // Utility
    // -------------------------------------------------------------------------

    public function humanFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        for ($i = 0; $bytes >= 1024 && $i < 3; $i++) {
            $bytes /= 1024;
        }
        return round($bytes, 1) . ' ' . $units[$i];
    }
}
