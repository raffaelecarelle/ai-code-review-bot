<?php

declare(strict_types=1);

namespace AICR\Tests\Unit;

use AICR\Config;
use AICR\Pipeline;
use AICR\Tests\Support\MockAIProvider;
use PHPUnit\Framework\TestCase;

final class PipelineChunksTest extends TestCase
{
    public function testPipelineBuildsUnifiedDiffChunks(): void
    {
        $cfgPath = sys_get_temp_dir().'/aicr_chunks_'.uniqid('', true).'.yml';
        file_put_contents($cfgPath, "providers:\n  default: openai\nopenai:\n  api_key: dummy\n");
        // But we will override provider with our MockAIProvider, so API keys won't be used
        $config = Config::load($cfgPath);
        @unlink($cfgPath);

        $mock = new MockAIProvider();
        $pipeline = new Pipeline($config, $mock);

        $diffPath = __DIR__.'/../../examples/sample.diff';
        // Run in JSON mode; we only inspect the mock's captured chunks
        $pipeline->run($diffPath, Pipeline::OUTPUT_FORMAT_JSON);

        $this->assertNotEmpty($mock->lastChunks, 'Chunks should not be empty');
        $first = $mock->lastChunks[0];
        $this->assertArrayHasKey('file_path', $first);
        $this->assertArrayHasKey('start_line', $first);
        $this->assertArrayHasKey('unified_diff', $first, 'Expect unified_diff to be provided to AI provider');
        $this->assertIsString($first['unified_diff']);
        $this->assertTrue(
            str_contains($first['unified_diff'], 'diff --git') || str_contains($first['unified_diff'], '@@'),
            'Unified diff chunk should contain headers or hunk markers'
        );
        $this->assertGreaterThanOrEqual(1, (int)$first['start_line']);
    }
}
