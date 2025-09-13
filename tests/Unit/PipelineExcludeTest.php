<?php

declare(strict_types=1);

namespace AICR\Tests\Unit;

use AICR\Config;
use AICR\Pipeline;
use AICR\Tests\Support\MockAIProvider;
use PHPUnit\Framework\TestCase;

final class PipelineExcludeTest extends TestCase
{
    public function testExcludeFilesUsingGlobPatterns(): void
    {
        $config = $this->createConfigWithExcludes(['*.md', '*.lock']);
        $mockProvider = new MockAIProvider([]);
        $pipeline = new Pipeline($config, $mockProvider);
        
        $diffContent = $this->createMultiFileDiff([
            'README.md' => '+Added content',
            'composer.lock' => '+Lock content',  
            'src/Test.php' => '+PHP content'
        ]);
        
        $diffFile = $this->createTempDiffFile($diffContent);
        
        try {
            $result = $pipeline->run($diffFile);
            $findings = json_decode($result, true);
            
            // Should only process src/Test.php, not *.md or *.lock files
            $this->assertIsArray($findings);
            // Verify that only one file (src/Test.php) was processed
            $this->assertCount(1, $mockProvider->lastChunks);
            $this->assertSame('b/src/Test.php', $mockProvider->lastChunks[0]['file_path']);
            // Since only one file was processed, we expect one finding
            $this->assertCount(1, $findings);
        } finally {
            @unlink($diffFile);
        }
    }

    public function testExcludeDirectories(): void
    {
        $config = $this->createConfigWithExcludes(['vendor', 'node_modules']);
        $mockProvider = new MockAIProvider([]);
        $pipeline = new Pipeline($config, $mockProvider);
        
        $diffContent = $this->createMultiFileDiff([
            'vendor/package/file.php' => '+Vendor content',
            'node_modules/package/file.js' => '+Node content',
            'src/App.php' => '+App content'
        ]);
        
        $diffFile = $this->createTempDiffFile($diffContent);
        
        try {
            $result = $pipeline->run($diffFile);
            $findings = json_decode($result, true);
            
            // Should only process src/App.php, not vendor/ or node_modules/ files
            $this->assertIsArray($findings);
            // Verify that only one file (src/App.php) was processed
            $this->assertCount(1, $mockProvider->lastChunks);
            $this->assertSame('b/src/App.php', $mockProvider->lastChunks[0]['file_path']);
            // Since only one file was processed, we expect one finding
            $this->assertCount(1, $findings);
        } finally {
            @unlink($diffFile);
        }
    }

    public function testExcludeSpecificFiles(): void
    {
        $config = $this->createConfigWithExcludes(['composer.json', 'package.json']);
        $mockProvider = new MockAIProvider([]);
        $pipeline = new Pipeline($config, $mockProvider);
        
        $diffContent = $this->createMultiFileDiff([
            'composer.json' => '+Composer config',
            'package.json' => '+Package config',
            'src/Config.php' => '+Config content'
        ]);
        
        $diffFile = $this->createTempDiffFile($diffContent);
        
        try {
            $result = $pipeline->run($diffFile);
            $findings = json_decode($result, true);
            
            // Should only process src/Config.php
            $this->assertIsArray($findings);
            // Verify that only one file (src/Config.php) was processed
            $this->assertCount(1, $mockProvider->lastChunks);
            $this->assertSame('b/src/Config.php', $mockProvider->lastChunks[0]['file_path']);
            // Since only one file was processed, we expect one finding
            $this->assertCount(1, $findings);
        } finally {
            @unlink($diffFile);
        }
    }

    public function testMixedExcludePatterns(): void
    {
        $config = $this->createConfigWithExcludes([
            '*.md',           // glob pattern
            'vendor',         // directory
            'composer.lock'   // specific file
        ]);
        $mockProvider = new MockAIProvider([]);
        $pipeline = new Pipeline($config, $mockProvider);
        
        $diffContent = $this->createMultiFileDiff([
            'README.md' => '+Readme content',
            'vendor/autoload.php' => '+Vendor content',
            'composer.lock' => '+Lock content',
            'src/Main.php' => '+Main content'
        ]);
        
        $diffFile = $this->createTempDiffFile($diffContent);
        
        try {
            $result = $pipeline->run($diffFile);
            $findings = json_decode($result, true);
            
            // Should only process src/Main.php
            $this->assertIsArray($findings);
            // Verify that only one file (src/Main.php) was processed
            $this->assertCount(1, $mockProvider->lastChunks);
            $this->assertSame('b/src/Main.php', $mockProvider->lastChunks[0]['file_path']);
            // Since only one file was processed, we expect one finding
            $this->assertCount(1, $findings);
        } finally {
            @unlink($diffFile);
        }
    }

    public function testNoExcludesProcessesAllFiles(): void
    {
        $config = $this->createConfigWithExcludes([]);
        $mockProvider = new MockAIProvider([]);
        $pipeline = new Pipeline($config, $mockProvider);
        
        $diffContent = $this->createMultiFileDiff([
            'README.md' => '+Readme content',
            'src/App.php' => '+App content'
        ]);
        
        $diffFile = $this->createTempDiffFile($diffContent);
        
        try {
            $result = $pipeline->run($diffFile);
            $findings = json_decode($result, true);
            
            // Should process all files when no excludes are configured
            $this->assertIsArray($findings);
            // Verify that both files were processed
            $this->assertCount(2, $mockProvider->lastChunks);
            $processedFiles = array_column($mockProvider->lastChunks, 'file_path');
            $this->assertContains('b/README.md', $processedFiles);
            $this->assertContains('b/src/App.php', $processedFiles);
            // Since both files were processed, we expect one finding (MockAIProvider generates one per first chunk)
            $this->assertCount(1, $findings);
        } finally {
            @unlink($diffFile);
        }
    }

    /**
     * @param array<string> $excludes
     */
    private function createConfigWithExcludes(array $excludes): Config
    {
        $tmp = sys_get_temp_dir().'/aicr_test_excludes_'.uniqid('', true).'.yml';
        $yaml = "version: 1\n";
        $yaml .= "providers:\n  default: mock\n";
        $yaml .= "excludes:\n";
        foreach ($excludes as $exclude) {
            $yaml .= "  - \"$exclude\"\n";
        }
        
        file_put_contents($tmp, $yaml);
        $config = Config::load($tmp);
        @unlink($tmp);
        
        return $config;
    }

    /**
     * @param array<string, string> $files
     */
    private function createMultiFileDiff(array $files): string
    {
        $diff = '';
        foreach ($files as $file => $content) {
            $diff .= "diff --git a/$file b/$file\n";
            $diff .= "new file mode 100644\n";
            $diff .= "index 0000000..1234567\n";
            $diff .= "--- /dev/null\n";
            $diff .= "+++ b/$file\n";
            $diff .= "@@ -0,0 +1,1 @@\n";
            $diff .= "$content\n";
        }
        
        return $diff;
    }

    private function createTempDiffFile(string $content): string
    {
        $tmp = sys_get_temp_dir().'/aicr_test_diff_'.uniqid('', true).'.diff';
        file_put_contents($tmp, $content);
        
        return $tmp;
    }
}