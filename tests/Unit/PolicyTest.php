<?php

declare(strict_types=1);

namespace AICR\Tests\Unit;

use AICR\Policy;
use PHPUnit\Framework\TestCase;

final class PolicyTest extends TestCase
{
    public function testApplyFiltersBySeverityDedupsAndRedacts(): void
    {
        $policy = new Policy([
            'min_severity_to_comment' => 'minor',
            'max_comments' => 10,
            'redact_secrets' => true,
        ]);
        $findings = [
            [
                'rule_id' => 'LOW', 'title' => 't', 'severity' => 'info', 'file_path' => 'a.php',
                'start_line' => 1, 'end_line' => 1, 'rationale' => 'r', 'suggestion' => 's', 'content' => 'password = secret1234'
            ],
            [
                'rule_id' => 'DUP', 'title' => 't', 'severity' => 'minor', 'file_path' => 'a.php',
                'start_line' => 2, 'end_line' => 2, 'rationale' => 'r', 'suggestion' => 's', 'content' => 'ok'
            ],
            [
                'rule_id' => 'DUP', 'title' => 't', 'severity' => 'minor', 'file_path' => 'a.php',
                'start_line' => 2, 'end_line' => 2, 'rationale' => 'r', 'suggestion' => 's', 'content' => 'ok'
            ],
        ];

        $out = $policy->apply($findings);
        // First finding filtered (info < minor), duplicates removed
        $this->assertCount(1, $out);
        $this->assertSame('DUP', $out[0]['rule_id']);
        $this->assertArrayHasKey('fingerprint', $out[0]);
    }
}
