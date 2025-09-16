<?php

declare(strict_types=1);

namespace AICR\Tests\Unit;

use AICR\Pipeline;
use PHPUnit\Framework\TestCase;

final class PipelineTest extends TestCase
{
    public function testFormatSummaryEmpty(): void
    {
        $out = Pipeline::formatSummary([]);
        $this->assertSame(Pipeline::MSG_NO_FINDINGS, $out);
    }

    public function testFormatSummaryWithFindings(): void
    {
        $findings = [[
            'rule_id' => 'R1',
            'title' => 'Title',
            'severity' => 'minor',
            'file' => 'a.php',
            'start_line' => 1,
            'end_line' => 1,
            'rationale' => 'Because',
            'suggestion' => 'Do X',
            'content' => 'c',
        ]];
        $out = Pipeline::formatSummary($findings);
        $this->assertStringContainsString('Findings (1):', $out);
        $this->assertStringContainsString('[MINOR] R1 (a.php:1-1)', $out);
    }
}
