<?php

declare(strict_types=1);

namespace AICR\Tests\Unit\Support;

use AICR\Support\StreamingFileReader;
use PHPUnit\Framework\TestCase;

class StreamingFileReaderTest extends TestCase
{
    private string $tempDir;
    private StreamingFileReader $reader;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/aicr_test_' . uniqid('', true);
        mkdir($this->tempDir);
        $this->reader = new StreamingFileReader();
    }

    protected function tearDown(): void
    {
        $this->cleanupTempDir();
    }

    public function testReadSmallFile(): void
    {
        $content = "Hello, World!\nThis is a test file.";
        $filePath = $this->createTempFile($content);

        $result = $this->reader->readFile($filePath);

        $this->assertSame($content, $result);
    }

    public function testReadLargeFile(): void
    {
        // Create a file larger than default chunk size (8KB)
        $content = str_repeat("Line of text with some content\n", 300); // ~9KB
        $filePath = $this->createTempFile($content);

        $result = $this->reader->readFile($filePath);

        $this->assertSame($content, $result);
    }

    public function testReadFileThrowsExceptionForNonExistentFile(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('File not found: /non/existent/file.txt');

        $this->reader->readFile('/non/existent/file.txt');
    }

    public function testReadFileThrowsExceptionForTooLargeFile(): void
    {
        $reader = new StreamingFileReader(8192, 1024); // 1KB limit
        $content = str_repeat('x', 2048); // 2KB content
        $filePath = $this->createTempFile($content);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File too large');

        $reader->readFile($filePath);
    }

    public function testReadFileChunks(): void
    {
        $content = "Line 1\nLine 2\nLine 3\nLine 4\n";
        $filePath = $this->createTempFile($content);

        $chunks = [];
        foreach ($this->reader->readFileChunks($filePath) as $chunk) {
            $chunks[] = $chunk;
        }

        $reconstructed = implode('', $chunks);
        $this->assertSame($content, $reconstructed);
    }

    public function testCreateTempFile(): void
    {
        $content = "Temporary file content for testing";

        $tempFilePath = $this->reader->createTempFile($content);

        $this->assertFileExists($tempFilePath);
        $this->assertSame($content, file_get_contents($tempFilePath));

        // Clean up
        unlink($tempFilePath);
    }

    public function testValidatePathAcceptsValidPaths(): void
    {
        $validPath = $this->createTempFile('test content');
        $this->assertTrue($this->reader->validatePath($validPath));
    }

    public function testValidatePathRejectsNonExistentPaths(): void
    {
        $this->assertFalse($this->reader->validatePath('/completely/fake/path.txt'));
    }

    public function testCustomChunkSize(): void
    {
        $reader = new StreamingFileReader(2048); // 2KB chunks
        $content = str_repeat("Line of content\n", 200); // ~3KB content
        $filePath = $this->createTempFile($content);

        $chunks = [];
        foreach ($reader->readFileChunks($filePath) as $chunk) {
            $chunks[] = $chunk;
            $this->assertLessThanOrEqual(2048, strlen($chunk));
        }

        $this->assertGreaterThan(1, count($chunks)); // Should be split into multiple chunks
        $this->assertSame($content, implode('', $chunks));
    }

    public function testConstructorValidatesMinimumChunkSize(): void
    {
        $reader = new StreamingFileReader(512); // Below minimum
        
        // Use reflection to check the actual chunk size
        $reflection = new \ReflectionClass($reader);
        $chunkSizeProperty = $reflection->getProperty('chunkSize');
        $chunkSizeProperty->setAccessible(true);
        
        $this->assertSame(1024, $chunkSizeProperty->getValue($reader)); // Should be clamped to minimum
    }

    private function createTempFile(string $content): string
    {
        $filePath = $this->tempDir . '/test_' . uniqid('', true) . '.txt';
        file_put_contents($filePath, $content);
        return $filePath;
    }

    private function cleanupTempDir(): void
    {
        if (!is_dir($this->tempDir)) {
            return;
        }

        $files = glob($this->tempDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->tempDir);
    }
}