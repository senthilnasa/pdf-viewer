<?php
/**
 * Resumable, chunked file uploads.
 *
 * Why: a large PDF sent as ONE request can be killed by limits outside PHP —
 * Apache mod_reqtimeout, nginx client_body_timeout / client_max_body_size,
 * LiteSpeed or hosting-panel request timeouts, CDN body limits — which show up
 * as "408 Request Timeout" or "413". Sending the file as small sequential
 * pieces keeps every request short and small, so it works on any server.
 *
 * Flow (all requests are CSRF-protected and role-checked by the caller):
 *   1. init(size, name)          -> upload id
 *   2. append(id, offset, chunk) -> bytes received so far (idempotent: a
 *      retried chunk that already arrived is acknowledged, not re-appended)
 *   3. the normal form submit sends chunk_upload_id instead of the file;
 *      finalize(id) returns the assembled file for validation & storage.
 *
 * Parts live in <upload_directory>/.chunks/ (denied to web clients by
 * uploads/.htaccess and router.php) and are purged after 24h.
 */
class ChunkedUpload
{
    private const ID_PATTERN = '/^[a-f0-9]{32}$/';
    private const MAX_AGE    = 86400;

    private string $dir;

    public function __construct(string $uploadDirectory, private int $maxSize)
    {
        $this->dir = rtrim($uploadDirectory, '/\\') . '/.chunks';
    }

    /** Largest single piece this PHP configuration accepts (≤ 2 MiB). */
    public static function chunkSize(): int
    {
        $limits = [2 * 1024 * 1024];
        $upload = iniBytes((string)ini_get('upload_max_filesize'));
        $post   = iniBytes((string)ini_get('post_max_size'));
        if ($upload > 0) $limits[] = $upload;
        if ($post > 0)   $limits[] = $post - 64 * 1024; // multipart overhead
        return max(64 * 1024, (int)min($limits));
    }

    public function init(int $userId, int $size, string $name): array
    {
        if ($size <= 0) {
            return ['success' => false, 'message' => 'The selected file is empty.'];
        }
        if ($size > $this->maxSize) {
            return ['success' => false, 'message' => sprintf(
                'File exceeds the maximum size of %s MB.', number_format($this->maxSize / 1048576, 0)
            )];
        }
        if (!$this->ensureDir()) {
            return ['success' => false, 'message' => 'Server error: the uploads directory is not writable by the web server.'];
        }
        $this->purgeStale();

        $id = bin2hex(random_bytes(16));
        $meta = [
            'user_id' => $userId,
            'size'    => $size,
            'name'    => mb_substr(basename($name), 0, 200),
            'created' => time(),
        ];
        if (file_put_contents($this->metaPath($id), json_encode($meta), LOCK_EX) === false
            || file_put_contents($this->partPath($id), '') === false) {
            return ['success' => false, 'message' => 'Server error: could not start the upload.'];
        }
        return ['success' => true, 'upload_id' => $id, 'chunk_size' => self::chunkSize()];
    }

    public function append(int $userId, string $id, int $offset, array $file): array
    {
        $meta = $this->meta($userId, $id);
        if (!$meta) {
            return ['success' => false, 'message' => 'Upload session not found or expired. Please start the upload again.'];
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
            return ['success' => false, 'retry' => true, 'message' => 'A part of the file did not arrive (error ' . (int)($file['error'] ?? -1) . ').'];
        }
        $len = (int)$file['size'];

        $fh = fopen($this->partPath($id), 'c+b');
        if (!$fh || !flock($fh, LOCK_EX)) {
            return ['success' => false, 'retry' => true, 'message' => 'Server busy, retrying.'];
        }
        try {
            clearstatcache(true, $this->partPath($id));
            $current = (int)fstat($fh)['size'];

            if ($offset + $len <= $current) {
                // Duplicate of a chunk we already stored (client retried after
                // a lost response) — acknowledge without writing it twice.
                return ['success' => true, 'received' => $current, 'complete' => $current === $meta['size']];
            }
            if ($offset !== $current) {
                // Out of order: tell the client where to resume from.
                return ['success' => false, 'resume_from' => $current, 'message' => 'Upload out of sync; resuming.'];
            }
            if ($current + $len > $meta['size']) {
                return ['success' => false, 'message' => 'More data was sent than the declared file size.'];
            }

            $in = fopen($file['tmp_name'], 'rb');
            fseek($fh, $current);
            $written = $in ? stream_copy_to_stream($in, $fh) : false;
            if ($in) fclose($in);
            fflush($fh);
            if ($written !== $len) {
                ftruncate($fh, $current); // roll back the partial write
                return ['success' => false, 'retry' => true, 'message' => 'Server error while saving part of the file.'];
            }
            $received = $current + $len;
            return ['success' => true, 'received' => $received, 'complete' => $received === $meta['size']];
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * Hand over a fully received upload. Returns [path, size] of the assembled
     * file; the caller validates it and moves it into place.
     */
    public function finalize(int $userId, string $id): array
    {
        $meta = $this->meta($userId, $id);
        if (!$meta) {
            return ['success' => false, 'error' => 'Upload session not found or expired. Please upload the file again.'];
        }
        clearstatcache(true, $this->partPath($id));
        $size = (int)@filesize($this->partPath($id));
        if ($size !== (int)$meta['size']) {
            return ['success' => false, 'error' => 'The file upload is incomplete. Please upload it again.'];
        }
        @unlink($this->metaPath($id)); // one-shot: can't be finalised twice
        return ['success' => true, 'path' => $this->partPath($id), 'size' => $size, 'name' => $meta['name']];
    }

    public function discard(string $id): void
    {
        if (preg_match(self::ID_PATTERN, $id)) {
            @unlink($this->partPath($id));
            @unlink($this->metaPath($id));
        }
    }

    /** Remove abandoned uploads. Returns the number of uploads removed. */
    public function purgeStale(int $maxAge = self::MAX_AGE): int
    {
        $n = 0;
        foreach (array_merge(glob($this->dir . '/*.json') ?: [], glob($this->dir . '/*.part') ?: []) as $f) {
            if (@filemtime($f) < time() - $maxAge) {
                @unlink($f);
                if (str_ends_with($f, '.json')) $n++;
            }
        }
        return $n;
    }

    // ------------------------------------------------------------------

    private function meta(int $userId, string $id): ?array
    {
        if (!preg_match(self::ID_PATTERN, $id) || !is_file($this->metaPath($id))) {
            return null;
        }
        $meta = json_decode((string)file_get_contents($this->metaPath($id)), true);
        // Upload ids are bound to the user that started them
        if (!is_array($meta) || (int)($meta['user_id'] ?? 0) !== $userId) {
            return null;
        }
        return $meta;
    }

    private function ensureDir(): bool
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0755, true) && !is_dir($this->dir)) {
            return false;
        }
        return is_writable($this->dir);
    }

    private function partPath(string $id): string { return "{$this->dir}/{$id}.part"; }
    private function metaPath(string $id): string { return "{$this->dir}/{$id}.json"; }
}
