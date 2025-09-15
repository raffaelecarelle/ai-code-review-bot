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

    public function testConsolidateSimilarFindings(): void
    {
        $policy = new Policy([
            'min_severity_to_comment' => 'info',
            'consolidate_similar_findings' => true,
            'max_comments' => 10,
        ]);
        
        $findings = [
            [
                'rule_id' => 'R1', 'title' => 'Missing type hint', 'severity' => 'warning',
                'file_path' => 'a.php', 'start_line' => 1, 'end_line' => 1,
                'rationale' => 'x', 'suggestion' => 'y', 'content' => 'function test()'
            ],
            [
                'rule_id' => 'R1', 'title' => 'Missing type hint', 'severity' => 'warning',
                'file_path' => 'b.php', 'start_line' => 5, 'end_line' => 5,
                'rationale' => 'x', 'suggestion' => 'y', 'content' => 'function helper()'
            ],
            [
                'rule_id' => 'R2', 'title' => 'Different issue', 'severity' => 'info',
                'file_path' => 'c.php', 'start_line' => 10, 'end_line' => 10,
                'rationale' => 'z', 'suggestion' => 'w', 'content' => 'unused variable'
            ]
        ];

        $out = $policy->apply($findings);
        
        $this->assertCount(2, $out, 'Should aggregate similar findings');
        
        // Check that one finding is aggregated
        $aggregatedFinding = array_filter($out, fn($f) => str_contains($f['title'], 'Aggregated:'));
        $this->assertCount(1, $aggregatedFinding, 'Should have one aggregated finding');
        
        $aggregated = array_values($aggregatedFinding)[0];
        $this->assertStringContainsString('Aggregated: Missing type hint', $aggregated['title']);
        $this->assertStringContainsString('a.php, b.php', $aggregated['file_path']);
        $this->assertEquals(2, $aggregated['aggregated_count']);
    }

    public function testSeverityLimits(): void
    {
        $policy = new Policy([
            'min_severity_to_comment' => 'info',
            'max_comments' => 50,
            'severity_limits' => [
                'error' => 2,
                'warning' => 1,
                'info' => 1
            ]
        ]);

        $findings = [
            [
                'rule_id' => 'R1', 'title' => 'Error 1', 'severity' => 'error',
                'file_path' => 'a.php', 'start_line' => 1, 'end_line' => 1,
                'rationale' => 'x', 'suggestion' => 'y', 'content' => 'error content'
            ],
            [
                'rule_id' => 'R2', 'title' => 'Error 2', 'severity' => 'error',
                'file_path' => 'b.php', 'start_line' => 2, 'end_line' => 2,
                'rationale' => 'x', 'suggestion' => 'y', 'content' => 'error content 2'
            ],
            [
                'rule_id' => 'R3', 'title' => 'Error 3', 'severity' => 'error',
                'file_path' => 'c.php', 'start_line' => 3, 'end_line' => 3,
                'rationale' => 'x', 'suggestion' => 'y', 'content' => 'error content 3'
            ],
            [
                'rule_id' => 'R4', 'title' => 'Warning 1', 'severity' => 'warning',
                'file_path' => 'd.php', 'start_line' => 4, 'end_line' => 4,
                'rationale' => 'x', 'suggestion' => 'y', 'content' => 'warning content'
            ],
            [
                'rule_id' => 'R5', 'title' => 'Warning 2', 'severity' => 'warning',
                'file_path' => 'e.php', 'start_line' => 5, 'end_line' => 5,
                'rationale' => 'x', 'suggestion' => 'y', 'content' => 'warning content 2'
            ]
        ];

        $out = $policy->apply($findings);
        
        // Should respect severity limits: 2 errors + 1 warning = 3 total
        $this->assertCount(3, $out);
        
        $errors = array_filter($out, fn($f) => $f['severity'] === 'error');
        $warnings = array_filter($out, fn($f) => $f['severity'] === 'warning');
        
        $this->assertCount(2, $errors, 'Should have exactly 2 errors due to limit');
        $this->assertCount(1, $warnings, 'Should have exactly 1 warning due to limit');
    }

    public function testMaxFindingsPerFile(): void
    {
        $policy = new Policy([
            'min_severity_to_comment' => 'info',
            'max_comments' => 50,
            'max_findings_per_file' => 2
        ]);

        $findings = [
            [
                'rule_id' => 'R1', 'title' => 'Issue 1', 'severity' => 'warning',
                'file_path' => 'same.php', 'start_line' => 1, 'end_line' => 1,
                'rationale' => 'x', 'suggestion' => 'y', 'content' => 'content 1'
            ],
            [
                'rule_id' => 'R2', 'title' => 'Issue 2', 'severity' => 'warning',
                'file_path' => 'same.php', 'start_line' => 5, 'end_line' => 5,
                'rationale' => 'x', 'suggestion' => 'y', 'content' => 'content 2'
            ],
            [
                'rule_id' => 'R3', 'title' => 'Issue 3', 'severity' => 'warning',
                'file_path' => 'same.php', 'start_line' => 10, 'end_line' => 10,
                'rationale' => 'x', 'suggestion' => 'y', 'content' => 'content 3'
            ],
            [
                'rule_id' => 'R4', 'title' => 'Issue 4', 'severity' => 'warning',
                'file_path' => 'different.php', 'start_line' => 1, 'end_line' => 1,
                'rationale' => 'x', 'suggestion' => 'y', 'content' => 'content 4'
            ]
        ];

        $out = $policy->apply($findings);
        
        // Should have max 2 findings from same.php + 1 from different.php = 3 total
        $this->assertCount(3, $out);
        
        $sameFileFindings = array_filter($out, fn($f) => $f['file_path'] === 'same.php');
        $this->assertCount(2, $sameFileFindings, 'Should have exactly 2 findings from same.php due to per-file limit');
    }

    public function testAggregateAndLimitsWorkTogether(): void
    {
        $policy = new Policy([
            'min_severity_to_comment' => 'info',
            'consolidate_similar_findings' => true,
            'max_findings_per_file' => 1,
            'severity_limits' => ['warning' => 1],
            'max_comments' => 10,
        ]);

        $findings = [
            [
                'rule_id' => 'R1', 'title' => 'Same issue', 'severity' => 'warning',
                'file_path' => 'a.php', 'start_line' => 1, 'end_line' => 1,
                'rationale' => 'x', 'suggestion' => 'y', 'content' => 'content'
            ],
            [
                'rule_id' => 'R1', 'title' => 'Same issue', 'severity' => 'warning',
                'file_path' => 'b.php', 'start_line' => 1, 'end_line' => 1,
                'rationale' => 'x', 'suggestion' => 'y', 'content' => 'content'
            ]
        ];

        $out = $policy->apply($findings);
        
        // Should consolidate similar findings and then apply limits
        $this->assertCount(1, $out, 'Should have 1 finding after consolidation and limits');
        $this->assertStringContainsString('Aggregated:', $out[0]['title']);
    }
}
