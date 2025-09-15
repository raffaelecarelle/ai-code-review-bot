<?php

declare(strict_types=1);

namespace AICR\Tests\E2E;

use AICR\Config;
use AICR\Pipeline;
use PHPUnit\Framework\TestCase;
use AICR\Tests\Support\MockAIProvider;

final class PipelineNoFindingsE2ETest extends TestCase
{
    private string $tmpCfg;

    protected function setUp(): void
    {
        $this->tmpCfg = sys_get_temp_dir().'/aicr_e2e_nofind_'.uniqid('', true).'.yml';
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
YML;
        file_put_contents($this->tmpCfg, $yaml);
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpCfg);
    }

    public function testSummaryWithNoFindings(): void
    {
        $config = Config::load($this->tmpCfg);
        // Override provider to always return no findings and avoid any network
        $provider = new class implements \AICR\Providers\AIProvider {
            /** @var array<int, array<string, mixed>> */
            public array $lastChunks = [];
            public function reviewChunks(array $chunks): array { $this->lastChunks = $chunks; return []; }

            public function getName(): string
            {
             return 'test';
            }

        };
        $pipeline = new Pipeline($config, $provider);

        $diffPath = __DIR__.'/../../examples/sample.diff';

        $summary = $pipeline->run($diffPath, Pipeline::OUTPUT_FORMAT_SUMMARY);
        $this->assertSame("No findings.\n", $summary);
    }
}
