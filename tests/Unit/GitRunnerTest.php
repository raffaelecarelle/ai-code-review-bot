<?php

declare(strict_types=1);

namespace AICR\Tests\Unit;

use AICR\Support\GitRunner;
use PHPUnit\Framework\TestCase;

final class GitRunnerTest extends TestCase
{
    public function testEsc(): void
    {
        $git = new GitRunner();
        $this->assertSame("'a b'", $git->esc('a b'));
        $this->assertSame("''" , $git->esc(''));
    }

    public function testRunFailureThrows(): void
    {
        $git = new GitRunner();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Git command failed');
        // Intentionally use an invalid git subcommand to force failure
        $git->run('definitely_invalid_subcommand_for_tests_'.uniqid('x', true));
    }

    public function testRunSuccessful(): void
    {
        $git = new GitRunner();
        // Test with a simple git command that should always work
        $result = $git->run('--version');
        
        // Git version output should contain 'git version'
        $this->assertStringContainsString('git version', $result);
        // Result should end with a newline
        $this->assertStringEndsWith("\n", $result);
    }

    public function testRunWithOutput(): void
    {
        $git = new GitRunner();
        // Test git status command which should produce output
        $result = $git->run('status --porcelain');
        
        // Result should be a string (even if empty)
        $this->assertIsString($result);
        // Result should end with a newline
        $this->assertStringEndsWith("\n", $result);
    }

    public function testRunHandlesEmptyOutput(): void
    {
        $git = new GitRunner();
        // Use a git command that typically produces no output
        $result = $git->run('status --porcelain --untracked-files=no');
        
        // Even with no output, should return a string ending with newline
        $this->assertIsString($result);
        $this->assertStringEndsWith("\n", $result);
    }
}
