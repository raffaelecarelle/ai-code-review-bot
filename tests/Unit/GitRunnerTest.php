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
}
