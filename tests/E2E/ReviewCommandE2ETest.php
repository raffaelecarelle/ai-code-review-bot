<?php

declare(strict_types=1);

namespace AICR\Tests\E2E;

use AICR\Adapters\VcsAdapter;
use AICR\Command\ReviewCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ReviewCommandE2ETest extends TestCase
{
    private string $tmpCfg;

    protected function setUp(): void
    {
        $this->tmpCfg = sys_get_temp_dir().'/aicr_cmd_e2e_'.uniqid('', true).'.yml';
        $yaml = <<<'YML'
providers:
  default: mock
vcs:
  platform: github
context:
  diff_token_limit: 10000
  per_file_token_cap: 5000
  overflow_strategy: trim
policy:
  min_severity_to_comment: info
  max_comments: 50
  redact_secrets: true
rules:
  include: []
  inline:
    - { id: "PHP.NO.ECHO", applies_to: ["**/*.php"], severity: "minor", rationale: "Avoid echo", pattern: "(^|\s)echo\s", suggestion: "Use logger", enabled: true }
YML;
        file_put_contents($this->tmpCfg, $yaml);
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpCfg);
    }

    public function testReviewCommand_withDiffFile_andMockedAdapterAndAI(): void
    {
        $fakeAdapter = new class implements VcsAdapter {
            public ?int $lastId = null;
            public ?string $lastBody = null;
            public function resolveBranchesFromId(int $id): array { return ['base', 'head']; }
            public function postComment(int $id, string $body): void { $this->lastId = $id; $this->lastBody = $body; }
        };

        $cmd = new ReviewCommand($fakeAdapter);

        $app = new Application('AI Code Review Bot Test');
        $app->add($cmd);

        $command = $app->find('review');
        $tester = new CommandTester($command);

        $diffPath = __DIR__.'/../../examples/sample.diff';

        $exit = $tester->execute([
            '--diff-file' => $diffPath,
            '--config' => $this->tmpCfg,
            '--output' => 'summary',
            '--id' => '42',
            '--comment' => true,
        ]);

        $this->assertSame(0, $exit);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Findings (', $display);
        $this->assertStringContainsString('src/Example.php', $display);
        $this->assertStringContainsString('Comment posted.', $display); // generic message for non-GitHub/GitLab adapter types

        // Ensure our fake adapter was used to post the comment
        $this->assertSame(42, $fakeAdapter->lastId);
        $this->assertIsString($fakeAdapter->lastBody);
        $this->assertStringContainsString('Findings (', $fakeAdapter->lastBody ?? '');
    }
}
