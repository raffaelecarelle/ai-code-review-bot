<?php

declare(strict_types=1);

namespace AICR\Support;

/**
 * Manages resources and ensures proper cleanup to prevent resource leaks.
 * Provides utilities for safe resource handling with automatic cleanup.
 */
final class ResourceManager
{
    /** @var array<string> List of temporary files to clean up */
    private static array $tempFiles = [];

    /** @var array<resource> List of open resources to close */
    private static array $openResources = [];

    /** @var bool Whether cleanup is registered */
    private static bool $cleanupRegistered = false;

    /**
     * Creates a temporary file and registers it for cleanup.
     *
     * @param string $prefix Prefix for the temporary file name
     * @param string $suffix Suffix for the temporary file name
     *
     * @return string Path to the created temporary file
     *
     * @throws \RuntimeException If unable to create temporary file
     */
    public static function createTempFile(string $prefix = 'aicr_', string $suffix = '.tmp'): string
    {
        self::ensureCleanupRegistered();

        $tempFile = tempnam(sys_get_temp_dir(), $prefix);
        if (false === $tempFile) {
            throw new \RuntimeException('Unable to create temporary file');
        }

        // Add suffix if provided
        if ('' !== $suffix) {
            $newTempFile = $tempFile.$suffix;
            if (!rename($tempFile, $newTempFile)) {
                unlink($tempFile);

                throw new \RuntimeException('Unable to rename temporary file with suffix');
            }
            $tempFile = $newTempFile;
        }

        self::$tempFiles[] = $tempFile;

        return $tempFile;
    }

    /**
     * Creates a temporary directory and registers it for cleanup.
     *
     * @param string $prefix Prefix for the temporary directory name
     *
     * @return string Path to the created temporary directory
     *
     * @throws \RuntimeException If unable to create temporary directory
     */
    public static function createTempDir(string $prefix = 'aicr_'): string
    {
        self::ensureCleanupRegistered();

        $tempFile = tempnam(sys_get_temp_dir(), $prefix);
        if (false === $tempFile) {
            throw new \RuntimeException('Unable to create temporary file for directory');
        }

        unlink($tempFile);
        $tempDir = $tempFile.'_dir';

        if (!mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
            throw new \RuntimeException('Unable to create temporary directory');
        }

        self::$tempFiles[] = $tempDir;

        return $tempDir;
    }

    /**
     * Opens a file resource and registers it for cleanup.
     *
     * @param string $filename Path to the file to open
     * @param string $mode     File open mode
     *
     * @return resource The opened file resource
     *
     * @throws \RuntimeException If unable to open file
     */
    public static function openFile(string $filename, string $mode = 'r')
    {
        self::ensureCleanupRegistered();

        $resource = fopen($filename, $mode);
        if (false === $resource) {
            throw new \RuntimeException("Unable to open file: {$filename}");
        }

        self::$openResources[] = $resource;

        return $resource;
    }

    /**
     * Executes a callback with automatic resource cleanup.
     *
     * @param callable $callback Function to execute with resource protection
     * @param mixed    ...$args  Arguments to pass to the callback
     *
     * @return mixed The return value of the callback
     *
     * @throws \Throwable Re-throws any exception from the callback after cleanup
     */
    public static function withCleanup(callable $callback, ...$args)
    {
        $initialTempFiles = count(self::$tempFiles);
        $initialResources = count(self::$openResources);

        try {
            return $callback(...$args);
        } finally {
            // Clean up resources created during callback execution
            self::cleanupResourcesFrom($initialResources);
            self::cleanupTempFilesFrom($initialTempFiles);
        }
    }

    /**
     * Manually cleans up a specific temporary file.
     *
     * @param string $tempFile Path to the temporary file to clean up
     */
    public static function cleanupTempFile(string $tempFile): void
    {
        if (is_file($tempFile)) {
            unlink($tempFile);
        } elseif (is_dir($tempFile)) {
            self::removeDirectory($tempFile);
        }

        // Remove from tracking list
        $key = array_search($tempFile, self::$tempFiles, true);
        if (false !== $key) {
            unset(self::$tempFiles[$key]);
            self::$tempFiles = array_values(self::$tempFiles);
        }
    }

    /**
     * Manually closes a specific resource.
     *
     * @param resource $resource The resource to close
     */
    public static function closeResource($resource): void
    {
        if (is_resource($resource)) {
            fclose($resource);
        }

        // Remove from tracking list
        $key = array_search($resource, self::$openResources, true);
        if (false !== $key) {
            unset(self::$openResources[$key]);
            self::$openResources = array_values(self::$openResources);
        }
    }

    /**
     * Gets the count of tracked temporary files.
     */
    public static function getTempFileCount(): int
    {
        return count(self::$tempFiles);
    }

    /**
     * Gets the count of tracked open resources.
     */
    public static function getOpenResourceCount(): int
    {
        return count(self::$openResources);
    }

    /**
     * Manually triggers cleanup of all tracked resources.
     * This is automatically called on script shutdown.
     */
    public static function cleanupAll(): void
    {
        foreach (self::$openResources as $resource) {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
        self::$openResources = [];

        foreach (self::$tempFiles as $tempFile) {
            if (is_file($tempFile)) {
                unlink($tempFile);
            } elseif (is_dir($tempFile)) {
                self::removeDirectory($tempFile);
            }
        }
        self::$tempFiles = [];
    }

    /**
     * Ensures that cleanup is registered for script shutdown.
     */
    private static function ensureCleanupRegistered(): void
    {
        if (!self::$cleanupRegistered) {
            register_shutdown_function([self::class, 'cleanupAll']);
            self::$cleanupRegistered = true;
        }
    }

    /**
     * Cleans up resources created after a specific index.
     */
    private static function cleanupResourcesFrom(int $startIndex): void
    {
        $resourcesToClean = array_slice(self::$openResources, $startIndex);
        foreach ($resourcesToClean as $resource) {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
        self::$openResources = array_slice(self::$openResources, 0, $startIndex);
    }

    /**
     * Cleans up temporary files created after a specific index.
     */
    private static function cleanupTempFilesFrom(int $startIndex): void
    {
        $filesToClean = array_slice(self::$tempFiles, $startIndex);
        foreach ($filesToClean as $tempFile) {
            if (is_file($tempFile)) {
                unlink($tempFile);
            } elseif (is_dir($tempFile)) {
                self::removeDirectory($tempFile);
            }
        }
        self::$tempFiles = array_slice(self::$tempFiles, 0, $startIndex);
    }

    /**
     * Recursively removes a directory and its contents.
     */
    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir.DIRECTORY_SEPARATOR.$file;
            if (is_dir($path)) {
                self::removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
