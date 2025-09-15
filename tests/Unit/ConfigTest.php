<?php

declare(strict_types=1);

namespace AICR\Tests\Unit;

use AICR\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDefaultsAndAccessors(): void
    {
        $cfg = Config::load(null);
        $all = $cfg->getAll();
        $this->assertIsArray($all);
        $this->assertIsArray($cfg->providers());
        $this->assertIsArray($cfg->context('test'));
        $this->assertIsArray($cfg->policy());
        $this->assertIsArray($cfg->vcs());
        $this->assertIsArray($cfg->excludes());
        $this->assertArrayHasKey('mock', $cfg->providers());
    }

    public function testYamlParsingAndEnvExpansion(): void
    {
        $tmp = sys_get_temp_dir().'/aicr_cfg_'.uniqid('', true).'.yml';
        putenv('CFG_TEST_TOKEN=ABC123');
        $yaml = <<<'YML'
providers:
  openai:
    api_key: test_key
context:
  diff_token_limit: 9000
  overflow_strategy: trim
  per_file_token_cap: 1000
policy:
  min_severity_to_comment: info
  max_comments: 10
  redact_secrets: true
vcs:
  platform: github
  repo: ${CFG_TEST_TOKEN}
prompts:
  system_append: "Be concise"
  user_append:
    - "Prefer security"
  extra:
    - "One"
    - "Two"
YML;
        file_put_contents($tmp, $yaml);
        $cfg = Config::load($tmp);
        @unlink($tmp);
        $this->assertArrayHasKey('openai', $cfg->providers());
        $this->assertSame(9000, $cfg->context('test')['diff_token_limit']);
        $this->assertSame('ABC123', $cfg->vcs()['repo']);
        $this->assertSame('github', $cfg->vcs()['platform']);
        $this->assertIsArray($cfg->getAll()['prompts']);
    }

    public function testJsonParsingAndInvalidFile(): void
    {
        $tmp = sys_get_temp_dir().'/aicr_cfg_'.uniqid('', true).'.json';
        file_put_contents($tmp, json_encode(['providers' => ['openai' => ['api_key' => 'test_key']]], JSON_PRETTY_PRINT));
        $cfg = Config::load($tmp);
        @unlink($tmp);
        $this->assertArrayHasKey('openai', $cfg->providers());

        $invalid = sys_get_temp_dir().'/aicr_cfg_bad_'.uniqid('', true).'.zzz';
        file_put_contents($invalid, 'not: [valid'); // broken YAML
        $this->expectException(\InvalidArgumentException::class);
        try {
            Config::load($invalid);
        } finally {
            @unlink($invalid);
        }
    }

    public function testExcludesConfiguration(): void
    {
        // Test default excludes (empty array)
        $cfg = Config::load(null);
        $this->assertSame([], $cfg->excludes());

        // Test excludes with various patterns
        $tmp = sys_get_temp_dir().'/aicr_cfg_excludes_'.uniqid('', true).'.yml';
        $yaml = <<<'YML'
excludes:
  - "*.md"
  - "composer.lock"
  - "tests/*.php"
  - "vendor"
  - "node_modules"
  - "build"
YML;
        file_put_contents($tmp, $yaml);
        $cfg = Config::load($tmp);
        @unlink($tmp);
        
        $excludes = $cfg->excludes();
        $this->assertCount(6, $excludes);
        $this->assertContains('*.md', $excludes);
        $this->assertContains('composer.lock', $excludes);
        $this->assertContains('tests/*.php', $excludes);
        $this->assertContains('vendor', $excludes);
        $this->assertContains('node_modules', $excludes);
        $this->assertContains('build', $excludes);
    }
}
