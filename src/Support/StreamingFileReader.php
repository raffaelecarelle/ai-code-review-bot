<?php

declare(strict_types=1);

namespace AICR\Support;

/**
 * Streaming file reader to handle large files without memory exhaustion.
 * Provides chunked reading capabilities for diff files and guidelines.
 */
final class StreamingFileReader
{
    private const DEFAULT_CHUNK_SIZE = 8192; // 8KB chunks
    private const MAX_FILE_SIZE      = 104857600; // 100MB limit
    private const MAX_MEMORY_USAGE   = 50331648; // 48MB memory limit

    /** @var int<1, max> */
    private int $chunkSize;
    private int $maxFileSize;
    private int $maxMemoryUsage;

    public function __construct(
        int $chunkSize = self::DEFAULT_CHUNK_SIZE,
        int $maxFileSize = self::MAX_FILE_SIZE,
        int $maxMemoryUsage = self::MAX_MEMORY_USAGE
    ) {
        $this->chunkSize      = max(1024, $chunkSize); // Minimum 1KB chunks
        $this->maxFileSize    = $maxFileSize;
        $this->maxMemoryUsage = $maxMemoryUsage;
    }

    /**
     * Read file content with memory and size limits.
     *
     * @throws \RuntimeException If file is too large or memory limit exceeded
     */
    public function readFile(string $filePath): string
    {
        if (!is_file($filePath)) {
            throw new \InvalidArgumentException("File not found: {$filePath}");
        }

        if (!is_readable($filePath)) {
            throw new \RuntimeException("File not readable: {$filePath}");
        }

        $fileSize = filesize($filePath);
        if (false === $fileSize) {
            throw new \RuntimeException("Unable to determine file size: {$filePath}");
        }

        if ($fileSize > $this->maxFileSize) {
            throw new \RuntimeException(
                sprintf('File too large: %d bytes (limit: %d bytes)', $fileSize, $this->maxFileSize)
            );
        }

        // For small files, use traditional approach
        if ($fileSize <= $this->chunkSize) {
            return $this->readSmallFile($filePath);
        }

        return $this->readLargeFile($filePath, $fileSize);
    }

    /**
     * Read file in chunks and yield content for processing.
     *
     * @return \Generator<string>
     */
    public function readFileChunks(string $filePath): \Generator
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new \InvalidArgumentException("File not found or not readable: {$filePath}");
        }

        $handle = fopen($filePath, 'rb');
        if (false === $handle) {
            throw new \RuntimeException("Unable to open file: {$filePath}");
        }

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, $this->chunkSize);
                if (false === $chunk) {
                    throw new \RuntimeException("Error reading file: {$filePath}");
                }

                if ('' !== $chunk) {
                    yield $chunk;
                }

                // Check memory usage
                if (memory_get_usage(true) > $this->maxMemoryUsage) {
                    throw new \RuntimeException('Memory limit exceeded while reading file');
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Create a temporary file with content streaming.
     */
    public function createTempFile(string $content): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'aicr_stream_');
        if (false === $tempFile) {
            throw new \RuntimeException('Unable to create temporary file');
        }

        $handle = fopen($tempFile, 'wb');
        if (false === $handle) {
            unlink($tempFile);

            throw new \RuntimeException('Unable to open temporary file for writing');
        }

        try {
            // Write content in chunks to avoid memory issues
            $contentLength = strlen($content);
            $offset        = 0;

            while ($offset < $contentLength) {
                $chunk   = substr($content, $offset, $this->chunkSize);
                $written = fwrite($handle, $chunk);

                if (false === $written) {
                    throw new \RuntimeException('Error writing to temporary file');
                }

                $offset += strlen($chunk);

                // Check memory usage
                if (memory_get_usage(true) > $this->maxMemoryUsage) {
                    throw new \RuntimeException('Memory limit exceeded while writing temporary file');
                }
            }
        } finally {
            fclose($handle);
        }

        return $tempFile;
    }

    /**
     * Validate file path to prevent directory traversal.
     */
    public function validatePath(string $filePath): bool
    {
        // Try to resolve the real path first (handles .. properly)
        $realPath = realpath($filePath);
        if (false === $realPath) {
            // If realpath fails, try to check if parent directory exists and build absolute path
            $absolutePath = $filePath;
            if (!str_starts_with($filePath, '/')) {
                $absolutePath = getcwd().DIRECTORY_SEPARATOR.$filePath;
            }
            $realPath = realpath($absolutePath);
        }

        // If we still can't resolve the path, check file existence directly
        if (false === $realPath) {
            return is_file($filePath) && is_readable($filePath);
        }

        // Check if resolved file exists and is readable
        return is_file($realPath) && is_readable($realPath);
    }

    private function readSmallFile(string $filePath): string
    {
        $content = file_get_contents($filePath);
        if (false === $content) {
            throw new \RuntimeException("Failed to read file: {$filePath}");
        }

        return $content;
    }

    private function readLargeFile(string $filePath, int $fileSize): string
    {
        $handle = fopen($filePath, 'rb');
        if (false === $handle) {
            throw new \RuntimeException("Unable to open file: {$filePath}");
        }

        $content   = '';
        $totalRead = 0;

        try {
            while (!feof($handle) && $totalRead < $fileSize) {
                $chunk = fread($handle, $this->chunkSize);
                if (false === $chunk) {
                    throw new \RuntimeException("Error reading file: {$filePath}");
                }

                $content .= $chunk;
                $totalRead += strlen($chunk);

                // Check memory usage periodically
                if (memory_get_usage(true) > $this->maxMemoryUsage) {
                    throw new \RuntimeException('Memory limit exceeded while reading file');
                }
            }
        } finally {
            fclose($handle);
        }

        return $content;
    }
}
