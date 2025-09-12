<?php

declare(strict_types=1);

namespace AICR\Tests\Unit;

use AICR\Output\MarkdownFormatter;
use AICR\Output\OutputFormatter;
use PHPUnit\Framework\TestCase;

final class MarkdownFormatterTest extends TestCase
{
    public function testConstructorWithDefaultOptions(): void
    {
        $formatter = new MarkdownFormatter();
        
        $this->assertInstanceOf(MarkdownFormatter::class, $formatter);
        $this->assertInstanceOf(OutputFormatter::class, $formatter);
    }

    public function testConstructorWithCustomOptions(): void
    {
        $options = [
            'include_metadata' => false,
            'custom_option' => 'value'
        ];
        
        $formatter = new MarkdownFormatter($options);
        
        $this->assertInstanceOf(MarkdownFormatter::class, $formatter);
    }

    public function testFormatWithEmptyFindings(): void
    {
        $formatter = new MarkdownFormatter();
        
        $result = $formatter->format([]);
        
        $this->assertIsString($result);
        $this->assertStringContainsString('# 🎉 Code Review Results', $result);
        $this->assertStringContainsString('## ✅ No Issues Found', $result);
        $this->assertStringContainsString('Great work! No issues were identified', $result);
    }

    public function testFormatWithSingleFinding(): void
    {
        $formatter = new MarkdownFormatter();
        
        $findings = [
            [
                'rule_id' => 'test-rule',
                'title' => 'Test Issue',
                'severity' => 'high',
                'file_path' => 'src/Test.php',
                'start_line' => 10,
                'end_line' => 10,
                'rationale' => 'This is a test issue',
                'suggestion' => 'Fix the issue',
                'content' => 'Test content'
            ]
        ];
        
        $result = $formatter->format($findings);
        
        $this->assertIsString($result);
        $this->assertStringContainsString('# 🔍 Code Review Results', $result);
        $this->assertStringContainsString('## 📊 Summary', $result);
        $this->assertStringContainsString('**Total Issues:** 1', $result);
        $this->assertStringContainsString('🔴 **high**: 1', $result);
        $this->assertStringContainsString('## 🚨 Issues Found', $result);
        $this->assertStringContainsString('### 📄 `src/Test.php`', $result);
        $this->assertStringContainsString('#### 🔴 Test Issue', $result);
        $this->assertStringContainsString('**Line:** 10', $result);
        $this->assertStringContainsString('**Severity:** high', $result);
        $this->assertStringContainsString('**Rule:** `test-rule`', $result);
        $this->assertStringContainsString('**Rationale:** This is a test issue', $result);
        $this->assertStringContainsString('**Suggestion:** Fix the issue', $result);
    }

    public function testFormatWithMultipleFindings(): void
    {
        $formatter = new MarkdownFormatter();
        
        $findings = [
            [
                'title' => 'First Issue',
                'severity' => 'high',
                'file_path' => 'src/First.php',
                'start_line' => 5,
                'rationale' => 'First issue rationale',
                'suggestion' => 'First suggestion'
            ],
            [
                'title' => 'Second Issue',
                'severity' => 'medium',
                'file_path' => 'src/Second.php',
                'start_line' => 15,
                'rationale' => 'Second issue rationale',
                'suggestion' => 'Second suggestion'
            ]
        ];
        
        $result = $formatter->format($findings);
        
        $this->assertStringContainsString('**Total Issues:** 2', $result);
        $this->assertStringContainsString('🔴 **high**: 1', $result);
        $this->assertStringContainsString('🟡 **medium**: 1', $result);
        $this->assertStringContainsString('### 📄 `src/First.php`', $result);
        $this->assertStringContainsString('### 📄 `src/Second.php`', $result);
        $this->assertStringContainsString('#### 🔴 First Issue', $result);
        $this->assertStringContainsString('#### 🟡 Second Issue', $result);
    }

