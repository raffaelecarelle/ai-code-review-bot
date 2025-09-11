<?php

declare(strict_types=1);

namespace AICR\Tests\Unit;

use AICR\Policy;
use PHPUnit\Framework\TestCase;

final class PolicyTest extends TestCase
{
    public function testSeverityFilteringAndDeduplicationAndCap(): void
    {
        $policy = new Policy([
            'min_severity_to_comment' => 'major',
            'max_comments' => 1,
            'redact_secrets' => true,
        ]);
        $findings = [
            [
                'rule_id' => 'R1', 'title' => 't', 'severity' => 'minor',
                'file_path' => 'a.php', 'start_line' => 1, 'end_line' => 1,
                'rationale' => 'x', 'suggestion' => 'y', 'content' => 'no secret'
            ],
            [
                'rule_id' => 'R2', 'title' => 't', 'severity' => 'major',
                'file_path' => 'b.php', 'start_line' => 2, 'end_line' => 2,
                'rationale' => 'x', 'suggestion' => 'y', 'content' => 'password: supersecret'
            ],
            // Duplicate of previous by fingerprint dimensions (rule_id, path, lines, content)
            [
                'rule_id' => 'R2', 'title' => 't', 'severity' => 'major',
                'file_path' => 'b.php', 'start_line' => 2, 'end_line' => 2,
                'rationale' => 'x', 'suggestion' => 'y', 'content' => 'password: supersecret'
            ],
        ];
        $out = $policy->apply($findings);
        $this->assertCount(1, $out, 'Should filter by severity and cap to max_comments 1');
        $this->assertSame('R2', $out[0]['rule_id']);
        $this->assertArrayHasKey('fingerprint', $out[0]);
        $this->assertStringContainsString('password', (string)$out[0]['content']);
        $this->assertStringContainsString('***', (string)$out[0]['content'], 'Expected secret redaction');
    }

    public function testNoRedactionWhenDisabled(): void
    {
        $policy = new Policy([
            'min_severity_to_comment' => 'info',
            'max_comments' => 10,
            'redact_secrets' => false,
        ]);
        $findings = [[
            'rule_id' => 'R3', 'title' => 't', 'severity' => 'info',
            'file_path' => 'c.php', 'start_line' => 3, 'end_line' => 3,
            'rationale' => 'x', 'suggestion' => 'y', 'content' => 'api-key: ABCDEF12345'
        ]];
        $out = $policy->apply($findings);
        $this->assertSame('api-key: ABCDEF12345', $out[0]['content']);
    }
}
