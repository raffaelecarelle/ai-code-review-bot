<?php

declare(strict_types=1);

namespace AICR\Support;

/**
 * Provides API response caching with TTL and invalidation strategies.
 * Improves performance by caching frequently accessed external API data.
 */
final class ApiCache
{
    /** @var string Default cache directory */
    private const DEFAULT_CACHE_DIR = 'aicr_cache';

    /** @var int Default TTL in seconds (1 hour) */
    private const DEFAULT_TTL = 3600;

    /** @var int Maximum cache size in bytes (50MB) */
    private const MAX_CACHE_SIZE = 52428800;

    /** @var string Cache directory path */
    private string $cacheDir;

    /** @var int Default TTL for cache entries */
    private int $defaultTtl;

    public function __construct(?string $cacheDir = null, int $defaultTtl = self::DEFAULT_TTL)
    {
        $this->cacheDir   = $cacheDir ?? sys_get_temp_dir().DIRECTORY_SEPARATOR.self::DEFAULT_CACHE_DIR;
        $this->defaultTtl = $defaultTtl;
        $this->ensureCacheDirectory();
    }

    /**
     * Gets cached data for a key, or null if not found/expired.
     *
     * @param string $key Cache key
     *
     * @return null|mixed Cached data or null if not found/expired
     */
    public function get(string $key)
    {
        $cacheFile = $this->getCacheFilePath($key);

        if (!is_file($cacheFile)) {
            return null;
        }

        $data = $this->readCacheFile($cacheFile);
        if (null === $data) {
            return null;
        }

        // Check if expired
        if ($data['expires'] <= time()) {
            $this->delete($key);

            return null;
        }

        return $data['value'];
    }

    /**
     * Stores data in cache with optional TTL.
     *
     * @param string   $key   Cache key
     * @param mixed    $value Data to cache
     * @param null|int $ttl   Time to live in seconds (null = use default)
     */
    public function set(string $key, $value, ?int $ttl = null): void
    {
        $ttl     = $ttl ?? $this->defaultTtl;
        $expires = time() + $ttl;

        $cacheFile = $this->getCacheFilePath($key);
        $data      = [
            'key'     => $key,
            'value'   => $value,
            'expires' => $expires,
            'created' => time(),
        ];

        $encoded = json_encode($data);
        if (false === $encoded) {
            throw new \RuntimeException('Unable to encode cache data as JSON');
        }
        $this->writeCacheFile($cacheFile, $encoded);
        $this->enforceMaxCacheSize();
    }

    /**
     * Deletes a specific cache entry.
     *
     * @param string $key Cache key to delete
     */
    public function delete(string $key): void
    {
        $cacheFile = $this->getCacheFilePath($key);
        if (is_file($cacheFile)) {
            unlink($cacheFile);
        }
    }

    /**
     * Checks if a key exists and is not expired.
     *
     * @param string $key Cache key to check
     */
    public function has(string $key): bool
    {
        return null !== $this->get($key);
    }

