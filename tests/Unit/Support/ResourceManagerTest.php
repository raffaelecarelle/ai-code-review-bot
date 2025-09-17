<?php

declare(strict_types=1);

namespace AICR\Tests\Unit\Support;

use AICR\Support\ResourceManager;
use PHPUnit\Framework\TestCase;

final class ResourceManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        // Clean up any resources created during tests
        ResourceManager::cleanupAll();
        parent::tearDown();
    }
    
    public function testCreateTempFileCreatesFile(): void
    {
        $tempFile = ResourceManager::createTempFile('test_', '.txt');
        
        $this->assertIsString($tempFile);
        $this->assertFileExists($tempFile);
        $this->assertStringEndsWith('.txt', $tempFile);
        $this->assertStringContainsString('test_', basename($tempFile));
    }
    
    public function testCreateTempFileWithDefaultSuffix(): void
    {
        $tempFile = ResourceManager::createTempFile();
        
        $this->assertIsString($tempFile);
        $this->assertFileExists($tempFile);
        $this->assertStringEndsWith('.tmp', $tempFile);
        $this->assertStringContainsString('aicr_', basename($tempFile));
    }
    
    public function testCreateTempDirCreatesDirectory(): void
    {
        $tempDir = ResourceManager::createTempDir('test_dir_');
        
        $this->assertIsString($tempDir);
        $this->assertDirectoryExists($tempDir);
        $this->assertStringContainsString('test_dir_', basename($tempDir));
        $this->assertStringEndsWith('_dir', $tempDir);
    }
    
    public function testOpenFileCreatesResource(): void
    {
        $tempFile = ResourceManager::createTempFile('test_', '.txt');
        file_put_contents($tempFile, 'test content');
        
        $resource = ResourceManager::openFile($tempFile, 'r');
        
        $this->assertIsResource($resource);
        $content = fread($resource, 1024);
        $this->assertSame('test content', $content);
    }
    
    public function testOpenFileThrowsOnNonExistentFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to open file');
        
        ResourceManager::openFile('/non/existent/file.txt');
    }
    
    public function testWithCleanupExecutesCallbackAndCleansUp(): void
    {
        $initialTempCount = ResourceManager::getTempFileCount();
        $initialResourceCount = ResourceManager::getOpenResourceCount();
        
        $result = ResourceManager::withCleanup(function () {
            $tempFile = ResourceManager::createTempFile();
            file_put_contents($tempFile, 'test');
            
            $resource = ResourceManager::openFile($tempFile, 'r');
            $content = fread($resource, 4);
            
            return $content;
        });
        
        $this->assertSame('test', $result);
        
        // Resources should be cleaned up after callback
        $this->assertSame($initialTempCount, ResourceManager::getTempFileCount());
        $this->assertSame($initialResourceCount, ResourceManager::getOpenResourceCount());
    }
    
    public function testWithCleanupHandlesExceptions(): void
    {
        $initialTempCount = ResourceManager::getTempFileCount();
        
        try {
            ResourceManager::withCleanup(function () {
                ResourceManager::createTempFile();
                throw new \RuntimeException('Test exception');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('Test exception', $e->getMessage());
        }
        
        // Resources should still be cleaned up even after exception
        $this->assertSame($initialTempCount, ResourceManager::getTempFileCount());
    }
    
    public function testCleanupTempFileRemovesFile(): void
    {
        $tempFile = ResourceManager::createTempFile();
        $this->assertFileExists($tempFile);
        
        ResourceManager::cleanupTempFile($tempFile);
        
        $this->assertFileDoesNotExist($tempFile);
    }
    
    public function testCleanupTempFileRemovesDirectory(): void
    {
        $tempDir = ResourceManager::createTempDir();
        $this->assertDirectoryExists($tempDir);
        
        ResourceManager::cleanupTempFile($tempDir);
        
        $this->assertDirectoryDoesNotExist($tempDir);
    }
    
    public function testCloseResourceClosesFile(): void
    {
        $tempFile = ResourceManager::createTempFile();
        file_put_contents($tempFile, 'test');
        
        $resource = ResourceManager::openFile($tempFile);
        $this->assertIsResource($resource);
        
        ResourceManager::closeResource($resource);
        
        // Resource should no longer be valid
        $this->assertFalse(is_resource($resource));
    }
    
    public function testGetTempFileCountTracksFiles(): void
    {
        $initialCount = ResourceManager::getTempFileCount();
        
        ResourceManager::createTempFile();
        $this->assertSame($initialCount + 1, ResourceManager::getTempFileCount());
        
        ResourceManager::createTempFile();
        $this->assertSame($initialCount + 2, ResourceManager::getTempFileCount());
        
        ResourceManager::createTempDir();
        $this->assertSame($initialCount + 3, ResourceManager::getTempFileCount());
    }
    
    public function testGetOpenResourceCountTracksResources(): void
    {
        $tempFile = ResourceManager::createTempFile();
        file_put_contents($tempFile, 'test');
        
        $initialCount = ResourceManager::getOpenResourceCount();
        
        $resource1 = ResourceManager::openFile($tempFile);
        $this->assertSame($initialCount + 1, ResourceManager::getOpenResourceCount());
        
        $resource2 = ResourceManager::openFile($tempFile);
        $this->assertSame($initialCount + 2, ResourceManager::getOpenResourceCount());
        
        ResourceManager::closeResource($resource1);
        $this->assertSame($initialCount + 1, ResourceManager::getOpenResourceCount());
    }
    
    public function testCleanupAllRemovesAllResources(): void
    {
        // Create some resources
        $tempFile = ResourceManager::createTempFile();
        $tempDir = ResourceManager::createTempDir();
        file_put_contents($tempFile, 'test');
        $resource = ResourceManager::openFile($tempFile);
        
        $this->assertGreaterThan(0, ResourceManager::getTempFileCount());
        $this->assertGreaterThan(0, ResourceManager::getOpenResourceCount());
        
        ResourceManager::cleanupAll();
        
        $this->assertSame(0, ResourceManager::getTempFileCount());
        $this->assertSame(0, ResourceManager::getOpenResourceCount());
        $this->assertFileDoesNotExist($tempFile);
        $this->assertDirectoryDoesNotExist($tempDir);
        $this->assertFalse(is_resource($resource));
    }
    
    public function testCreateTempFileWithEmptySuffix(): void
    {
        $tempFile = ResourceManager::createTempFile('test_', '');
        
        $this->assertIsString($tempFile);
        $this->assertFileExists($tempFile);
        $this->assertThat($tempFile, $this->logicalNot($this->stringEndsWith('.tmp')));
        $this->assertStringContainsString('test_', basename($tempFile));
    }
    
    public function testWithCleanupWithArguments(): void
    {
        $result = ResourceManager::withCleanup(function (string $arg1, int $arg2) {
            return $arg1 . '_' . $arg2;
        }, 'test', 42);
        
        $this->assertSame('test_42', $result);
    }
    
    public function testResourceTrackingAfterManualCleanup(): void
    {
        $tempFile = ResourceManager::createTempFile();
        $initialCount = ResourceManager::getTempFileCount();
        
        // Manually clean up
        ResourceManager::cleanupTempFile($tempFile);
        
        // Count should be reduced
        $this->assertSame($initialCount - 1, ResourceManager::getTempFileCount());
    }
}