    public function testFormatWithFindingsGroupedByFile(): void
    {
        $formatter = new MarkdownFormatter();
        
        $findings = [
            [
                'title' => 'Issue 1 in File A',
                'severity' => 'high',
                'file_path' => 'src/FileA.php',
                'start_line' => 5
            ],
            [
                'title' => 'Issue 2 in File A',
                'severity' => 'low',
                'file_path' => 'src/FileA.php',
                'start_line' => 10
            ],
            [
                'title' => 'Issue in File B',
                'severity' => 'medium',
                'file_path' => 'src/FileB.php',
                'start_line' => 3
            ]
        ];
        
        $result = $formatter->format($findings);
        
        // Verify files are sorted alphabetically
        $fileAPos = strpos($result, '### 📄 `src/FileA.php`');
        $fileBPos = strpos($result, '### 📄 `src/FileB.php`');
        $this->assertLessThan($fileBPos, $fileAPos);
        
        // Verify both issues for FileA are under its section
        $this->assertStringContainsString('Issue 1 in File A', $result);
        $this->assertStringContainsString('Issue 2 in File A', $result);
        $this->assertStringContainsString('Issue in File B', $result);
    }

    public function testSeverityEmojis(): void
    {
        $formatter = new MarkdownFormatter();
        
        $findings = [
            ['title' => 'High', 'severity' => 'high', 'file_path' => 'test.php'],
            ['title' => 'Error', 'severity' => 'error', 'file_path' => 'test.php'],
            ['title' => 'Medium', 'severity' => 'medium', 'file_path' => 'test.php'],
            ['title' => 'Warning', 'severity' => 'warning', 'file_path' => 'test.php'],
            ['title' => 'Low', 'severity' => 'low', 'file_path' => 'test.php'],
            ['title' => 'Info', 'severity' => 'info', 'file_path' => 'test.php'],
            ['title' => 'Unknown', 'severity' => 'unknown', 'file_path' => 'test.php'],
        ];
        
        $result = $formatter->format($findings);
        
        $this->assertStringContainsString('#### 🔴 High', $result);
        $this->assertStringContainsString('#### 🔴 Error', $result);
        $this->assertStringContainsString('#### 🟡 Medium', $result);
        $this->assertStringContainsString('#### 🟡 Warning', $result);
        $this->assertStringContainsString('#### 🔵 Low', $result);
        $this->assertStringContainsString('#### 🔵 Info', $result);
        $this->assertStringContainsString('#### ⚪ Unknown', $result);
    }

    public function testFormatWithMissingFields(): void
    {
        $formatter = new MarkdownFormatter();
        
        $findings = [
            [
                // Missing most optional fields
                'file_path' => 'test.php'
            ]
        ];
        
        $result = $formatter->format($findings);
        
        $this->assertStringContainsString('#### ⚪ Unknown Issue', $result);
        $this->assertStringContainsString('**Line:** 0', $result);
        $this->assertStringContainsString('**Severity:** unknown', $result);
        $this->assertStringNotContainsString('**Rule:**', $result);
        $this->assertStringNotContainsString('**Rationale:**', $result);
        $this->assertStringNotContainsString('**Suggestion:**', $result);
    }

    public function testFormatWithUnknownFilePath(): void
    {
        $formatter = new MarkdownFormatter();
        
        $findings = [
            [
                'title' => 'Issue with unknown file',
                'severity' => 'info'
                // Missing file_path
            ]
        ];
        
        $result = $formatter->format($findings);
        
        $this->assertStringContainsString('### 📄 `unknown`', $result);
        $this->assertStringContainsString('Issue with unknown file', $result);
    }

    public function testFormatWithMetadataEnabled(): void
    {
        $formatter = new MarkdownFormatter(['include_metadata' => true]);
        
        $findings = [
            [
                'title' => 'Test',
                'severity' => 'info',
                'file_path' => 'test.php'
            ]
        ];
        
        $result = $formatter->format($findings);
        
        $this->assertStringContainsString('## 📋 Metadata', $result);
        $this->assertStringContainsString('**Generated:**', $result);
        $this->assertStringContainsString('**Formatter:** Markdown (Custom Plugin)', $result);
    }