    /**
     * Gets or sets cache data using a callback.
     *
     * @param string   $key      Cache key
     * @param callable $callback Function to generate data if not cached
     * @param null|int $ttl      Cache TTL in seconds
     *
     * @return mixed Cached or generated data
     */
    public function remember(string $key, callable $callback, ?int $ttl = null)
    {
        $cached = $this->get($key);
        if (null !== $cached) {
            return $cached;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    /**
     * Clears all cache entries.
     */
    public function clear(): void
    {
        if (!is_dir($this->cacheDir)) {
            return;
        }

        $files = glob($this->cacheDir.DIRECTORY_SEPARATOR.'*.cache');
        if (false !== $files) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
    }

    /**
     * Clears expired cache entries.
     */
    public function clearExpired(): void
    {
        if (!is_dir($this->cacheDir)) {
            return;
        }

        $files = glob($this->cacheDir.DIRECTORY_SEPARATOR.'*.cache');
        if (false !== $files) {
            $currentTime = time();
            foreach ($files as $file) {
                $data = $this->readCacheFile($file);
                if (null !== $data && $data['expires'] <= $currentTime) {
                    unlink($file);
                }
            }
        }
    }

    /**
     * Gets cache statistics.
     *
     * @return array{entries: int, size: int, expired: int}
     */
    public function getStats(): array
    {
        if (!is_dir($this->cacheDir)) {
            return ['entries' => 0, 'size' => 0, 'expired' => 0];
        }

        $files = glob($this->cacheDir.DIRECTORY_SEPARATOR.'*.cache');
        if (false === $files) {
            return ['entries' => 0, 'size' => 0, 'expired' => 0];
        }

        $entries     = 0;
        $size        = 0;
        $expired     = 0;
        $currentTime = time();

        foreach ($files as $file) {
            $size += filesize($file) ?: 0;
            $data = $this->readCacheFile($file);
            if (null !== $data) {
                ++$entries;
                if ($data['expires'] <= $currentTime) {
                    ++$expired;
                }
            }
        }

        return [
            'entries' => $entries,
            'size'    => $size,
            'expired' => $expired,
        ];
    }

    /**
     * Generates cache key for API requests.
     *
     * @param string               $method HTTP method
     * @param string               $url    Request URL
     * @param array<string, mixed> $params Request parameters
     */
    public static function generateApiKey(string $method, string $url, array $params = []): string
    {
        $normalized = [
            'method' => strtoupper($method),
            'url'    => $url,
            'params' => $params,
        ];

        return 'api_'.hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    /**
     * Ensures cache directory exists.
     */
    private function ensureCacheDirectory(): void
    {
        if (!is_dir($this->cacheDir)) {
            if (!mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
                throw new \RuntimeException("Unable to create cache directory: {$this->cacheDir}");
            }
        }
    }

    /**
     * Gets cache file path for a key.
     */
    private function getCacheFilePath(string $key): string
    {
        $hashedKey = hash('sha256', $key);

        return $this->cacheDir.DIRECTORY_SEPARATOR.$hashedKey.'.cache';
    }

    /**
     * Reads cache file and returns unserialized data.
     *
     * @return null|array<string, mixed>
     */
    private function readCacheFile(string $cacheFile): ?array
    {
        $content = file_get_contents($cacheFile);
        if (false === $content) {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['key'], $data['value'], $data['expires'])) {
            // Invalid cache file, remove it
            unlink($cacheFile);

            return null;
        }

        return $data;
    }

    /**
     * Writes serialized data to cache file.
     */
    private function writeCacheFile(string $cacheFile, string $serialized): void
    {
        $tempFile = $cacheFile.'.tmp';
        if (false === file_put_contents($tempFile, $serialized, LOCK_EX)) {
            throw new \RuntimeException("Unable to write cache file: {$cacheFile}");
        }

        if (!rename($tempFile, $cacheFile)) {
            unlink($tempFile);

            throw new \RuntimeException("Unable to rename cache file: {$cacheFile}");
        }
    }

    /**
     * Enforces maximum cache size by removing oldest entries.
     */
    private function enforceMaxCacheSize(): void
    {
        $stats = $this->getStats();
        if ($stats['size'] <= self::MAX_CACHE_SIZE) {
            return;
        }

        // Get all cache files with their creation times
        $files = glob($this->cacheDir.DIRECTORY_SEPARATOR.'*.cache');
        if (false === $files) {
            return;
        }

        $fileData = [];
        foreach ($files as $file) {
            $data = $this->readCacheFile($file);
            if (null !== $data) {
                $fileData[] = [
                    'file'    => $file,
                    'created' => $data['created'],
                ];
            }
        }

        // Sort by creation time (oldest first)
        usort($fileData, fn ($a, $b) => $a['created'] <=> $b['created']);

        // Remove oldest files until under size limit
        $currentSize = $stats['size'];
        foreach ($fileData as $item) {
            if ($currentSize <= self::MAX_CACHE_SIZE) {
                break;
            }

            $fileSize = filesize($item['file']) ?: 0;
            unlink($item['file']);
            $currentSize -= $fileSize;
        }
    }
}
