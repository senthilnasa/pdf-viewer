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
        if (array_key_exists('show_in_catalog', $filters)) {
            $where[]  = 'p.show_in_catalog = ?';
            $params[] = (int)$filters['show_in_catalog'];
        }
        if (!empty($filters['category_id'])) {
            // Matches the category itself or any of its direct children —
            // lets "filter by parent category" also surface docs filed
            // under that parent's subcategories.
            $where[]  = '(p.category_id = ? OR p.category_id IN (SELECT id FROM categories WHERE parent_id = ?))';
            $params[] = (int)$filters['category_id'];
            $params[] = (int)$filters['category_id'];
        }

        $sql = 'SELECT p.*, u.name AS author_name,
                       c.name AS category_name, c.parent_id AS category_parent_id,
                       pc.name AS category_parent_name,
                       (SELECT COUNT(*) FROM pdf_views WHERE pdf_id = p.id) AS total_views
                FROM pdf_documents p
                LEFT JOIN users u ON u.id = p.created_by
                LEFT JOIN categories c ON c.id = p.category_id
                LEFT JOIN categories pc ON pc.id = c.parent_id
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

        try {
            return (int)Database::insert(
            'INSERT INTO pdf_documents (title, description, slug, file_path, file_size, page_count, visibility, status, meta_title, meta_desc, enable_download, show_in_catalog, category_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
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
                $data['show_in_catalog'] ?? 1,
                $data['category_id'] ?? null,
                $data['created_by'],
            ]
            );
        } catch (PDOException $e) {
            // Concurrent upload grabbed the same slug between the check and
            // the insert — report it like any other slug conflict, not a 500.
            if (Database::isDuplicateKey($e)) return false;
            throw $e;
        }
    }

    public function update(int $id, array $data): bool
    {
        $current = $this->getById($id);
        if (!$current) return false;

        // Check slug uniqueness if changed
        if (isset($data['slug']) && $data['slug'] !== $current['slug']) {
            if (!$this->isUniqueSlug($data['slug'], $id)) return false;
        }

        // category_id uses array_key_exists (not ??) so explicitly passing
        // null — "uncategorize this document" — isn't silently discarded.
        $categoryId = array_key_exists('category_id', $data) ? $data['category_id'] : $current['category_id'];

        try {
        Database::query(
            'UPDATE pdf_documents SET
                title = ?, description = ?, slug = ?, visibility = ?, status = ?,
                meta_title = ?, meta_desc = ?, enable_download = ?, show_in_catalog = ?, category_id = ?
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
                $data['show_in_catalog']?? $current['show_in_catalog'],
                $categoryId,
                $id,
            ]
        );
        } catch (PDOException $e) {
            if (Database::isDuplicateKey($e)) return false; // slug race
            throw $e;
        }
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

    public function maxUploadSize(): int
    {
        $appLimit = (int)($this->config['max_upload_size'] ?? 50 * 1024 * 1024);
        return function_exists('effectiveUploadLimit') ? effectiveUploadLimit($appLimit) : $appLimit;
    }

    public function handleUpload(array $file): array
    {
        if (!isset($file['error']) || is_array($file['error'])) {
            return ['success' => false, 'error' => 'Invalid upload.'];
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            // Human-readable reasons instead of "error code: 1"
            $reason = match ($file['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE
                    => 'The file exceeds the server upload limit of ' . $this->humanFileSize($this->maxUploadSize()) . '.',
                UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded. Please try again.',
                UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server misconfiguration: missing temporary upload folder.',
                UPLOAD_ERR_CANT_WRITE => 'Server error: failed to write the upload to disk.',
                UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
                default               => 'Upload failed (error code ' . (int)$file['error'] . ').',
            };
            return ['success' => false, 'error' => $reason];
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            return ['success' => false, 'error' => 'Invalid upload.'];
        }

        if ($file['size'] <= 0) {
            return ['success' => false, 'error' => 'The uploaded file is empty.'];
        }
        if ($file['size'] > $this->config['max_upload_size']) {
            return ['success' => false, 'error' => 'File exceeds maximum size of ' . $this->humanFileSize($this->config['max_upload_size'])];
        }

        // Validate MIME type (content sniffing, not the client-supplied type)
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);
        if (!in_array($mime, $this->config['allowed_types'] ?? ['application/pdf'], true)) {
            return ['success' => false, 'error' => 'Only PDF files are allowed.'];
        }

        $uploadDir = rtrim($this->config['upload_directory'], '/\\') . '/';
        if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            return ['success' => false, 'error' => 'Server error: the uploads directory does not exist and could not be created.'];
        }
        if (!is_writable($uploadDir)) {
            error_log('[pdf-viewer] uploads directory not writable: ' . $uploadDir);
            return ['success' => false, 'error' => 'Server error: the uploads directory is not writable by the web server.'];
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

    /**
     * Normalise a string into a URL slug that the /pdf/{slug} route can match
     * (the rewrite rules only accept [a-z0-9_-]). Returns '' if nothing usable
     * remains.
     */
    public function sanitizeSlug(string $value): string
    {
        $slug = $value;
        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
            if ($ascii !== false) $slug = $ascii;
        }
        $slug = strtolower($slug);
        $slug = preg_replace('/[^a-z0-9\s_-]/', '', $slug);
        $slug = preg_replace('/[\s_-]+/', '-', $slug);
        $slug = trim($slug, '-');
        return trim(substr($slug, 0, 100), '-');
    }

    public function generateSlug(string $title, int $excludeId = 0): string
    {
        // Previously this kept unicode letters (\w with /u), producing slugs
        // like "résumé" that the [a-z0-9_-] route never matches, and an empty
        // slug for titles made only of symbols / non-Latin scripts.
        $slug = $this->sanitizeSlug($title);
        if ($slug === '') {
            $slug = 'document-' . bin2hex(random_bytes(3));
        }

        $base = $slug;
        $n    = 1;
        while (!$this->isUniqueSlug($slug, $excludeId)) {
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

    /**
     * Look up a share link by token. Pure read — no side effects.
     */
    public function getShareLinkByToken(string $token): array|false
    {
        return Database::fetchOne(
            'SELECT sl.*, p.slug, p.title, p.visibility, p.status FROM share_links sl
             JOIN pdf_documents p ON p.id = sl.pdf_id
             WHERE sl.token = ?',
            [$token]
        );
    }

    /**
     * Check whether a (already-fetched) share link is currently usable.
     * Pure read — no side effects, no DB writes. Returns a reason code so
     * callers can show a specific error message instead of a generic 403.
     */
    public function checkShareLink(array|false $link, string $password = ''): array
    {
        if (!$link) {
            return ['ok' => false, 'reason' => 'not_found'];
        }
        if ($link['expires_at'] && strtotime($link['expires_at']) < time()) {
            return ['ok' => false, 'reason' => 'expired'];
        }
        if ($link['max_views'] !== null && (int)$link['view_count'] >= (int)$link['max_views']) {
            return ['ok' => false, 'reason' => 'limit_reached'];
        }
        if ($link['password'] && !password_verify($password, $link['password'])) {
            return ['ok' => false, 'reason' => 'invalid_password'];
        }
        return ['ok' => true, 'reason' => null];
    }

    /**
     * Convenience wrapper: fetch + check in one call. Still no side effects —
     * callers must explicitly call recordShareLinkView() once per real view.
     *
     * @return array|false The link row on success, false otherwise.
     */
    public function validateShareLink(string $token, string $password = ''): array|false
    {
        $link = $this->getShareLinkByToken($token);
        return $this->checkShareLink($link, $password)['ok'] ? $link : false;
    }

    /**
     * Record one genuine view against a share link's view_count.
     * Call exactly once per real document view (not per HTTP request —
     * PDF.js issues multiple range requests per view against serve-pdf.php,
     * so the increment belongs at the viewer page level, not the file-serving
     * endpoint).
     */
    public function recordShareLinkView(int $linkId): bool
    {
        // Atomic check-and-increment: two simultaneous visits can no longer
        // both pass a "view_count < max_views" check and exceed the limit.
        $stmt = Database::query(
            'UPDATE share_links SET view_count = view_count + 1
             WHERE id = ? AND (max_views IS NULL OR view_count < max_views)',
            [$linkId]
        );
        return $stmt->rowCount() === 1;
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
