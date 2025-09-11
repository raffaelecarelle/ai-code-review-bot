<?php

declare(strict_types=1);

namespace AICR\Tests\Unit;

use AICR\Output\JsonFormatter;
use PHPUnit\Framework\TestCase;

final class JsonFormatterTest extends TestCase
{
    public function testFormat(): void
    {
        $fmt = new JsonFormatter();
        $findings = [[
            'rule_id' => 'R1',
            'severity' => 'info',
            'file_path' => 'a.php',
            'start_line' => 1,
            'end_line' => 1,
        ]];
        $json = $fmt->format($findings);
        $this->assertJson($json);
        $this->assertStringContainsString('"rule_id": "R1"', $json);
    }
}
