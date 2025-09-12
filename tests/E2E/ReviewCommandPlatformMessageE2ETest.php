<?php

declare(strict_types=1);

namespace AICR\Tests\E2E;

use AICR\Adapters\GithubAdapter;
use AICR\Command\ReviewCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ReviewCommandPlatformMessageE2ETest extends TestCase
{
    private string $tmpCfg;

    protected function setUp(): void
    {
        $this->tmpCfg = sys_get_temp_dir().'/aicr_cmd_e2e_platform_'.uniqid('', true).'.yml';
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
YML;
        file_put_contents($this->tmpCfg, $yaml);
    }

    protected function tearDown(): void
    {
        @unlink($this->tmpCfg);
    }

    /**
     * A GithubAdapter-compatible test double that avoids any real Git/HTTP.
     */
    private function makeGithubAdapterDouble(): GithubAdapter
    {
        return new class extends GithubAdapter {
            public ?int $postedId = null;
            public ?string $postedBody = null;
            public function __construct() { /* do not call parent */ }
            public function resolveBranchesFromId(int $id): array { return ['base', 'head']; }
            public function postComment(int $id, string $body): void { $this->postedId = $id; $this->postedBody = $body; }
        };
    }

    public function testReviewCommand_postsComment_withGithubSpecificMessage(): void
    {
        $gh = $this->makeGithubAdapterDouble();
        $cmd = new ReviewCommand($gh);
        $app = new Application('AI Code Review Bot Test');
        $app->add($cmd);

        $command = $app->find('review');
        $tester  = new CommandTester($command);

        $diffPath = __DIR__.'/../../examples/sample.diff';

        $exit = $tester->execute([
            '--diff-file' => $diffPath,
            '--config'    => $this->tmpCfg,
            '--output'    => 'summary',
            '--id'        => '7',
            '--comment'   => true,
        ]);

        $this->assertSame(0, $exit);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Comment posted.', $display);
        $this->assertSame(7, $gh->postedId);
        $this->assertIsString($gh->postedBody);
        $this->assertStringContainsString('Findings (', $gh->postedBody ?? '');
    }
}
