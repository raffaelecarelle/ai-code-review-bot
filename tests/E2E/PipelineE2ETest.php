<?php

declare(strict_types=1);

namespace AICR\Tests\E2E;

use AICR\Config;
use AICR\Pipeline;
use PHPUnit\Framework\TestCase;
use AICR\Tests\Support\MockAIProvider;

final class PipelineE2ETest extends TestCase
{
    private string $tmpCfg;

    protected function setUp(): void
    {
        $this->tmpCfg = sys_get_temp_dir().'/aicr_e2e_'.uniqid('', true).'.yml';
        $yaml = <<<'YML'
providers:
  default: openai
context:
  diff_token_limit: 10000
  per_file_token_cap: 5000
  overflow_strategy: trim
policy:
  min_severity_to_comment: info
  max_comments: 50
  redact_secrets: true
rules:
  include: []
  inline:
    - { id: "PHP.NO.ECHO", applies_to: ["**/*.php"], severity: "minor", rationale: "Avoid echo", pattern: "(^|\s)echo\s", suggestion: "Use logger", enabled: true }
YML;
        file_put_contents($this->tmpCfg, $yaml);
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpCfg);
    }

    public function testRunJsonAndSummaryWithMockedLLM(): void
    {
        $config = Config::load($this->tmpCfg);
        $mock   = new MockAIProvider();
        $pipeline = new Pipeline($config, $mock);

        $diffPath = __DIR__.'/../../examples/sample.diff';

        $jsonOut = $pipeline->run($diffPath, Pipeline::OUTPUT_FORMAT_JSON);
        $data = json_decode($jsonOut, true);
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
        $this->assertArrayHasKey('rule_id', $data[0]);

        $summary = $pipeline->run($diffPath, Pipeline::OUTPUT_FORMAT_SUMMARY);
        $this->assertStringContainsString('Findings (', $summary);
        $this->assertStringContainsString('src/Example.php', $summary);
    }
}
