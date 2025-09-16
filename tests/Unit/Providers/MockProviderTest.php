<?php

declare(strict_types=1);

namespace AICR\Tests\Unit\Providers;

use AICR\Providers\MockProvider;
use PHPUnit\Framework\TestCase;

final class MockProviderTest extends TestCase
{
    public function testReturnsPresetResponses(): void
    {
        $preset = [[
            'rule_id' => 'R1',
            'title' => 'T',
            'severity' => 'info',
            'file' => 'a.php',
            'start_line' => 1,
            'end_line' => 1,
            'rationale' => 'r',
            'suggestion' => 's',
            'content' => '',
        ]];
        $prov = new MockProvider($preset);
        $out  = $prov->reviewChunks([]);
        $this->assertSame($preset, $out);
    }

    public function testGeneratesFindingFromFirstChunkWhenNoPreset(): void
    {
        $prov = new MockProvider();
        $chunks = [[ 'file' => 'src/Foo.php', 'start_line' => 12 ]];
        $out = $prov->reviewChunks($chunks);
        $this->assertSame('AI.MOCK.CHECK', $out[0]['rule_id'] ?? null);
        $this->assertSame('src/Foo.php', $out[0]['file'] ?? null);
        $this->assertSame(12, $out[0]['start_line'] ?? null);
    }
}
