<?php

declare(strict_types=1);

namespace AICR\Tests\E2E;

use AICR\Config;
use AICR\Pipeline;
use AICR\Tests\Support\MockAIProvider;
use PHPUnit\Framework\TestCase;

final class ExcludeE2ETest extends TestCase
{
    public function testExcludeFunctionalityEndToEnd(): void
    {
        // Create a config file with excludes
        $configFile = $this->createConfigFile([
            '*.md',
            'vendor',  
            'composer.lock',
            'tests/*.php'
        ]);
        
        // Create a diff file with multiple files
        $diffFile = $this->createDiffFile([
            'README.md' => 'Documentation update',
            'vendor/autoload.php' => 'Vendor change', 
            'composer.lock' => 'Lock file update',
            'tests/SomeTest.php' => 'Test update',
            'src/Service.php' => 'Service implementation',
            'src/Controller.php' => 'Controller logic'
        ]);
        
        try {
            $config = Config::load($configFile);
            
            // Verify excludes are loaded correctly
            $excludes = $config->excludes();
            $this->assertCount(4, $excludes);
            $this->assertContains('*.md', $excludes);
            $this->assertContains('vendor', $excludes);
            $this->assertContains('composer.lock', $excludes);
            $this->assertContains('tests/*.php', $excludes);
            
            // Test with mock provider that captures processed files
            $mockProvider = new MockAIProvider([]);
            $pipeline = new Pipeline($config, $mockProvider);
            
            $result = $pipeline->run($diffFile, Pipeline::OUTPUT_FORMAT_JSON);
            $findings = json_decode($result, true);
            
            // Should only process src/ files, excluding all others
            $this->assertIsArray($findings);
            // Verify that only src/ files were processed (2 files: Service.php and Controller.php)
            $this->assertCount(2, $mockProvider->lastChunks);
            $processedFiles = array_column($mockProvider->lastChunks, 'file_path');
            $this->assertContains('b/src/Service.php', $processedFiles);
            $this->assertContains('b/src/Controller.php', $processedFiles);
            // MockAIProvider generates one finding for the first chunk
            $this->assertCount(1, $findings);
            
            // Test summary format as well
            $summaryResult = $pipeline->run($diffFile, Pipeline::OUTPUT_FORMAT_SUMMARY);
            $this->assertStringContainsString('Findings (1):', $summaryResult);
            
        } finally {
            @unlink($configFile);
            @unlink($diffFile);
        }
    }

    public function testExcludeWithRealFindings(): void
    {
        // Create a config file with excludes
        $configFile = $this->createConfigFile(['*.md', 'vendor']);
        
        // Create a diff file with multiple files
        $diffFile = $this->createDiffFile([
            'README.md' => 'Documentation update',
            'vendor/package.php' => 'Vendor change',
            'src/Important.php' => 'Important change'
        ]);
        
        try {
            $config = Config::load($configFile);
            
            // Test with mock provider that returns findings for processed files
            $mockFindings = [[
                'rule_id' => 'TEST_RULE',
                'title' => 'Test Finding',
                'severity' => 'info',
                'file_path' => 'b/src/Important.php',
                'start_line' => 1,
                'end_line' => 1,
                'rationale' => 'Test rationale',
                'suggestion' => 'Test suggestion',
                'content' => 'Important change'
            ]];
            
            $mockProvider = new MockAIProvider($mockFindings);
            $pipeline = new Pipeline($config, $mockProvider);
            
            $result = $pipeline->run($diffFile, Pipeline::OUTPUT_FORMAT_JSON);
            $findings = json_decode($result, true);
            
            // Should have findings only for src/Important.php, not for excluded files
            $this->assertIsArray($findings);
            $this->assertCount(1, $findings);
            $this->assertSame('TEST_RULE', $findings[0]['rule_id']);
            $this->assertSame('b/src/Important.php', $findings[0]['file_path']);
            
        } finally {
            @unlink($configFile);
            @unlink($diffFile);
        }
    }

    /**
     * @param array<string> $excludes
     */
    private function createConfigFile(array $excludes): string
    {
        $tmp = sys_get_temp_dir().'/aicr_e2e_config_'.uniqid('', true).'.yml';
        
        $yaml = <<<'YML'
version: 1
providers:
  default: mock
context:
  diff_token_limit: 8000
  overflow_strategy: trim
  per_file_token_cap: 2000
policy:
  min_severity_to_comment: info
  max_comments: 50
  redact_secrets: true
YML;
        
        $yaml .= "\nexcludes:\n";
        foreach ($excludes as $exclude) {
            $yaml .= "  - \"$exclude\"\n";
        }
        
        file_put_contents($tmp, $yaml);
        
        return $tmp;
    }

    /**
     * @param array<string, string> $files
     */
    private function createDiffFile(array $files): string
    {
        $tmp = sys_get_temp_dir().'/aicr_e2e_diff_'.uniqid('', true).'.diff';
        
        $diff = '';
        foreach ($files as $file => $content) {
            $diff .= "diff --git a/$file b/$file\n";
            $diff .= "new file mode 100644\n";
            $diff .= "index 0000000..abcdef1 100644\n";
            $diff .= "--- /dev/null\n";
            $diff .= "+++ b/$file\n";
            $diff .= "@@ -0,0 +1,3 @@\n";
            $diff .= "+// $content\n";
            $diff .= "+function example() {\n";
            $diff .= "+    return true;\n";
            $diff .= "+}\n";
        }
        
        file_put_contents($tmp, $diff);
        
        return $tmp;
    }
}