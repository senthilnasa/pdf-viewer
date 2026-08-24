<?php
/**
 * Storage Provider Abstraction Layer
 * PDF Viewer Platform
 *
 * Supports multiple storage backends: local, S3, Cloudflare R2, etc.
 */

interface StorageProviderInterface
{
    /**
     * Store a file
     */
    public function put(string $path, string $contents, array $options = []): array;

    /**
     * Store a file from uploaded form
     */
    public function putFile(string $path, array $file): array;

    /**
     * Get file contents
     */
    public function get(string $path): ?string;

    /**
     * Check if file exists
     */
    public function exists(string $path): bool;

    /**
     * Delete a file
     */
    public function delete(string $path): bool;

    /**
     * Get file size
     */
    public function size(string $path): int;

    /**
     * Get file URL (for public access)
     */
    public function url(string $path): string;

    /**
     * Generate a signed/temporary URL
     */
    public function temporaryUrl(string $path, int $expiresInSeconds = 3600): string;

    /**
     * Health check
     */
    public function isHealthy(): bool;
}

/**
 * Local Filesystem Storage
 */
class LocalStorageProvider implements StorageProviderInterface
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
        if (!is_dir($this->basePath)) {
            mkdir($this->basePath, 0755, true);
        }
    }

    public function put(string $path, string $contents, array $options = []): array
    {
        try {
            $fullPath = $this->basePath . '/' . ltrim($path, '/');
            $dir = dirname($fullPath);

            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $bytes = file_put_contents($fullPath, $contents);
            if ($bytes === false) {
                return ['success' => false, 'error' => 'Failed to write file'];
            }

            return ['success' => true, 'path' => $path, 'size' => $bytes];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function putFile(string $path, array $file): array
    {
        try {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                return ['success' => false, 'error' => 'Upload error: ' . $file['error']];
            }

            $fullPath = $this->basePath . '/' . ltrim($path, '/');
            $dir = dirname($fullPath);

            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
                return ['success' => false, 'error' => 'Failed to move uploaded file'];
            }

            return ['success' => true, 'path' => $path, 'size' => filesize($fullPath)];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function get(string $path): ?string
    {
        $fullPath = $this->basePath . '/' . ltrim($path, '/');
        if (!file_exists($fullPath)) {
            return null;
        }
        return file_get_contents($fullPath);
    }

    public function exists(string $path): bool
    {
        return file_exists($this->basePath . '/' . ltrim($path, '/'));
    }

    public function delete(string $path): bool
    {
        $fullPath = $this->basePath . '/' . ltrim($path, '/');
        if (file_exists($fullPath)) {
            return @unlink($fullPath);
        }
        return true;
    }

    public function size(string $path): int
    {
        $fullPath = $this->basePath . '/' . ltrim($path, '/');
        if (!file_exists($fullPath)) {
            return 0;
        }
        return filesize($fullPath);
    }

    public function url(string $path): string
    {
        return $path;
    }

    public function temporaryUrl(string $path, int $expiresInSeconds = 3600): string
    {
        return $path;
    }

    public function isHealthy(): bool
    {
        return is_writable($this->basePath);
    }
}

/**
 * AWS S3 Storage Provider
 */
class S3StorageProvider implements StorageProviderInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function put(string $path, string $contents, array $options = []): array
    {
        // Implementation would use AWS SDK
        return ['success' => false, 'error' => 'S3 provider not yet implemented. Ensure AWS SDK is installed.'];
    }

    public function putFile(string $path, array $file): array
    {
        return ['success' => false, 'error' => 'S3 provider not yet implemented'];
    }

    public function get(string $path): ?string
    {
        return null;
    }

    public function exists(string $path): bool
    {
        return false;
    }

    public function delete(string $path): bool
    {
        return false;
    }

    public function size(string $path): int
    {
        return 0;
    }

    public function url(string $path): string
    {
        $bucket = $this->config['bucket'] ?? '';
        $region = $this->config['region'] ?? 'us-east-1';
        return "https://{$bucket}.s3.{$region}.amazonaws.com/{$path}";
    }

    public function temporaryUrl(string $path, int $expiresInSeconds = 3600): string
    {
        return $this->url($path);
    }

    public function isHealthy(): bool
    {
        return !empty($this->config['key']) && !empty($this->config['secret']);
    }
}

/**
 * Cloudflare R2 Storage Provider
 */
class CloudflareR2StorageProvider implements StorageProviderInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function put(string $path, string $contents, array $options = []): array
    {
        return ['success' => false, 'error' => 'Cloudflare R2 provider not yet implemented'];
    }

    public function putFile(string $path, array $file): array
    {
        return ['success' => false, 'error' => 'Cloudflare R2 provider not yet implemented'];
    }

    public function get(string $path): ?string
    {
        return null;
    }

    public function exists(string $path): bool
    {
        return false;
    }

    public function delete(string $path): bool
    {
        return false;
    }

    public function size(string $path): int
    {
        return 0;
    }

    public function url(string $path): string
    {
        $bucket = $this->config['bucket'] ?? '';
        $domain = $this->config['domain'] ?? '';
        return "https://{$domain}/{$path}";
    }

    public function temporaryUrl(string $path, int $expiresInSeconds = 3600): string
    {
        return $this->url($path);
    }

    public function isHealthy(): bool
    {
        return !empty($this->config['api_token']) && !empty($this->config['bucket']);
    }
}

/**
 * Storage Factory
 */
class StorageFactory
{
    private static ?StorageProviderInterface $provider = null;

    public static function setProvider(StorageProviderInterface $provider): void
    {
        self::$provider = $provider;
    }

    public static function getProvider(array $config): StorageProviderInterface
    {
        if (self::$provider) {
            return self::$provider;
        }

        $driver = $config['storage_driver'] ?? 'local';

        return match ($driver) {
            's3' => new S3StorageProvider($config),
            'cloudflare_r2' => new CloudflareR2StorageProvider($config),
            default => new LocalStorageProvider($config['upload_directory'] ?? '/tmp'),
        };
    }
}
