<?php

declare(strict_types=1);

namespace AICR\Tests\Unit;

use AICR\Command\ReviewCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ReviewCommandConfigValidationTest extends TestCase
{
    private function makeAppWithCommand(): array
    {
        $app = new Application('aicr');
        $cmd = new ReviewCommand();
        $app->add($cmd);

        return [$app, $cmd];
    }

    public function testConfigureMethod(): void
    {
        [$app, $cmd] = $this->makeAppWithCommand();
        $command = $app->find('review');
        
        $definition = $command->getDefinition();
        
        $this->assertTrue($definition->hasOption('diff-file'));
        $this->assertTrue($definition->hasOption('config'));
        $this->assertTrue($definition->hasOption('output'));
        $this->assertTrue($definition->hasOption('provider'));
        $this->assertTrue($definition->hasOption('id'));
        $this->assertTrue($definition->hasOption('comment'));
        
        $this->assertSame('json', $definition->getOption('output')->getDefault());
        $this->assertFalse($definition->getOption('comment')->getDefault());
    }

    public function testBuildSpecificProviderWithValidProvider(): void
    {
        [$app, $cmd] = $this->makeAppWithCommand();
        $command = $app->find('review');
        $tester = new CommandTester($command);

        $diffPath = __DIR__.'/../../examples/sample.diff';
        $tmpCfg = sys_get_temp_dir().'/aicr_cmd_'.uniqid('', true).'.yml';
        file_put_contents($tmpCfg, "providers:\n  mock:\n    type: mock\n  openai:\n    type: openai\n    api_key: test\n");

        $exit = $tester->execute([
            'command' => $command->getName(),
            '--diff-file' => $diffPath,
            '--config' => $tmpCfg,
            '--provider' => 'mock',
            '--output' => 'json',
        ]);
        @unlink($tmpCfg);

        $this->assertSame(0, $exit);
    }

    public function testBuildSpecificProviderWithDefaultSelection(): void
    {
        [$app, $cmd] = $this->makeAppWithCommand();
        $command = $app->find('review');
        $tester = new CommandTester($command);

        $diffPath = __DIR__.'/../../examples/sample.diff';
        $tmpCfg = sys_get_temp_dir().'/aicr_cmd_'.uniqid('', true).'.yml';
        file_put_contents($tmpCfg, "providers:\n  mock:\n    type: mock\n  openai:\n    type: openai\n    api_key: test\n");

        $exit = $tester->execute([
            'command' => $command->getName(),
            '--diff-file' => $diffPath,
            '--config' => $tmpCfg,
            '--output' => 'json',
        ]);
        @unlink($tmpCfg);

        $this->assertSame(0, $exit);
    }

    public function testConfigWithEnvironmentVariables(): void
    {
        [$app, $cmd] = $this->makeAppWithCommand();
        $command = $app->find('review');
        $tester = new CommandTester($command);

        $diffPath = __DIR__.'/../../examples/sample.diff';
        $tmpCfg = sys_get_temp_dir().'/aicr_cmd_'.uniqid('', true).'.yml';
        
        // Set environment variable for testing
        putenv('TEST_API_KEY=secret123');
        
        file_put_contents($tmpCfg, "providers:\n  mock:\n    type: mock\n  openai:\n    type: openai\n    api_key: \${TEST_API_KEY}\n");

        $exit = $tester->execute([
            'command' => $command->getName(),
            '--diff-file' => $diffPath,
            '--config' => $tmpCfg,
            '--provider' => 'mock',
            '--output' => 'json',
        ]);
        @unlink($tmpCfg);
        putenv('TEST_API_KEY'); // Unset environment variable

        $this->assertSame(0, $exit);
    }

    public function testConfigWithMultipleOutputFormats(): void
    {
        [$app, $cmd] = $this->makeAppWithCommand();
        $command = $app->find('review');
        $diffPath = __DIR__.'/../../examples/sample.diff';
        $tmpCfg = sys_get_temp_dir().'/aicr_cmd_'.uniqid('', true).'.yml';
        file_put_contents($tmpCfg, "providers:\n  default: mock\n");

        $formats = ['json', 'summary', 'markdown'];
        
        foreach ($formats as $format) {
            $tester = new CommandTester($command);
            $exit = $tester->execute([
                'command' => $command->getName(),
                '--diff-file' => $diffPath,
                '--config' => $tmpCfg,
                '--output' => $format,
            ]);
            
            $this->assertSame(0, $exit, "Failed for format: {$format}");
            $this->assertNotEmpty(trim($tester->getDisplay()), "Empty output for format: {$format}");
        }
        
        @unlink($tmpCfg);
    }

    public function testConfigurationWithAllProviderTypes(): void
    {
        [$app, $cmd] = $this->makeAppWithCommand();
        $command = $app->find('review');
        $tester = new CommandTester($command);

        $diffPath = __DIR__.'/../../examples/sample.diff';
        $tmpCfg = sys_get_temp_dir().'/aicr_cmd_'.uniqid('', true).'.yml';
        
        $configContent = "
providers:
  mock:
    type: mock
  openai:
    type: openai
    api_key: test_key
  gemini:
    type: gemini
    api_key: test_key
  anthropic:
    type: anthropic
    api_key: test_key
  ollama:
    type: ollama
    base_url: http://localhost:11434
";
        file_put_contents($tmpCfg, $configContent);

        // Test that the config loads successfully with all provider types
        $exit = $tester->execute([
            'command' => $command->getName(),
            '--diff-file' => $diffPath,
            '--config' => $tmpCfg,
            '--provider' => 'mock',
            '--output' => 'json',
        ]);
        @unlink($tmpCfg);

        $this->assertSame(0, $exit);
    }

    public function testConfigWithPolicySettings(): void
    {
        [$app, $cmd] = $this->makeAppWithCommand();
        $command = $app->find('review');
        $tester = new CommandTester($command);

        $diffPath = __DIR__.'/../../examples/sample.diff';
        $tmpCfg = sys_get_temp_dir().'/aicr_cmd_'.uniqid('', true).'.yml';
        
        $configContent = "
providers:
  default: mock
policy:
  min_severity_to_comment: warning
  max_comments: 25
  redact_secrets: true
  consolidate_similar_findings: false
  max_findings_per_file: 3
";
        file_put_contents($tmpCfg, $configContent);

        $exit = $tester->execute([
            'command' => $command->getName(),
            '--diff-file' => $diffPath,
            '--config' => $tmpCfg,
            '--output' => 'json',
        ]);
        @unlink($tmpCfg);

        $this->assertSame(0, $exit);
    }

    public function testConfigWithContextSettings(): void
    {
        [$app, $cmd] = $this->makeAppWithCommand();
        $command = $app->find('review');
        $tester = new CommandTester($command);

        $diffPath = __DIR__.'/../../examples/sample.diff';
        $tmpCfg = sys_get_temp_dir().'/aicr_cmd_'.uniqid('', true).'.yml';
        
        $configContent = "
providers:
  default: mock
context:
  diff_token_limit: 4000
  overflow_strategy: chunk
  per_file_token_cap: 1000
  enable_semantic_chunking: false
  enable_diff_compression: false
";
        file_put_contents($tmpCfg, $configContent);

        $exit = $tester->execute([
            'command' => $command->getName(),
            '--diff-file' => $diffPath,
            '--config' => $tmpCfg,
            '--output' => 'json',
        ]);
        @unlink($tmpCfg);

        $this->assertSame(0, $exit);
    }
}