    public function testFormatWithMetadataDisabled(): void
    {
        $formatter = new MarkdownFormatter(['include_metadata' => false]);
        
        $findings = [
            [
                'title' => 'Test',
                'severity' => 'info',
                'file_path' => 'test.php'
            ]
        ];
        
        $result = $formatter->format($findings);
        
        $this->assertStringNotContainsString('## 📋 Metadata', $result);
        $this->assertStringNotContainsString('**Generated:**', $result);
    }

    public function testSeveritySorting(): void
    {
        $formatter = new MarkdownFormatter();
        
        $findings = [
            ['severity' => 'low', 'file_path' => 'test.php'],
            ['severity' => 'high', 'file_path' => 'test.php'],
            ['severity' => 'medium', 'file_path' => 'test.php'],
            ['severity' => 'high', 'file_path' => 'test.php'],
        ];
        
        $result = $formatter->format($findings);
        
        // Verify high appears before medium in summary (arsort)
        $highPos = strpos($result, '🔴 **high**: 2');
        $mediumPos = strpos($result, '🟡 **medium**: 1');
        $lowPos = strpos($result, '🔵 **low**: 1');
        
        $this->assertNotFalse($highPos);
        $this->assertNotFalse($mediumPos);
        $this->assertNotFalse($lowPos);
        $this->assertLessThan($mediumPos, $highPos);
        $this->assertLessThan($lowPos, $mediumPos);
    }

    public function testFormatWithComplexScenario(): void
    {
        $formatter = new MarkdownFormatter();
        
        $findings = [
            [
                'rule_id' => 'SECURITY-001',
                'title' => 'SQL Injection Risk',
                'severity' => 'high',
                'file_path' => 'src/Database/UserRepository.php',
                'start_line' => 45,
                'end_line' => 47,
                'rationale' => 'Direct SQL query construction without parameter binding',
                'suggestion' => 'Use prepared statements or query builder',
                'content' => '$query = "SELECT * FROM users WHERE id = " . $id;'
            ],
            [
                'rule_id' => 'STYLE-002',
                'title' => 'Missing Type Declaration',
                'severity' => 'low',
                'file_path' => 'src/Database/UserRepository.php',
                'start_line' => 12,
                'end_line' => 12,
                'rationale' => 'Method parameter lacks type declaration',
                'suggestion' => 'Add type declaration for better code clarity',
                'content' => 'public function findUser($id)'
            ],
            [
                'rule_id' => 'PERF-003',
                'title' => 'N+1 Query Problem',
                'severity' => 'medium',
                'file_path' => 'src/Service/OrderService.php',
                'start_line' => 23,
                'end_line' => 28,
                'rationale' => 'Loop executes database query for each iteration',
                'suggestion' => 'Use eager loading or batch queries',
                'content' => 'foreach ($orders as $order) { $order->getUser(); }'
            ]
        ];
        
        $result = $formatter->format($findings);
        
        // Verify structure
        $this->assertStringContainsString('# 🔍 Code Review Results', $result);
        $this->assertStringContainsString('**Total Issues:** 3', $result);
        
        // Verify severity counts in sorted order
        $this->assertStringContainsString('🔴 **high**: 1', $result);
        $this->assertStringContainsString('🟡 **medium**: 1', $result);
        $this->assertStringContainsString('🔵 **low**: 1', $result);
        
        // Verify files are grouped and sorted
        $userRepoPos = strpos($result, '### 📄 `src/Database/UserRepository.php`');
        $orderServicePos = strpos($result, '### 📄 `src/Service/OrderService.php`');
        $this->assertLessThan($orderServicePos, $userRepoPos);
        
        // Verify individual findings
        $this->assertStringContainsString('#### 🔴 SQL Injection Risk', $result);
        $this->assertStringContainsString('**Rule:** `SECURITY-001`', $result);
        $this->assertStringContainsString('Direct SQL query construction', $result);
        $this->assertStringContainsString('Use prepared statements', $result);
    }
}