<?php

declare(strict_types=1);

namespace AICR\Tests\Unit;

use AICR\Output\SummaryFormatter;
use PHPUnit\Framework\TestCase;

final class SummaryFormatterTest extends TestCase
{
    public function testNoFindings(): void
    {
        $fmt = new SummaryFormatter();
        $this->assertSame(SummaryFormatter::MSG_NO_FINDINGS, $fmt->format([]));
    }

    public function testFormat(): void
    {
        $fmt = new SummaryFormatter();
        $out = $fmt->format([
            [
                'rule_id' => 'R1',
                'title' => 'T',
                'severity' => 'major',
                'file_path' => 'file.php',
                'start_line' => 3,
                'end_line' => 5,
                'rationale' => 'Because',
                'suggestion' => 'Fix it',
            ],
        ]);
        $this->assertStringContainsString('Findings (1):', $out);
        $this->assertStringContainsString('[MAJOR] R1 (file.php:3-5)', $out);
        $this->assertStringContainsString('Suggestion: Fix it', $out);
    }
}
